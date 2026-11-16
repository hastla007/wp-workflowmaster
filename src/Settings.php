<?php
/**
 * Settings of the site (konzept 8 "Einstellungen", 15.3). Stored in one option; every value is clamped to its range,
 * and retention periods can only be shortened, never extended beyond the proposal of R-12.4.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'workflow_captain_settings';

	/** key => array( default, min, max ) */
	const FIELDS = array(
		'batch'              => array( 25, 1, 200 ),
		'catch_up_hours'     => array( 24, 1, 168 ),
		'max_site'           => array( 5, 1, 20 ),
		'max_workflow'       => array( 2, 1, 10 ),
		'retention_finished' => array( 30, 1, 30 ),
		'retention_stuck'    => array( 90, 1, 90 ),
		'notify_daily'       => array( 1, 0, 1 ),
	);

	/** @return array<string,int> */
	public static function defaults(): array {
		return array_map( static fn( $f ) => $f[0], self::FIELDS );
	}

	/** Clamps input to the allowed ranges; unknown keys are dropped. */
	public static function sanitize( $input ): array {
		$clean = self::defaults();
		foreach ( self::FIELDS as $key => list( $default, $min, $max ) ) {
			if ( is_array( $input ) && isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$clean[ $key ] = max( $min, min( $max, (int) $input[ $key ] ) );
			}
		}
		return $clean;
	}

	/** @return array<string,int> */
	public static function get(): array {
		return self::sanitize( function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array() );
	}

	/** Limits for the engine (WF-CORE-05, catch-up of konzept 15.3). */
	public static function engine_limits( array $settings ): array {
		return array(
			'site'     => $settings['max_site'],
			'workflow' => $settings['max_workflow'],
			'catch_up' => $settings['catch_up_hours'] * HOUR_IN_SECONDS,
		);
	}

	/** Filter callback for workflow_captain_retention_days: the setting can only shorten the period. */
	public static function retention( $days, $group ): int {
		$settings = self::get();
		$own      = 'finished' === $group ? $settings['retention_finished'] : $settings['retention_stuck'];
		return min( (int) $days, $own );
	}
}
