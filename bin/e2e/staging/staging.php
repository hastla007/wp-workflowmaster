<?php
/**
 * Checks of the staging run that need PHP on the site (not shipped: /bin is export-ignore). Uses the helpers of
 * bin/e2e/checks.php. Results go to $E2E_RESULTS.
 *
 *     wp eval-file bin/e2e/staging/staging.php <section> [params]
 *
 * Sections: co_setup (product, coupon, payment methods, workflows, both checkout pages), co_mode <blocks|classic>,
 * co_check <order-id> <label> <expected-method> <expected-created-via>, co_paid_check, co_pii, co_drafts.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;
define( 'E2E_LIB_ONLY', true );
require dirname( __DIR__ ) . '/checks.php';

$st_section = $args[0] ?? '';
$st_params  = array_slice( $args, 1 );
$st_state   = E2E_LOG_DIR . '/checkout-state.json';

/** Event rows of one order, oldest first. */
function st_order_events( string $order_id ): array {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, type, state, last_error, idempotency_key, envelope FROM {$wpdb->prefix}workflow_captain_events WHERE subject_type = 'order' AND subject_id = %s ORDER BY id", $order_id ), ARRAY_A );
}

/**
 * Data of an event. A processed event keeps no data in its row (the plugin empties it); then the data comes from the
 * context of a run it started, if any.
 */
function st_event_data( array $e ): array {
	global $wpdb;
	$env = json_decode( (string) $e['envelope'], true );
	if ( ! empty( $env['data'] ) ) {
		return $env['data'];
	}
	$ctx = $wpdb->get_var( $wpdb->prepare( "SELECT context FROM {$wpdb->prefix}workflow_captain_runs WHERE event_key = %s ORDER BY id LIMIT 1", (string) $e['idempotency_key'] ) );
	$ctx = $ctx ? json_decode( (string) $ctx, true ) : null;
	return is_array( $ctx['data'] ?? null ) ? $ctx['data'] : array();
}

/** Runs of the workflows of this check for one order. */
function st_order_runs( array $workflows, string $order_id ): array {
	global $wpdb;
	$out = array();
	foreach ( $workflows as $key => $wf ) {
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.id, r.state FROM {$wpdb->prefix}workflow_captain_runs r JOIN {$wpdb->prefix}workflow_captain_events e ON e.idempotency_key = r.event_key WHERE r.workflow_id = %d AND e.subject_type = 'order' AND e.subject_id = %s ORDER BY r.id", (int) $wf, $order_id ), ARRAY_A );
		$out[ $key ] = array_map( static fn( $r ) => $r['state'], $rows );
	}
	return $out;
}

