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

	/** @var (callable(string):bool)|null Decides per key whether an action may be used right now. */
	private $availability = null;

	/**
	 * Sets the check whether an action is available right now. The core uses it for actions of own connectors (keys
	 * with the prefix "x"): they exist only while their connector is registered and enabled (F-22, WF-CONNECTOR-10).
	 * An unavailable action is not offered, a workflow using it cannot be published, and a step pauses
	 * ("action_unavailable").
	 *
	 * @param callable(string):bool $availability
	 */
	public function set_availability( callable $availability ): void {
		$this->availability = $availability;
	}

	public function add( Action $action ): bool {
		$key = $action->key();
		if ( ! preg_match( '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $key ) || isset( $this->actions[ $key ] ) ) {
			return false;
		}
		$this->actions[ $key ] = $action;
		return true;
	}

	public function get( string $key ): ?Action {
		return isset( $this->actions[ $key ] ) && $this->available( $key ) ? $this->actions[ $key ] : null;
	}

	/** @return array<string,Action> Available actions. */
	public function all(): array {
		return array_filter( $this->actions, fn( $key ) => $this->available( (string) $key ), ARRAY_FILTER_USE_KEY );
	}

	private function available( string $key ): bool {
		return null === $this->availability || (bool) ( $this->availability )( $key );
	}
}
