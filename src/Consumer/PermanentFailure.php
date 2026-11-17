<?php
/**
 * A failure repeating cannot fix (schema, unknown contract): the event goes to dead_letter at once (7.4). With
 * $pause the event goes to paused instead (missing permission, section 10).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Consumer;

defined( 'ABSPATH' ) || exit;

class PermanentFailure extends \RuntimeException {

	public function __construct( public readonly string $reason, public readonly bool $pause = false ) {
		parent::__construct( $reason );
	}
}
