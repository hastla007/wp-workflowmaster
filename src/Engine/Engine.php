<?php
/**
 * The workflow engine: starts runs for stored events (Dispatcher) and runs their steps (worker).
 *
 * Runs keep a snapshot of the event (context) and the published version they started with (WF-CORE-02). Every step has
 * a durable key (R-8.4) and a result booking; a repeated step with a booked result runs nothing again (konzept 15.3).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

use WorkflowCaptain\Clock;
use WorkflowCaptain\Consumer\Consumer;
use WorkflowCaptain\Consumer\Dispatcher;
use WorkflowCaptain\Consumer\DispatchResult;
use WorkflowCaptain\Consumer\PermanentFailure;
use WorkflowCaptain\Consumer\RetryPlan;
use WorkflowCaptain\Consumer\TransientFailure;
use WorkflowCaptain\Envelope\Uuid;

defined( 'ABSPATH' ) || exit;

final class Engine implements Dispatcher {

	/** Seconds a worker holds a claimed step (WF-CORE-06). */
	const LEASE_SECONDS = 300;

	/** A step overdue by more than this is not run on its own but shown for a decision (konzept 15.3). */
	const CATCH_UP_SECONDS = DAY_IN_SECONDS;

	private ?Consumer $consumer = null;

	/** Global emergency pause (WF-SET-11): no step runs while it is set. */
	private bool $paused = false;

	/** Limits of konzept 15.3 "Parallelität und Reihenfolge"; start values, adjustable in the settings. */
	private array $limits = array(
		'site'     => 5,
		'workflow' => 2,
		'catch_up' => DAY_IN_SECONDS,
		'seconds'  => 20,
	);

	public function __construct(
		private EngineStore $store,
		private ActionRegistry $actions,
		private Directory $directory,
		private Clock $clock,
		private string $timezone = 'UTC',
		array $limits = array()
	) {
		foreach ( $limits as $key => $value ) {
			if ( isset( $this->limits[ $key ] ) && (int) $value > 0 ) {
				$this->limits[ $key ] = (int) $value;
			}
		}
	}

	/** @var callable():array<string,?string>|null */
	private $contracts = null;

	/** Current contract version per registered provider, for the run snapshot check (WF-CORE-02). */
	public function set_provider_contracts( callable $contracts ): void {
		$this->contracts = $contracts;
	}

	/** Whether the provider of an action still has the MAJOR the version was published with. */
	private function provider_compatible( array $version, string $action_key ): bool {
		$snapshot = (array) ( $version['graph']['providers'] ?? array() );
		if ( null === $this->contracts || array() === $snapshot ) {
			return true;
		}
		$product = \WorkflowCaptain\Envelope\Validator::product_for_prefix( (string) strstr( $action_key . '.', '.', true ), array_keys( $snapshot ) );
		if ( null === $product || ! array_key_exists( $product, $snapshot ) ) {
			return true;
		}
		$current = (array) ( $this->contracts )();
		if ( ! array_key_exists( $product, $current ) ) {
			return false;
		}
		$major = static fn( $v ) => null === $v ? null : (int) $v;
		return $major( $snapshot[ $product ] ) === $major( $current[ $product ] );
	}

	public function set_paused( bool $paused ): void {
		$this->paused = $paused;
	}

	public function limits(): array {
		return $this->limits;
	}

	/** The consumer whose run_command() gives events caused by an action their causation (C-9). */
	public function set_consumer( Consumer $consumer ): void {
		$this->consumer = $consumer;
	}

	public function store(): EngineStore {
		return $this->store;
	}

	public function actions(): ActionRegistry {
		return $this->actions;
	}

	// ----- Dispatcher -----------------------------------------------------------------------------------------

	public function dispatch( array $envelope, ?int $exclude_workflow_id ): DispatchResult {
		$started = array();
		$now     = $this->clock->mysql();
		foreach ( $this->store->active_versions_for_trigger( (string) $envelope['type'] ) as $version ) {
			if ( $version['workflow_id'] === $exclude_workflow_id ) {
				continue; // R-7.14: a workflow never starts from an event it caused.
			}
			$trigger = Graph::trigger( $version['graph'] );
			$first   = null === $trigger ? null : Graph::next( $version['graph'], (string) $trigger['id'] );
			if ( null === $first ) {
				continue;
			}
			list( $run_id, $created ) = $this->store->create_run(
				array(
					'workflow_id'   => $version['workflow_id'],
					'version_id'    => $version['id'],
					'event_row_id'  => 0,
					'event_key'     => (string) $envelope['idempotency_key'],
					'context'       => $envelope,
					'actor_user_id' => isset( $envelope['actor_user_id'] ) ? (int) $envelope['actor_user_id'] : null,
					'serial_key'    => self::serial_key( $trigger, $envelope, (int) $version['workflow_id'] ),
				),
				$now
			);
			// Also when the run existed (repetition after a failure): its first step must exist exactly once.
			$this->store->ensure_step( $run_id, $first, self::step_key( $version, $envelope, $first ), $now, $now );
			if ( $created ) {
				$started[] = $version['workflow_id'];
			}
		}
		// Runs keep their own snapshot of the event, so the stored event can drop its personal data right away.
		return new DispatchResult( $started, false );
	}

	/**
	 * Serialisation key of a run (konzept 15.3): with "serialize" = "subject" or "actor" on the trigger, at most one run
	 * of the workflow per object or user progresses at a time; the others wait in start order.
	 */
	public static function serial_key( array $trigger, array $envelope, int $workflow_id ): ?string {
		$mode = $trigger['serialize'] ?? null;
		if ( 'subject' === $mode ) {
			return hash( 'sha256', $workflow_id . '|s|' . ( $envelope['source']['product'] ?? '' ) . '|' . ( $envelope['subject']['type'] ?? '' ) . '|' . ( $envelope['subject']['id'] ?? '' ) );
		}
		if ( 'actor' === $mode && isset( $envelope['actor_user_id'] ) ) {
			return hash( 'sha256', $workflow_id . '|a|' . (int) $envelope['actor_user_id'] );
		}
		return null;
	}

	/** Key of a step: workflow, version, event and node (R-8.4). */
	public static function step_key( array $version, array $envelope, string $node_id ): string {
		return hash( 'sha256', implode( '|', array( $version['workflow_id'], $version['version'], (string) $envelope['id'], $node_id ) ) );
	}

	// ----- Worker ---------------------------------------------------------------------------------------------

	/**
	 * Runs due steps one at a time within the limits (konzept 15.3): at most "site" steps held by workers at once,
	 * at most "workflow" per workflow, at most half a batch for one workflow while others wait, and serialised runs in
	 * start order. Returns the number handled.
	 */
	public function process( int $limit = 25 ): int {
		if ( $this->paused ) {
			return 0;
		}
		$owner   = Uuid::v4();
		$handled = 0;
		$per_wf  = array();
		$skip    = array();
		$half    = max( 1, (int) ceil( $limit / 2 ) );
		$started = microtime( true );
		while ( $handled < $limit ) {
			// Time budget per worker run (WF-SET-05): a step already started finishes; no new one after the budget.
			if ( microtime( true ) - $started >= $this->limits['seconds'] ) {
				break;
			}
			$now     = $this->clock->now();
			$mysql   = $this->clock->mysql( $now );
			$running = $this->store->running_counts( $mysql );
			if ( $running['total'] >= $this->limits['site'] ) {
				break;
			}
			$candidates = array_filter( $this->store->due_steps( 4 * $limit + 10, $mysql ), static fn( $c ) => ! isset( $skip[ $c['id'] ] ) );
			if ( array() === $candidates ) {
				break;
			}
			$several = count( array_unique( array_column( $candidates, 'workflow_id' ) ) ) > 1;
			$picked  = null;
			foreach ( $candidates as $c ) {
				$wf = $c['workflow_id'];
				if ( ( $running['by_workflow'][ $wf ] ?? 0 ) >= $this->limits['workflow'] || ( $several && ( $per_wf[ $wf ] ?? 0 ) >= $half ) ) {
					continue;
				}
				if ( null !== $c['serial_key'] && $this->store->older_open_run( $wf, $c['run_id'], $c['serial_key'] ) ) {
					$skip[ $c['id'] ] = true;
					continue;
				}
				$picked = $c;
				break;
			}
			if ( null === $picked ) {
				break;
			}
			$skip[ $picked['id'] ] = true;
			$step                  = $this->store->claim_step( $picked['id'], $mysql, $owner, $this->clock->mysql( $now + self::LEASE_SECONDS ) );
			if ( null === $step ) {
				continue; // Another worker was faster.
			}
			$this->handle( $step, $owner );
			$per_wf[ $picked['workflow_id'] ] = ( $per_wf[ $picked['workflow_id'] ] ?? 0 ) + 1;
			++$handled;
		}
		return $handled;
	}

	private function handle( array $step, string $owner ): void {
		$run      = $this->store->get_run( (int) $step['run_id'] );
		$attempts = (int) $step['attempts'] + 1;
		if ( null === $run ) {
			$this->finish( $step, $owner, EngineStore::STEP_CANCELLED, array( 'last_error' => 'run_missing' ) );
			return;
		}
		if ( EngineStore::RUN_CANCELLED === $run['state'] ) {
			$this->finish( $step, $owner, EngineStore::STEP_CANCELLED );
			return;
		}
		$version = $this->store->get_version( (int) $run['version_id'] );
		$node    = null === $version ? null : Graph::node( $version['graph'], (string) $step['node_id'] );
		if ( null === $node ) {
			$this->stop( $step, $owner, $run, EngineStore::STEP_ACTION_REQUIRED, EngineStore::RUN_ACTION_REQUIRED, 'node_missing', $attempts );
			return;
		}
		$envelope = $run['context'];
		try {
			switch ( $node['type'] ) {
				case 'end':
					$this->finish( $step, $owner, EngineStore::STEP_SUCCEEDED, array( 'attempts' => $attempts ) );
					$this->store->set_run_state( $run['id'], EngineStore::RUN_SUCCEEDED, null, $this->clock->mysql() );
					return;

				case 'condition':
					$outcome = Conditions::evaluate( (array) ( $node['rules'] ?? array() ), $envelope, (string) ( $node['missing'] ?? 'false' ) );
					if ( Conditions::STOP === $outcome ) {
						$this->finish( $step, $owner, EngineStore::STEP_SUCCEEDED, array( 'attempts' => $attempts, 'result' => array( 'outcome' => 'stop' ) ) );
						$this->store->set_run_state( $run['id'], EngineStore::RUN_STOPPED, 'condition_field_missing', $this->clock->mysql() );
						return;
					}
					if ( Conditions::ERROR === $outcome ) {
						$this->stop( $step, $owner, $run, EngineStore::STEP_ACTION_REQUIRED, EngineStore::RUN_ACTION_REQUIRED, 'condition_field_missing', $attempts );
						return;
					}
					$branch = Conditions::TRUE === $outcome ? 'then' : 'else';
					$this->advance( $step, $owner, $run, $version, $node, $branch, $this->clock->mysql(), array( 'outcome' => $branch ), $attempts );
					return;

				case 'delay':
					$until = $this->delay_until( (string) $node['unit'], (int) $node['amount'] );
					$this->advance( $step, $owner, $run, $version, $node, null, $this->clock->mysql( $until ), array( 'until' => $this->clock->rfc3339( $until ) ), $attempts );
					return;

				case 'action':
					$this->run_action( $step, $owner, $run, $version, $node, $envelope, $attempts );
					return;
			}
			$this->stop( $step, $owner, $run, EngineStore::STEP_ACTION_REQUIRED, EngineStore::RUN_ACTION_REQUIRED, 'unknown_node_type', $attempts );
		} catch ( PermanentFailure $e ) {
			$this->stop(
				$step,
				$owner,
				$run,
				$e->pause ? EngineStore::STEP_PAUSED : EngineStore::STEP_ACTION_REQUIRED,
				$e->pause ? EngineStore::RUN_PAUSED : EngineStore::RUN_ACTION_REQUIRED,
				self::code( $e->reason ),
				$attempts
			);
		} catch ( \Throwable $e ) {
			$reason = $e instanceof TransientFailure ? $e->reason : 'exception:' . ( new \ReflectionClass( $e ) )->getShortName();
			$fresh  = $this->store->get_step( (int) $step['id'] );
			$action = 'action' === $node['type'] ? $this->actions->get( (string) $node['action'] ) : null;
			// A started, non-idempotent side effect may have happened: never repeat it on our own (R-8.12).
			$unclear = ! $e instanceof TransientFailure && null !== $action && ! $action->idempotent() && EngineStore::EFFECT_STARTED === ( $fresh['effect'] ?? '' );
			$delay   = $unclear ? null : RetryPlan::delay( $attempts );
			if ( null === $delay ) {
				$this->stop( $step, $owner, $run, EngineStore::STEP_ACTION_REQUIRED, EngineStore::RUN_ACTION_REQUIRED, self::code( $unclear ? 'unclear_outcome' : $reason ), $attempts );
				return;
			}
			$change = array(
				'attempts'   => $attempts,
				'due_at'     => $this->clock->mysql( $this->clock->now() + $delay ),
				'last_error' => self::code( $reason ),
			);
			if ( $e instanceof TransientFailure ) {
				$change['effect'] = EngineStore::EFFECT_NONE; // The action said nothing happened.
			}
			$this->finish( $step, $owner, EngineStore::STEP_RETRY_WAIT, $change );
		}
	}

	private function run_action( array $step, string $owner, array $run, array $version, array $node, array $envelope, int $attempts ): void {
		$action = $this->actions->get( (string) ( $node['action'] ?? '' ) );
		if ( null === $action ) {
			// Provider or action gone: pause safely, never switch to another action (WF-CORE-02).
			$this->stop( $step, $owner, $run, EngineStore::STEP_PAUSED, EngineStore::RUN_PAUSED, 'action_unavailable', $attempts );
			return;
		}
		if ( ! $this->provider_compatible( $version, (string) $node['action'] ) ) {
			// The provider of this action changed its MAJOR since publishing (or is gone): never run on a new contract.
			$this->stop( $step, $owner, $run, EngineStore::STEP_PAUSED, EngineStore::RUN_PAUSED, 'provider_contract_changed', $attempts );
			return;
		}
		$authorized_by = (int) $version['authorized_by_user_id'];
		$capability    = $action->capability();
		if ( null !== $capability && ! $this->directory->user_can( $authorized_by, $capability ) ) {
			// R-8.8: the publishing user no longer exists or lost the right; the workflow pauses with a reason.
			$this->stop( $step, $owner, $run, EngineStore::STEP_PAUSED, EngineStore::RUN_PAUSED, 'not_authorized', $attempts );
			return;
		}
		if ( EngineStore::EFFECT_DONE !== $step['effect'] ) {
			if ( 1 === $attempts && strtotime( $step['due_at'] . ' UTC' ) < $this->clock->now() - $this->limits['catch_up'] ) {
				$this->stop( $step, $owner, $run, EngineStore::STEP_ACTION_REQUIRED, EngineStore::RUN_ACTION_REQUIRED, 'overdue', $attempts );
				return;
			}
			if ( EngineStore::EFFECT_STARTED === $step['effect'] && ! $action->idempotent() ) {
				$this->stop( $step, $owner, $run, EngineStore::STEP_ACTION_REQUIRED, EngineStore::RUN_ACTION_REQUIRED, 'unclear_outcome', $attempts );
				return;
			}
			if ( ! $action->idempotent() ) {
				$this->store->set_effect( (int) $step['id'], $owner, EngineStore::EFFECT_STARTED, $this->clock->mysql() );
			}
			$step_id   = (int) $step['id'];
			$heartbeat = fn(): bool => $this->store->extend_lease( $step_id, $owner, $this->clock->mysql( $this->clock->now() + self::LEASE_SECONDS ), $this->clock->mysql() );
			$context   = new ActionContext( $envelope, (array) ( $node['config'] ?? array() ), (string) $step['step_key'], (int) $run['workflow_id'], (int) $run['id'], $step_id, $authorized_by, $heartbeat );
			$execute = static fn() => $action->execute( $context );
			$result  = null === $this->consumer ? $execute() : $this->consumer->run_command( Consumer::cause_for( $envelope, (int) $run['workflow_id'] ), $execute );
			$this->store->set_effect( (int) $step['id'], $owner, EngineStore::EFFECT_DONE, $this->clock->mysql() );
		} else {
			$result = (array) ( $step['result'] ?? array() ); // Done before a crash: book it, do not repeat it.
		}
		$this->advance( $step, $owner, $run, $version, $node, null, $this->clock->mysql(), self::redact( (array) $result ), $attempts );
	}

	/** Creates the next step first, then closes this one, so a crash in between loses nothing. */
	private function advance( array $step, string $owner, array $run, array $version, array $node, ?string $branch, string $due, array $result, int $attempts ): void {
		$next = Graph::next( $version['graph'], (string) $node['id'], $branch );
		if ( null !== $next ) {
			$this->store->ensure_step( (int) $run['id'], $next, self::step_key( $version, $run['context'], $next ), $due, $this->clock->mysql() );
		}
		$this->finish( $step, $owner, EngineStore::STEP_SUCCEEDED, array( 'attempts' => $attempts, 'result' => $result, 'last_error' => null ) );
		if ( null === $next ) {
			$this->store->set_run_state( (int) $run['id'], EngineStore::RUN_SUCCEEDED, null, $this->clock->mysql() );
		}
	}

	private function stop( array $step, string $owner, array $run, string $step_state, string $run_state, string $reason, int $attempts ): void {
		$this->finish( $step, $owner, $step_state, array( 'attempts' => $attempts, 'last_error' => $reason ) );
		$this->store->set_run_state( (int) $run['id'], $run_state, $reason, $this->clock->mysql() );
	}

	private function finish( array $step, string $owner, string $state, array $change = array() ): void {
		$this->store->finish_step( (int) $step['id'], $owner, array( 'state' => $state ) + $change, $this->clock->mysql() );
	}

	/**
	 * Target time of a delay (konzept 15.3 "Zeitplansemantik"): minutes and hours are absolute; days and weeks are
	 * calendar days in the site's time zone and keep the local time; a time in the DST gap moves forward like PHP's
	 * DateTime does.
	 */
	public function delay_until( string $unit, int $amount ): int {
		if ( 'minutes' === $unit || 'hours' === $unit ) {
			return $this->clock->now() + $amount * ( 'minutes' === $unit ? MINUTE_IN_SECONDS : HOUR_IN_SECONDS );
		}
		try {
			$zone = new \DateTimeZone( $this->timezone );
		} catch ( \Exception $e ) {
			$zone = new \DateTimeZone( 'UTC' );
		}
		$days  = 'weeks' === $unit ? $amount * 7 : $amount;
		$local = ( new \DateTimeImmutable( '@' . $this->clock->now() ) )->setTimezone( $zone )->modify( '+' . $days . ' days' );
		return $local->getTimestamp();
	}

	/** Results keep only scalar values and no address-like strings (run log, R-12.3). */
	private static function redact( array $result ): array {
		$clean = array();
		foreach ( $result as $key => $value ) {
			if ( is_scalar( $value ) && ! ( is_string( $value ) && str_contains( $value, '@' ) ) ) {
				$clean[ (string) $key ] = $value;
			}
		}
		return $clean;
	}

	private static function code( string $reason ): string {
		$code = preg_replace( '/[^a-z0-9_:.\-]/i', '', $reason );
		return substr( '' === $code ? 'error' : $code, 0, 64 );
	}
}
