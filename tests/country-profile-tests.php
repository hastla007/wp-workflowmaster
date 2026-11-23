<?php
/**
 * Country profiles (SET-WC-17, S8, S9): proposal with before/after, never a silent overwrite, only selected values,
 * unchecked flags on values with legal weight, OTHER neutral, values within the register, undo. Required by
 * tests/run.php after the settings transfer tests (in-memory options).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\CountryProfiles;
use WorkflowCaptain\Settings;
use WorkflowCaptain\SettingsRegistry;
use WorkflowCaptain\SettingsTransfer;

echo "\n# SET-WC-17 country profiles\n";

check( 'SET-WC-17 profile keys are DE, AT, CH, EU, OTHER (S8)', array( 'DE', 'AT', 'CH', 'EU', 'OTHER' ) === CountryProfiles::keys() && array( 'DE', 'AT', 'CH', 'EU', 'OTHER' ) === array_keys( CountryProfiles::labels() ) );

$wfc_in_range = true;
foreach ( CountryProfiles::keys() as $wfc_p ) {
	foreach ( CountryProfiles::values( $wfc_p ) as $wfc_k => $wfc_entry ) {
		list( $wfc_ok, $wfc_clean ) = SettingsTransfer::check( $wfc_k, $wfc_entry[0] );
		if ( ! $wfc_ok || $wfc_clean !== $wfc_entry[0] || ! is_bool( $wfc_entry[1] ) ) {
			$wfc_in_range = false;
			echo "  out of range: {$wfc_p}.{$wfc_k}\n";
		}
	}
}
check( 'SET-WC-17 every proposed value is a register field within its type and range', $wfc_in_range );

$wfc_legal    = array( 'quiet_hours_start', 'quiet_hours_end', 'send_days', 'daily_limit', 'retention_finished', 'retention_runs', 'retention_stuck' );
$wfc_flagged  = true;
foreach ( array( 'DE', 'AT', 'CH', 'EU' ) as $wfc_p ) {
	foreach ( $wfc_legal as $wfc_k ) {
		$wfc_v = CountryProfiles::values( $wfc_p );
		if ( ! isset( $wfc_v[ $wfc_k ] ) || false !== $wfc_v[ $wfc_k ][1] ) {
			$wfc_flagged = false;
		}
	}
}
check( 'SET-WC-17 values with legal weight (DE/AT/CH/EU) are flagged unchecked until the legal review (S8)', $wfc_flagged );

$wfc_other         = CountryProfiles::values( 'OTHER' );
$wfc_other_neutral = array() !== $wfc_other;
foreach ( $wfc_other as $wfc_k => $wfc_entry ) {
	if ( SettingsRegistry::field( $wfc_k )['default'] !== $wfc_entry[0] || false !== $wfc_entry[1] ) {
		$wfc_other_neutral = false;
	}
}
check( 'SET-WC-17 OTHER: neutral values (the register defaults, nothing held back) and all "not checked"', $wfc_other_neutral && -1 === $wfc_other['quiet_hours_start'][0] && 0 === $wfc_other['daily_limit'][0] && 127 === $wfc_other['send_days'][0] );

// Shorter retention set by the operator stays (S9); the profile only proposes.
$GLOBALS['wfc_test_options'][ Settings::OPTION ] = array_merge( Settings::stored(), array( 'retention_finished' => 14, 'retention_stuck' => 90, 'quiet_hours_start' => -1, 'quiet_hours_end' => -1, 'daily_limit' => 0, 'send_days' => 127 ) );
unset( $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] );
$wfc_before = SettingsTransfer::current();
$wfc_diff   = CountryProfiles::diff( 'DE', $wfc_before );
check( 'SET-WC-17 diff: current, proposed and checked per changed key; equal values are left out', array( 14, 30, false ) === $wfc_diff['retention_finished'] && array( -1, 20, false ) === $wfc_diff['quiet_hours_start'] && ! isset( $wfc_diff['retention_stuck'] ) );
check( 'SET-WC-17 diff never writes (no silent overwrite)', $wfc_before === SettingsTransfer::current() && null === SettingsTransfer::backup() );

list( $wfc_none, $wfc_none_errors ) = CountryProfiles::apply( 'DE', array() );
check( 'SET-WC-17 nothing selected: nothing written, retention of 14 days stays (S9)', array() === $wfc_none && array() === $wfc_none_errors && $wfc_before === SettingsTransfer::current() && 14 === Settings::stored()['retention_finished'] );

list( $wfc_bad, $wfc_bad_errors ) = CountryProfiles::apply( 'DE', array( 'quiet_hours_start', 'batch' ) );
check( 'SET-WC-17 a key outside the profile refuses the whole selection, nothing written', array() === $wfc_bad && 'not_in_profile' === ( $wfc_bad_errors['batch'] ?? '' ) && $wfc_before === SettingsTransfer::current() );
check( 'SET-WC-17 an unknown profile is refused', 'unknown_profile' === ( CountryProfiles::apply( 'XX', array( 'daily_limit' ) )[1]['_profile'] ?? '' ) );

unset( $GLOBALS['wfc_test_options']['workflow_captain_audit'] );
list( $wfc_changed, $wfc_errors ) = CountryProfiles::apply( 'DE', array( 'quiet_hours_start', 'quiet_hours_end' ) );
$wfc_after = SettingsTransfer::current();
check( 'SET-WC-17 only the selected keys are applied', array() === $wfc_errors && array( 'quiet_hours_start', 'quiet_hours_end' ) === array_keys( $wfc_changed ) && 20 === $wfc_after['quiet_hours_start'] && 8 === $wfc_after['quiet_hours_end'] && 0 === $wfc_after['daily_limit'] && 14 === $wfc_after['retention_finished'] && 127 === $wfc_after['send_days'] );
$wfc_audit = get_option( 'workflow_captain_audit', array() );
check( 'SET-WC-17 audit entry with the source profile, backup for undo', 'settings_profile' === ( $wfc_audit[0]['what'] ?? '' ) && array( -1, 20 ) === ( $wfc_audit[0]['detail']['quiet_hours_start'] ?? null ) && 'profile' === ( SettingsTransfer::backup()['source'] ?? '' ) );

SettingsTransfer::undo();
check( 'SET-WC-17 undo restores the state before the profile', $wfc_before === SettingsTransfer::current() && null === SettingsTransfer::backup() );

check( 'SET-WC-17 selection skips keys that already have the proposed value', array( array(), array() ) === CountryProfiles::selection( 'DE', array( 'retention_stuck' ), $wfc_before ) );
