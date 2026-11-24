<?php
/**
 * Suite checks: Workflow Captain + Workflow Captain Pro + Pass, Voucher, Affiliate Captain + WP License Captain on a real
 * WordPress + WooCommerce site (not shipped: /bin is export-ignore).
 *
 *     wpsuite eval-file bin/e2e/suite/checks.php <section> [param]
 *
 * Each check appends one JSON line to $E2E_RESULTS (default /home/user/wp-e2e/logs/suite-results.jsonl) and prints
 * PASS/FAIL. State shared between sections and sites lives in /home/user/wp-e2e/logs/suite-state.json. Runs as part of
 * bin/e2e/suite/run-suite.sh, which explains the order.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

$s_section = $args[0] ?? '';
$s_param   = $args[1] ?? '';

// E2E_STATE: own state file of a separate run (bin/e2e/suite/ms-providers.sh).
define( 'S_STATE', getenv( 'E2E_STATE' ) ?: '/home/user/wp-e2e/logs/suite-state.json' );

/** Records one result line. */
function s_check( string $id, string $name, bool $ok, $detail = '' ): bool {
	$file = getenv( 'E2E_RESULTS' ) ?: '/home/user/wp-e2e/logs/suite-results.jsonl';
	$row  = array(
		'id'     => $id,
		'name'   => $name,
		'result' => $ok ? 'PASS' : 'FAIL',
		'detail' => is_string( $detail ) ? $detail : wp_json_encode( $detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
	);
	$row['detail'] = mb_substr( (string) $row['detail'], 0, 600 );
	file_put_contents( $file, wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND );
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $id . ' - ' . $name . ( '' !== $row['detail'] ? ' :: ' . $row['detail'] : '' ) . "\n";
	return $ok;
}

function s_state( ?string $key = null, $value = null ) {
	$state = is_readable( S_STATE ) ? (array) json_decode( (string) file_get_contents( S_STATE ), true ) : array();
	if ( null === $key ) {
		return $state;
	}
	if ( func_num_args() < 2 ) {
		return $state[ $key ] ?? null;
	}
	$state[ $key ] = $value;
	file_put_contents( S_STATE, wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	return $value;
}

function s_admin_id(): int {
	$u = get_user_by( 'login', 'admin' );
	return $u ? (int) $u->ID : 1;
}

/** Runs the worker of Workflow Captain like the scheduled action does (bounded). */
function s_work( int $passes = 6 ): void {
	for ( $i = 0; $i < $passes; $i++ ) {
		Plugin::instance()->run_worker();
	}
}

function s_events( string $type_like, string $where = '' ): array {
	global $wpdb;
	$sql = $wpdb->prepare( "SELECT id, type, state, last_error, subject_id, envelope FROM {$wpdb->prefix}workflow_captain_events WHERE type LIKE %s", $type_like );
	return (array) $wpdb->get_results( $sql . ( '' !== $where ? ' AND ' . $where : '' ) . ' ORDER BY id', ARRAY_A ); // phpcs:ignore
}

function s_types_seen( string $prefix ): array {
	return array_values( array_unique( array_column( s_events( $prefix . '%' ), 'type' ) ) );
}

function s_env( array $row ): array {
	return (array) json_decode( (string) $row['envelope'], true );
}

function s_registered(): array {
	return (array) ( Plugin::instance()->diagnostics()['adapters']['registered'] ?? array() );
}

/** Saves and publishes a linear workflow trigger -> actions -> end (as bin/e2e/checks.php); returns its ID. */
function s_publish( string $name, array $trigger, array $actions, bool $quiet = false ): int {
	$nodes = array( array( 'id' => 't', 'type' => 'trigger' ) + $trigger );
	$edges = array();
	$prev  = 't';
	foreach ( $actions as $i => $a ) {
		$id      = 'a' . $i;
		$nodes[] = array( 'id' => $id, 'type' => 'action', 'action' => $a[0], 'config' => $a[1] );
		$edges[] = array( 'from' => $prev, 'to' => $id );
		$prev    = $id;
	}
	$nodes[] = array( 'id' => 'end', 'type' => 'end' );
	$edges[] = array( 'from' => $prev, 'to' => 'end' );
	$wfs     = Plugin::instance()->workflows();
	list( $id, $violations ) = $wfs->save( null, $name, array( 'nodes' => $nodes, 'edges' => $edges ) );
	$pub = $id ? $wfs->publish( (int) $id, s_admin_id() ) : array( 'not_saved' );
	if ( array() !== $violations || array() !== $pub ) {
		$quiet || s_check( 'publish', 'publish ' . $name, false, array_merge( (array) $violations, (array) $pub ) );
		return 0;
	}
	return (int) $id;
}

function s_runs( int $workflow_id ): array {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, state, last_error FROM {$wpdb->prefix}workflow_captain_runs WHERE workflow_id = %d ORDER BY id", $workflow_id ), ARRAY_A );
}

function s_steps( int $run_id ): array {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}workflow_captain_steps WHERE run_id = %d ORDER BY id", $run_id ), ARRAY_A );
}

function s_pause_all_workflows(): void {
	global $wpdb;
	foreach ( (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}workflow_captain_workflows WHERE status = 'active'" ) as $wf ) {
		Plugin::instance()->workflows()->pause( (int) $wf );
	}
}

/** Pro add-on object of a product, or null when the add-on is not loaded. */
function s_addon( string $ns ) {
	$f = $ns . '\\addon';
	return function_exists( $f ) ? $f() : null;
}

function s_pro() {
	return s_addon( 'WorkflowCaptainPro' );
}

/** Status lines of the suite integrations (what the license screen shows). */
function s_suite_lines(): array {
	$pro = s_pro();
	if ( ! $pro ) {
		return array();
	}
	foreach ( (array) $pro->features()->all() as $feature ) {
		$feature = is_array( $feature ) ? ( $feature['feature'] ?? null ) : $feature;
		if ( $feature instanceof \WorkflowCaptainPro\Features\SuiteIntegrations ) {
			return $feature->status_lines();
		}
	}
	return array();
}

// ---- License server (WP License Captain on this site) --------------------------------------------------------------

function s_customer( string $login ): int {
	$u = get_user_by( 'login', $login );
	if ( $u ) {
		return (int) $u->ID;
	}
	$c = new WC_Customer();
	$c->set_username( $login );
	$c->set_email( $login . '@customer.test' );
	$c->set_password( 'customer-e2e-pass' );
	$c->set_billing_first_name( ucfirst( $login ) );
	$c->set_billing_last_name( 'Tester' );
	$c->set_billing_email( $login . '@customer.test' );
	$c->set_billing_country( 'DE' );
	$c->set_billing_city( 'Berlin' );
	return (int) $c->save();
}

/** A WooCommerce product mapped to a license plan; returns the product ID. */
function s_plan( string $name, string $product_key, string $plan_key, array $entitlements, int $limit ): int {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( '49.00' );
	$p->set_virtual( true );
	$p->set_status( 'publish' );
	$id  = $p->save();
	$ent = array();
	foreach ( $entitlements as $k ) {
		$ent[ $k ] = true;
	}
	$ok = \WPLC\Catalog\Catalog::save_mapping( $id, 0, array( 'product_key' => $product_key, 'plan_key' => $plan_key, 'billing_interval' => 'year', 'live_site_limit' => $limit, 'support_level' => 'standard', 'entitlements' => $ent ) );
	s_check( 's0/' . $plan_key, 'license plan ' . $plan_key . ' (' . $product_key . ') mapped to a product', $ok, $ok ? implode( ',', $entitlements ) . ' limit ' . $limit : \WPLC\Catalog\Catalog::last_error() );
	return $id;
}

/** Buys a product as customer through WooCommerce CRUD (paid); returns the license row. */
function s_buy( int $customer_id, int $product_id ): array {
	global $wpdb;
	$c     = new WC_Customer( $customer_id );
	$order = wc_create_order( array( 'customer_id' => $customer_id ) );
	$order->add_product( wc_get_product( $product_id ), 1 );
	$order->set_address( $c->get_billing(), 'billing' );
	$order->set_payment_method( 'bacs' );
	$order->calculate_totals();
	$order->save();
	$order->payment_complete( 'e2e-' . $order->get_id() );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wplc_licenses WHERE source_order_id = %d ORDER BY id DESC LIMIT 1", $order->get_id() ), ARRAY_A );
	return is_array( $row ) ? $row : array();
}

function s_license_key( array $license ): string {
	return \WPLC\License\LicenseKey::for_uuid( (string) $license['license_uuid'], (string) $license['license_key_secret_id'] );
}

/** TIME SIMULATION (as tests/e2e/lib.php of wp-license): the paid period of a license ended $days ago. */
function s_move_paid_until( int $license_id, int $days ): void {
	global $wpdb;
	$t          = $wpdb->prefix . 'wplc_';
	$paid_until = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	$wpdb->query( $wpdb->prepare( "UPDATE {$t}licenses SET paid_until = %s, next_payment_at = IF( renewal_enabled = 1, %s, NULL ) WHERE id = %d", $paid_until, $paid_until, $license_id ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$t}entitlements SET ends_at = %s WHERE license_id = %d", $paid_until, $license_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t}renewals WHERE license_id = %d AND status <> 'paid'", $license_id ) );
}

