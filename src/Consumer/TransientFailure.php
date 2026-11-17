<?php
/**
 * A temporary failure (database, timeout, lock): processing is repeated after the plan of 7.4.
 *
 * The reason is a short fixed code, never a value from the event: it ends up in last_error and in the admin (7.4).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Consumer;

defined( 'ABSPATH' ) || exit;

class TransientFailure extends \RuntimeException {

	public function __construct( public readonly string $reason ) {
		parent::__construct( $reason );
	}
}
