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
check( 'P4 manipulated import file: nested lists are errors without "Array to string" warnings', in_array( $wfc_nested['errors']['kinds'] ?? '', array( 'unknown_kind', 'not_a_list' ), true ) && 'not_a_number' === ( $wfc_nested['errors']['batch'] ?? '' ) && array( 'failed' ) === $wfc_clean && array() === $wfc_warnings );
check( 'P4 upload: only PHP\'s temp file of this request is read (no path from the request, no array, no failed upload)', array( '', '', '', '' ) === $wfc_upload );
check( 'P4 transfer actions only by POST (admin-post.php also runs them for GET)', \WorkflowCaptain\Admin\SettingsPage::is_post( array( 'REQUEST_METHOD' => 'POST' ) ) && ! \WorkflowCaptain\Admin\SettingsPage::is_post( array( 'REQUEST_METHOD' => 'GET' ) ) && ! \WorkflowCaptain\Admin\SettingsPage::is_post( array() ) && ! \WorkflowCaptain\Admin\SettingsPage::is_post( array( 'REQUEST_METHOD' => array( 'POST' ) ) ) );
$wfc_page = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/SettingsPage.php' );
check( 'P4 export, import, reset and undo all pass the guard (capability, POST, own nonce)', 4 === preg_match_all( "/public function handle_(export|import|reset|undo)\\(\\): void \\{\\s*self::guard\\( 'workflow_captain_settings_\\1' \\);/", $wfc_page ) );
$GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] = array( 'at' => time() - 29 * 86400, 'values' => array( 'batch' => 1 ) );
check( 'P3 a backup within 30 days stays', false === SettingsTransfer::purge_expired_backup() && isset( $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] ) );
unset( $GLOBALS['wfc_test_options'][ Settings::OPTION ], $GLOBALS['wfc_test_options'][ Notifications::OPTION ], $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] );
$wfc_av = new ReflectionMethod( \WorkflowCaptain\Admin\SettingsPage::class, 'audit_value' );
check( 'SET-WC-12 audit view prints list values (notice kinds) without "Array to string" (found in the PHP 8.5 run)', 'failed, pause → failed' === $wfc_av->invoke( null, array( array( 'failed', 'pause' ), array( 'failed' ) ) ) && '7 → 9' === $wfc_av->invoke( null, array( 7, 9 ) ) );

// SET-WC-25: notify.admin to the users of roles the operator released.
$wfc_dir         = new FakeDirectory();
$wfc_dir->roles  = array( 'editor' => array( 17 ) );
$wfc_na          = new \WorkflowCaptain\Engine\Actions\NotifyAdmin( $wfc_dir );
$wfc_na_ctx      = static fn( string $to ) => new \WorkflowCaptain\Engine\ActionContext( array( 'subject' => array( 'id' => '5' ), 'data' => array() ), array( 'subject' => 'S', 'message' => 'M', 'to' => $to ), 'k', 1, 1, 1, 1 );
check( 'SET-WC-25 a role that is not released is refused at publish', array( 'to:invalid' ) === $wfc_na->validate_config( array( 'subject' => 'S', 'message' => 'M', 'to' => 'role:editor' ) ) );
$GLOBALS['wfc_test_options'][ Notifications::OPTION ] = array( 'notify_admin_roles' => array( 'editor', 'editor', 'Böse Rolle' ) );
check( 'SET-WC-25 released roles are kept unique and well-formed', array( 'editor' ) === Notifications::config()['notify_admin_roles'] );
check( 'SET-WC-25 a released role is accepted at publish and offered in the editor', array() === $wfc_na->validate_config( array( 'subject' => 'S', 'message' => 'M', 'to' => 'role:editor' ) ) && in_array( 'role:editor', array_column( $wfc_na->builder_fields()[2]['options'], 'value' ), true ) && 'select' === $wfc_na->builder_fields()[2]['type'] );
$wfc_na_res = $wfc_na->execute( $wfc_na_ctx( 'role:editor' ) );
check( 'SET-WC-25 the users of the role get the message; the log names the group, no address', 1 === $wfc_na_res['sent'] && 'role:editor' === $wfc_na_res['to'] && ! str_contains( (string) json_encode( $wfc_na_res ), '@' ) );
$GLOBALS['wfc_test_options'][ Notifications::OPTION ] = array();
check( 'SET-WC-25 a role withdrawn after publishing sends nothing and says why', array( 'sent' => 0, 'skipped' => 'recipient_not_allowed' ) === $wfc_na->execute( $wfc_na_ctx( 'role:editor' ) ) );
check( 'SET-WC-25 the fixed capabilities and the site address still work (published versions unchanged)', array() === $wfc_na->validate_config( array( 'subject' => 'S', 'message' => 'M', 'to' => 'manage_woocommerce' ) ) && array() === $wfc_na->validate_config( array( 'subject' => 'S', 'message' => 'M' ) ) );
check( 'SET-WC-25 import accepts released roles and refuses malformed ones', array( true, array( 'editor' ), '' ) === SettingsTransfer::check( 'notify_admin_roles', array( 'editor' ) ) && 'unknown_role' === SettingsTransfer::check( 'notify_admin_roles', array( 'editor', '<x>' ) )[2] );
unset( $GLOBALS['wfc_test_options'][ Notifications::OPTION ] );

