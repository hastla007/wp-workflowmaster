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
			if ( is_array( $input ) && isset( $input[ $key ] ) ) {
				// Numbers are clamped to their range; the sending days also come as a list of weekdays (SET-WC-07).
				$clean[ $key ] = SettingsRegistry::clean( $key, $input[ $key ] );
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
			if ( is_array( $input[ $key ] ) && SettingsRegistry::TYPE_DAYS === SettingsRegistry::field( $key )['type'] ) {
				if ( 0 === SettingsRegistry::days_mask( $input[ $key ] ) ) {
					$problems[ $key ] = array( '', $clean[ $key ], $min, $max );
				}
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
		// Switching on "delete data on uninstall" needs the typed word, as in every Captain plugin (S10).
		if ( 1 === $clean['delete_data_on_uninstall'] && 1 !== self::stored()['delete_data_on_uninstall'] && ! self::deletion_confirmed( $input ) ) {
			$clean['delete_data_on_uninstall'] = 0;
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, 'wfc-delete_data_on_uninstall', __( '“Delete all data when the plugin is deleted” was not switched on: Type DELETE to confirm.', 'workflow-captain' ), 'error' );
			}
		}
		if ( function_exists( 'add_settings_error' ) ) {
			foreach ( self::problems( $input ) as $key => list( $raw, $stored, $min, $max ) ) {
				if ( SettingsRegistry::TYPE_DAYS === SettingsRegistry::field( $key )['type'] ) {
					$clean[ $key ] = self::stored()[ $key ];
					add_settings_error( self::OPTION, 'wfc-' . $key, __( 'Choose at least one sending day. The days were not changed.', 'workflow-captain' ), 'error' );
					continue;
				}
				/* translators: 1: entered value, 2: lowest value, 3: highest value, 4: stored value */
				add_settings_error( self::OPTION, 'wfc-' . $key, sprintf( __( 'The value “%1$s” is not between %2$d and %3$d. Saved: %4$d.', 'workflow-captain' ), $raw, $min, $max, $stored ), 'error' );
			}
		}
		return $clean;
	}

	/** Words that confirm "delete data on uninstall" (the same in every Captain plugin), case-insensitive. */
	const CONFIRM_WORDS = array( 'LÖSCHEN', 'LOESCHEN', 'DELETE' );

	/** Whether the settings form carried the typed confirmation for "delete data on uninstall". */
	public static function deletion_confirmed( $input ): bool {
		$typed = is_array( $input ) && is_scalar( $input['delete_data_on_uninstall_confirm'] ?? null ) ? trim( (string) $input['delete_data_on_uninstall_confirm'] ) : '';
		return in_array( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $typed, 'UTF-8' ) : strtoupper( $typed ), self::CONFIRM_WORDS, true );
	}

	/** @return array<string,int> What the operator stored, without filters: the values the settings form shows and saves. */
	public static function stored(): array {
		return self::sanitize( function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array() );
	}

	/** @return array<string,int> The values in effect: stored values with the filter of each field applied. */
	public static function get(): array {
		$values = self::stored();
		foreach ( $values as $key => $value ) {
			$values[ $key ] = SettingsRegistry::filtered( $key, $value );
		}
		return $values;
	}

	/**
	 * Time zone of Workflow Captain (SET-WC-22): the own setting, else the one of WordPress (an IANA name or an offset
	 * such as "+02:00"), else UTC.
	 */
	public static function timezone(): string {
		$own = Messages::config()['timezone'] ?? '';
		if ( '' !== $own ) {
			return $own;
		}
		$wp = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';
		return '' === $wp ? 'UTC' : $wp;
	}

	/** Limits for the engine (WF-CORE-05, catch-up of konzept 15.3). */
	public static function engine_limits( array $settings ): array {
		return array(
			'site'     => $settings['max_site'],
			'workflow' => $settings['max_workflow'],
			'catch_up' => $settings['catch_up_hours'] * HOUR_IN_SECONDS,
			'seconds'  => $settings['max_seconds'],
			'detailed' => LogLevel::detailed( time() ), // SET-WC-21.
		);
	}

	/** Filter callback for workflow_captain_retention_days: the setting of the group (1 to 180 days, E-S7 = F-04). */
	public static function retention( $days, $group ): int {
		$settings = self::get();
		return array( 'finished' => $settings['retention_finished'], 'stuck' => $settings['retention_stuck'], 'runs' => $settings['retention_runs'] )[ $group ] ?? (int) $days;
	}
}
