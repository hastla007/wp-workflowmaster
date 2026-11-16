<?php
/**
 * Contract of an action that may run in a run from another site of the same network (ForeignRuns, spec section 17).
 *
 * Only actions that implement it run there; every other action pauses such a step ("action_not_network_safe"). An
 * action implements it only when it writes in the consumer or the site it runs on, needs no user context of the
 * source site and re-checks what it needs on the target site (R-17.4).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

interface NetworkSafe {

	/**
	 * Checked right before the step runs on the target site, after every other check.
	 *
	 * @return string|null Null when the step may run; otherwise a fixed reason code, and the step pauses.
	 */
	public function network_check( ActionContext $context ): ?string;
}