// SET-WC-13: number of audit entries.
$wfc_log = array();
for ( $i = 0; $i < 60; $i++ ) {
	$wfc_log = \WorkflowCaptain\Audit::append( $wfc_log, 'settings', 0, array( 'n' => $i ), 't' . $i );
}
check( 'SET-WC-13 audit keeps 50 entries by default, newest first', 50 === count( $wfc_log ) && 59 === $wfc_log[0]['detail']['n'] );
$GLOBALS['wfc_test_options'][ Settings::OPTION ] = array( 'audit_keep' => 10 );
check( 'SET-WC-13 the setting lowers the number (10 to 500)', 10 === count( \WorkflowCaptain\Audit::append( $wfc_log, 'settings', 0, array(), 'x' ) ) && 500 === Settings::sanitize( array( 'audit_keep' => 9999 ) )['audit_keep'] && 10 === Settings::sanitize( array( 'audit_keep' => 1 ) )['audit_keep'] );
unset( $GLOBALS['wfc_test_options'][ Settings::OPTION ] );
// ----- P1 review (settings and data)
$GLOBALS['wfc_test_options'][ Settings::OPTION ]      = array( 'notify_daily' => 1, 'retention_stuck' => 90 );
$GLOBALS['wfc_test_options'][ Notifications::OPTION ] = array( 'recipients' => 'ops@example.org', 'kinds' => array( 'failed', 'pause' ) );
add_filter( 'workflow_captain_setting_notify_daily', '__return_false_p1' );
function __return_false_p1() {
	return false;
}
add_filter( 'workflow_captain_setting_kinds', static fn( $v ) => 'failed' );
add_filter( 'workflow_captain_setting_recipients', static fn( $v ) => array( 'Filter@example.org', 'kaputt' ) );
add_filter( 'workflow_captain_setting_retention_stuck', static fn( $v ) => 10 );
$wfc_p1_settings = Settings::get();
$wfc_p1_notify   = Notifications::config();
check( 'P1 filter: false switches the daily mail off (was: fell back to the default 1, mail stayed on)', 0 === $wfc_p1_settings['notify_daily'] );
check( 'P1 filter: a result of the wrong type keeps the stored value (kinds not widened to all six)', array( 'failed', 'pause' ) === $wfc_p1_notify['kinds'] );
check( 'P1 filter: addresses may come as a list; invalid ones are dropped', 'filter@example.org' === $wfc_p1_notify['recipients'] );
check( 'P1 form values: stored() is what the form shows and saves, not the filtered value (saving a tab stored the filter result for good)', 90 === Settings::stored()['retention_stuck'] && 10 === $wfc_p1_settings['retention_stuck'] && 'ops@example.org' === Notifications::stored()['recipients'] && SettingsTransfer::current()['retention_stuck'] === 90 );
check( 'P1 form: the settings page fills its fields from stored(), not from the filtered get()/config()', 1 === preg_match( '/\$s\s+= Settings::stored\(\);/', (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/SettingsPage.php' ) ) && 1 === preg_match( '/\$c\s+= Notifications::stored\(\);/', (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/SettingsPage.php' ) ) );
unset( $GLOBALS['wfc_test_filters']['workflow_captain_setting_notify_daily'], $GLOBALS['wfc_test_filters']['workflow_captain_setting_kinds'], $GLOBALS['wfc_test_filters']['workflow_captain_setting_recipients'], $GLOBALS['wfc_test_filters']['workflow_captain_setting_retention_stuck'] );

$wfc_p1_nested = SettingsTransfer::parse( array( 'format' => 1, 'plugin' => 'workflow-captain', 'settings' => array( 'kinds' => array( array( 'failed' ) ) ) ) );
check( 'P1 import: a nested list in kinds is refused without "Array to string" warning', 'not_a_list' === ( $wfc_p1_nested['errors']['kinds'] ?? '' ) && array( 'failed' ) === SettingsRegistry::clean( 'kinds', array( array( 'x' ), 'failed' ) ) );
check( 'P1 check: the same address twice is one address, not an error; eleven addresses are too many', array( true, 'a@example.org', '' ) === SettingsTransfer::check( 'recipients', 'a@example.org, A@example.org' ) && 'too_many' === SettingsTransfer::check( 'recipients', implode( ',', array_map( static fn( $i ) => "a{$i}@example.org", range( 1, 11 ) ) ) )[2] && 'invalid_address' === SettingsTransfer::check( 'recipients', array( 'a@example.org' ) )[2] );