function s_license_row( int $id ): array {
	global $wpdb;
	return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wplc_licenses WHERE id = %d", $id ), ARRAY_A );
}

/** Activates the license of a Pro add-on against the shop; returns 'ok' or the error code. */
function s_activate( $addon, string $key ): string {
	if ( ! $addon ) {
		return 'addon_missing';
	}
	$r = $addon->license()->activate( $key );
	return is_wp_error( $r ) ? $r->get_error_code() . ': ' . $r->get_error_message() : 'ok';
}

function s_refresh( $addon ): string {
	$r = $addon->license()->refresh( true );
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
}

// ---- Products of the suite --------------------------------------------------------------------------------------

/** Pass Captain: event, occurrence and ticket type (as tests/e2e/helper.php of wp-passmaster), plus a linked WC product. */
function s_pass_ticket_product(): int {
	global $wpdb;
	$p   = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Suite Konzert', 'slug' => 'suite-' . bin2hex( random_bytes( 4 ) ), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Berlin', 'location' => 'Halle', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Eintritt', 'price_minor' => 2500, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
	$type    = (int) $wpdb->insert_id;
	$product = new WC_Product_Simple();
	$product->set_name( 'Suite Konzertticket' );
	$product->set_regular_price( '25.00' );
	$product->set_virtual( true );
	$product->set_status( 'publish' );
	$product->update_meta_data( \PassCaptain\Integrations\WooCommerce\OrderAdapter::PRODUCT_META, $type );
	return (int) $product->save();
}

