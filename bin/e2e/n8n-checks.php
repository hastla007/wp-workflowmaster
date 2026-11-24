<?php
/**
 * Security matrix of the n8n connection against a real, self-hosted n8n (WF-N8N-12, docs/n8n/MATRIX.md). Not shipped
 * (/bin is export-ignore). Runs inside bin/e2e/n8n.sh, which starts n8n on 127.0.0.1:5678 and the test site on
 * 127.0.0.1:8087, and does the n8n side (credentials, activation) between the sections:
 *
 *     wp eval-file bin/e2e/n8n-checks.php <section>
 *
 * Every check appends one JSON line to $E2E_RESULTS (default logs/n8n-results.jsonl) and prints PASS/FAIL. State
 * shared with the shell script: logs/n8n-state.json (secrets of the test connections, test-only values).
 *
 * @package WorkflowCaptain
 */

defined( 'ABSPATH' ) || exit;

$n_section = $args[0] ?? '';
$n_root    = getenv( 'E2E_ROOT' ) ?: '/home/user/wp-e2e';

define( 'N_STATE', $n_root . '/logs/n8n-state.json' );
define( 'N_API', $n_root . '/logs/n8n-api.json' );
define( 'N_N8N', getenv( 'N8N_URL' ) ?: 'http://127.0.0.1:5678' );
// Webhook IDs of the helper workflows in bin/e2e/n8n/ and of the template docs/n8n/woocommerce-bezahlt.json.
const N_STATUS_HOOK = '0d8f1c55-0001-4b6e-9a51-1d0c3a7e0101';
const N_PAID_PATH   = '/webhook/workflow-captain-woocommerce-order-paid';
const N_PAID_TYPE   = 'woocommerce.order.paid';
// Personal data a careless sender would forward; none of it may reach n8n.
const N_PII = array(
	'billing_email' => 'kundin@customer.test',
	'first_name'    => 'Erika',
	'last_name'     => 'Mustermann',
	'customer_note' => 'Bitte klingeln bei Mustermann',
	'voucher_code'  => 'GUTSCHEIN-SECRET-4711',
);