$GLOBALS['wfc_test_options']['workflow_captain_audit'] = array();
\WorkflowCaptain\Audit::settings_updated( array( 'recipients' => 'a@example.org', 'kinds' => array( 'failed' ) ), array( 'recipients' => 'b@example.org', 'kinds' => array( 'failed' ) ) );
$wfc_p1_audit = get_option( 'workflow_captain_audit', array() );
check( 'P1 audit: a form save of the notice settings is logged, the address without value', 'settings' === ( $wfc_p1_audit[0]['what'] ?? '' ) && array( 'recipients' => array( '…', '…' ) ) === $wfc_p1_audit[0]['detail'] );
\WorkflowCaptain\Audit::settings_added( Settings::OPTION, array_merge( Settings::defaults(), array( 'batch' => 60 ) ) );
$wfc_p1_audit = get_option( 'workflow_captain_audit', array() );
check( 'P1 audit: the first form save of a site (creates the option, no update hook) is logged against the defaults', array( 'batch' => array( 25, 60 ) ) === ( $wfc_p1_audit[0]['detail'] ?? null ) );
\WorkflowCaptain\Audit::settings_added( Notifications::OPTION, array( 'recipients' => '', 'kinds' => array( 'failed' ) ) );
$wfc_p1_audit = get_option( 'workflow_captain_audit', array() );
check( 'P1 audit: the first save of the notice settings is logged too', array( 'kinds' ) === array_keys( $wfc_p1_audit[0]['detail'] ?? array() ) );
$wfc_p1_page = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/SettingsPage.php' );
check( 'P1 audit: the settings page hooks the audit of both options and of the first save', str_contains( $wfc_p1_page, 'Audit::watch_settings( true );' ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Audit.php' ), "'add_option_' . \$option" ) );

$GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] = array( 'at' => time() - 31 * 86400, 'user' => 1, 'source' => 'reset', 'values' => array( 'recipients' => 'ops@example.org' ) );
check( 'P1 backup: an expired backup (holds addresses) is deleted by the maintenance, a fresh one is kept', true === SettingsTransfer::purge_backup() && ! isset( $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] ) && false === SettingsTransfer::purge_backup() && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Plugin.php' ), 'SettingsTransfer::purge_backup();' ) );
$GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] = array( 'at' => time(), 'user' => 1, 'source' => 'reset', 'values' => array() );
check( 'P1 backup: a backup within 30 days stays for undo', false === SettingsTransfer::purge_backup() && isset( $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ] ) );
unset( $GLOBALS['wfc_test_options'][ Settings::OPTION ], $GLOBALS['wfc_test_options'][ Notifications::OPTION ], $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ], $GLOBALS['wfc_test_options']['workflow_captain_audit'] );
check( 'P1 import: "Übernehmen" after the preview expired says so instead of "Import verworfen"', 1 === preg_match( "/elseif \\( ! is_array\\( \\\$preview \\) .*?Die Vorschau ist abgelaufen/s", $wfc_p1_page ) );

// P6: every hook of the core is part of the public contract and listed in docs/SDK.md, section 5 (the setting filters
// as workflow_captain_setting_<feld>); found: the new setting filters and three older hooks were missing there.
$wfc_sdk   = (string) file_get_contents( dirname( __DIR__ ) . '/docs/SDK.md' );
$wfc_nodoc = array();
foreach ( array_merge( array( dirname( __DIR__ ) . '/workflow-captain.php' ), iterator_to_array( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', FilesystemIterator::SKIP_DOTS ) ) ) ) as $wfc_file ) {
	preg_match_all( "/(?:apply_filters|do_action)\\(\\s*'(workflow_captain_[a-z0-9_]*)'(\\s*\\.)?/", (string) file_get_contents( (string) $wfc_file ), $wfc_m, PREG_SET_ORDER );
	foreach ( $wfc_m as $wfc_hook ) {
		$wfc_name = $wfc_hook[1] . ( isset( $wfc_hook[2] ) && '' !== $wfc_hook[2] ? '<feld>' : '' );
		if ( ! str_contains( $wfc_sdk, '`' . $wfc_name . '`' ) ) {
			$wfc_nodoc[] = $wfc_name;
		}
	}
}
check( 'P6 every hook of the core is documented in docs/SDK.md' . ( $wfc_nodoc ? ': ' . implode( ', ', array_unique( $wfc_nodoc ) ) : '' ), array() === $wfc_nodoc );
