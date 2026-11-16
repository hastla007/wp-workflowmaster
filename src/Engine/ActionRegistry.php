<?php
/**
 * The actions a workflow can use. The core registers the commands of 8.1 that it runs itself; add-ons can add
 * actions with the filter "workflow_captain_actions_v1", never one of the forbidden list 8.2.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class ActionRegistry {

	/** @var array<string,Action> */
	private array $actions = array();

	public function add( Action $action ): bool {
		$key = $action->key();
		if ( ! preg_match( '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $key ) || isset( $this->actions[ $key ] ) ) {
			return false;
		}
		$this->actions[ $key ] = $action;
		return true;
	}

	public function get( string $key ): ?Action {
		return $this->actions[ $key ] ?? null;
	}

	/** @return array<string,Action> */
	public function all(): array {
		return $this->actions;
	}
}