/** Voucher Captain: a WC product that sells a voucher. */
function s_voucher_product(): int {
	$product = new WC_Product_Simple();
	$product->set_name( 'Suite Gutschein 30' );
	$product->set_regular_price( '30.00' );
	$product->set_virtual( true );
	$product->set_status( 'publish' );
	$product->update_meta_data( '_vcap_enabled', 'yes' );
	return (int) $product->save();
}

function s_plain_product(): int {
	$product = new WC_Product_Simple();
	$product->set_name( 'Suite Buch' );
	$product->set_regular_price( '20.00' );
	$product->set_status( 'publish' );
	return (int) $product->save();
}

/** Affiliate Captain: an active partner. */
function s_affiliate(): int {
	$existing = s_state( 'affiliate_id' );
	if ( $existing && \WPAffiliateCaptain\Affiliates\AffiliateRepository::find( (int) $existing ) ) {
		return (int) $existing;
	}
	$id = \WPAffiliateCaptain\Affiliates\AffiliateRepository::create( array( 'name' => 'Suite Partner', 'email' => 'partner-' . wp_generate_password( 4, false, false ) . '@partner.test', 'status' => 'active' ) );
	return is_wp_error( $id ) ? 0 : (int) s_state( 'affiliate_id', (int) $id );
}

/**
 * A paid WooCommerce order through the classic checkout hooks (Pass Captain reserves on
 * woocommerce_checkout_order_processed), optionally attributed to a partner by hand.
 */
function s_wc_order( array $product_ids, int $affiliate = 0, int $customer = 0 ): \WC_Order {
	$order = wc_create_order( array( 'customer_id' => $customer, 'status' => 'pending' ) );
	foreach ( $product_ids as $pid ) {
		$order->add_product( wc_get_product( $pid ), 1 );
	}
	$order->set_billing_email( 'suite-buyer@customer.test' );
	$order->set_billing_first_name( 'Suite' );
	$order->set_billing_last_name( 'Kaeufer' );
	$order->set_billing_country( 'DE' );
	$order->set_payment_method( 'bacs' );
	$order->set_currency( 'EUR' );
	if ( $affiliate ) {
		$order->update_meta_data( \WPAffiliateCaptain\Integrations\WooCommerce::META_MANUAL, $affiliate );
	}
	$order->calculate_totals();
	$order->save();
	do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );
	$order = wc_get_order( $order->get_id() );
	$order->payment_complete( 'suite-' . $order->get_id() );
	return wc_get_order( $order->get_id() );
}

/** Runs due Action Scheduler actions of the product plugins (vouchers, pass jobs) a few times. */
function s_run_as( int $rounds = 3 ): void {
	if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
		return;
	}
	for ( $i = 0; $i < $rounds; $i++ ) {
		\ActionScheduler_QueueRunner::instance()->run( 'Suite E2E' );
	}
}

require __DIR__ . '/sections.php';
