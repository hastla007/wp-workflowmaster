<?php
/**
 * What the workflow engine did with an event.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Consumer;

defined( 'ABSPATH' ) || exit;

final class DispatchResult {

	/**
	 * @param int[] $started_workflow_ids Workflows a run was started for.
	 * @param bool  $waiting              Whether a run still needs actor and data of the stored event; if not, the
	 *                                    consumer clears them right away (R-12.4).
	 */
	public function __construct( public readonly array $started_workflow_ids = array(), public readonly bool $waiting = false ) {}
}
