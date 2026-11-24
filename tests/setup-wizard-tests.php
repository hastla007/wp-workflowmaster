<?php
/**
 * Setup assistant (SET-WC-27): steps profile → sender/footer → first recipe → summary, every step skippable,
 * moving between steps writes nothing, only the confirmed summary writes (one settings change with backup and audit
 * entry, the recipe as a draft), the notice until finished or hidden. Required by tests/run.php after the country
 * profile tests (in-memory options, engine_site() from the engine tests).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Admin\SetupWizard;
use WorkflowCaptain\Admin\TemplatesPage;
use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Messages;
use WorkflowCaptain\Settings;
use WorkflowCaptain\SettingsRegistry;
use WorkflowCaptain\SettingsTransfer;

echo "\n# SET-WC-27 setup assistant\n";

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$html = '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';
		if ( $display ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
		}
		return $html;
	}
}

$wfc_s_saved = array_intersect_key( $GLOBALS['wfc_test_options'], array_flip( array( Settings::OPTION, Messages::OPTION, SetupWizard::OPTION, SettingsTransfer::BACKUP_OPTION, 'workflow_captain_audit' ) ) );
unset( $GLOBALS['wfc_test_options'][ Settings::OPTION ], $GLOBALS['wfc_test_options'][ Messages::OPTION ], $GLOBALS['wfc_test_options'][ SetupWizard::OPTION ], $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ], $GLOBALS['wfc_test_options']['workflow_captain_audit'] );

$wfc_s_tpl = TemplatesPage::templates( glob( WORKFLOW_CAPTAIN_PATH . 'assets/recipes/*.json' ) );
$wfc_s_docs = array_map( static fn( $f ) => basename( $f, '.json' ), glob( WORKFLOW_CAPTAIN_PATH . 'docs/recipes/*.json' ) );
sort( $wfc_s_docs );
check( 'SET-WC-27 recipes of step 3 are the shipped templates (copies of docs/recipes)', $wfc_s_docs === array_keys( $wfc_s_tpl ) );

// Notice: shown until finished or hidden, with a link to the assistant and a dismiss form (POST, nonce).
check( 'SET-WC-27 a new site: assistant open, notice wanted', '' === SetupWizard::state() && SetupWizard::notice_wanted() );
$wfc_s_notice = SetupWizard::notice_html();
check( 'SET-WC-27 notice: "Start setup" link to the assistant and a dismiss form with its own nonce', str_contains( $wfc_s_notice, 'page=' . SetupWizard::SLUG ) && str_contains( $wfc_s_notice, 'Start setup' ) && str_contains( $wfc_s_notice, '<form method="post"' ) && str_contains( $wfc_s_notice, 'value="workflow_captain_setup_dismiss"' ) && str_contains( $wfc_s_notice, 'nonce-workflow_captain_setup_dismiss' ) );
check( 'SET-WC-27 close() accepts only done or dismissed', false === SetupWizard::close( 'later' ) && '' === SetupWizard::state() );
SetupWizard::close( SetupWizard::DISMISSED );
check( 'SET-WC-27 hidden: no notice any more, state without user or address', '' === SetupWizard::notice_html() && SetupWizard::DISMISSED === SetupWizard::state() && array( 'state', 'at' ) === array_keys( $GLOBALS['wfc_test_options'][ SetupWizard::OPTION ] ) );
unset( $GLOBALS['wfc_test_options'][ SetupWizard::OPTION ] );

// Reading the form keeps known keys only.
$wfc_s_plan = SetupWizard::read( array( 'profile' => 'de', 'keys' => array( 'quiet_hours_start', 'batch', array( 'x' ) ), 'recipe' => 'nope', 'sender_email' => 'a@b.example' ), $wfc_s_tpl );
check( 'SET-WC-27 read: profile by key, keys outside the profile dropped, unknown recipe none, step 2 only when reached', 'DE' === $wfc_s_plan['profile'] && array( 'quiet_hours_start' ) === $wfc_s_plan['keys'] && '' === $wfc_s_plan['recipe'] && null === $wfc_s_plan['messages'] );
check( 'SET-WC-27 read: unknown profile gives no profile and no keys', array( '', array() ) === array_values( array_slice( SetupWizard::read( array( 'profile' => 'XX', 'keys' => array( 'daily_limit' ) ), $wfc_s_tpl ), 0, 2 ) ) );

// Navigation: never writes.
$wfc_s_before = $GLOBALS['wfc_test_options'];
$wfc_s_cur    = SettingsTransfer::current();
$wfc_s_nav    = SetupWizard::navigate( array(), $wfc_s_cur, $wfc_s_tpl );
check( 'SET-WC-27 navigation: starts with the country profile', 'profile' === $wfc_s_nav['step'] && '' === $wfc_s_nav['plan']['profile'] );
$wfc_s_nav = SetupWizard::navigate( array( 'wfc_from' => 'profile', 'wfc_preview' => '1', 'profile' => 'DE' ), $wfc_s_cur, $wfc_s_tpl );
$wfc_s_legal = array_keys( array_filter( WorkflowCaptain\CountryProfiles::values( 'DE' ), static fn( $e ) => ! $e[1] ) );
check( 'SET-WC-27 step 1 proposal: values with legal weight are never preselected, only those without', 'profile' === $wfc_s_nav['step'] && array( 'timezone' ) === $wfc_s_nav['plan']['keys'] && array() === array_intersect( $wfc_s_legal, $wfc_s_nav['plan']['keys'] ) );
$wfc_s_nav = SetupWizard::navigate( array( 'wfc_from' => 'profile', 'wfc_next' => '1', 'profile' => 'AT', 'shown_profile' => 'DE', 'keys' => array( 'quiet_hours_start', 'quiet_hours_end' ) ), $wfc_s_cur, $wfc_s_tpl );
check( 'SET-WC-27 step 1: another profile chosen without its proposal shows that proposal, the old selection is not taken over', 'profile' === $wfc_s_nav['step'] && 'AT' === $wfc_s_nav['plan']['profile'] && array( 'timezone' ) === $wfc_s_nav['plan']['keys'] );
$wfc_s_nav = SetupWizard::navigate( array( 'wfc_from' => 'profile', 'wfc_next' => '1', 'profile' => 'DE', 'shown_profile' => 'DE', 'keys' => array( 'quiet_hours_start', 'quiet_hours_end', 'timezone' ) ), $wfc_s_cur, $wfc_s_tpl );
check( 'SET-WC-27 step 1 next: on to sender and footer with the selection', 'messages' === $wfc_s_nav['step'] && array( 'quiet_hours_start', 'quiet_hours_end', 'timezone' ) === $wfc_s_nav['plan']['keys'] );
$wfc_s_skip = SetupWizard::navigate( array( 'wfc_from' => 'profile', 'wfc_skip' => '1', 'profile' => 'DE', 'keys' => array( 'quiet_hours_start' ) ), $wfc_s_cur, $wfc_s_tpl );
check( 'SET-WC-27 step 1 skip: profile and selection dropped', 'messages' === $wfc_s_skip['step'] && '' === $wfc_s_skip['plan']['profile'] && array() === $wfc_s_skip['plan']['keys'] );
$wfc_s_msg = array( 'msg_set' => '1', 'sender_name' => 'Shop', 'sender_email' => 'not-an-address', 'reply_to' => '', 'message_footer' => '' );
$wfc_s_nav = SetupWizard::navigate( array( 'wfc_from' => 'messages', 'wfc_next' => '1', 'profile' => 'DE', 'keys' => array( 'quiet_hours_start' ) ) + $wfc_s_msg, $wfc_s_cur, $wfc_s_tpl );
check( 'SET-WC-27 step 2: an invalid address keeps the step and names the field', 'messages' === $wfc_s_nav['step'] && array( 'sender_email' => 'invalid_address' ) === $wfc_s_nav['errors'] );
$wfc_s_nav = SetupWizard::navigate( array( 'wfc_from' => 'messages', 'wfc_skip' => '1' ) + $wfc_s_msg, $wfc_s_cur, $wfc_s_tpl );
check( 'SET-WC-27 step 2 skip: the fields are dropped (nothing changes there)', 'recipe' === $wfc_s_nav['step'] && null === $wfc_s_nav['plan']['messages'] );
$wfc_s_msg['sender_email'] = 'Shop@Example.org';
$wfc_s_nav = SetupWizard::navigate( array( 'wfc_from' => 'messages', 'wfc_back' => '1' ) + $wfc_s_msg, $wfc_s_cur, $wfc_s_tpl );
check( 'SET-WC-27 back keeps what was entered', 'profile' === $wfc_s_nav['step'] && 'Shop@Example.org' === $wfc_s_nav['plan']['messages']['sender_email'] );
$wfc_s_recipe = array_key_first( $wfc_s_tpl );
$wfc_s_nav    = SetupWizard::navigate( array( 'wfc_from' => 'recipe', 'wfc_skip' => '1', 'recipe' => $wfc_s_recipe ), $wfc_s_cur, $wfc_s_tpl );
check( 'SET-WC-27 step 3 skip: no recipe, on to the summary', 'summary' === $wfc_s_nav['step'] && '' === $wfc_s_nav['plan']['recipe'] );
check( 'SET-WC-27 moving through the steps writes nothing (cancel changes nothing)', $wfc_s_before === $GLOBALS['wfc_test_options'] );

// Summary: before → after, legal weight marked; only changed values.
$wfc_s_full = SetupWizard::read( array( 'profile' => 'DE', 'keys' => array( 'quiet_hours_start', 'quiet_hours_end', 'timezone' ), 'recipe' => $wfc_s_recipe ) + $wfc_s_msg, $wfc_s_tpl );
$wfc_s_rows = SetupWizard::summary( $wfc_s_full, $wfc_s_cur );
check( 'SET-WC-27 summary: profile values with current, new and the unchecked flag; own entries of step 2; unchanged fields left out', array( -1, 20, false ) === $wfc_s_rows['quiet_hours_start'] && array( '', 'Europe/Berlin', true ) === $wfc_s_rows['timezone'] && array( '', 'shop@example.org', true ) === $wfc_s_rows['sender_email'] && array( '', 'Shop', true ) === $wfc_s_rows['sender_name'] && ! isset( $wfc_s_rows['reply_to'] ) && ! isset( $wfc_s_rows['message_footer'] ) );
check( 'SET-WC-27 summary never writes', $wfc_s_before === $GLOBALS['wfc_test_options'] );

// Finish: refused on an error, nothing written.
$wfc_s_e   = engine_site();
$wfc_s_bad = SetupWizard::read( array( 'profile' => 'DE', 'keys' => array( 'quiet_hours_start' ), 'recipe' => $wfc_s_recipe ) + array_merge( $wfc_s_msg, array( 'reply_to' => 'broken' ) ), $wfc_s_tpl );
$wfc_s_res = SetupWizard::finish( $wfc_s_bad, $wfc_s_e->workflows, $wfc_s_tpl );
check( 'SET-WC-27 finish with an invalid field: nothing written, no draft, assistant stays open', false === $wfc_s_res['ok'] && 'invalid_address' === $wfc_s_res['errors']['reply_to'] && $wfc_s_before === $GLOBALS['wfc_test_options'] && 0 === $wfc_s_e->store->count_workflows() );

// Finish: one settings change (backup, audit), the recipe as a draft, assistant done.
$wfc_s_res   = SetupWizard::finish( $wfc_s_full, $wfc_s_e->workflows, $wfc_s_tpl );
$wfc_s_after = SettingsTransfer::current();
$wfc_s_audit = get_option( 'workflow_captain_audit', array() );
check( 'SET-WC-27 finish: exactly the confirmed values are written', $wfc_s_res['ok'] && 20 === $wfc_s_after['quiet_hours_start'] && 8 === $wfc_s_after['quiet_hours_end'] && 'Europe/Berlin' === $wfc_s_after['timezone'] && 'shop@example.org' === $wfc_s_after['sender_email'] && 'Shop' === $wfc_s_after['sender_name'] && 0 === $wfc_s_after['daily_limit'] && 30 === $wfc_s_after['retention_finished'] && '' === $wfc_s_after['reply_to'] );
check( 'SET-WC-27 finish: audit entry settings_setup with the changes, backup with source setup for undo', 'setup_recipe' === ( $wfc_s_audit[0]['what'] ?? '' ) && 'settings_setup' === ( $wfc_s_audit[1]['what'] ?? '' ) && array( -1, 20 ) === ( $wfc_s_audit[1]['detail']['quiet_hours_start'] ?? null ) && 'setup' === ( SettingsTransfer::backup()['source'] ?? '' ) );
$wfc_s_wf = $wfc_s_e->store->get_workflow( (int) $wfc_s_res['workflow'] );
check( 'SET-WC-27 finish: the recipe becomes a draft, never published or active', null !== $wfc_s_wf && EngineStore::WF_DRAFT === $wfc_s_wf['status'] && null === $wfc_s_wf['published_version_id'] && $wfc_s_tpl[ $wfc_s_recipe ]['name'] === $wfc_s_wf['name'] );
check( 'SET-WC-27 finish: audit entry setup_recipe names the recipe and the draft', array( 'recipe' => $wfc_s_recipe, 'workflow' => (int) $wfc_s_res['workflow'] ) === ( $wfc_s_audit[0]['detail'] ?? null ) );
check( 'SET-WC-27 finish: assistant done, notice gone', SetupWizard::DONE === SetupWizard::state() && '' === SetupWizard::notice_html() );
SettingsTransfer::undo();
check( 'SET-WC-27 undo restores the settings before the assistant', $wfc_s_cur === SettingsTransfer::current() );

// Finish without any choice: nothing written but the state.
unset( $GLOBALS['wfc_test_options'][ SetupWizard::OPTION ], $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ], $GLOBALS['wfc_test_options']['workflow_captain_audit'] );
$wfc_s_e2   = engine_site();
$wfc_s_res  = SetupWizard::finish( SetupWizard::read( array(), $wfc_s_tpl ), $wfc_s_e2->workflows, $wfc_s_tpl );
check( 'SET-WC-27 every step skipped: no setting, no backup, no audit entry, no draft; assistant done', $wfc_s_res['ok'] && array() === $wfc_s_res['changed'] && null === SettingsTransfer::backup() && array() === get_option( 'workflow_captain_audit', array() ) && 0 === $wfc_s_e2->store->count_workflows() && SetupWizard::DONE === SetupWizard::state() );

// Rights, nonces, storage, wiring.
$wfc_s_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/SetupWizard.php' );
check( 'SET-WC-27 every part needs manage_workflow_captain (SET-WC-30)', 'manage_workflow_captain' === SettingsRegistry::CAPABILITY && 3 === substr_count( $wfc_s_src, 'current_user_can( SettingsRegistry::CAPABILITY )' ) && str_contains( $wfc_s_src, "add_submenu_page( OverviewPage::SLUG, __( 'Setup', 'workflow-captain' ), __( 'Setup', 'workflow-captain' ), SettingsRegistry::CAPABILITY" ) );
check( 'SET-WC-27 writing actions: POST, own nonce, right (guard), the page itself writes nothing', 1 === preg_match( "/function handle_dismiss\\(\\): void \\{\\s*self::guard\\( 'workflow_captain_setup_dismiss' \\);/", $wfc_s_src ) && 1 === preg_match( "/function handle_apply\\(\\): void \\{\\s*self::guard\\( 'workflow_captain_setup_apply' \\);/", $wfc_s_src ) && str_contains( $wfc_s_src, 'SettingsPage::is_post( $_SERVER )' ) && str_contains( $wfc_s_src, 'check_admin_referer( $action )' ) && 1 === preg_match( "/wp_verify_nonce\\([^;]*'workflow_captain_setup_step'/", $wfc_s_src ) );
check( 'SET-WC-27 state option is written with autoload no and removed on uninstall', str_contains( $wfc_s_src, "update_option( self::OPTION, array( 'state' => \$state, 'at' => time() ), false )" ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Install.php' ), "'workflow_captain_setup'" ) );
check( 'SET-WC-27 the assistant uses SettingsTransfer::apply() and Transfer::import(), no write of its own to the settings', 1 === substr_count( $wfc_s_src, 'SettingsTransfer::apply( $values, self::SOURCE )' ) && 1 === substr_count( $wfc_s_src, 'Transfer::import( TemplatesPage::localized(' ) && ! preg_match( '/update_option\(\s*(Settings|Messages|Notifications)::OPTION/', $wfc_s_src ) );
check( 'SET-WC-27 wired: registered by the plugin, notice on the overview for manage_workflow_captain', str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Plugin.php' ), 'new \\WorkflowCaptain\\Admin\\SetupWizard( $this ) )->register();' ) && 1 === preg_match( '/current_user_can\( \\\\WorkflowCaptain\\\\SettingsRegistry::CAPABILITY \) \) \{\s*echo SetupWizard::notice_html\(\);/', (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/OverviewPage.php' ) ) );
check( 'SET-WC-27 docs/OPTIONS.md names the option', str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/docs/OPTIONS.md' ), '`' . SetupWizard::OPTION . '`' ) );

unset( $GLOBALS['wfc_test_options'][ Settings::OPTION ], $GLOBALS['wfc_test_options'][ Messages::OPTION ], $GLOBALS['wfc_test_options'][ SetupWizard::OPTION ], $GLOBALS['wfc_test_options'][ SettingsTransfer::BACKUP_OPTION ], $GLOBALS['wfc_test_options']['workflow_captain_audit'] );
$GLOBALS['wfc_test_options'] = array_merge( $GLOBALS['wfc_test_options'], $wfc_s_saved );
