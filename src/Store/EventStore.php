<?php
/**
 * Storage of the consumer (docs/SPEC-suite-events.md, 7.3 and 7.4): one table per site, unique per site and
 * idempotency key.
 *
 * A row is an array with the keys id, state, attempts, envelope (decoded), last_error, product, type, subject_type,
 * subject_id, actor_user_id, next_attempt_at, created_at, updated_at.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Store;

use WorkflowCaptain\Consumer\Causation;

defined( 'ABSPATH' ) || exit;

interface EventStore {

	const RECEIVED     = 'received';
	const PROCESSING   = 'processing';
	const DONE         = 'done';
	const RETRY_WAIT   = 'retry_wait';
	const DEAD_LETTER  = 'dead_letter';
	const DISCARDED    = 'discarded';
	const LOOP_BLOCKED = 'loop_blocked';
	const PAUSED       = 'paused';

	const STATES = array( self::RECEIVED, self::PROCESSING, self::DONE, self::RETRY_WAIT, self::DEAD_LETTER, self::DISCARDED, self::LOOP_BLOCKED, self::PAUSED );

	const STORED    = 'stored';
	const DUPLICATE = 'duplicate';
	const FAILED    = 'failed';

	/**
	 * Stores a validated envelope. Exactly one write (R-7.6).
	 *
	 * @param string $state Initial state (received; loop_blocked if the depth is over the limit).
	 * @return string self::STORED, self::DUPLICATE (same site and idempotency key exists, R-7.10) or self::FAILED.
	 */
	public function insert( array $envelope, string $state, string $now ): string;

	/**
	 * Claims up to $limit due events atomically for one worker (C-6): received or retry_wait with
	 * next_attempt_at <= now, or processing with an expired lease. Claimed rows are processing with the lease.
	 *
	 * @return array<int,array<string,mixed>> Claimed rows, oldest first.
	 */
	public function claim_due( int $limit, string $now, string $owner, string $lease_until ): array;

	/**
	 * Ends the processing of a claimed row. Only the worker holding the lease may do this.
	 *
	 * @param array{state:string,attempts?:int,next_attempt_at?:?string,last_error?:?string,minimize?:bool} $change
	 */
	public function finish( int $id, string $owner, array $change, string $now ): bool;

	/** Sets every open event (received, retry_wait) of a product to paused (R-10.7, C-15). Returns the count. */
	public function pause_product( string $product, string $now ): int;

	/** Puts a dead_letter or paused event back into the queue; only a human does this (7.4). */
	public function requeue( int $id, string $now ): bool;

	/** @return array<string,mixed>|null */
	public function get( int $id ): ?array;

	/**
	 * Rows for the admin, newest first.
	 *
	 * @param array{state?:string,product?:string,correlation_id?:string} $filter
	 * @return array<int,array<string,mixed>>
	 */
	public function find( array $filter, int $limit, int $offset ): array;

	/**
	 * Number of rows find() would return without limit (pagination, konzept 10).
	 *
	 * @param array{state?:string,product?:string,correlation_id?:string} $filter
	 */
	public function count( array $filter ): int;

	/** @return array<string,int> Count per state. */
	public function count_by_state(): array;

	/** @return array<string,array<string,int>> Count per product and state. */
	public function count_by_product(): array;

	/** @return array<int,array<string,mixed>> Every row whose actor is this user (R-12.5). */
	public function by_actor( int $user_id, int $limit, int $offset ): array;

	/** Removes the actor from every row of this user, in column and envelope (R-12.5). Returns the count. */
	public function anonymize_actor( int $user_id, string $now ): int;

	/**
	 * Clears actor and data of every row of a product whose subject is $subject_type/$subject_id or whose data names
	 * the object in $data_field (affiliate.affiliate.erased, R-12.5). Returns the count.
	 */
	public function anonymize_subject( string $product, string $subject_type, string $subject_id, string $data_field, string $now ): int;

	/** Deletes rows in the given states last updated before $before (R-12.4). Returns the count. */
	public function delete_older( array $states, string $before ): int;

	/** Remembers that a command created or changed this object (R-7.12, way 2). */
	public function remember_cause( string $subject_type, string $subject_id, Causation $cause, string $expires ): void;

	/** The causation remembered for this object, if not expired. */
	public function find_cause( string $subject_type, string $subject_id, string $now ): ?Causation;

	/** Deletes expired causation entries. */
	public function purge_causes( string $now ): int;
}
