<?php
/**
 * Retry plan of docs/SPEC-suite-events.md, 7.4: after a temporary failure at most 8 more attempts, with the delays
 * 1 min, 5 min, 15 min, 1 h, 3 h, 6 h, 12 h, 24 h; when the eighth retry fails, the event goes to dead_letter (C-5).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Consumer;

defined( 'ABSPATH' ) || exit;

final class RetryPlan {

	const DELAYS = array( 60, 300, 900, 3600, 10800, 21600, 43200, 86400 );

	/**
	 * @param int $failed_attempts Number of processing attempts that failed so far (1 after the first failure).
	 * @return int|null Seconds until the next attempt, or null when the event goes to dead_letter.
	 */
	public static function delay( int $failed_attempts ): ?int {
		return self::DELAYS[ $failed_attempts - 1 ] ?? null;
	}
}
