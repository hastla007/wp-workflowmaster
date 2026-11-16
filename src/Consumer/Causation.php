<?php
/**
 * Context of a command a workflow is running (R-7.12): every event captured while it is active, or later on an object
 * the command created, inherits it.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Consumer;

defined( 'ABSPATH' ) || exit;

final class Causation {

	/**
	 * @param string $command_id     UUID v4 of the command; becomes causation_id of the caused event.
	 * @param int    $workflow_id    Workflow whose step runs the command; becomes caused_by_workflow_id.
	 * @param string $correlation_id correlation_id of the event that started the run.
	 * @param int    $depth          chain_depth of the event that started the run; the caused event gets depth + 1.
	 */
	public function __construct(
		public readonly string $command_id,
		public readonly int $workflow_id,
		public readonly string $correlation_id,
		public readonly int $depth
	) {}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return array(
			'command_id'     => $this->command_id,
			'workflow_id'    => $this->workflow_id,
			'correlation_id' => $this->correlation_id,
			'depth'          => $this->depth,
		);
	}

	public static function from_array( array $row ): self {
		return new self( (string) $row['command_id'], (int) $row['workflow_id'], (string) $row['correlation_id'], (int) $row['depth'] );
	}
}