switch ( $st_section ) {

	case 'co_setup':
		global $wpdb;
		foreach ( (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}workflow_captain_workflows WHERE status = 'active'" ) as $old ) {
			Plugin::instance()->workflows()->pause( (int) $old );
		}
		// Payment methods of the check: bank transfer and cash on delivery (both built into WooCommerce).
		foreach ( array( 'bacs', 'cod' ) as $gw ) {
			$s            = (array) get_option( "woocommerce_{$gw}_settings", array() );
			$s['enabled'] = 'yes';
			update_option( "woocommerce_{$gw}_settings", $s );
		}
		update_option( 'woocommerce_coming_soon', 'no' );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_default_country', 'DE:BE' );
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'DE' ) );
		update_option( 'woocommerce_calc_taxes', 'no' );
		// A physical product with free shipping to Germany, so the checkout asks for an address (cash on delivery).
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'Deutschland' );
		$zone->add_location( 'DE', 'country' );
		$zone->save();
		$zone->add_shipping_method( 'free_shipping' );
		$product = new \WC_Product_Simple();
		$product->set_name( 'Segelkurs Staging' );
		$product->set_regular_price( '39.90' );
		$product->set_status( 'publish' );
		$product->save();
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'gratis100' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 100 );
		$coupon->set_free_shipping( true );
		$coupon->save();
		$wf = array(
			'created' => e2e_publish( 'Checkout erstellt', array( 'event' => 'woocommerce.order.created' ), array( array( 'workflow.tag.set', array( 'tag' => 'co-erstellt' ) ) ) ),
			'status'  => e2e_publish( 'Checkout Status', array( 'event' => 'woocommerce.order.status_changed' ), array( array( 'workflow.note.add', array( 'text' => 'Status {{data.from_status}} -> {{data.to_status}}' ) ) ) ),
			'paid'    => e2e_publish(
				'Checkout bezahlt',
				array( 'event' => 'woocommerce.order.paid' ),
				array(
					array( 'notify.admin', array( 'to' => 'site_admin', 'subject' => 'Checkout {{subject.id}} bezahlt: {{data.total_minor}} {{data.currency}} ({{data.payment_method}})', 'message' => 'Artikel {{data.item_count}}' ) ),
					array( 'workflow.tag.set', array( 'tag' => 'co-bezahlt' ) ),
				)
			),
		);
		// The block checkout page WooCommerce created on activation, and a second page with the classic shortcode.
		$blocks  = (int) get_option( 'woocommerce_checkout_page_id' );
		$classic = wp_insert_post( array( 'post_title' => 'Kasse klassisch', 'post_name' => 'kasse-klassisch', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' ) );
		$state   = array( 'workflows' => $wf, 'product' => $product->get_id(), 'blocks_page' => $blocks, 'classic_page' => $classic, 'cart_page' => (int) get_option( 'woocommerce_cart_page_id' ) );
		file_put_contents( $st_state, wp_json_encode( $state ) );
		$content = (string) get_post_field( 'post_content', $blocks );
		e2e_check( 'co0', 'setup: WooCommerce created the checkout page with the Checkout block; bank transfer and cash on delivery enabled; three workflows published', str_contains( $content, 'wp:woocommerce/checkout' ) && ! in_array( 0, $wf, true ) && 'yes' === get_option( 'woocommerce_bacs_settings' )['enabled'] && 'yes' === get_option( 'woocommerce_cod_settings' )['enabled'], $state + array( 'theme' => get_stylesheet() ) );
		echo 'STATE=' . wp_json_encode( $state ) . "\n";
		break;

	case 'co_mode': // blocks|classic: which page WooCommerce treats as the checkout page.
		$state = json_decode( (string) file_get_contents( $st_state ), true );
		update_option( 'woocommerce_checkout_page_id', 'classic' === ( $st_params[0] ?? '' ) ? $state['classic_page'] : $state['blocks_page'] );
		echo 'CHECKOUT=' . get_permalink( (int) get_option( 'woocommerce_checkout_page_id' ) ) . "\n";
		break;

	case 'co_check': // <order> <label> <method> <created_via> <expected statuses, comma separated> [paid:0|1]
		$state = json_decode( (string) file_get_contents( $st_state ), true );
		list( $oid, $label, $method, $via, $statuses ) = array_pad( $st_params, 5, '' );
		$paid  = '1' === ( $st_params[5] ?? '0' );
		$method = 'none' === $method ? '' : $method; // free order: no payment method
		$order = wc_get_order( (int) $oid );
		e2e_check( "co-{$label}-1", "{$label}: order placed in the browser, payment method {$method}, created via {$via}", $order && $method === $order->get_payment_method() && $via === $order->get_created_via(), $order ? array( 'status' => $order->get_status(), 'method' => $order->get_payment_method(), 'via' => $order->get_created_via(), 'total' => $order->get_total() ) : 'no order ' . $oid );
		if ( ! $order ) {
			break;
		}
		// Read the envelopes before the worker runs: processed events keep no data (the plugin empties it).
		$events = st_order_events( (string) $oid );
		$created = array_values( array_filter( $events, static fn( $e ) => 'woocommerce.order.created' === $e['type'] ) );
		$env     = $created ? json_decode( $created[0]['envelope'], true ) : array();
		if ( $created && empty( $env['data'] ) ) { // already processed by a worker in a web request (Action Scheduler)
			$env['data'] = st_event_data( $created[0] );
		}
		e2e_work();
		$events = st_order_events( (string) $oid );
		$types  = array_map( static fn( $e ) => $e['type'] . ( 'woocommerce.order.status_changed' === $e['type'] ? ':' . ( st_event_data( $e )['to_status'] ?? '?' ) : '' ), $events );
		$want   = array( 'woocommerce.order.created' );
		foreach ( array_filter( explode( ',', $statuses ) ) as $to ) {
			$want[] = 'woocommerce.order.status_changed:' . $to;
		}
		if ( $paid ) {
			$want[] = 'woocommerce.order.paid';
		}
		$missing = array_diff( $want, $types );
		$extra   = array_values( array_diff( $types, $want ) );
		// The block checkout moves its draft to "pending" and on to the gateway's status in the same request: the
		// "pending" event is discarded on re-read (R-7.4, finding 3 of the first run). Anything else extra is a failure.
		$extra_ok = array();
		foreach ( $events as $i => $e ) {
			if ( in_array( $types[ $i ], $extra, true ) && 'woocommerce.order.status_changed:pending' === $types[ $i ] && 'discarded' === $e['state'] && 'reread_mismatch' === $e['last_error'] ) {
				$extra_ok[] = $types[ $i ];
			}
		}
		e2e_check( "co-{$label}-2", "{$label}: events " . implode( ', ', $want ) . ' (each once)', array() === $missing && count( $extra ) === count( $extra_ok ) && count( $types ) === count( array_unique( $types ) ), array( 'events' => $types, 'discarded_on_reread' => $extra_ok ) );
		e2e_check( "co-{$label}-3", "{$label}: order.created envelope valid, with status, items, total and channel of the placed order, no customer data", $env && \WorkflowCaptain\Envelope\Validator::is_valid( $env ) && (int) $env['data']['item_count'] >= 1 && (int) round( (float) $order->get_total() * 100 ) === (int) $env['data']['total_minor'] && $via === $env['data']['created_via'], $env['data'] ?? array() );
		$bad = array_values( array_filter( $events, static fn( $e ) => 'done' !== $e['state'] && ! ( 'discarded' === $e['state'] && 'reread_mismatch' === $e['last_error'] && in_array( 'woocommerce.order.status_changed:pending', $extra_ok, true ) ) ) );
		e2e_check( "co-{$label}-4", "{$label}: every expected event processed to done", array() === $bad, array_map( static fn( $e ) => $e['type'] . ' ' . $e['state'] . ' ' . $e['last_error'], $bad ) );
		$runs = st_order_runs( $state['workflows'], (string) $oid );
		$ok   = array( 'succeeded' ) === $runs['created'] && count( $runs['status'] ) === count( array_filter( explode( ',', $statuses ) ) ) && ! in_array( false, array_map( static fn( $s ) => 'succeeded' === $s, $runs['status'] ), true ) && ( $paid ? array( 'succeeded' ) === $runs['paid'] : array() === $runs['paid'] );
		e2e_check( "co-{$label}-5", "{$label}: runs succeeded (created 1, status " . count( array_filter( explode( ',', $statuses ) ) ) . ', paid ' . ( $paid ? 1 : 0 ) . ')', $ok, $runs );
		if ( $paid ) {
			$subject = 'Checkout ' . $oid . ' bezahlt: ' . (int) round( (float) $order->get_total() * 100 ) . ' EUR (' . $method . ')';
			$found   = array_filter( e2e_mails(), static fn( $m ) => $m['subject'] === $subject );
			e2e_check( "co-{$label}-6", "{$label}: paid workflow mail with rendered subject, tag set", 1 === count( $found ) && e2e_tag( 'woocommerce', 'order', (string) $oid, 'co-bezahlt' ), $subject );
		}
		break;

	case 'co_pii': // Personal data typed into the checkout forms must not reach any Workflow Captain table.
		$dump = e2e_dump();
		$hits = array();
		foreach ( E2E_PII as $k => $v ) {
			if ( false !== stripos( $dump, $v ) ) {
				$hits[] = $k;
			}
		}
		foreach ( array( '10115', 'Hinterhof 3', '98765432' ) as $v ) { // postcode, address line 2 and phone typed in the browser
			if ( false !== stripos( $dump, $v ) ) {
				$hits[] = $v;
			}
		}
		e2e_check( 'co-pii', 'no personal data typed into the block and classic checkout (e-mail, names, address, phone, city, postcode) in any workflow_captain_* table', array() === $hits, $hits ? $hits : strlen( $dump ) . ' bytes searched' );
		break;

	case 'co_mark': // Before the abandoned checkout: highest order and event IDs.
		global $wpdb;
		$state            = json_decode( (string) file_get_contents( $st_state ), true );
		$state['mark']    = array(
			'event' => (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}workflow_captain_events" ),
			'order' => (int) ( wc_get_orders( array( 'limit' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'return' => 'ids', 'status' => array_merge( array_keys( wc_get_order_statuses() ), array( 'checkout-draft' ) ) ) )[0] ?? 0 ),
		);
		file_put_contents( $st_state, wp_json_encode( $state ) );
		break;

	case 'co_drafts': // An abandoned block checkout (address typed, never placed) must not start anything.
		global $wpdb;
		$state  = json_decode( (string) file_get_contents( $st_state ), true );
		$events = (array) $wpdb->get_col( $wpdb->prepare( "SELECT type FROM {$wpdb->prefix}workflow_captain_events WHERE id > %d AND type LIKE %s", (int) $state['mark']['event'], 'woocommerce.%' ) );
		$drafts = wc_get_orders( array( 'status' => 'checkout-draft', 'limit' => -1, 'return' => 'ids' ) );
		e2e_check( 'co-draft', 'abandoned block checkout (address typed, order not placed): no WooCommerce event, so no run (WooCommerce 11.3 keeps no draft order for it)', array() === $events, array( 'new_events' => $events, 'draft_orders' => $drafts ) );
		break;

	default:
		echo "unknown section {$st_section}\n";
}
