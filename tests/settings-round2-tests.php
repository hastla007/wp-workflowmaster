<?php
/**
 * Second review round of the settings (SET-WC-P1, -P3, -P5, 24.11.2026) over what was built after round 1: pacing,
 * sender/footer/time zone, log level, country profiles, admin times, export/import of the new types.
 * Required by tests/run.php last (uses the option stubs of the connector tests).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Messages;
use WorkflowCaptain\Notifications;
use WorkflowCaptain\Settings;
use WorkflowCaptain\SettingsRegistry;
use WorkflowCaptain\SettingsTransfer;

echo "\n# SET-WC-P1/P3/P5 round 2\n";

// P1 round trip: every exportable field with a value other than its default comes back identical.
$GLOBALS['wfc_test_options'][ Settings::OPTION ]      = array( 'batch' => 40, 'quiet_hours_start' => 22, 'quiet_hours_end' => 7, 'send_days' => 31, 'daily_limit' => 3, 'daily_limit_action' => 1, 'show_pro_hints' => 0 );
$GLOBALS['wfc_test_options'][ Notifications::OPTION ] = array( 'recipients' => 'ops@example.org', 'kinds' => array( 'failed', 'pause' ), 'notify_admin_roles' => array( 'editor' ) );
$GLOBALS['wfc_test_options'][ Messages::OPTION ]      = array( 'sender_name' => 'Laden „Süd“', 'sender_email' => 'shop@example.org', 'reply_to' => '', 'message_footer' => "Zeile 1\nZeile 2 – {site_name}", 'timezone' => 'Europe/Berlin' );
$wfc_r2_cur   = SettingsTransfer::current();
$wfc_r2_json  = json_encode( SettingsTransfer::export( $wfc_r2_cur, '0.4.0' ) );
$wfc_r2_back  = SettingsTransfer::parse( $wfc_r2_json );
$wfc_r2_exp   = array_intersect_key( $wfc_r2_cur, array_filter( array_map( static fn( $k ) => SettingsRegistry::field( $k )['export'], array_combine( array_keys( SettingsRegistry::SPEC ), array_keys( SettingsRegistry::SPEC ) ) ) ) );
check( 'P1 round 2: export then import gives the same values for every exportable field, the new types included', array() === $wfc_r2_back['errors'] && ksort( $wfc_r2_exp ) && ksort( $wfc_r2_back['values'] ) && $wfc_r2_exp === $wfc_r2_back['values'] && 12 < count( $wfc_r2_exp ) && array() === SettingsTransfer::diff( $wfc_r2_cur, $wfc_r2_back['values'] ) );

// P1 audit: Install::settings_not_autoloaded() creates every option as an empty array, so the first form save fires
// update_option_ with an empty old value; it listed every field as changed from nothing.
$GLOBALS['wfc_test_options']['workflow_captain_audit'] = array();
\WorkflowCaptain\Audit::settings_updated( array(), array_merge( Settings::defaults(), array( 'batch' => 60 ) ), Settings::OPTION );
$wfc_r2_audit = get_option( 'workflow_captain_audit', array() );
check( 'P1 round 2 audit: the first save into the pre-created empty option logs only the changed field', array( 'batch' => array( 25, 60 ) ) === ( $wfc_r2_audit[0]['detail'] ?? null ) );
$GLOBALS['wfc_test_options']['workflow_captain_audit'] = array();
\WorkflowCaptain\Audit::settings_added( Messages::OPTION, array( 'sender_name' => 'Shop', 'sender_email' => 'anna@example.org', 'reply_to' => '', 'message_footer' => '', 'timezone' => '' ) );
$wfc_r2_audit = get_option( 'workflow_captain_audit', array() );
check( 'P1 round 2 audit: the first save of the sender settings is logged (was compared as notice settings and dropped)', array( 'sender_name', 'sender_email' ) === array_keys( $wfc_r2_audit[0]['detail'] ?? array() ) );
check( 'P1 round 2 audit: the sender address is personal data and is logged without value, like the recipients', array( '…', '…' ) === ( $wfc_r2_audit[0]['detail']['sender_email'] ?? null ) && array( '', 'Shop' ) === ( $wfc_r2_audit[0]['detail']['sender_name'] ?? null ) );
check( 'P1 round 2 audit: the form hook receives the option name', str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Audit.php' ), "array( self::class, 'settings_updated' ), 10, 3 )" ) );
$GLOBALS['wfc_test_options']['workflow_captain_audit'] = array();
SettingsTransfer::apply( array( 'reply_to' => 'bob@example.org' ), 'cli' );
$wfc_r2_audit = get_option( 'workflow_captain_audit', array() );
check( 'P1 round 2 audit: WP-CLI/import/reset log the reply address without value as well', 'settings_cli' === ( $wfc_r2_audit[0]['what'] ?? '' ) && array( 'reply_to' => array( '…', '…' ) ) === $wfc_r2_audit[0]['detail'] && ! str_contains( (string) json_encode( $wfc_r2_audit ), 'bob@' ) );

// P1/P3 daily limit: a failed mail gives its slot back to the day it was taken on, even after midnight.
$wfc_r2_db = new FakeWpdb( FakeWpdb::memory() );
$wfc_r2_db->create_engine_tables();
$wfc_r2_store = new \WorkflowCaptain\Engine\WpdbEngineStore( $wfc_r2_db );
$wfc_r2_clock = new FixedClock( ( new DateTimeImmutable( '2026-11-23 23:59:50', new DateTimeZone( 'Europe/Berlin' ) ) )->getTimestamp() );
$wfc_r2_pace  = new \WorkflowCaptain\Engine\Pacing( $wfc_r2_store, $wfc_r2_clock, 'Europe/Berlin', static fn(): array => array( 'quiet_hours_start' => -1, 'quiet_hours_end' => -1, 'send_days' => 127, 'daily_limit' => 1, 'daily_limit_action' => 0 ) );
$wfc_r2_ok    = null === $wfc_r2_pace->admit( 17 );
$wfc_r2_clock->advance( 30 ); // The mail call ran past midnight; another message took the slot of the new day meanwhile.
$wfc_r2_store->take_send_slot( '2026-11-24', 17, 1 );
$wfc_r2_pace->release( 17 );
check( 'P1 round 2 pacing: the slot goes back to 23 Nov (taken), the new day stays full', $wfc_r2_ok && ! $wfc_r2_store->take_send_slot( '2026-11-24', 17, 1 ) && $wfc_r2_store->take_send_slot( '2026-11-23', 17, 1 ) );
$wfc_r2_pace->release( 17 );
check( 'P1 round 2 pacing: a second release without a taken slot gives nothing back', ! $wfc_r2_store->take_send_slot( '2026-11-23', 17, 1 ) );

// P3 admin times: the schedule list and the undo button wrote wp_date() in the time zone of WordPress while the
// page names the time zone of Workflow Captain.
check( 'P3 round 2 time zone object: own name, offset, garbage falls back to UTC', 'Europe/Berlin' === \WorkflowCaptain\Admin\Time::zone( 'Europe/Berlin' )->getName() && '+02:00' === \WorkflowCaptain\Admin\Time::zone( '+02:00' )->getName() && 'UTC' === \WorkflowCaptain\Admin\Time::zone( 'Mars/Olympus' )->getName() );
$wfc_r2_dates = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WORKFLOW_CAPTAIN_PATH . 'src', FilesystemIterator::SKIP_DOTS ) ) as $wfc_r2_file ) {
	foreach ( preg_split( '/\R/', (string) file_get_contents( (string) $wfc_r2_file ) ) as $wfc_r2_line ) {
		if ( preg_match( '/\bwp_date\(/', $wfc_r2_line ) && ! preg_match( '#^\s*(\*|//|/\*)#', $wfc_r2_line ) &&! str_contains( $wfc_r2_line, 'Time::zone(' ) ) {
			$wfc_r2_dates[] = basename( (string) $wfc_r2_file );
		}
	}
}
check( 'P3 round 2 every wp_date() of the core passes the time zone of Workflow Captain' . ( $wfc_r2_dates ? ': ' . implode( ', ', $wfc_r2_dates ) : '' ), array() === $wfc_r2_dates );
check( 'P3/P5 round 2 the help texts name the time zone of Workflow Captain, not the site\'s (they differ once an own zone is set)', ! str_contains( SettingsRegistry::texts()['quiet_hours_start'][1], 'site’s time zone' ) && ! str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Admin/SchedulesPage.php' ), "site\\'s time zone" ) && ! str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Admin/SettingsPage.php' ), "site\\'s time zone" ) );

// P1/P5 reset: the section list of the reset lacked "Messages" and "General"; WP-CLI named three tabs only.
$wfc_r2_tabs = array_values( array_unique( array_column( SettingsRegistry::SPEC, 5 ) ) );
$wfc_r2_page = (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Admin/SettingsPage.php' );
$wfc_r2_miss = array_filter( $wfc_r2_tabs, static fn( string $t ): bool => ! in_array( $t, \WorkflowCaptain\Admin\SettingsPage::RESET_TABS, true ) || ! str_contains( $wfc_r2_page, '<option value="' . $t . '">' ) );
check( 'P1 round 2 reset: every tab with settings can be reset on its own, in the form and on the server' . ( $wfc_r2_miss ? ': ' . implode( ', ', $wfc_r2_miss ) : '' ), array() === $wfc_r2_miss && array() !== SettingsTransfer::reset_values( 'messages' ) && array( 'show_pro_hints' ) === array_keys( SettingsTransfer::reset_values( 'general' ) ) );
check( 'P5 round 2 WP-CLI names every tab for reset', str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Cli/Command.php' ), 'For reset: general, execution, messages, notify or privacy.' ) && str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Cli/Command.php' ), 'SettingsPage::RESET_TABS' ) );

unset( $GLOBALS['wfc_test_options'][ Settings::OPTION ], $GLOBALS['wfc_test_options'][ Notifications::OPTION ], $GLOBALS['wfc_test_options'][ Messages::OPTION ], $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ], $GLOBALS['wfc_test_options']['workflow_captain_audit'] );
