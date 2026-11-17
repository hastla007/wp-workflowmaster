<?php
/**
 * Source of the current time, so the consumer can be tested with a fixed clock. Always UTC.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

class Clock {

	/** Seconds since the epoch. */
	public function now(): int {
		return time();
	}

	/** RFC 3339 in UTC with the literal Z (envelope field occurred_at, C-3). */
	public function rfc3339( ?int $timestamp = null ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ?? $this->now() );
	}

	/** Database format in UTC. */
	public function mysql( ?int $timestamp = null ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp ?? $this->now() );
	}
}
