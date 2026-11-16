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

	/** @var callable():string[]|null Event types offered by registered adapters; null = not checked (tests). */
	private $available_types;

	/** @var callable():array<string,?string>|null Contract version per registered provider (WF-CORE-02). */
	private $contracts;

	public function __construct( private EngineStore $store, private ActionRegistry $actions, private Directory $directory, private Clock $clock, ?callable $available_types = null, ?callable $contracts = null ) {
		$this->available_types = $available_types;
		$this->contracts       = $contracts;
	}

	/**
	 * Providers a graph depends on (trigger prefix, action prefixes) with their current contract version. Stored in
	 * the published version as "providers"; the engine pauses a run when a provider's MAJOR changes (WF-CORE-02).
	 *
	 * @return array<string,?string>
	 */
	public function provider_snapshot( array $graph ): array {
		if ( null === $this->contracts ) {
			return array();
		}
		$current  = (array) ( $this->contracts )();
		$prefixes = array();
		$trigger  = Graph::trigger( $graph );
		if ( null !== $trigger ) {
			$prefixes[] = (string) strstr( (string) ( $trigger['event'] ?? '' ) . '.', '.', true );
		}
		foreach ( Graph::action_keys( $graph ) as $key ) {
			$prefixes[] = (string) strstr( $key . '.', '.', true );
		}
		$out = array();
		foreach ( array_unique( $prefixes ) as $prefix ) {
			$product = \WorkflowCaptain\Envelope\Validator::product_for_prefix( $prefix, array_keys( $current ) );
			if ( null !== $product && array_key_exists( $product, $current ) ) {
				$out[ $product ] = $current[ $product ];
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Provider availability (WF-BUILDER-11): the trigger must be offered by a registered adapter. A workflow whose
	 * provider is missing, inactive or unlicensed cannot be published.
	 *
	 * @return string[]
	 */
	public function availability( array $graph ): array {
		if ( null === $this->available_types ) {
			return array();
		}
		$trigger = Graph::trigger( $graph );
		if ( null === $trigger || in_array( (string) ( $trigger['event'] ?? '' ), (array) ( $this->available_types )(), true ) ) {
			return array();
		}
		return array( 'node:' . $trigger['id'] . ':provider_unavailable' );
	}

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
		$v     = array_merge( Graph::violations( $graph, $this->actions ), $this->availability( $graph ) );
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
		$graph['providers'] = $this->provider_snapshot( $graph );
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

	/** Copies the draft into a new draft workflow (konzept 8.2). Returns the new ID. */
	public function duplicate( int $id ): int {
		$wf = $this->store->get_workflow( $id );
		if ( null === $wf ) {
			return 0;
		}
		/* translators: %s: name of the original workflow */
		list( $new ) = $this->save( null, sprintf( __( '%s (Kopie)', 'workflow-captain' ), $wf['name'] ), $wf['draft'] );
		return $new;
	}

	/**
	 * Deleting archives (konzept 15.3): the workflow starts nothing any more, versions, runs and logs stay. Runs already
	 * open continue; a human cancels them if wanted.
	 */
	public function archive( int $id ): bool {
		return null !== $this->store->get_workflow( $id ) && $this->store->set_workflow_status( $id, EngineStore::WF_ARCHIVED, $this->clock->mysql() );
	}

	/** Brings an archived workflow back as paused (if it was published) or as draft. */
	public function restore( int $id ): bool {
		$wf = $this->store->get_workflow( $id );
		if ( null === $wf || EngineStore::WF_ARCHIVED !== $wf['status'] ) {
			return false;
		}
		return $this->store->set_workflow_status( $id, null === $wf['published_version_id'] ? EngineStore::WF_DRAFT : EngineStore::WF_PAUSED, $this->clock->mysql() );
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
