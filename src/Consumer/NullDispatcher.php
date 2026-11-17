<?php
/**
 * Dispatcher while no workflow engine is installed: starts nothing, needs no data.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Consumer;

defined( 'ABSPATH' ) || exit;

final class NullDispatcher implements Dispatcher {

	public function dispatch( array $envelope, ?int $exclude_workflow_id ): DispatchResult {
		return new DispatchResult();
	}
}
