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

	/**
	 * key => array( default, min, max ) of the values in this option, read from the register (SET-WC-01).
	 *
	 * @return array<string,array{0:int,1:int,2:int}>
	 */
	public static function fields(): array {
		$out = array();
		foreach ( SettingsRegistry::keys( self::OPTION ) as $key ) {
			$f           = SettingsRegistry::field( $key );
			$out[ $key ] = array( $f['default'], $f['min'], $f['max'] );
		}
		return $out;
	}

	/** @return array<string,int> */
	public static function defaults(): array {
		return array_map( static fn( $f ) => $f[0], self::fields() );
	}

	/** Clamps input to the allowed ranges; unknown keys are dropped. */
	public static function sanitize( $input ): array {
		$clean = self::defaults();
		foreach ( self::fields() as $key => list( $default, $min, $max ) ) {
			if ( is_array( $input ) && isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$clean[ $key ] = max( $min, min( $max, (int) $input[ $key ] ) );
			}
		}
		return $clean;
	}

	/**
	 * Values the operator entered that were not taken as they are: key => array( entered, stored, min, max ).
	 * Empty when everything was in range (WF-SET-13).
	 *
	 * @return array<string,array{0:string,1:int,2:int,3:int}>
	 */
	public static function problems( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$clean    = self::sanitize( $input );
		$problems = array();
		foreach ( self::fields() as $key => list( , $min, $max ) ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$raw = is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( ! preg_match( '/^-?\d+$/', $raw ) || (int) $raw !== $clean[ $key ] ) {
				$problems[ $key ] = array( $raw, $clean[ $key ], $min, $max );
			}
		}
		return $problems;
	}

	/** sanitize_callback of the settings form: clamps and reports each changed field at the field (WF-SET-13). */
	public static function sanitize_and_report( $input ): array {
		$clean = self::sanitize( $input );
		if ( function_exists( 'add_settings_error' ) ) {
			foreach ( self::problems( $input ) as $key => list( $raw, $stored, $min, $max ) ) {
				/* translators: 1: entered value, 2: lowest value, 3: highest value, 4: stored value */
				add_settings_error( self::OPTION, 'wfc-' . $key, sprintf( __( 'Der Wert „%1$s“ liegt nicht zwischen %2$d und %3$d. Gespeichert wurde %4$d.', 'workflow-captain' ), $raw, $min, $max, $stored ), 'error' );
			}
		}
		return $clean;
	}

	/** @return array<string,int> */
	public static function get(): array {
		$values = self::sanitize( function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array() );
		foreach ( $values as $key => $value ) {
			$values[ $key ] = SettingsRegistry::filtered( $key, $value );
		}
		return $values;
	}

	/** Limits for the engine (WF-CORE-05, catch-up of konzept 15.3). */
	public static function engine_limits( array $settings ): array {
		return array(
			'site'     => $settings['max_site'],
			'workflow' => $settings['max_workflow'],
			'catch_up' => $settings['catch_up_hours'] * HOUR_IN_SECONDS,
			'seconds'  => $settings['max_seconds'],
		);
	}

	/** Filter callback for workflow_captain_retention_days: the setting can only shorten the period. */
	public static function retention( $days, $group ): int {
		$settings = self::get();
		$own      = array( 'finished' => $settings['retention_finished'], 'stuck' => $settings['retention_stuck'], 'runs' => $settings['retention_runs'] )[ $group ] ?? (int) $days;
		return min( (int) $days, $own );
	}
}
