<?php
/**
 * SET-WC-34: suggested privacy policy text (wp_add_privacy_policy_content) with the periods in effect, and the
 * retention period for tags and notes (0 = none, the behaviour before).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Privacy\PolicyText;
use WorkflowCaptain\Privacy\Privacy;
use WorkflowCaptain\Settings;
use WorkflowCaptain\SettingsRegistry;
use WorkflowCaptain\SettingsTransfer;

echo "\n# SET-WC-34 privacy policy text and retention of tags and notes\n";

if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = 'default' ) { return 1 === (int) $n ? $one : $many; }
}
if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
	function wp_add_privacy_policy_content( $name, $text ) {
		$GLOBALS['wfc_policy'][] = array( $name, $text );
	}
}

// ----- Register -----------------------------------------------------------------------------------------------
$wfc_f = SettingsRegistry::field( 'retention_tags_notes' );
check( 'SET-WC-34 register: tags and notes period on the privacy tab, default 0 (none), 0 to 180, exported', null !== $wfc_f && 'privacy' === $wfc_f['tab'] && 0 === $wfc_f['default'] && 0 === $wfc_f['min'] && 180 === $wfc_f['max'] && true === $wfc_f['export'] && Settings::OPTION === $wfc_f['option'] );
check( 'SET-WC-34 register: 0 stays 0, values are clamped to 0..180', 0 === Settings::sanitize( array( 'retention_tags_notes' => '0' ) )['retention_tags_notes'] && 180 === Settings::sanitize( array( 'retention_tags_notes' => 999 ) )['retention_tags_notes'] && 0 === Settings::sanitize( array( 'retention_tags_notes' => -5 ) )['retention_tags_notes'] && array() === Settings::problems( array( 'retention_tags_notes' => '0' ) ) );
check( 'SET-WC-34 register: label and help text exist', '' !== ( SettingsRegistry::texts()['retention_tags_notes'][0] ?? '' ) && str_contains( SettingsRegistry::texts()['retention_tags_notes'][1] ?? '', '0 (default)' ) );
$wfc_doc = SettingsTransfer::export( array( 'retention_tags_notes' => 45 ) + Settings::defaults(), '0.3.0' );
$wfc_imp = SettingsTransfer::parse( (string) json_encode( $wfc_doc ) );
check( 'SET-WC-34 export and import: the period travels in the settings file and is checked strictly', 45 === ( $wfc_doc['settings']['retention_tags_notes'] ?? null ) && 45 === ( $wfc_imp['values']['retention_tags_notes'] ?? null ) && isset( SettingsTransfer::parse( array( 'format' => 1, 'plugin' => $wfc_doc['plugin'], 'settings' => array( 'retention_tags_notes' => 999 ) ) )['errors']['retention_tags_notes'] ) );

// ----- Retention ----------------------------------------------------------------------------------------------
/** Site with one tag and one note 100 days old and one of each 10 days old. */
function wfc_tagged_site(): object {
	$e = engine_site();
	$e->clock->advance( -100 * DAY_IN_SECONDS );
	$e->store->set_tag( 'pass-captain', 'ticket', 'old', 'alt', 1, $e->clock->mysql() );
	$e->store->add_note( 'k-old', 'pass-captain', 'ticket', 'old', 'alte Notiz', 1, 1, $e->clock->mysql() );
	$e->clock->advance( 90 * DAY_IN_SECONDS );
	$e->store->set_tag( 'pass-captain', 'ticket', 'new', 'neu', 1, $e->clock->mysql() );
	$e->store->add_note( 'k-new', 'pass-captain', 'ticket', 'new', 'neue Notiz', 1, 1, $e->clock->mysql() );
	$e->clock->advance( 10 * DAY_IN_SECONDS );
	return $e;
}
$wfc_saved_filters = $GLOBALS['wfc_test_filters']['workflow_captain_retention_days'] ?? array();
$wfc_saved_opts    = $GLOBALS['wfc_test_options'][ Settings::OPTION ] ?? null;
$GLOBALS['wfc_test_filters']['workflow_captain_retention_days'] = array( array( Settings::class, 'retention' ) );