function n_check( string $id, string $name, bool $ok, $detail = '' ): bool {
	$file = getenv( 'E2E_RESULTS' ) ?: dirname( N_STATE ) . '/n8n-results.jsonl';
	$row  = array(
		'id'     => $id,
		'name'   => $name,
		'result' => $ok ? 'PASS' : 'FAIL',
		'detail' => mb_substr( is_string( $detail ) ? $detail : (string) wp_json_encode( $detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 0, 700 ),
	);
	file_put_contents( $file, wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND );
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $id . ' - ' . $name . ( '' !== $row['detail'] ? ' :: ' . $row['detail'] : '' ) . "\n";
	return $ok;
}

function n_state( ?string $key = null, $value = null ) {
	$state = is_readable( N_STATE ) ? (array) json_decode( (string) file_get_contents( N_STATE ), true ) : array();
	if ( null === $key ) {
		return $state;
	}
	if ( func_num_args() < 2 ) {
		return $state[ $key ] ?? null;
	}
	$state[ $key ] = $value;
	file_put_contents( N_STATE, wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	return $value;
}

function n_pro() {
	return function_exists( '\\WorkflowCaptainPro\\addon' ) ? \WorkflowCaptainPro\addon() : null;
}

function n_hooks() {
	$pro = n_pro();
	foreach ( $pro ? (array) $pro->features()->all() : array() as $f ) {
		if ( $f instanceof \WorkflowCaptainPro\Features\Webhooks ) {
			return $f;
		}
	}
	return null;
}

function n_admin(): void {
	$u = get_user_by( 'login', 'admin' );
	wp_set_current_user( $u ? (int) $u->ID : 1 );
}

function n_uuid(): string {
	return wp_generate_uuid4();
}

/** An envelope as Workflow Captain builds it (docs/SPEC-suite-events.md 6), with PII fields a sender must drop. */
function n_envelope( string $type, array $data = array() ): array {
	return array(
		'specversion'    => '1.0',
		'id'             => n_uuid(),
		'type'           => $type,
		'source'         => 'workflow-captain',
		'time'           => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'correlation_id' => n_uuid(),
		'subject'        => array( 'type' => 'order', 'id' => '1001' ),
		'data'           => $data + array( 'currency' => 'EUR', 'total_minor' => 4900, 'payment_method' => 'bacs', 'item_count' => 2 ) + N_PII,
	);
}

/** Puts one envelope into the outbox and sends due rows; returns the rows of that event. */
function n_send( array $envelope ): array {
	$f = n_hooks();
	$f->outbox()->enqueue( $envelope );
	$f->outbox()->run();
	return n_rows( (string) $envelope['id'] );
}

function n_rows( string $event_id ): array {
	global $wpdb;
	$t = $wpdb->prefix . 'workflow_captain_pro_outbox';
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE event_id = %s ORDER BY id", $event_id ), ARRAY_A ); // phpcs:ignore
}

function n_row_summary( array $rows ): array {
	return array_map( static fn( $r ) => $r['connection'] . ':' . $r['state'] . ':' . $r['last_code'] . ':' . $r['attempts'], $rows );
}

/** Calls n8n's public API. */
function n_api( string $path ): array {
	$key = (string) ( json_decode( (string) @file_get_contents( N_API ), true )['apiKey'] ?? '' );
	$res = wp_remote_get( N_N8N . '/api/v1' . $path, array( 'timeout' => 30, 'headers' => array( 'X-N8N-API-KEY' => $key ) ) );
	return (array) json_decode( (string) wp_remote_retrieve_body( $res ), true );
}

/** Executions of a workflow in n8n (newest first), with their data. */
function n_executions( string $workflow_id ): array {
	for ( $i = 0; $i < 10; $i++ ) {
		$list = (array) ( n_api( '/executions?includeData=true&limit=50&workflowId=' . rawurlencode( $workflow_id ) )['data'] ?? array() );
		if ( array() !== $list && ! in_array( true, array_map( static fn( $e ) => empty( $e['finished'] ) && 'waiting' !== ( $e['status'] ?? '' ) && 'error' !== ( $e['status'] ?? '' ), $list ), true ) ) {
			return $list;
		}
		sleep( 1 );
	}
	return $list ?? array();
}

/** The execution of n8n that received a given event ID, or null. */
function n_execution_for( string $workflow_id, string $event_id, int $nth = 0 ): ?array {
	// The template answers at once ("onReceived"); n8n saves the execution a moment later.
	for ( $try = 0; $try < 15; $try++ ) {
		$found = array();
		foreach ( n_executions( $workflow_id ) as $e ) {
			$body = $e['data']['resultData']['runData']['Workflow Captain'][0]['data']['main'][0][0]['json']['body'] ?? null;
			if ( is_array( $body ) && ( $body['id'] ?? '' ) === $event_id && ! empty( $e['finished'] ) ) {
				$found[] = $e;
			}
		}
		$found = array_reverse( $found ); // oldest first
		if ( isset( $found[ $nth ] ) ) {
			return $found[ $nth ];
		}
		sleep( 1 );
	}
	return null;
}

/** Reason of a refusal of the inbound route ("unknown_connection" is sent as code workflow_captain_pro_unknown_connection). */
function n_reason( array $r ): string {
	return (string) ( $r['body']['message'] ?? $r['body']['code'] ?? '' );
}

/** Sends a signed request from n8n to WordPress through the helper workflow e2e-inbound (n8n signs with HMAC-SHA256). */
function n_via_n8n( array $order ): array {
	$res  = wp_remote_post( N_N8N . '/webhook/e2e-inbound', array( 'timeout' => 40, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $order ) ) );
	$body = (array) json_decode( (string) wp_remote_retrieve_body( $res ), true );
	return array(
		'n8n'    => (int) wp_remote_retrieve_response_code( $res ),
		'status' => (int) ( $body['status'] ?? 0 ),
		'body'   => $body['body'] ?? null,
		'nonce'  => (string) ( $body['nonce'] ?? '' ),
	);
}

function n_events( string $type ): array {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, type, envelope FROM {$wpdb->prefix}workflow_captain_events WHERE type = %s ORDER BY id", $type ), ARRAY_A ); // phpcs:ignore
}

$n_base = array(
	'kind'          => 'n8n',
	'base_url'      => N_N8N,
	'allow_private' => 1,
	'allow_http'    => 1,
	'cidr'          => '127.0.0.1/32',
);

n_admin();
$f    = n_hooks();
$page = $f ? $f->page() : null;

switch ( $n_section ) {

	case 'license':
		global $wpdb;
		$p = new WC_Product_Simple();
		$p->set_name( 'Workflow Captain Pro Agentur' );
		$p->set_regular_price( '49.00' );
		$p->set_virtual( true );
		$p->set_status( 'publish' );
		$pid = $p->save();
		$all = array( 'workflow.suite_integrations', 'workflow.webhooks', 'workflow.event_correlation', 'workflow.advanced_logic', 'workflow.recipe_templates', 'workflow.extended_logs', 'workflow.custom_connectors', 'workflow.multisite_network' );
		\WPLC\Catalog\Catalog::save_mapping( $pid, 0, array( 'product_key' => 'workflow-captain-pro', 'plan_key' => 'agency', 'billing_interval' => 'year', 'live_site_limit' => 3, 'support_level' => 'standard', 'entitlements' => array_fill_keys( $all, true ) ) );
		$c = new WC_Customer();
		$c->set_username( 'n8nkunde' );
		$c->set_email( 'n8nkunde@customer.test' );
		$c->set_password( 'customer-e2e-pass' );
		$c->set_billing_email( 'n8nkunde@customer.test' );
		$c->set_billing_country( 'DE' );
		$cid   = $c->save();
		$order = wc_create_order( array( 'customer_id' => $cid ) );
		$order->add_product( wc_get_product( $pid ), 1 );
		$order->set_address( $c->get_billing(), 'billing' );
		$order->set_payment_method( 'bacs' );
		$order->calculate_totals();
		$order->save();
		$order->payment_complete( 'e2e-' . $order->get_id() );
		$lic = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wplc_licenses WHERE source_order_id = %d", $order->get_id() ), ARRAY_A );
		$key = $lic ? \WPLC\License\LicenseKey::for_uuid( (string) $lic['license_uuid'], (string) $lic['license_key_secret_id'] ) : '';
		n_state( 'license_key', $key );
		$r  = n_pro() ? n_pro()->license()->activate( $key ) : new WP_Error( 'addon_missing' );
		$st = n_pro() ? n_pro()->license()->status() : null;
		n_check( 'n0', 'Workflow Captain Pro licensed against the local shop (agency plan, workflow.webhooks)', ! is_wp_error( $r ) && $st && $st->access && n_pro()->is_entitled( 'workflow.webhooks' ), is_wp_error( $r ) ? $r->get_error_code() : ( $st ? $st->state : '' ) );
		break;

	case 'create':
		$made = array(
			'n8n-paid'    => $n_base + array( 'name' => 'n8n Bestellung bezahlt', 'key' => 'n8n-paid', 'direction' => 'outbound', 'url' => N_PAID_PATH, 'auth_mode' => 'header', 'header_name' => 'X-WFC-Token', 'events' => array( N_PAID_TYPE ), 'fields' => array( N_PAID_TYPE => 'currency, total_minor, payment_method, item_count' ), 'forward' => 1 ),
			'n8n-500'     => $n_base + array( 'name' => 'n8n 500', 'key' => 'n8n-500', 'direction' => 'outbound', 'url' => '/webhook/' . N_STATUS_HOOK . '/e2e-status/500', 'events' => array( 'e2etest.status.c500' ), 'forward' => 1 ),
			'n8n-429'     => $n_base + array( 'name' => 'n8n 429', 'key' => 'n8n-429', 'direction' => 'outbound', 'url' => '/webhook/' . N_STATUS_HOOK . '/e2e-status/429', 'events' => array( 'e2etest.status.c429' ), 'forward' => 1 ),
			'n8n-400'     => $n_base + array( 'name' => 'n8n 400', 'key' => 'n8n-400', 'direction' => 'outbound', 'url' => '/webhook/' . N_STATUS_HOOK . '/e2e-status/400', 'events' => array( 'e2etest.status.c400' ), 'forward' => 1 ),
			'n8n-slow'    => $n_base + array( 'name' => 'n8n langsam', 'key' => 'n8n-slow', 'direction' => 'outbound', 'url' => '/webhook/e2e-slow', 'events' => array( 'e2etest.status.slow' ), 'forward' => 1 ),
			'n8n-testurl' => $n_base + array( 'name' => 'n8n Test-URL', 'key' => 'n8n-testurl', 'direction' => 'outbound', 'url' => '/webhook-test/workflow-captain-woocommerce-order-paid', 'events' => array( N_PAID_TYPE ) ),
			'n8n-in'      => array( 'kind' => 'n8n', 'name' => 'n8n eingehend', 'key' => 'n8n-in', 'direction' => 'inbound', 'triggers' => 'crm_lead: lead_id:integer, source' ),
			'n8n-rate'    => array( 'kind' => 'n8n', 'name' => 'n8n Rate', 'key' => 'n8n-rate', 'direction' => 'inbound', 'triggers' => 'rate_lead: lead_id:integer', 'rate' => 2 ),
		);
		$notices = array();
		$secrets = array();
		foreach ( $made as $key => $input ) {
			$r               = $page->dispatch( 'create', '', $input );
			$notices[ $key ] = ( $r['wcp_notice'] ?? '' ) . ( isset( $r['wcp_fields'] ) ? ':' . $r['wcp_fields'] : '' );
			$secrets[ $key ] = (array) ( $f->connections()->take_reveal( get_current_user_id() )['secrets'] ?? array() );
		}
		n_check( 'n1', 'eight n8n connections created (local exception for 127.0.0.1:5678 over HTTP, header auth, inbound triggers)', array() === array_diff( $notices, array( 'created' ) ), $notices );
		n_state( 'header_secret', (string) ( $secrets['n8n-paid']['header'] ?? '' ) );
		n_state( 'inbound_secret', (string) ( $secrets['n8n-in']['inbound'] ?? '' ) );
		n_state( 'rate_secret', (string) ( $secrets['n8n-rate']['inbound'] ?? '' ) );
		n_check( 'n1b', 'secrets shown once after creation (header value for the n8n credential, inbound token), masked afterwards', '' !== n_state( 'header_secret' ) && '' !== n_state( 'inbound_secret' ) && null === $f->connections()->take_reveal( get_current_user_id() ) && ! str_contains( $f->connections()->masked( 'n8n-paid', 'header' ), (string) n_state( 'header_secret' ) ), $f->connections()->masked( 'n8n-paid', 'header' ) );
		break;

	case 'activate':
		$tests = array();
		$acts  = array();
		foreach ( array( 'n8n-paid', 'n8n-500', 'n8n-429', 'n8n-400', 'n8n-slow' ) as $key ) {
			$tests[ $key ] = ( $page->dispatch( 'test', $key, array() )['wcp_code'] ?? '' );
			$acts[ $key ]  = ( $page->dispatch( 'activate', $key, array() )['wcp_notice'] ?? '' );
		}
		foreach ( array( 'n8n-in', 'n8n-rate' ) as $key ) {
			$acts[ $key ] = ( $page->dispatch( 'activate', $key, array() )['wcp_notice'] ?? '' );
		}
		n_check( 'n2', 'connection test reaches the real n8n production webhooks (ok:200; header auth accepted by n8n)', array() === array_diff( $tests, array( 'ok:200' ) ), $tests );
		n_check( 'n3', 'connections activated after the test', array() === array_diff( $acts, array( 'activated' ) ), $acts );
		// Test URL: refused for the test and for activation, n8n is not even called.
		$t = $page->dispatch( 'test', 'n8n-testurl', array() );
		$a = $page->dispatch( 'activate', 'n8n-testurl', array() );
		// The test URL may be used for the connection test (n8n listens there only during "Listen for test event", so
		// it answers 404 here); an active connection with it is refused.
		n_check( 'n4', 'n8n test URL /webhook-test/: activation refused (n8n_test_url); the connection test may use it', 'n8n_test_url' === ( $a['wcp_code'] ?? '' ), array( $t, $a ) );
		break;

	case 'send':
		// Outbound with valid header auth: one row per forwarding connection, sent, n8n ran the template.
		$env = n_envelope( N_PAID_TYPE );
		$rows = n_send( $env );
		n_state( 'paid_event', $env['id'] );
		n_check( 'n5', 'outbox delivered woocommerce.order.paid to the n8n template (sent, ok:200)', 1 === count( $rows ) && 'sent' === $rows[0]['state'] && 'ok:200' === $rows[0]['last_code'], n_row_summary( $rows ) );
		$ex   = n_execution_for( (string) n_state( 'wf_paid' ), $env['id'] );
		$json = $ex['data']['resultData']['runData']['Workflow Captain'][0]['data']['main'][0][0]['json'] ?? array();
		$data = (array) ( $json['body']['data'] ?? array() );
		n_check( 'n6', 'n8n execution of the template holds only the selected fields; the event id is the one of Workflow Captain', null !== $ex && array() === array_diff( array_keys( $data ), array( 'currency', 'total_minor', 'payment_method', 'item_count' ) ) && 4 === count( $data ) && ( $json['body']['id'] ?? '' ) === $env['id'], array( 'execution' => $ex['id'] ?? null, 'status' => $ex['status'] ?? null, 'data' => $data ) );
		$dump = (string) wp_json_encode( $ex );
		$pii  = array_values( array_filter( N_PII, static fn( $v ) => str_contains( $dump, $v ) ) );
		n_check( 'n7', 'PII: no e-mail, name, note or voucher code anywhere in the n8n execution data', null !== $ex && array() === $pii, $pii );
		$headers = (array) ( $json['headers'] ?? array() );
		$hmac    = (string) ( $headers['x-workflow-captain-signature'] ?? '' );
		n_state( 'n8n_headers', array_keys( $headers ) );
		n_check( 'n7b', 'signature headers arrive in n8n (X-Workflow-Captain-Signature/-Timestamp/-Nonce/-Event-Id), event id header equals body id', '' !== $hmac && ( $headers['x-workflow-captain-event-id'] ?? '' ) === $env['id'], array_keys( $headers ) );
		$secret_seen = '' !== (string) n_state( 'header_secret' ) && str_contains( $dump, (string) n_state( 'header_secret' ) );
		n_check( 'n7c', 'note: does n8n keep the Header-Auth value in the saved execution? (n8n behaviour, see MATRIX.md)', null !== $ex, $secret_seen ? 'yes: header value visible in the execution data (x-wfc-token)' : 'no' );
		n_state( 'header_in_execution', $secret_seen );
		// Event types and fields outside the connection are not sent.
		$other = n_envelope( 'woocommerce.order.refunded' );
		$f->outbox()->enqueue( $other );
		n_check( 'n8', 'event type outside the connection: no outbox row, nothing sent to n8n', array() === n_rows( $other['id'] ), n_row_summary( n_rows( $other['id'] ) ) );
		break;

	case 'duplicate':
		// The same event a second time (replay of the outbox row: same id) - n8n's "Doppelte verwerfen" drops it.
		$rows = n_rows( (string) n_state( 'paid_event' ) );
		$r    = $page->dispatch( 'replay', '', array( 'row' => (int) ( $rows[0]['id'] ?? 0 ), 'id' => (int) ( $rows[0]['id'] ?? 0 ) ) );
		if ( 'replayed' !== ( $r['wcp_notice'] ?? '' ) ) {
			$r = array( 'wcp_notice' => $f->outbox()->replay( (int) ( $rows[0]['id'] ?? 0 ) ) ? 'replayed' : 'error' );
		}
		$f->outbox()->run();
		$rows   = n_rows( (string) n_state( 'paid_event' ) );
		$first  = n_execution_for( (string) n_state( 'wf_paid' ), (string) n_state( 'paid_event' ), 0 );
		$second = n_execution_for( (string) n_state( 'wf_paid' ), (string) n_state( 'paid_event' ), 1 );
		$out1   = count( (array) ( $first['data']['resultData']['runData']['Doppelte verwerfen (Ereignis-id)'][0]['data']['main'][0] ?? array() ) );
		$out2   = count( (array) ( $second['data']['resultData']['runData']['Doppelte verwerfen (Ereignis-id)'][0]['data']['main'][0] ?? array() ) );
		$next2  = isset( $second['data']['resultData']['runData']['Felder auswählen'] );
		n_check( 'n9', 'duplicate delivery: same event id sent again (sent), n8n "Doppelte verwerfen" passes the first and drops the second', 'replayed' === $r['wcp_notice'] && 'sent' === ( $rows[0]['state'] ?? '' ) && null !== $second && 1 === $out1 && 0 === $out2 && ! $next2, array( 'replay' => $r['wcp_notice'], 'rows' => n_row_summary( $rows ), 'first_out' => $out1, 'second_out' => $out2, 'second_ran_next_step' => $next2 ) );
		break;

	case 'codes':
		$res = array();
		foreach ( array( 'c500', 'c429', 'c400' ) as $c ) {
			$res[ $c ] = n_row_summary( n_send( n_envelope( 'e2etest.status.' . $c ) ) );
		}
		n_check( 'n10', 'n8n answers 500: temporary, outbox retry_wait (http:500, attempt 1)', array( 'n8n-500:retry_wait:http:500:1' ) === $res['c500'], $res['c500'] );
		n_check( 'n11', 'n8n answers 429: temporary, outbox retry_wait (http:429)', array( 'n8n-429:retry_wait:http:429:1' ) === $res['c429'], $res['c429'] );
		n_check( 'n12', 'n8n answers 400: permanent, outbox failed (http:400)', array( 'n8n-400:failed:http:400:1' ) === $res['c400'], $res['c400'] );
		$t0   = microtime( true );
		$slow = n_row_summary( n_send( n_envelope( 'e2etest.status.slow' ) ) );
		$secs = (int) round( microtime( true ) - $t0 );
		// SafeHttp::TIMEOUT is 10 s (MATRIX.md said 30 s); the behaviour that matters: a time-out counts as temporary.
		n_check( 'n13', 'n8n answers after 40 s: time-out (SafeHttp, 10 s), temporary (transport:*, retry_wait)', 1 === count( $slow ) && 1 === preg_match( '/^n8n-slow:retry_wait:transport:[a-z0-9_]+:1$/', $slow[0] ) && $secs >= 8 && $secs <= 35, array( 'rows' => $slow, 'seconds' => $secs ) );
		break;

	case 'inactive':
		// The shell deactivated the n8n workflow: the production URL answers 404.
		$rows = n_send( n_envelope( N_PAID_TYPE ) );
		n_check( 'n14', 'n8n workflow inactive: production URL 404, permanent, outbox failed (http:404)', array( 'n8n-paid:failed:http:404:1' ) === n_row_summary( $rows ), n_row_summary( $rows ) );
		break;

	case 'rotate_header':
		$g = $page->dispatch( 'regenerate', 'n8n-paid', array( 'field' => 'header' ) );
		$new = (string) ( $f->connections()->take_reveal( get_current_user_id() )['secrets']['header'] ?? '' );
		n_state( 'header_secret_old', n_state( 'header_secret' ) );
		n_state( 'header_secret', $new );
		$rows = n_send( n_envelope( N_PAID_TYPE ) );
		n_check( 'n15', 'header secret rotated in WordPress, n8n credential not yet updated: n8n refuses (403), outbox failed', 'regenerated' === ( $g['wcp_notice'] ?? '' ) && '' !== $new && $new !== n_state( 'header_secret_old' ) && array( 'n8n-paid:failed:http:403:1' ) === n_row_summary( $rows ), array( $g['wcp_notice'] ?? '', n_row_summary( $rows ) ) );
		break;

	case 'rotate_header_after':
		$rows = n_send( n_envelope( N_PAID_TYPE ) );
		n_check( 'n16', 'n8n credential updated to the new value: delivery accepted again (sent, ok:200)', array( 'n8n-paid:sent:ok:200:1' ) === n_row_summary( $rows ), n_row_summary( $rows ) );
		break;

	case 'inbound':
		$secret = (string) n_state( 'inbound_secret' );
		$ok     = n_via_n8n( array( 'key' => 'n8n-in', 'trigger' => 'crm_lead', 'secret' => $secret, 'data' => array( 'lead_id' => 7, 'source' => 'n8n', 'email' => 'lead@customer.test', 'name' => 'Max Lead' ) ) );
		n_check( 'n17', 'inbound from n8n (Crypto node HMAC-SHA256 over timestamp.nonce.body, HTTP Request node): accepted 202 captured', 202 === $ok['status'] && 'captured' === ( $ok['body']['result'] ?? '' ), $ok );
		$ev  = n_events( 'workflow.inbound.crm_lead' );
		$env = $ev ? (array) json_decode( (string) end( $ev )['envelope'], true ) : array();
		n_check( 'n18', 'inbound event stored with only the declared fields (no e-mail, no name)', array( 'lead_id' => 7, 'source' => 'n8n' ) == ( $env['data'] ?? null ), $env['data'] ?? 'none' );
		$replay = n_via_n8n( array( 'key' => 'n8n-in', 'trigger' => 'crm_lead', 'secret' => $secret, 'nonce' => $ok['nonce'], 'data' => array( 'lead_id' => 7, 'source' => 'n8n' ) ) );
		n_check( 'n19', 'replay from n8n with the same nonce refused (409)', 409 === $replay['status'], $replay );
		$wrong = n_via_n8n( array( 'key' => 'n8n-in', 'trigger' => 'crm_lead', 'secret' => 'falsches-geheimnis-0123456789abcdef0123456789', 'data' => array( 'lead_id' => 8 ) ) );
		n_check( 'n20', 'wrong secret in n8n refused (401)', 401 === $wrong['status'], $wrong );
		$old = n_via_n8n( array( 'key' => 'n8n-in', 'trigger' => 'crm_lead', 'secret' => $secret, 'ts_offset' => -900, 'data' => array( 'lead_id' => 9 ) ) );
		n_check( 'n21', 'timestamp 15 min old refused (401)', 401 === $old['status'], $old );
		$scope = n_via_n8n( array( 'key' => 'n8n-in', 'trigger' => 'order_refund', 'secret' => $secret, 'data' => array( 'x' => 1 ) ) );
		n_check( 'n22', 'trigger not on the allowlist of the connection (out of scope) refused (403)', 403 === $scope['status'], $scope );
		$unknown = n_via_n8n( array( 'key' => 'n8n-gibts-nicht', 'trigger' => 'crm_lead', 'secret' => $secret, 'data' => array( 'lead_id' => 1 ) ) );
		n_check( 'n23', 'unknown connection refused (401 unknown_connection)', 401 === $unknown['status'] && 'unknown_connection' === n_reason( $unknown ), $unknown );
		$codes   = array();
		$codes[] = n_via_n8n( array( 'key' => 'n8n-rate', 'trigger' => 'rate_lead', 'secret' => (string) n_state( 'rate_secret' ), 'data' => array( 'lead_id' => 1 ) ) )['status'];
		$counted = (int) get_transient( 'wfc_rl_' . md5( 'rate:n8n-rate:' . intdiv( time(), 60 ) ) );
		n_check( 'n24b', 'rate counter: one accepted request counts once', 1 === $counted, array( 'status' => $codes[0], 'counted' => $counted ) );
		for ( $i = 2; $i <= 3; $i++ ) {
			$codes[] = n_via_n8n( array( 'key' => 'n8n-rate', 'trigger' => 'rate_lead', 'secret' => (string) n_state( 'rate_secret' ), 'data' => array( 'lead_id' => $i ) ) )['status'];
		}
		n_check( 'n24', 'rate limit per connection (2/min): third request from n8n refused (429)', array( 202, 202, 429 ) === $codes, $codes );
		break;

	case 'rotate_inbound':
		$old = (string) n_state( 'inbound_secret' );
		$g   = $page->dispatch( 'regenerate', 'n8n-in', array( 'field' => 'inbound' ) );
		$new = (string) ( $f->connections()->take_reveal( get_current_user_id() )['secrets']['inbound'] ?? '' );
		$r1  = n_via_n8n( array( 'key' => 'n8n-in', 'trigger' => 'crm_lead', 'secret' => $old, 'data' => array( 'lead_id' => 11 ) ) );
		$r2  = n_via_n8n( array( 'key' => 'n8n-in', 'trigger' => 'crm_lead', 'secret' => $new, 'data' => array( 'lead_id' => 12 ) ) );
		n_check( 'n25', 'inbound token rotated: n8n with the old value refused at once (401), with the new value accepted (202)', 'regenerated' === ( $g['wcp_notice'] ?? '' ) && 401 === $r1['status'] && 202 === $r2['status'], array( $g['wcp_notice'] ?? '', $r1['status'], $r2['status'] ) );
		n_state( 'inbound_secret', $new );
		$rv = $page->dispatch( 'revoke', 'n8n-rate', array() );
		$r3 = n_via_n8n( array( 'key' => 'n8n-rate', 'trigger' => 'rate_lead', 'secret' => (string) n_state( 'rate_secret' ), 'data' => array( 'lead_id' => 99 ) ) );
		n_check( 'n26', 'revoked connection refused (401 unknown_connection)', 'revoked' === ( $rv['wcp_notice'] ?? '' ) && 401 === $r3['status'], array( $rv, $r3 ) );
		break;

	case 'ssrf':
		// SSRF targets are WordPress' business; checked here with the real SafeHttp from the test site.
		$a = $page->dispatch( 'create', '', array( 'kind' => 'n8n', 'name' => 'ohne Ausnahme', 'key' => 'n8n-noexc', 'direction' => 'outbound', 'base_url' => N_N8N, 'url' => N_PAID_PATH, 'allow_http' => 1, 'events' => array( N_PAID_TYPE ) ) );
		$t = $page->dispatch( 'test', 'n8n-noexc', array() );
		n_check( 'n27', 'n8n on 127.0.0.1 without a private exception: connection test refused before any request', 'created' === ( $a['wcp_notice'] ?? '' ) && 'test_failed' === ( $t['wcp_notice'] ?? '' ) && ! str_starts_with( (string) ( $t['wcp_code'] ?? '' ), 'ok:' ), array( $a, $t ) );
		$m  = $page->dispatch( 'create', '', array( 'kind' => 'n8n', 'name' => 'Metadaten', 'key' => 'n8n-meta', 'direction' => 'outbound', 'base_url' => 'http://169.254.169.254', 'url' => '/latest/meta-data', 'allow_private' => 1, 'allow_http' => 1, 'cidr' => '169.254.169.254/32', 'events' => array( N_PAID_TYPE ) ) );
		$mt = $page->dispatch( 'test', 'n8n-meta', array() );
		n_check( 'n28', 'metadata address 169.254.169.254 refused even with an exception', 'test_failed' === ( $mt['wcp_notice'] ?? '' ) || 'invalid' === ( $m['wcp_notice'] ?? '' ), array( $m, $mt ) );
		$p  = $page->dispatch( 'create', '', array_merge( $n_base, array( 'name' => 'anderer Port', 'key' => 'n8n-port', 'direction' => 'outbound', 'base_url' => 'http://127.0.0.1:8087', 'url' => '/wp-json/', 'exception_host' => '127.0.0.1', 'exception_port' => 5678, 'events' => array( N_PAID_TYPE ) ) ) );
		$pt = $page->dispatch( 'test', 'n8n-port', array() );
		n_check( 'n29', 'exception for 127.0.0.1:5678 does not open another port of the same host (8087): refused before any request', 'invalid' === ( $p['wcp_notice'] ?? '' ) || ( 'test_failed' === ( $pt['wcp_notice'] ?? '' ) && str_starts_with( (string) ( $pt['wcp_code'] ?? '' ), 'target:' ) ), array( $p, $pt ) );
		break;

	case 'license_off':
		n_pro()->license()->deactivate();
		$queued = $f->outbox()->enqueue( n_envelope( N_PAID_TYPE ) );
		$r      = n_via_n8n( array( 'key' => 'n8n-in', 'trigger' => 'crm_lead', 'secret' => (string) n_state( 'inbound_secret' ), 'data' => array( 'lead_id' => 21 ) ) );
		n_check( 'n30', 'license deactivated: nothing new queued for n8n, inbound from n8n refused (403 license_inactive)', 0 === $queued && 403 === $r['status'] && 'license_inactive' === n_reason( $r ), array( 'queued' => $queued, 'inbound' => $r ) );
		$a = n_pro()->license()->activate( (string) n_state( 'license_key' ) );
		n_check( 'n30b', 'license activated again', ! is_wp_error( $a ) && n_pro()->is_entitled( 'workflow.webhooks' ), is_wp_error( $a ) ? $a->get_error_code() : 'ok' );
		break;

	case 'down':
		// The shell stopped n8n: nothing listens on 127.0.0.1:5678.
		$rows = n_send( n_envelope( N_PAID_TYPE ) );
		$sum  = n_row_summary( $rows );
		n_check( 'n31', 'n8n stopped (not reachable): temporary, outbox retry_wait with a transport code, retried later', 1 === count( $sum ) && 1 === preg_match( '/^n8n-paid:retry_wait:transport:[a-z0-9_]+:1$/', $sum[0] ), $sum );
		$row = $rows[0] ?? array();
		n_check( 'n32', 'error text without URL, header or secret (fixed code only)', isset( $row['last_code'] ) && ! str_contains( (string) wp_json_encode( $row ), '127.0.0.1' ) && ! str_contains( (string) wp_json_encode( $row ), (string) n_state( 'header_secret' ) ), $row['last_code'] ?? '' );
		break;

	case 'log':
		$log  = (string) @file_get_contents( getenv( 'E2E_DEBUG_LOG' ) ?: dirname( N_STATE ) . '/debug-n8n.log' );
		$hits = array_values( array_filter( explode( "\n", $log ), static fn( $l ) => 1 === preg_match( '/(Fatal|Warning|Deprecated).*(workflow-captain|captain-pro)/i', $l ) ) );
		n_check( 'n33', 'no PHP fatal, warning or deprecation of Workflow Captain or Pro in debug-n8n.log', array() === $hits, array_slice( $hits, 0, 3 ) );
		break;

	default:
		n_check( '?', 'unknown section ' . $n_section, false );
}
