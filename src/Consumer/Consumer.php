<?php
/**
 * The consumer of docs/SPEC-suite-events.md: takes events from adapters, stores them once per idempotency key,
 * processes them with retries and hands them to the workflow engine.
 *
 * Capture (adapter callback) and processing (worker) are separate: a callback writes exactly once and never runs a
 * workflow (R-7.6); the worker runs from cron.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Consumer;

use WorkflowCaptain\Adapter\Adapter;
use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\Clock;
use WorkflowCaptain\Envelope\Builder;
use WorkflowCaptain\Envelope\Uuid;
use WorkflowCaptain\Envelope\Validator;
use WorkflowCaptain\Store\EventStore;

defined( 'ABSPATH' ) || exit;

final class Consumer {

	/** Events deeper than this start no workflow (R-7.13; the number is a proposal, F-15). */
	const MAX_DEPTH = 3;

	/** Seconds a worker holds a claimed event (C-6). */
	const LEASE_SECONDS = 300;

	/** How long the consumer remembers which command created an object (R-7.12, way 2). */
	const CAUSE_TTL = 7 * DAY_IN_SECONDS;

	const CAPTURED = 'captured';
	const REJECTED = 'rejected';

	/** @var Causation[] Commands running in this process, innermost last (R-7.12, way 1). */
	private array $context = array();

	/** @var callable(string[]):void */
	private $on_reject;

	public function __construct(
		private EventStore $store,
		private AdapterRegistry $adapters,
		private Dispatcher $dispatcher,
		private Clock $clock,
		private int $blog_id,
		?callable $on_reject = null
	) {
		$this->on_reject = $on_reject ?? static function ( array $codes ): void {};
	}

	/**
	 * Called by an adapter in its callback on a provider action.
	 *
	 * Never throws: an error here must not reach the provider (R-7.6, A-5). Writes once into the store.
	 *
	 * @param array{type:string,subject:array,data?:array,actor_user_id?:int|null,discriminator:string} $event
	 * @return string self::CAPTURED, EventStore::DUPLICATE, self::REJECTED or EventStore::FAILED.
	 */
	public function capture( Adapter $adapter, array $event ): string {
		try {
			$subject = (array) ( $event['subject'] ?? array() );
			$cause   = $this->current_cause();
			if ( null === $cause && isset( $subject['type'], $subject['id'] ) ) {
				$cause = $this->store->find_cause( (string) $subject['type'], (string) $subject['id'], $this->clock->mysql() );
			}
			$envelope = Builder::build(
				$event,
				array(
					'product'          => $adapter->product(),
					'core_version'     => $adapter->core_version(),
					'contract_version' => $adapter->contract_version(),
				),
				$this->blog_id,
				$this->clock,
				$cause
			);
			$violations = Validator::violations( $envelope );
			if ( array() !== $violations ) {
				( $this->on_reject )( $violations );
				return self::REJECTED;
			}
			$state  = $envelope['chain_depth'] > self::MAX_DEPTH ? EventStore::LOOP_BLOCKED : EventStore::RECEIVED;
			$result = $this->store->insert( $envelope, $state, $this->clock->mysql() );
			return EventStore::STORED === $result ? self::CAPTURED : $result;
		} catch ( \Throwable $e ) {
			( $this->on_reject )( array( 'capture:exception' ) );
			return EventStore::FAILED;
		}
	}

	/**
	 * Runs a command of a workflow step. Every event captured meanwhile inherits the causation (R-7.12, way 1).
	 *
	 * @template T
	 * @param callable():T $command
	 * @return T
	 */
	public function run_command( Causation $cause, callable $command ) {
		$this->context[] = $cause;
		try {
			return $command();
		} finally {
			array_pop( $this->context );
		}
	}

	/** Remembers that a command created or changed this object, for events that follow later (R-7.12, way 2). */
	public function remember_cause( string $subject_type, string $subject_id, Causation $cause ): void {
		$this->store->remember_cause( $subject_type, $subject_id, $cause, $this->clock->mysql( $this->clock->now() + self::CAUSE_TTL ) );
	}

	/** A new causation for a command that a step of $workflow_id runs because of $envelope. */
	public static function cause_for( array $envelope, int $workflow_id ): Causation {
		return new Causation( Uuid::v4(), $workflow_id, (string) $envelope['correlation_id'], (int) $envelope['chain_depth'] );
	}

	/**
	 * Processes due events (worker). Returns the number of events handled.
	 */
	public function process( int $limit = 25 ): int {
		$now   = $this->clock->now();
		$owner = Uuid::v4();
		$rows  = $this->store->claim_due( $limit, $this->clock->mysql( $now ), $owner, $this->clock->mysql( $now + self::LEASE_SECONDS ) );
		foreach ( $rows as $row ) {
			$this->handle( $row, $owner );
		}
		return count( $rows );
	}

	/** Sets the open events of a product to paused when its adapter is gone (R-10.7, C-15). */
	public function pause_product( string $product ): int {
		return $this->store->pause_product( $product, $this->clock->mysql() );
	}

	public function requeue( int $id ): bool {
		return $this->store->requeue( $id, $this->clock->mysql() );
	}

	/**
	 * Requeues several dead_letter or paused events, a human decision after looking at the list (7.4, WF-CORE-10).
	 *
	 * @param int[] $ids
	 * @return int Number requeued; ids in other states are skipped.
	 */
	public function requeue_many( array $ids ): int {
		$count = 0;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id > 0 && $this->store->requeue( $id, $this->clock->mysql() ) ) {
				++$count;
			}
		}
		return $count;
	}

	public function store(): EventStore {
		return $this->store;
	}

	private function handle( array $row, string $owner ): void {
		$envelope = $row['envelope'];
		$attempts = (int) $row['attempts'] + 1;
		$finish   = function ( string $state, array $extra = array() ) use ( $row, $owner, $attempts ): void {
			$this->store->finish( (int) $row['id'], $owner, array( 'state' => $state, 'attempts' => $attempts ) + $extra, $this->clock->mysql() );
		};
		try {
			if ( ! Validator::is_valid( $envelope ) ) {
				$finish( EventStore::DEAD_LETTER, array( 'last_error' => 'schema' ) );
				return;
			}
			if ( (int) $envelope['chain_depth'] > self::MAX_DEPTH ) {
				$finish( EventStore::LOOP_BLOCKED, array( 'last_error' => 'chain_depth' ) );
				return;
			}
			$adapter = $this->adapters->get( (string) $envelope['source']['product'] );
			if ( null === $adapter ) {
				$finish( EventStore::PAUSED, array( 'last_error' => 'adapter_unregistered' ) );
				return;
			}
			if ( ! in_array( $envelope['type'], $adapter->types(), true ) ) {
				// Stored, but starts nothing (R-13.3).
				$finish( EventStore::DONE, array( 'last_error' => 'unknown_type' ) );
				return;
			}
			if ( $adapter->needs_reread( (string) $envelope['type'] ) && ! $adapter->reread( $envelope ) ) {
				$finish( EventStore::DISCARDED, array( 'last_error' => 'reread_mismatch' ) );
				return;
			}
			if ( 'affiliate.affiliate.erased' === $envelope['type'] ) {
				// The affiliate was erased in the core: clear actor and data of every stored event about them (R-12.5).
				$this->store->anonymize_subject( 'affiliate-captain', 'affiliate', (string) $envelope['subject']['id'], 'affiliate_id', $this->clock->mysql() );
			}
			$exclude = isset( $envelope['caused_by_workflow_id'] ) ? (int) $envelope['caused_by_workflow_id'] : null;
			$result  = $this->dispatcher->dispatch( $envelope, $exclude );
			$finish( EventStore::DONE, array( 'last_error' => null, 'minimize' => ! $result->waiting ) );
		} catch ( PermanentFailure $e ) {
			$finish( $e->pause ? EventStore::PAUSED : EventStore::DEAD_LETTER, array( 'last_error' => self::code( $e->reason ) ) );
		} catch ( \Throwable $e ) {
			$reason = $e instanceof TransientFailure ? $e->reason : 'exception:' . ( new \ReflectionClass( $e ) )->getShortName();
			$delay  = RetryPlan::delay( $attempts );
			if ( null === $delay ) {
				$finish( EventStore::DEAD_LETTER, array( 'last_error' => self::code( $reason ) ) );
			} else {
				$finish(
					EventStore::RETRY_WAIT,
					array(
						'last_error'      => self::code( $reason ),
						'next_attempt_at' => $this->clock->mysql( $this->clock->now() + $delay ),
					)
				);
			}
		}
	}

	private function current_cause(): ?Causation {
		return array() === $this->context ? null : $this->context[ count( $this->context ) - 1 ];
	}

	/** last_error holds a fixed code only, never a value (7.4, R-12.3). */
	private static function code( string $reason ): string {
		$code = preg_replace( '/[^a-z0-9_:.\-]/i', '', $reason );
		return substr( '' === $code ? 'error' : $code, 0, 64 );
	}
}