$GLOBALS['wfc_test_options'][ Settings::OPTION ] = array();
$e = wfc_tagged_site();
( new Privacy( fn() => $e->events, $e->clock, fn() => $e->store ) )->apply_retention();
check( 'SET-WC-34 default 0: the daily maintenance deletes no tag and no note', 0 === Privacy::days( 'tags_notes' ) && array( 'alt' ) === $e->store->tags_for( 'pass-captain', 'ticket', 'old' ) && 1 === count( $e->store->notes_for( 'pass-captain', 'ticket', 'old' ) ) && array( 'neu' ) === $e->store->tags_for( 'pass-captain', 'ticket', 'new' ) );

$GLOBALS['wfc_test_options'][ Settings::OPTION ] = array( 'retention_tags_notes' => 30 );
$e = wfc_tagged_site();
( new Privacy( fn() => $e->events, $e->clock, fn() => $e->store ) )->apply_retention();
check( 'SET-WC-34 period 30 days: tag and note of 100 days are deleted', 30 === Privacy::days( 'tags_notes' ) && array() === $e->store->tags_for( 'pass-captain', 'ticket', 'old' ) && array() === $e->store->notes_for( 'pass-captain', 'ticket', 'old' ) );
check( 'SET-WC-34 period 30 days: tag and note of 10 days stay', array( 'neu' ) === $e->store->tags_for( 'pass-captain', 'ticket', 'new' ) && 'neue Notiz' === ( $e->store->notes_for( 'pass-captain', 'ticket', 'new' )[0]['note'] ?? '' ) );
check( 'SET-WC-34 delete_tags_notes_older counts deleted rows', 2 === $e->store->delete_tags_notes_older( $e->clock->mysql() ) && 0 === $e->store->delete_tags_notes_older( $e->clock->mysql() ) );

// ----- Export and erasure of tags, notes and the unsubscribe marker (R-12.5, docs/LOESCHUNG.md) -------------
if ( ! function_exists( 'get_user_option' ) ) {
	function get_user_option( $option, $user = 0 ) {
		return $GLOBALS['wfc_test_user_options'][ (int) $user ][ $option ] ?? false;
	}
}
$GLOBALS['wfc_test_user_options'] = array( 42 => array( \WorkflowCaptain\Unsubscribe::META => '2026-11-01T10:00:00Z' ) );
$GLOBALS['wfc_test_users'][42]    = 'kundin@example.org';
$GLOBALS['wfc_test_users'][43]    = 'other@example.org';
$e = engine_site();
$e->store->set_tag( 'wordpress', 'user', '42', 'vip', 1, $e->clock->mysql() );
$e->store->add_note( 'n42', 'wordpress', 'user', '42', 'Rückruf gewünscht', 1, 1, $e->clock->mysql() );
$e->store->set_tag( 'wordpress', 'user', '43', 'vip', 1, $e->clock->mysql() );
$e->store->set_tag( 'woocommerce', 'order', '42', 'paid', 1, $e->clock->mysql() );
$wfc_priv = new Privacy( fn() => $e->events, $e->clock, fn() => $e->store );
$wfc_exp  = $wfc_priv->export( 'kundin@example.org' );
$wfc_grp  = array_count_values( array_map( static fn( $i ) => $i['group_id'], $wfc_exp['data'] ) );
check( 'R-12.5 export: tags and notes on the user and the unsubscribe marker are included, not those of others or of an order with the same number', 1 === ( $wfc_grp['workflow-captain-tags'] ?? 0 ) && 1 === ( $wfc_grp['workflow-captain-notes'] ?? 0 ) && 1 === ( $wfc_grp['workflow-captain-unsubscribe'] ?? 0 ) && str_contains( (string) json_encode( $wfc_exp['data'], JSON_UNESCAPED_UNICODE ), 'Rückruf gewünscht' ) );
check( 'R-12.5 export: tags and notes only on the first page', array() === array_filter( $wfc_priv->export( 'kundin@example.org', 2 )['data'], static fn( $i ) => 'workflow-captain-events' !== $i['group_id'] && 'workflow-captain-runs' !== $i['group_id'] ) );
$wfc_er = $wfc_priv->erase( 'kundin@example.org' );
check( 'R-12.5 erase: tags and notes on the user are deleted, others stay', true === $wfc_er['items_removed'] && array() === $e->store->tags_for( 'wordpress', 'user', '42' ) && array() === $e->store->notes_for( 'wordpress', 'user', '42' ) && array( 'vip' ) === $e->store->tags_for( 'wordpress', 'user', '43' ) && array( 'paid' ) === $e->store->tags_for( 'woocommerce', 'order', '42' ) );
check( 'R-9.7 erase: the unsubscribe marker is kept and reported as retained', true === $wfc_er['items_retained'] && 1 === count( $wfc_er['messages'] ) );
$e->store->set_tag( 'wordpress', 'user', '43', 'neu', 1, $e->clock->mysql() );
$wfc_priv->on_deleted_user( 43 );
check( 'R-12.5 deleted user: tags and notes on the user are deleted', array() === $e->store->tags_for( 'wordpress', 'user', '43' ) );
unset( $GLOBALS['wfc_test_users'][42], $GLOBALS['wfc_test_users'][43] );
$GLOBALS['wfc_test_user_options'] = array();

