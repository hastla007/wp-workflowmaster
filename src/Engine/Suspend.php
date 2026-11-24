<?php
/**
 * Thrown by an action whose step must wait for something outside the run (for example a person who releases it):
 * nothing has happened. The engine sets the step and its run to "waiting", frees the lease, counts no attempt and
 * books no side effect. The step runs again, with the same step ID and idempotency key, once $until has passed or
 * once Waiting::resume() woke it. Without $until only resume() wakes it.
 *
 * The reason is a short fixed code (a-z, 0-9, "_", ":", ".", "-", at most 64 characters), never a value from the event.
 * Not allowed in runs from another site of the network: such a step pauses with "suspend_not_network_safe".
 *
 * @package WorkflowCaptain
 * @since   0.2.0 (contract 0.2.0)
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class Suspend extends \RuntimeException {

	/**
	 * @param string   $reason Fixed code, see above.
	 * @param int|null $until  UTC timestamp at which the step is due again; null: only Waiting::resume() wakes it.
	 * @throws \InvalidArgumentException When the reason is no valid code.
	 */
	public function __construct( public readonly string $reason, public readonly ?int $until = null ) {
		if ( ! self::valid( $reason ) ) {
			throw new \InvalidArgumentException( 'suspend_reason_invalid' );
		}
		parent::__construct( $reason );
	}

	/** Whether $reason is a fixed code the engine may log (also used by Halt). */
	public static function valid( string $reason ): bool {
		return 1 === preg_match( '/^[a-z0-9_:.\-]{1,64}$/D', $reason );
	}
}
