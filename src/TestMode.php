<?php
/**
 * Test mode of the site (SET-WC-14): off, every message to a catch address, or nothing executed (steps are only
 * logged as "skipped: test_mode"). The constant WORKFLOW_CAPTAIN_TEST_MODE in wp-config.php ("off", "redirect",
 * "dry") wins over the setting, so a copy of a live site can never send by accident; the filter
 * `workflow_captain_test_mode` lets an add-on read or set it.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class TestMode {

	const OFF      = 0;
	const REDIRECT = 1;
	const DRY      = 2;

	const NAMES = array( 'off' => self::OFF, 'redirect' => self::REDIRECT, 'dry' => self::DRY );

	public static function mode(): int {
		if ( defined( 'WORKFLOW_CAPTAIN_TEST_MODE' ) ) {
			$c    = constant( 'WORKFLOW_CAPTAIN_TEST_MODE' );
			$mode = is_int( $c ) ? $c : ( self::NAMES[ strtolower( (string) $c ) ] ?? self::DRY );
		} else {
			$mode = (int) Settings::get()['test_mode'];
		}
		if ( function_exists( 'apply_filters' ) ) {
			$mode = (int) apply_filters( 'workflow_captain_test_mode', $mode );
		}
		return in_array( $mode, array( self::OFF, self::REDIRECT, self::DRY ), true ) ? $mode : self::DRY;
	}

	/** Whether the constant fixes the mode (the settings page then shows it read-only). */
	public static function forced(): bool {
		return defined( 'WORKFLOW_CAPTAIN_TEST_MODE' );
	}

	/** Catch address: the first recipient of the daily notice, else the address of the site. */
	public static function catch_address(): string {
		$list = array_filter( array_map( 'trim', explode( ',', (string) Notifications::config()['recipients'] ) ) );
		if ( $list ) {
			return (string) reset( $list );
		}
		return function_exists( 'get_option' ) ? (string) get_option( 'admin_email' ) : '';
	}
}
