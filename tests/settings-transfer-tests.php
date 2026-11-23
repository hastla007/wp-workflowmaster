<?php
/**
 * Export, import, reset and undo of the settings (SET-WC-11, SET-WC-12, SET-WC-23). Required by tests/run.php after
 * the connector tests (which define get_option/update_option on an in-memory array).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Notifications;
use WorkflowCaptain\Settings;
use WorkflowCaptain\SettingsRegistry;
use WorkflowCaptain\SettingsTransfer;

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['wfc_test_options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 0;
	}
}
if ( ! function_exists( 'has_action' ) ) {
	function has_action( $hook, $callback = false ) {
		return false;
	}
}
if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $callback, $priority = 10 ) {
		return true;
	}
}

$GLOBALS['wfc_test_options'][ Settings::OPTION ]      = array( 'batch' => 40, 'max_site' => 7 );
$GLOBALS['wfc_test_options'][ Notifications::OPTION ] = array( 'recipients' => 'ops@example.org', 'kinds' => array( 'failed', 'pause' ) );
unset( $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ], $GLOBALS['wfc_test_options']['workflow_captain_audit'] );

$wfc_export = SettingsTransfer::export( SettingsTransfer::current(), '0.2.0' );
check( 'SET-WC-11 export: format, plugin, version and the stored values', 1 === $wfc_export['format'] && 'workflow-captain' === $wfc_export['plugin'] && '0.2.0' === $wfc_export['version'] && 40 === $wfc_export['settings']['batch'] && array( 'failed', 'pause' ) === $wfc_export['settings']['kinds'] );
check( 'SET-WC-11 export: no addresses (personal, tied to the installation)', ! isset( $wfc_export['settings']['recipients'] ) && ! str_contains( (string) json_encode( $wfc_export ), 'ops@example.org' ) );

$wfc_parsed = SettingsTransfer::parse( json_encode( $wfc_export ) );
check( 'SET-WC-11 import: own export reads back without errors (round trip)', array() === $wfc_parsed['errors'] && $wfc_export['settings'] === $wfc_parsed['values'] );
check( 'SET-WC-11 import: a newer format is refused', array( '_file' => 'newer_format' ) === SettingsTransfer::parse( array( 'format' => 2, 'plugin' => 'workflow-captain', 'settings' => array() ) )['errors'] );
check( 'SET-WC-11 import: another plugin, no JSON, no format are refused', 'other_plugin' === SettingsTransfer::parse( array( 'format' => 1, 'plugin' => 'voucher-captain', 'settings' => array() ) )['errors']['_file'] && 'not_a_settings_file' === SettingsTransfer::parse( 'kaputt' )['errors']['_file'] && 'no_format' === SettingsTransfer::parse( array( 'plugin' => 'workflow-captain', 'settings' => array() ) )['errors']['_file'] );
$wfc_bad = SettingsTransfer::parse( array( 'format' => 1, 'plugin' => 'workflow-captain', 'settings' => array( 'batch' => 9999, 'max_site' => 'viele', 'kinds' => array( 'failed', 'evil' ), 'recipients' => 'x@example.org', 'evil' => 1 ) ) );
check( 'SET-WC-11 import: values out of range are errors, not silently corrected', 'out_of_range' === $wfc_bad['errors']['batch'] && 'not_a_number' === $wfc_bad['errors']['max_site'] && 'unknown_kind' === $wfc_bad['errors']['kinds'] && array() === $wfc_bad['values'] );
check( 'SET-WC-11 import: addresses and unknown keys are ignored, not imported', array( 'recipients', 'evil' ) === $wfc_bad['ignored'] );

$wfc_diff = SettingsTransfer::diff( SettingsTransfer::current(), array( 'batch' => 40, 'max_site' => 9 ) );
check( 'SET-WC-11 preview: only changed values with old and new value', array( 'max_site' => array( 7, 9 ) ) === $wfc_diff );

$wfc_changed = SettingsTransfer::apply( array( 'max_site' => 9, 'retention_stuck' => 45 ), 'import' );
$wfc_backup  = SettingsTransfer::backup();
check( 'SET-WC-11 apply: writes both options, keeps the other values', 9 === Settings::get()['max_site'] && 40 === Settings::get()['batch'] && 45 === Settings::get()['retention_stuck'] && 'ops@example.org' === Notifications::config()['recipients'] );
check( 'SET-WC-23 apply: keeps a backup of the previous values for undo', is_array( $wfc_backup ) && 7 === $wfc_backup['values']['max_site'] && 'import' === $wfc_backup['source'] );
$wfc_audit = get_option( 'workflow_captain_audit', array() );
check( 'SET-WC-12 apply: audit entry with source and old/new value', 'settings_import' === ( $wfc_audit[0]['what'] ?? '' ) && array( 7, 9 ) === ( $wfc_audit[0]['detail']['max_site'] ?? null ) );

$wfc_reset = SettingsTransfer::apply( SettingsTransfer::reset_values( 'execution' ), 'reset' );
check( 'SET-WC-23 reset of one tab: only that tab returns to its defaults', 25 === Settings::get()['batch'] && 5 === Settings::get()['max_site'] && 45 === Settings::get()['retention_stuck'] && 'ops@example.org' === Notifications::config()['recipients'] );
$wfc_undo = SettingsTransfer::undo();
check( 'SET-WC-23 undo restores the values before the reset, once', is_array( $wfc_undo ) && 40 === Settings::get()['batch'] && 9 === Settings::get()['max_site'] && null === SettingsTransfer::undo() );

SettingsTransfer::apply( array( 'recipients' => 'neu@example.org' ), 'cli' );
$wfc_audit = get_option( 'workflow_captain_audit', array() );
check( 'SET-WC-12 an address change is logged without the address', 'settings_cli' === $wfc_audit[0]['what'] && ! str_contains( (string) json_encode( $wfc_audit[0] ), 'neu@example.org' ) );
check( 'SET-WC-12 check: CLI values are checked like the form (out of range refused)', array( false, null, 'out_of_range' ) === SettingsTransfer::check( 'max_workflow', '11' ) && array( true, 3, '' ) === SettingsTransfer::check( 'max_workflow', '3' ) && false === SettingsTransfer::check( 'recipients', 'a@example.org, kaputt' )[0] );
$GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ]['at'] = time() - 31 * 86400;
check( 'SET-WC-23 a backup older than 30 days cannot be restored', null === SettingsTransfer::backup() );
check( 'P4 a backup older than 30 days is deleted, not only hidden (it holds the recipient addresses)', ! isset( $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] ) );
$GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] = array( 'at' => time() - 31 * 86400, 'user' => 1, 'source' => 'import', 'values' => array( 'recipients' => 'ops@example.org' ) );
SettingsTransfer::purge_expired_backup();
check( 'P4 daily maintenance removes an expired backup', ! isset( $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] ) );
$GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] = array( 'at' => time() - 86400, 'user' => 1, 'source' => 'import', 'values' => array( 'recipients' => 'ops@example.org' ) );
SettingsTransfer::purge_expired_backup();
check( 'P4 daily maintenance keeps a backup within the undo period', isset( $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] ) );
unset( $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] );
$wfc_warnings = array();
set_error_handler( static function ( int $no, string $msg ) use ( &$wfc_warnings ): bool { $wfc_warnings[] = $msg; return true; } ); // phpcs:ignore
$wfc_nested = SettingsTransfer::parse( array( 'format' => 1, 'plugin' => 'workflow-captain', 'settings' => array( 'kinds' => array( array( 'failed' ), array( 'x' => 1 ) ), 'batch' => array( 1 ) ) ) );
$wfc_clean  = SettingsRegistry::clean( 'kinds', array( 'failed', array( 'pause' ) ) );
$wfc_upload = array( \WorkflowCaptain\Admin\SettingsPage::uploaded_text( array( 'tmp_name' => array( 'a' ), 'error' => array( 0 ), 'size' => array( 1 ) ) ), \WorkflowCaptain\Admin\SettingsPage::uploaded_text( array( 'tmp_name' => '/etc/passwd', 'error' => 0, 'size' => 10 ) ), \WorkflowCaptain\Admin\SettingsPage::uploaded_text( null ), \WorkflowCaptain\Admin\SettingsPage::uploaded_text( array( 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE ) ) );
restore_error_handler();
check( 'P4 manipulated import file: nested lists are errors without "Array to string" warnings', 'unknown_kind' === ( $wfc_nested['errors']['kinds'] ?? '' ) && 'not_a_number' === ( $wfc_nested['errors']['batch'] ?? '' ) && array( 'failed' ) === $wfc_clean && array() === $wfc_warnings );
check( 'P4 upload: only PHP\'s temp file of this request is read (no path from the request, no array, no failed upload)', array( '', '', '', '' ) === $wfc_upload );
check( 'P4 transfer actions only by POST (admin-post.php also runs them for GET)', \WorkflowCaptain\Admin\SettingsPage::is_post( array( 'REQUEST_METHOD' => 'POST' ) ) && ! \WorkflowCaptain\Admin\SettingsPage::is_post( array( 'REQUEST_METHOD' => 'GET' ) ) && ! \WorkflowCaptain\Admin\SettingsPage::is_post( array() ) && ! \WorkflowCaptain\Admin\SettingsPage::is_post( array( 'REQUEST_METHOD' => array( 'POST' ) ) ) );
$wfc_page = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/SettingsPage.php' );
check( 'P4 export, import, reset and undo all pass the guard (capability, POST, own nonce)', 4 === preg_match_all( "/public function handle_(export|import|reset|undo)\\(\\): void \\{\\s*self::guard\\( 'workflow_captain_settings_\\1' \\);/", $wfc_page ) );
unset( $GLOBALS['wfc_test_options'][ Settings::OPTION ], $GLOBALS['wfc_test_options'][ Notifications::OPTION ], $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] );
$wfc_av = new ReflectionMethod( \WorkflowCaptain\Admin\SettingsPage::class, 'audit_value' );
check( 'SET-WC-12 audit view prints list values (notice kinds) without "Array to string" (found in the PHP 8.5 run)', 'failed, pause → failed' === $wfc_av->invoke( null, array( array( 'failed', 'pause' ), array( 'failed' ) ) ) && '7 → 9' === $wfc_av->invoke( null, array( 7, 9 ) ) );
