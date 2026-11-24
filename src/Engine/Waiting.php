<?php
/**
 * Wakes steps that wait after an action threw Suspend. Handed to add-ons once per request on
 * "workflow_captain_waiting_v1" (init, priority 20; also in cron, WP-CLI and during the emergency pause).
 *
 * resume() checks no user rights: the caller checks them and writes its own audit entry.
 *
 * @package WorkflowCaptain
 * @since   0.2.0 (contract 0.2.0)
 */

namespace WorkflowCaptain\Engine;

use WorkflowCaptain\Clock;

defined( 'ABSPATH' ) || exit;

final class Waiting {

	public function __construct( private EngineStore $store, private Clock $clock ) {}

	/**
	 * Puts a waiting step back into the queue, due now, and its run from "waiting" to "running" (atomic: only one of
	 * two concurrent calls succeeds). No attempt is counted, no side effect booked; the worker picks the step up like
	 * any other due step. Only when the node of the step uses the action $action_key.
	 *
	 * @return bool False for an unknown, cancelled or already woken step, or a step of another action.
	 */
	public function resume( int $step_id, string $action_key ): bool {
		$step = $this->store->get_step( $step_id );
		if ( null === $step || EngineStore::STEP_WAITING !== $step['state'] ) {
			return false;
		}
		$run     = $this->store->get_run( (int) $step['run_id'] );
		$version = null === $run || ForeignRuns::is_foreign( $run ) ? null : $this->store->get_version( (int) $run['version_id'] );
		$node    = null === $version ? null : Graph::node( $version['graph'], (string) $step['node_id'] );
		if ( null === $node || 'action' !== ( $node['type'] ?? '' ) || $action_key !== (string) ( $node['action'] ?? '' ) ) {
			return false;
		}
		return $this->store->wake_step( $step_id, $this->clock->mysql() );
	}

	/** State of the step (EngineStore::STEP_*), or null when there is no such step. */
	public function state( int $step_id ): ?string {
		$step = $this->store->get_step( $step_id );
		return null === $step ? null : (string) $step['state'];
	}
}
