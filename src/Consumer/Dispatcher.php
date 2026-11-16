<?php
/**
 * Hands a stored event to the workflow engine. The engine is not built yet; until then NullDispatcher starts nothing.
 * An engine replaces it with the filter "workflow_captain_dispatcher".
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Consumer;

defined( 'ABSPATH' ) || exit;

interface Dispatcher {

	/**
	 * Starts every published workflow whose trigger matches the event.
	 *
	 * @param array    $envelope            Valid, stored envelope.
	 * @param int|null $exclude_workflow_id A workflow that must not start (R-7.14: the one that caused the event).
	 * @throws TransientFailure When starting failed temporarily; the consumer repeats the whole event. Runs already
	 *                          started for it must be recognised by their idempotency key (R-8.4, C-10).
	 * @throws PermanentFailure When repeating cannot help.
	 */
	public function dispatch( array $envelope, ?int $exclude_workflow_id ): DispatchResult;
}