// ----- Policy text --------------------------------------------------------------------------------------------
$GLOBALS['wfc_test_options'][ Settings::OPTION ] = array( 'retention_finished' => 14, 'retention_stuck' => 60, 'retention_runs' => 21, 'retention_tags_notes' => 45, 'audit_keep' => 77, 'daily_limit' => 3 );
$GLOBALS['wfc_policy'] = array();
$wfc_hooks_before      = count( $GLOBALS['wfc_test_filters']['admin_init'] ?? array() );
PolicyText::register();
check( 'SET-WC-34 policy text: hooked on admin_init', count( $GLOBALS['wfc_test_filters']['admin_init'] ?? array() ) === $wfc_hooks_before + 1 );
PolicyText::add();
$wfc_text = (string) ( $GLOBALS['wfc_policy'][0][1] ?? '' );
check( 'SET-WC-34 policy text: registered once under the plugin name', 1 === count( $GLOBALS['wfc_policy'] ) && 'Workflow Captain' === $GLOBALS['wfc_policy'][0][0] );
check( 'SET-WC-34 policy text: marked as a suggestion to check legally', str_contains( $wfc_text, 'Suggestion – have it checked legally before use.' ) && str_contains( $wfc_text, 'privacy-policy-tutorial' ) );
check( 'SET-WC-34 policy text: periods of the settings are filled in', str_contains( $wfc_text, 'after 14 days, failed and paused events after 60 days' ) && str_contains( $wfc_text, 'deleted 21 days after they end' ) && str_contains( $wfc_text, 'They are deleted after 45 days.' ) && str_contains( $wfc_text, 'last 77 changes' ) && str_contains( $wfc_text, 'deleted after 2 days' ) );
check( 'SET-WC-34 policy text: names events, runs, tags, notes, unsubscribing, counters, notices and webhooks/n8n', str_contains( $wfc_text, 'Events:' ) && str_contains( $wfc_text, 'Runs and logs:' ) && str_contains( $wfc_text, 'Tags and notes:' ) && str_contains( $wfc_text, 'Unsubscribing:' ) && str_contains( $wfc_text, 'counted per calendar day' ) && str_contains( $wfc_text, 'Notices to the site operator:' ) && str_contains( $wfc_text, 'n8n' ) );
$GLOBALS['wfc_test_options'][ Settings::OPTION ] = array( 'retention_finished' => 1 );
$wfc_text = PolicyText::text();
check( 'SET-WC-34 policy text: without a period for tags it says so, without a daily limit no counters, singular day', str_contains( $wfc_text, 'no period is set' ) && ! str_contains( $wfc_text, 'counted per calendar day' ) && str_contains( $wfc_text, 'after 1 day,' ) );
check( 'SET-WC-34 policy text: the plugin registers it', str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Plugin.php' ), 'PolicyText::register()' ) && str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Privacy/PolicyText.php' ), "function_exists( 'wp_add_privacy_policy_content' )" ) );

$GLOBALS['wfc_test_filters']['workflow_captain_retention_days'] = $wfc_saved_filters;
if ( null === $wfc_saved_opts ) {
	unset( $GLOBALS['wfc_test_options'][ Settings::OPTION ] );
} else {
	$GLOBALS['wfc_test_options'][ Settings::OPTION ] = $wfc_saved_opts;
}
