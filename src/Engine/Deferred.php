<?php
/**
 * Thrown by an action that must not take effect yet (SET-WC-07/-08): outside the sending window or over the daily
 * limit of a recipient. Nothing has happened; the engine puts the step back in the queue until $until, without
 * counting an attempt and without marking a side effect.
 *
 * The reason is a short fixed code (quiet_hours, daily_limit), never a value from the event.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class Deferred extends \RuntimeException {

	public function __construct( public readonly int $until, public readonly string $reason ) {
		parent::__construct( $reason );
	}
}
