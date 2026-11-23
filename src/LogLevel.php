<?php
/**
 * Log level (SET-WC-21): "normal" or "detailed". Detailed adds to the step log which rule of a condition held (field,
 * operator, result; never a value) and how long an action took. It is meant for finding a problem, so it falls back to
 * normal by itself after DAYS days; the daily maintenance switches it back and notes that in the change log.
 *
 * The moment it was switched on is kept in OPTION_SINCE, set by the option hook below, so the form, an import and
 * WP-CLI all start the period. A filter on the setting (workflow_captain_setting_log_level) wins and has no period.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class LogLevel {

	const NORMAL       = 0;
	const DETAILED     = 1;
	const DAYS         = 7;
	const OPTION_SINCE = 'workflow_captain_log_detailed_since';

	/** Whether the stored "detailed" is still inside its period. */
	public static function in_period( int $stored, ?int $since, int $now ): bool {
		return self::DETAILED === $stored && null !== $since && $now - $since < self::DAYS * DAY_IN_SECONDS;
	}

	/** Whether steps are logged in detail right now. */
	public static function detailed( int $now ): bool {
		$stored    = (int) ( Settings::stored()['log_level'] ?? 0 );
		$effective = (int) ( Settings::get()['log_level'] ?? 0 );
		if ( $effective !== $stored ) {
			return self::DETAILED === $effective; // A filter decides, without a period.
		}
		return self::in_period( $stored, self::since(), $now );
	}

	/** Unix time it was switched on, or null. */
	public static function since(): ?int {
		$v = function_exists( 'get_option' ) ? get_option( self::OPTION_SINCE, null ) : null;
		return is_numeric( $v ) ? (int) $v : null;
	}

	/**
	 * Hook on update_option/add_option of the settings: switching to detailed starts the period, back to normal ends it.
	 *
	 * @param mixed $old Old value (update) or option name (add).
	 * @param mixed $new New value.
	 */
	public static function changed( $old, $new ): void {
		$was = is_array( $old ) ? (int) ( $old['log_level'] ?? 0 ) : 0;
		$is  = is_array( $new ) ? (int) ( $new['log_level'] ?? 0 ) : 0;
		if ( self::DETAILED === $is && self::DETAILED !== $was ) {
			update_option( self::OPTION_SINCE, time(), false );
		} elseif ( self::DETAILED !== $is ) {
			delete_option( self::OPTION_SINCE );
		}
	}

	/** Hook on add_option (name, value). */
	public static function added( $name, $value ): void {
		self::changed( array(), $value );
	}

	/** Daily maintenance: an expired "detailed" goes back to normal; noted in the change log. */
	public static function revert_if_expired( int $now ): bool {
		$stored = Settings::stored();
		if ( self::DETAILED !== (int) ( $stored['log_level'] ?? 0 ) || self::in_period( self::DETAILED, self::since(), $now ) ) {
			return false;
		}
		$stored['log_level'] = self::NORMAL;
		update_option( Settings::OPTION, $stored, false );
		delete_option( self::OPTION_SINCE );
		Audit::record( 'log_level_reverted', array( 'days' => self::DAYS ) );
		return true;
	}
}
