<?php
/**
 * Storage of the workflow engine: workflows with draft graph, immutable published versions, runs, steps, tags and
 * notes. One set of tables per site (R-12.6).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

interface EngineStore {

	// Workflow states.
	const WF_DRAFT  = 'draft';
	const WF_ACTIVE = 'active';
	const WF_PAUSED   = 'paused';
	const WF_ARCHIVED = 'archived';
	/** Anchor of runs whose definition lives outside this site (ForeignRuns, network workflows R-17): never edited here. */
	const WF_NETWORK = 'network';

	// Run states.
	const RUN_RUNNING         = 'running';
	const RUN_SUCCEEDED       = 'succeeded';
	const RUN_STOPPED         = 'stopped';
	const RUN_FAILED          = 'failed';
	const RUN_ACTION_REQUIRED = 'action_required';
	const RUN_PAUSED          = 'paused';
	const RUN_CANCELLED       = 'cancelled';

	const RUN_OPEN = array( self::RUN_RUNNING, self::RUN_ACTION_REQUIRED, self::RUN_PAUSED );

	// Step states.
	const STEP_QUEUED          = 'queued';
	const STEP_RUNNING         = 'running';
	const STEP_RETRY_WAIT      = 'retry_wait';
	const STEP_SUCCEEDED       = 'succeeded';
	const STEP_ACTION_REQUIRED = 'action_required';
	const STEP_PAUSED          = 'paused';
	const STEP_CANCELLED       = 'cancelled';

	// Side effect booking of a step (R-8.12, konzept 15.3 "Ausführungsgarantie").
	const EFFECT_NONE    = 'none';
	const EFFECT_STARTED = 'started';
	const EFFECT_DONE    = 'done';

	public function create_workflow( string $name, array $graph, string $now ): int;

	public function update_draft( int $id, string $name, array $graph, string $now ): bool;

	/** @return array<string,mixed>|null id, name, status, draft (graph), published_version_id, created_at, updated_at */
	public function get_workflow( int $id ): ?array;

	/** @return array<int,array<string,mixed>> */
	public function list_workflows( int $limit, int $offset ): array;

	public function set_workflow_status( int $id, string $status, string $now ): bool;

	/** Adds an immutable published version and makes it the workflow's current one. Returns the version ID. */
	public function add_version( int $workflow_id, array $graph, string $trigger_type, int $authorized_by_user_id, string $now ): int;

	/** @return array<string,mixed>|null id, workflow_id, version, graph, trigger_type, authorized_by_user_id */
	public function get_version( int $id ): ?array;

	/** @return array<int,array<string,mixed>> Current versions of active workflows whose trigger is $type. */
	public function active_versions_for_trigger( string $type ): array;

	/**
	 * Creates a run unless one exists for this version and event (C-10).
	 *
	 * @param array{workflow_id:int,version_id:int,event_row_id:int,event_key:string,context:array,actor_user_id:?int,serial_key?:?string,network?:?array} $run
	 * @return array{0:int,1:bool} Run ID and whether it was created now.
	 */
	public function create_run( array $run, string $now ): array;

	/**
	 * The anchor workflow of an outside origin (ForeignRuns): created once per origin with status WF_NETWORK, never
	 * listed, never published, never started by a local event. Its ID is the workflow of the runs of that origin, so
	 * caused events carry a workflow ID of this site that no local workflow has (R-7.14).
	 */
	public function ensure_anchor( string $origin, string $name, string $now ): int;

	/** @return array<string,mixed>|null The run of an outside origin with this key (version_id 0). */
	public function foreign_run( string $event_key ): ?array;

	/** @return array<string,mixed>|null */
	public function get_run( int $id ): ?array;

	/** Whether an older run of this workflow with the same serialisation key is still open (konzept 15.3). */
	public function older_open_run( int $workflow_id, int $run_id, string $serial_key ): bool;

	/**
	 * Due steps that are not held by a worker, with workflow and serialisation key of their run, oldest due first.
	 *
	 * @return array<int,array{id:int,run_id:int,workflow_id:int,serial_key:?string}>
	 */
	public function due_steps( int $limit, string $now ): array;

	/** @return array{total:int,by_workflow:array<int,int>} Steps currently held by a worker with a valid lease. */
	public function running_counts( string $now ): array;

	/** Claims one step if it is still due; returns it or null (WF-CORE-06). */
	public function claim_step( int $id, string $now, string $owner, string $lease_until ): ?array;

	/**
	 * Work that hangs (konzept 15.3, WF-CORE-06): steps whose lease expired before $before (no worker took them over,
	 * for example because cron does not run) and running runs without any open step. Nothing is reset here.
	 *
	 * @return array{expired_leases:int,runs_without_steps:int[]}
	 */
	public function stuck( string $before ): array;

	/**
	 * Consistency after a backup restore (WF-CORE-07): references that do not resolve. Report only.
	 *
	 * @return array<string,int[]> runs_without_version, steps_without_run, versions_without_workflow,
	 *                             active_without_version, running_without_open_step
	 */
	public function consistency(): array;

	/** Extends the lease of a step the worker holds (heartbeat, WF-CORE-06). */
	public function extend_lease( int $id, string $owner, string $lease_until, string $now ): bool;

	public function set_run_state( int $id, string $state, ?string $last_error, string $now ): bool;

	/** @return array<int,array<string,mixed>> */
	public function find_runs( array $filter, int $limit, int $offset ): array;

	/** Creates the step for a node of a run unless it exists (key unique, R-8.4). Returns the step ID. */
	public function ensure_step( int $run_id, string $node_id, string $key, string $due_at, string $now ): int;

	/** @return array<int,array<string,mixed>> Claimed steps (queued/retry_wait due, or running with expired lease). */
	public function claim_steps( int $limit, string $now, string $owner, string $lease_until ): array;

	/**
	 * @param array{state:string,attempts?:int,due_at?:string,last_error?:?string,result?:?array,effect?:string} $change
	 */
	public function finish_step( int $id, string $owner, array $change, string $now ): bool;

	/** Books the side effect state while the worker holds the lease (R-8.12). */
	public function set_effect( int $id, string $owner, string $effect, string $now ): bool;

	/** @return array<int,array<string,mixed>> */
	public function steps_for_run( int $run_id ): array;

	/** @return array<string,mixed>|null */
	public function get_step( int $id ): ?array;

	/** A human puts an action_required or paused step back into the queue; clears the effect booking if $confirmed. */
	public function requeue_step( int $id, bool $confirmed, string $now ): bool;

	/** Cancels the open steps of a run. */
	public function cancel_steps( int $run_id, string $now ): int;

	public function set_tag( string $product, string $subject_type, string $subject_id, string $tag, int $workflow_id, string $now ): void;

	public function remove_tag( string $product, string $subject_type, string $subject_id, string $tag ): void;

	/** @return string[] */
	public function tags_for( string $product, string $subject_type, string $subject_id ): array;

	/** Adds a note once per key (R-8.4). */
	public function add_note( string $key, string $product, string $subject_type, string $subject_id, string $note, int $workflow_id, int $run_id, string $now ): void;

	/** @return array<int,array<string,mixed>> */
	public function notes_for( string $product, string $subject_type, string $subject_id ): array;

	/** @return array<int,array<string,mixed>> Runs whose context actor is this user (R-12.5). */
	public function runs_by_actor( int $user_id, int $limit, int $offset ): array;

	/** Removes the user from the context of every run. Returns the count. */
	public function anonymize_runs( int $user_id, string $now ): int;

	/** Deletes finished runs (and their steps) last updated before $before. */
	public function delete_runs_older( array $states, string $before ): int;
}
