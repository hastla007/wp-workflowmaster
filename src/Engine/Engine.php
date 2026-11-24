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

	/** Runs whose definition lives outside this site (network workflows, R-17.4). */
	private ForeignRuns $foreign;

	/** Global emergency pause (WF-SET-11): no step runs while it is set. */
	private bool $paused = false;

	/** Limits of konzept 15.3 "Parallelität und Reihenfolge"; start values, adjustable in the settings. */
	private array $limits = array(
		'site'     => 5,
		'workflow' => 2,
		'catch_up' => DAY_IN_SECONDS,
		'seconds'  => 20,
		'detailed' => 0, // SET-WC-21: detailed step log (0/1).
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
			if ( 'detailed' === $key ) {
				$this->limits[ $key ] = $value ? 1 : 0;
			} elseif ( isset( $this->limits[ $key ] ) && (int) $value > 0 ) {
				$this->limits[ $key ] = (int) $value;
			}
		}
		$this->foreign = new ForeignRuns();
	}

	/** Extension point for runs from another site of the network (resolver, gate, observer). */
	public function foreign(): ForeignRuns {
		return $this->foreign;
	}

	/** @var callable():array<string,?string>|null */
	private $contracts = null;

	/**
	 * Due schedule events of active workflows with a schedule trigger (WF-CORE-04). The last fired time per workflow
	 * is kept by the caller; a missed time fires at most once and never when older than the catch-up limit.
	 *
	 * @param array<int,int> $last_fired workflow ID => UTC timestamp of the last fired time.
	 * @return array<int,array{workflow_id:int,due:int,event:array}>
	 */
	public function due_schedules( array $last_fired ): array {
		$now = $this->clock->now();
		$out = array();
		foreach ( $this->store->active_versions_for_trigger( Schedule::EVENT ) as $version ) {
			$trigger = Graph::trigger( $version['graph'] );
			$wid     = (int) $version['workflow_id'];
			$after   = max( (int) strtotime( $version['created_at'] . ' UTC' ), (int) ( $last_fired[ $wid ] ?? 0 ), $now - $this->limits['catch_up'] );
			$due     = null === $trigger ? null : Schedule::last_due( (array) ( $trigger['schedule'] ?? array() ), $now, $after, $this->timezone );
			if ( null !== $due ) {
				$out[] = array( 'workflow_id' => $wid, 'due' => $due, 'event' => Schedule::event( $wid, $due, $now ) );
			}
		}
		return $out;
	}

	/**
	 * Overview of all schedules (docs/UI-ENTWUERFE.md, section 4): every active or paused workflow whose published
	 * version starts on a schedule, with the next due time (Schedule::upcoming(), site time zone; none while paused),
	 * the last fired time and whether the draft has another trigger or schedule than the published version.
	 *
	 * @param array<int,int> $last_fired workflow ID => UTC timestamp of the last fired time (as for due_schedules()).
	 * @return array<int,array{workflow_id:int,name:string,status:string,version:int,schedule:array,next:?int,last_fired:?int,draft_differs:bool}>
	 */
	public function schedules( array $last_fired ): array {
		$now = $this->clock->now();
		$out = array();
		foreach ( $this->store->published_for_trigger( Schedule::EVENT ) as $row ) {
			$wf       = $row['workflow'];
			$trigger  = Graph::trigger( $row['version']['graph'] );
			$schedule = (array) ( $trigger['schedule'] ?? array() );
			$draft    = Graph::trigger( (array) $wf['draft'] );
			$next     = EngineStore::WF_ACTIVE === $wf['status'] ? ( Schedule::upcoming( $schedule, $now, $this->timezone, 1 )[0] ?? null ) : null;
			$out[]    = array(
				'workflow_id'   => (int) $wf['id'],
				'name'          => (string) $wf['name'],
				'status'        => (string) $wf['status'],
				'version'       => (int) $row['version']['version'],
				'schedule'      => $schedule,
				'next'          => $next,
				'last_fired'    => isset( $last_fired[ (int) $wf['id'] ] ) ? (int) $last_fired[ (int) $wf['id'] ] : null,
				'draft_differs' => Schedule::EVENT !== ( $draft['event'] ?? null ) || (array) ( $draft['schedule'] ?? array() ) != $schedule,
			);
		}
		return $out;
	}

	/** Site time zone the engine computes schedules in. */
	public function timezone(): string {
		return $this->timezone;
	}

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

	/** Wakes steps that wait after Suspend (Plugin hands one to "workflow_captain_waiting_v1"). */
	public function waiting(): Waiting {
		return new Waiting( $this->store, $this->clock );
	}

	// ----- Dispatcher -----------------------------------------------------------------------------------------

	public function dispatch( array $envelope, ?int $exclude_workflow_id ): DispatchResult {
		$started = array();
		$now     = $this->clock->mysql();
		foreach ( $this->store->active_versions_for_trigger( (string) $envelope['type'] ) as $version ) {
			if ( $version['workflow_id'] === $exclude_workflow_id ) {
				continue; // R-7.14: a workflow never starts from an event it caused.
			}
			if ( Schedule::EVENT === $envelope['type'] && (string) $version['workflow_id'] !== (string) ( $envelope['subject']['id'] ?? '' ) ) {
				continue; // A schedule starts only the workflow it belongs to (WF-CORE-04).
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
		if ( EngineStore::RUN_WAITING === $run['state'] ) {
			// The waiting step is due again (its time has passed): the run continues.
			$this->run_state( $run, EngineStore::RUN_RUNNING, null );
			$run['state'] = EngineStore::RUN_RUNNING;
		}
		$foreign = ForeignRuns::is_foreign( $run );
		$version = $foreign ? $this->foreign->version( $run ) : $this->store->get_version( (int) $run['version_id'] );
		if ( $foreign && null === $version ) {
			// The definition is not available here (add-on inactive, workflow gone): pause, never guess (R-17.4).
			$this->stop( $step, $owner, $run, EngineStore::STEP_PAUSED, EngineStore::RUN_PAUSED, ForeignRuns::UNAVAILABLE, $attempts );
			return;
		}
		if ( $foreign ) {
			$this->foreign->observe( $run, EngineStore::RUN_RUNNING, null );
		}
		$node = null === $version ? null : Graph::node( $version['graph'], (string) $step['node_id'] );
		if ( null === $node ) {
			$this->stop( $step, $owner, $run, EngineStore::STEP_ACTION_REQUIRED, EngineStore::RUN_ACTION_REQUIRED, 'node_missing', $attempts );
			return;
		}
		$envelope = $run['context'];
		try {
			switch ( $node['type'] ) {
				case 'end':
					$this->finish( $step, $owner, EngineStore::STEP_SUCCEEDED, array( 'attempts' => $attempts ) );
					$this->run_state( $run, EngineStore::RUN_SUCCEEDED, null );
					return;

				case 'condition':
					$outcome = Conditions::evaluate( (array) ( $node['rules'] ?? array() ), $envelope, (string) ( $node['missing'] ?? 'false' ) );
					if ( Conditions::STOP === $outcome ) {
						$this->finish( $step, $owner, EngineStore::STEP_SUCCEEDED, array( 'attempts' => $attempts, 'result' => array( 'outcome' => 'stop' ) ) );
						$this->run_state( $run, EngineStore::RUN_STOPPED, 'condition_field_missing' );
						return;
					}
					if ( Conditions::ERROR === $outcome ) {
						$this->stop( $step, $owner, $run, EngineStore::STEP_ACTION_REQUIRED, EngineStore::RUN_ACTION_REQUIRED, 'condition_field_missing', $attempts );
						return;
					}
					$branch = Conditions::TRUE === $outcome ? 'then' : 'else';
					$logged = array( 'outcome' => $branch );
					if ( ! empty( $this->limits['detailed'] ) ) {
						$logged['rules'] = Conditions::explain( (array) ( $node['rules'] ?? array() ), $envelope ); // SET-WC-21.
					}
					$this->advance( $step, $owner, $run, $version, $node, $branch, $this->clock->mysql(), $logged, $attempts );
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
		} catch ( Suspend $s ) {
			// The action waits for something outside the run: nothing happened. No lease, no attempt, no side effect.
			if ( ForeignRuns::is_foreign( $run ) ) {
				// A run of another site cannot be woken here (the add-on that resumes it lives on the source site).
				$this->finish( $step, $owner, EngineStore::STEP_PAUSED, array( 'attempts' => $attempts - 1, 'effect' => EngineStore::EFFECT_NONE, 'last_error' => 'suspend_not_network_safe' ) );
				$this->run_state( $run, EngineStore::RUN_PAUSED, 'suspend_not_network_safe' );
				return;
			}
			$reason = self::code( $s->reason );
			$this->finish(
				$step,
				$owner,
				EngineStore::STEP_WAITING,
				array(
					'attempts'   => $attempts - 1,
					'due_at'     => null === $s->until ? EngineStore::WAIT_NO_DEADLINE : $this->clock->mysql( $s->until ),
					'effect'     => EngineStore::EFFECT_NONE,
					'last_error' => null,
					'result'     => array( 'waiting' => $reason, 'until' => null === $s->until ? null : $this->clock->rfc3339( $s->until ) ),
				)
			);
			$this->run_state( $run, EngineStore::RUN_WAITING, $reason );
		} catch ( Halt $h ) {
			// The action ends the run regularly here: the step succeeded, no further step (like a condition "stop").
			$reason = self::code( $h->reason );
			$this->finish(
				$step,
				$owner,
				EngineStore::STEP_SUCCEEDED,
				array(
					'attempts'   => $attempts,
					'effect'     => EngineStore::EFFECT_DONE,
					'last_error' => null,
					'result'     => array( 'halted' => $reason ) + self::redact( $h->result ),
				)
			);
			$this->run_state( $run, EngineStore::RUN_STOPPED, $reason );
		} catch ( Deferred $d ) {
			// Not yet (sending window, daily limit; SET-WC-07/-08): nothing happened. Back to the queue until the
			// window opens, without counting an attempt; the reason and time are visible in the step log.
			$this->finish(
				$step,
				$owner,
				EngineStore::STEP_QUEUED,
				array(
					'attempts'   => $attempts - 1,
					'due_at'     => $this->clock->mysql( max( $d->until, $this->clock->now() + MINUTE_IN_SECONDS ) ),
					'effect'     => EngineStore::EFFECT_NONE,
					'last_error' => null,
					'result'     => array( 'deferred' => self::code( $d->reason ), 'until' => $this->clock->rfc3339( $d->until ) ),
				)
			);
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
				if ( $e instanceof TransientFailure && EngineStore::EFFECT_STARTED === ( $fresh['effect'] ?? '' ) ) {
					// The action said nothing happened: a human may continue without accepting a repeated side effect.
					$this->store->set_effect( (int) $step['id'], $owner, EngineStore::EFFECT_NONE, $this->clock->mysql() );
				}
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
		$step_id   = (int) $step['id'];
		$heartbeat = fn(): bool => $this->store->extend_lease( $step_id, $owner, $this->clock->mysql( $this->clock->now() + self::LEASE_SECONDS ), $this->clock->mysql() );
		$context   = new ActionContext( $envelope, (array) ( $node['config'] ?? array() ), (string) $step['step_key'], (int) $run['workflow_id'], (int) $run['id'], $step_id, $authorized_by, $heartbeat );
		if ( ForeignRuns::is_foreign( $run ) ) {
			// R-17.4: only network-safe actions, only while the add-on agrees (consent, license, site), and only when the
			// action itself agrees on this site (for example: the addressed user is a member here).
			$reason = $action instanceof NetworkSafe ? $this->foreign->gate( $run, $node ) : 'action_not_network_safe';
			if ( null === $reason && $action instanceof NetworkSafe ) {
				$reason = $action->network_check( $context );
			}
			if ( null !== $reason ) {
				$this->stop( $step, $owner, $run, EngineStore::STEP_PAUSED, EngineStore::RUN_PAUSED, self::code( $reason ), $attempts );
				return;
			}
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
			$execute = \WorkflowCaptain\TestMode::DRY === \WorkflowCaptain\TestMode::mode()
				? static fn(): array => array( 'skipped' => 'test_mode' ) // SET-WC-14: nothing takes effect, the step is logged.
				: static fn() => $action->execute( $context );
			$started = microtime( true );
			$result  = null === $this->consumer ? $execute() : $this->consumer->run_command( Consumer::cause_for( $envelope, (int) $run['workflow_id'] ), $execute );
			if ( ! empty( $this->limits['detailed'] ) && is_array( $result ) ) {
				$result['ms'] = (int) round( ( microtime( true ) - $started ) * 1000 ); // SET-WC-21: duration of the action.
			}
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
			$key = ForeignRuns::is_foreign( $run ) ? ForeignRuns::step_key( (string) $run['event_key'], $next ) : self::step_key( $version, $run['context'], $next );
			$this->store->ensure_step( (int) $run['id'], $next, $key, $due, $this->clock->mysql() );
		}
		$this->finish( $step, $owner, EngineStore::STEP_SUCCEEDED, array( 'attempts' => $attempts, 'result' => $result, 'last_error' => null ) );
		if ( null === $next ) {
			$this->run_state( $run, EngineStore::RUN_SUCCEEDED, null );
		}
	}

	private function stop( array $step, string $owner, array $run, string $step_state, string $run_state, string $reason, int $attempts ): void {
		$this->finish( $step, $owner, $step_state, array( 'attempts' => $attempts, 'last_error' => $reason ) );
		$this->run_state( $run, $run_state, $reason );
	}

	/** Sets the state of a run; a run from another site is also reported to the observer (R-17.6). */
	private function run_state( array $run, string $state, ?string $reason ): void {
		$this->store->set_run_state( (int) $run['id'], $state, $reason, $this->clock->mysql() );
		if ( ForeignRuns::is_foreign( $run ) ) {
			$this->foreign->observe( $run, $state, $reason );
		}
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
	public static function redact( array $result ): array {
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
