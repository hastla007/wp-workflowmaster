<?php
/**
 * Saving, publishing and controlling workflows and runs.
 *
 * Publishing creates an immutable version (WF-BUILDER-02); running runs keep theirs. The publishing user must hold
 * activate_workflows and every capability the graph's actions name; the user ID is stored with the version as
 * authorized_by_user_id (R-8.7) and checked again before every provider-writing step (R-8.8).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

use WorkflowCaptain\Clock;

defined( 'ABSPATH' ) || exit;

final class Workflows {

	const PUBLISH_CAP = 'activate_workflows';

	public function __construct( private EngineStore $store, private ActionRegistry $actions, private Directory $directory, private Clock $clock ) {}

	/**
	 * Saves a draft; a draft may be incomplete. Returns the workflow ID and the violations that would block publishing.
	 *
	 * @return array{0:int,1:string[]}
	 */
	public function save( ?int $id, string $name, array $graph ): array {
		$name = trim( $name );
		$name = '' === $name ? __( 'Ohne Namen', 'workflow-captain' ) : mb_substr( $name, 0, 191 );
		if ( null === $id ) {
			$id = $this->store->create_workflow( $name, $graph, $this->clock->mysql() );
		} else {
			$this->store->update_draft( $id, $name, $graph, $this->clock->mysql() );
		}
		return array( $id, Graph::violations( $graph, $this->actions ) );
	}

	/**
	 * Publishes the draft as a new version.
	 *
	 * @return string[] Violations; empty on success.
	 */
	public function publish( int $id, int $user_id ): array {
		$workflow = $this->store->get_workflow( $id );
		if ( null === $workflow ) {
			return array( 'workflow:not_found' );
		}
		$graph = $workflow['draft'];
		$v     = Graph::violations( $graph, $this->actions );
		if ( array() !== $v ) {
			return $v;
		}
		$needed = array( self::PUBLISH_CAP );
		foreach ( Graph::action_keys( $graph ) as $key ) {
			$cap = $this->actions->get( $key )?->capability();
			if ( null !== $cap ) {
				$needed[] = $cap;
			}
		}
		foreach ( array_unique( $needed ) as $cap ) {
			if ( ! $this->directory->user_can( $user_id, $cap ) ) {
				$v[] = 'permission:' . $cap;
			}
		}
		if ( array() !== $v ) {
			return $v;
		}
		$trigger = Graph::trigger( $graph );
		$this->store->add_version( $id, $graph, (string) $trigger['event'], $user_id, $this->clock->mysql() );
		return array();
	}

	public function pause( int $id ): bool {
		return $this->store->set_workflow_status( $id, EngineStore::WF_PAUSED, $this->clock->mysql() );
	}

	/** Activates the last published version again. */
	public function resume( int $id ): bool {
		$workflow = $this->store->get_workflow( $id );
		return null !== $workflow && null !== $workflow['published_version_id'] && $this->store->set_workflow_status( $id, EngineStore::WF_ACTIVE, $this->clock->mysql() );
	}

	/** Cancels a run: waiting steps do not run; finished actions are not undone (konzept 15.3). */
	public function cancel_run( int $run_id ): bool {
		$this->store->cancel_steps( $run_id, $this->clock->mysql() );
		return $this->store->set_run_state( $run_id, EngineStore::RUN_CANCELLED, 'cancelled', $this->clock->mysql() );
	}

	/**
	 * A human continues a step that needs a decision (overdue, unclear outcome, missing right). With $confirmed the
	 * human accepts that a side effect may repeat; without it a step with a started effect stays blocked.
	 */
	public function continue_step( int $step_id, bool $confirmed ): bool {
		$step = $this->store->get_step( $step_id );
		if ( null === $step || ( EngineStore::EFFECT_STARTED === $step['effect'] && ! $confirmed ) ) {
			return false;
		}
		if ( ! $this->store->requeue_step( $step_id, $confirmed, $this->clock->mysql() ) ) {
			return false;
		}
		return $this->store->set_run_state( (int) $step['run_id'], EngineStore::RUN_RUNNING, null, $this->clock->mysql() );
	}
}
