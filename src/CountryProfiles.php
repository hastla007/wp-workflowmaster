<?php
/**
 * Country profiles (SET-WC-17, decisions S8 and S9): a set of proposed values per country, never applied on its own.
 * A profile only produces a preview (current value, proposed value, checked or not); the operator picks the values to
 * take over, and only those go through SettingsTransfer (strict check per field, backup for undo, audit entry with
 * the source "profile").
 *
 * Keys: DE, AT, CH, EU, OTHER. Every value with legal weight carries `checked` = false until it has been reviewed
 * legally and for tax purposes; DE, AT and CH must be reviewed before the sale starts (S8). OTHER holds neutral values
 * (the defaults, everything off) and is "not checked" as a whole. Retention periods are only proposed: the stored
 * periods (default 30 and 90 days) stay until the operator selects another value (S9).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class CountryProfiles {

	/** Source of the change in the backup and the audit log (audit entry "settings_profile"). */
	const SOURCE = 'profile';

	/**
	 * Profile key => setting key => array( value, checked ). `checked` false: the value has legal weight and has not
	 * been reviewed yet; the preview marks it and never preselects it. Values must lie within the register ranges.
	 */
	const PROFILES = array(
		'DE'    => array(
			'quiet_hours_start'  => array( 20, false ),
			'quiet_hours_end'    => array( 8, false ),
			'send_days'          => array( 63, false ), // Monday to Saturday.
			'daily_limit'        => array( 2, false ),
			'daily_limit_action' => array( 0, true ), // Send on the next day: keeps every message, no legal weight.
			'timezone'           => array( 'Europe/Berlin', true ),
			'retention_finished' => array( 30, false ),
			'retention_runs'     => array( 30, false ),
			'retention_stuck'    => array( 90, false ),
		),
		'AT'    => array(
			'quiet_hours_start'  => array( 20, false ),
			'quiet_hours_end'    => array( 8, false ),
			'send_days'          => array( 63, false ),
			'daily_limit'        => array( 2, false ),
			'daily_limit_action' => array( 0, true ),
			'timezone'           => array( 'Europe/Vienna', true ),
			'retention_finished' => array( 30, false ),
			'retention_runs'     => array( 30, false ),
			'retention_stuck'    => array( 90, false ),
		),
		'CH'    => array(
			'quiet_hours_start'  => array( 20, false ),
			'quiet_hours_end'    => array( 8, false ),
			'send_days'          => array( 63, false ),
			'daily_limit'        => array( 2, false ),
			'daily_limit_action' => array( 0, true ),
			'timezone'           => array( 'Europe/Zurich', true ),
			'retention_finished' => array( 30, false ),
			'retention_runs'     => array( 30, false ),
			'retention_stuck'    => array( 90, false ),
		),
		'EU'    => array(
			'quiet_hours_start'  => array( 21, false ),
			'quiet_hours_end'    => array( 8, false ),
			'send_days'          => array( 127, false ),
			'daily_limit'        => array( 3, false ),
			'daily_limit_action' => array( 0, true ),
			'retention_finished' => array( 30, false ),
			'retention_runs'     => array( 30, false ),
			'retention_stuck'    => array( 90, false ),
		),
		// Neutral: the defaults of the register, nothing held back; not checked for any country.
		'OTHER' => array(
			'quiet_hours_start'  => array( -1, false ),
			'quiet_hours_end'    => array( -1, false ),
			'send_days'          => array( 127, false ),
			'daily_limit'        => array( 0, false ),
			'daily_limit_action' => array( 0, false ),
			'retention_finished' => array( 30, false ),
			'retention_runs'     => array( 30, false ),
			'retention_stuck'    => array( 90, false ),
		),
	);

	/** @return string[] Profile keys in display order. */
	public static function keys(): array {
		return array_keys( self::PROFILES );
	}

	public static function exists( string $profile ): bool {
		return isset( self::PROFILES[ $profile ] );
	}

	/**
	 * Names of the profiles (translated at call time).
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'DE'    => __( 'Germany', 'workflow-captain' ),
			'AT'    => __( 'Austria', 'workflow-captain' ),
			'CH'    => __( 'Switzerland', 'workflow-captain' ),
			'EU'    => __( 'European Union (general)', 'workflow-captain' ),
			'OTHER' => __( 'Other countries (neutral values)', 'workflow-captain' ),
		);
	}

	/**
	 * Proposed values of a profile: key => array( value, checked ).
	 *
	 * @return array<string,array{0:mixed,1:bool}>
	 */
	public static function values( string $profile ): array {
		return self::PROFILES[ $profile ] ?? array();
	}

	/**
	 * Values of the profile that differ from the current ones: key => array( current, proposed, checked ). Never
	 * writes. Keys without a current value compare against the register default.
	 *
	 * @param array<string,mixed> $current Stored values (SettingsTransfer::current()).
	 * @return array<string,array{0:mixed,1:mixed,2:bool}>
	 */
	public static function diff( string $profile, array $current ): array {
		$out = array();
		foreach ( self::values( $profile ) as $key => $entry ) {
			$f = SettingsRegistry::field( $key );
			if ( null === $f ) {
				continue;
			}
			$now = array_key_exists( $key, $current ) ? $current[ $key ] : $f['default'];
			if ( $now !== $entry[0] ) {
				$out[ $key ] = array( $now, $entry[0], (bool) $entry[1] );
			}
		}
		return $out;
	}

	/**
	 * Checks the selection of the operator: only keys of the profile whose value differs, each value strictly checked.
	 * Returns array( values to write, errors key => code ). Never writes.
	 *
	 * @param string[]            $selected
	 * @param array<string,mixed> $current
	 * @return array{0:array<string,mixed>,1:array<string,string>}
	 */
	public static function selection( string $profile, array $selected, array $current ): array {
		if ( ! self::exists( $profile ) ) {
			return array( array(), array( '_profile' => 'unknown_profile' ) );
		}
		$diff   = self::diff( $profile, $current );
		$values = array();
		$errors = array();
		foreach ( array_unique( array_map( 'strval', array_filter( $selected, 'is_scalar' ) ) ) as $key ) {
			if ( ! isset( self::PROFILES[ $profile ][ $key ] ) ) {
				$errors[ $key ] = 'not_in_profile';
				continue;
			}
			if ( ! isset( $diff[ $key ] ) ) {
				continue; // Already the proposed value: nothing to write.
			}
			list( $ok, $clean, $code ) = SettingsTransfer::check( $key, $diff[ $key ][1] );
			if ( $ok ) {
				$values[ $key ] = $clean;
			} else {
				$errors[ $key ] = $code;
			}
		}
		return array( $values, $errors );
	}

	/**
	 * Applies the selected values of a profile (and nothing else). On any error nothing is written. Returns
	 * array( changed key => array( old, new ), errors ).
	 *
	 * @param string[] $selected
	 * @return array{0:array<string,array{0:mixed,1:mixed}>,1:array<string,string>}
	 */
	public static function apply( string $profile, array $selected ): array {
		list( $values, $errors ) = self::selection( $profile, $selected, SettingsTransfer::current() );
		if ( array() !== $errors || array() === $values ) {
			return array( array(), $errors );
		}
		return array( SettingsTransfer::apply( $values, self::SOURCE ), array() );
	}
}
