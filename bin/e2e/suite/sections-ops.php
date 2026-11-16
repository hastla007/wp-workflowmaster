<?php
/**
 * Operations sections of bin/e2e/suite/checks.php: provider missing, product license missing, contract major (T88),
 * webhooks and outbox, license expiry and renewal (WF-CONNECTOR-12). Not shipped.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

global $wpdb;

/** Providers page of the core as HTML. */
function s_providers_page(): string {
	ob_start();
	( new \WorkflowCaptain\Admin\ProvidersPage( Plugin::instance() ) )->render();
	return (string) ob_get_clean();
}

/** The Webhooks feature object of Pro (or null). */
function s_hooks_feature() {
	$pro = s_pro();
	foreach ( $pro ? (array) $pro->features()->all() : array() as $f ) {
		if ( $f instanceof \WorkflowCaptainPro\Features\Webhooks ) {
			return $f;
		}
	}
	return null;
}

function s_hook_rows(): array {
	$file = '/home/user/wp-e2e/logs/suite-hooks.jsonl';
	return is_readable( $file ) ? array_values( array_filter( array_map( static fn( $l ) => json_decode( $l, true ), file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ) ) ) : array();
}

function s_outbox_rows(): array {
	global $wpdb;
	$t = $wpdb->prefix . 'workflow_captain_pro_outbox';
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ? (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id", ARRAY_A ) : array(); // phpcs:ignore
}

const S_HMAC    = 'suite-e2e-hmac-secret-0123456789abcdef0123456789';
const S_INBOUND = 'suite-e2e-inbound-secret-0123456789abcdef01234567';

switch ( $s_section ) {

	// ---- T88: provider (plugin) missing ------------------------------------------------------------------------
	case 'missing_prep':
		s_pause_all_workflows();
		$wf = s_publish( 'Suite Pass fehlt', array( 'event' => 'pass.order.tickets_issued' ), array( array( 'workflow.tag.set', array( 'tag' => 'suite-pass-missing' ) ) ) );
		$products = (array) s_state( 'products' );
		$order    = s_wc_order( array( (int) $products['ticket'] ) );
		$events   = s_events( 'pass.%', "state = 'received'" );
		s_state( 'missing', array( 'wf' => $wf, 'order' => $order->get_id(), 'events' => array_map( 'intval', array_column( $events, 'id' ) ) ) );
		s_check( 'p0', 'Pass events of a new order wait for the worker (received)', count( $events ) >= 2, array_column( $events, 'type' ) );
		break;

	case 'missing_check':
		$m = (array) s_state( 'missing' );
		s_work( 3 );
		$states = array();
		foreach ( (array) $m['events'] as $id ) {
			$row      = $wpdb->get_row( $wpdb->prepare( "SELECT type, state, last_error FROM {$wpdb->prefix}workflow_captain_events WHERE id = %d", $id ), ARRAY_A );
			$states[] = $row['type'] . ':' . $row['state'] . ':' . $row['last_error'];
		}
		s_check( 'p1', 'Pass Captain deactivated: its stored events pause (adapter_unregistered), none lost or failed', array() !== $states && array() === array_filter( $states, static fn( $s ) => ! str_contains( $s, ':paused:' ) ), $states );
		s_check( 'p2', 'Pass Captain deactivated: no run started', array() === s_runs( (int) $m['wf'] ) );
		s_check( 'p3', 'Pass Captain deactivated: adapter not registered', ! in_array( 'pass-captain', s_registered(), true ), implode( ',', s_registered() ) );
		$lines = s_suite_lines();
		s_check( 'p4', 'Pro license screen: "Pass Captain: not active on this site"', (bool) array_filter( $lines, static fn( $l ) => str_starts_with( $l, 'Pass Captain' ) && str_contains( $l, 'not active on this site' ) ), $lines );
		$html = s_providers_page();
		s_check( 'p5', 'Providers page of the core lists pass-captain with its paused events', str_contains( $html, '<code>pass-captain</code>' ), substr( wp_strip_all_tags( (string) strstr( $html, '<code>pass-captain</code>' ) ), 0, 200 ) );
		$wf = s_publish( 'Suite Pass fehlt 2', array( 'event' => 'pass.order.paid' ), array( array( 'workflow.tag.set', array( 'tag' => 'x' ) ) ), true );
		s_check( 'p6', 'Pass Captain deactivated: a new workflow with a Pass trigger cannot be published (provider_unavailable)', 0 === $wf );
		break;

	case 'missing_resume':
		$m  = (array) s_state( 'missing' );
		$ok = Plugin::instance()->consumer()->requeue_many( (array) $m['events'] );
		s_work( 4 );
		$runs = array_column( s_runs( (int) $m['wf'] ), 'state' );
		$left = array_filter( array_map( static fn( $id ) => $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT state FROM {$GLOBALS['wpdb']->prefix}workflow_captain_events WHERE id = %d", $id ) ), (array) $m['events'] ), static fn( $st ) => 'done' !== $st );
		s_check( 'p7', 'Pass Captain active again: requeue processes the paused events, the run succeeds', $ok === count( (array) $m['events'] ) && array() === $left && array( 'succeeded' ) === $runs, array( 'requeued' => $ok, 'left' => array_values( $left ), 'runs' => $runs ) );
		s_pause_all_workflows();
		// Prepare the next case: a voucher event that waits while Voucher Captain Pro is switched off.
		$v = vcap()->issuer->issue_standalone( array( 'amount' => 11, 'source' => 'admin', 'recipient_email' => 'gutschein2@customer.test', 'send' => false ) );
		s_state( 'addon_missing_event', (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}workflow_captain_events WHERE type = 'voucher.voucher.issued_standalone'" ) );
		break;

	// ---- T88: license of the product missing (its Pro add-on switched off) -------------------------------------
	case 'addon_missing_check':
		s_check( 'q1', 'Voucher Captain Pro deactivated: voucher adapter not registered (Pro right of the product missing)', ! in_array( 'voucher-captain', s_registered(), true ), implode( ',', s_registered() ) );
		$lines = s_suite_lines();
		s_check( 'q2', 'license screen: Voucher Captain off, Voucher Captain Pro not active', (bool) array_filter( $lines, static fn( $l ) => str_starts_with( $l, 'Voucher Captain' ) && str_contains( $l, 'Voucher Captain Pro is not active' ) ), $lines );
		s_work( 2 );
		$id  = (int) s_state( 'addon_missing_event' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT state, last_error FROM {$wpdb->prefix}workflow_captain_events WHERE id = %d", $id ), ARRAY_A );
		s_check( 'q3', 'a voucher event stored before stays paused (adapter_unregistered), not lost', $row && 'paused' === $row['state'], $row );
		$before = count( s_events( 'voucher.%' ) );
		vcap()->issuer->issue_standalone( array( 'amount' => 12, 'source' => 'admin', 'recipient_email' => 'gutschein3@customer.test', 'send' => false ) );
		s_check( 'q4', 'a voucher issued now stores no event (no license, nothing captured, R-10.1)', count( s_events( 'voucher.%' ) ) === $before );
		Plugin::instance()->consumer()->requeue( $id );
		break;

	// ---- T88: contract major of a provider (Affiliate Captain claims WPAC_CONTRACT_VERSION 2.0.0) -------------------
	case 'major_check':
		s_check( 'm1', 'Affiliate Captain with contract 2.0.0: adapter not registered (contract_major)', '2.0.0' === WPAC_CONTRACT_VERSION && ! in_array( 'affiliate-captain', s_registered(), true ), WPAC_CONTRACT_VERSION . ' / ' . implode( ',', s_registered() ) );
		$lines = s_suite_lines();
		s_check( 'm2', 'license screen names the incompatible contract of Affiliate Captain', (bool) array_filter( $lines, static fn( $l ) => str_starts_with( $l, 'Affiliate Captain' ) && ! str_contains( $l, ': on' ) ), $lines );
		$types = Plugin::instance()->available_event_types();
		s_check( 'm3', 'no affiliate.* event type offered while the contract is incompatible', array() === array_filter( $types, static fn( $t ) => str_starts_with( $t, 'affiliate.' ) ) );
		$before = count( s_events( 'affiliate.%' ) );
		\WPAffiliateCaptain\Affiliates\AffiliateRepository::create( array( 'name' => 'Major Partner', 'email' => 'major-' . wp_generate_password( 4, false, false ) . '@partner.test', 'status' => 'active' ) );
		s_check( 'm4', 'an affiliate event during the incompatible contract is not captured', count( s_events( 'affiliate.%' ) ) === $before );
		break;

	case 'major_back':
		s_check( 'm5', 'contract 1.x again: affiliate adapter registered', in_array( 'affiliate-captain', s_registered(), true ), implode( ',', s_registered() ) );
		s_work( 2 );
		$st = $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$wpdb->prefix}workflow_captain_events WHERE id = %d", (int) s_state( 'addon_missing_event' ) ) );
		s_check( 'm6', 'voucher adapter registered again; the requeued voucher event is processed', in_array( 'voucher-captain', s_registered(), true ) && 'done' === $st, (string) $st );
		break;

	default:
		require __DIR__ . '/sections-hooks.php';
}
