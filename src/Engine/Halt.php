<?php
/**
 * Thrown by an action that ends its run regularly at this step: the step succeeds with $result plus
 * {"halted": reason} (redacted like every result), no further step is created and the run ends as "stopped" with the
 * reason, like a condition in mode "stop". No retry, no decision for a human.
 *
 * The reason is a short fixed code (a-z, 0-9, "_", ":", ".", "-", at most 64 characters), never a value from the event.
 *
 * @package WorkflowCaptain
 * @since   0.2.0 (contract 0.2.0)
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class Halt extends \RuntimeException {

	/**
	 * @param string $reason Fixed code, see above.
	 * @param array  $result Result for the run log (no personal data; redacted by the engine).
	 * @throws \InvalidArgumentException When the reason is no valid code.
	 */
	public function __construct( public readonly string $reason, public readonly array $result = array() ) {
		if ( ! Suspend::valid( $reason ) ) {
			throw new \InvalidArgumentException( 'halt_reason_invalid' );
		}
		parent::__construct( $reason );
	}
}
