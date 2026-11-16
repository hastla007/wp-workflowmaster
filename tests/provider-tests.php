<?php
/**
 * Tests of the built-in WordPress and WooCommerce adapters (src/Providers, F-20, SPEC-suite-events 4.7; included by
 * tests/run.php). WordPress and WooCommerce are replaced by the minimal stubs below.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\AdapterKit\AdapterConformance;
use WorkflowCaptain\Envelope\Validator;
use WorkflowCaptain\Providers\CoreAdapter;
use WorkflowCaptain\Providers\WooCommerceAdapter;
use WorkflowCaptain\Providers\WordPressAdapter;
use WorkflowCaptain\Store\EventStore;

require_once dirname( __DIR__ ) . '/tests/adapter-kit/AdapterConformance.php';

// --- Stubs of WordPress and WooCommerce ------------------------------------------------------------------------

$GLOBALS['wfc_current_user'] = 0;
$GLOBALS['wfc_wp_users']     = array();
$GLOBALS['wfc_wp_posts']     = array();
$GLOBALS['wfc_wc_orders']    = array();
$GLOBALS['wfc_wc_decimals']  = 2;

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return $GLOBALS['wfc_current_user'];
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $id ) {
		return $GLOBALS['wfc_wp_users'][ (int) $id ] ?? false;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		return $GLOBALS['wfc_wp_posts'][ (int) $id ] ?? null;
	}
}
if ( ! function_exists( 'is_post_type_viewable' ) ) {
	function is_post_type_viewable( $type ) {
		return in_array( $type, array( 'post', 'page', 'product', 'revision', 'nav_menu_item' ), true );
	}
}
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post ) {
		return 'revision' === $post->post_type ? (int) $post->post_parent : false;
	}
}
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) {
		$order = $GLOBALS['wfc_wc_orders'][ (int) $id ] ?? false;
		if ( $order instanceof Closure ) {
			return $order();
		}
		return $order;
	}
}
if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	function wc_get_price_decimals() {
		return $GLOBALS['wfc_wc_decimals'];
	}
}
if ( ! class_exists( 'WC_Order', false ) ) {
	/** Getters as in WooCommerce; the personal ones exist so a test can prove they are never read into an event. */
	class WC_Order {
		public array $p = array();
		public function __construct( array $p ) {
			$m       = AdapterConformance::MARKERS;
			$this->p = $p + array(
				'id'             => 5123,
				'status'         => 'processing',
				'currency'       => 'eur',
				'total'          => '59.70',
				'item_count'     => 3,
				'created_via'    => 'checkout',
				'customer_id'    => 17,
				'payment_method' => 'stripe',
				'date_paid'      => null,
				'total_refunded' => '0',
				'billing_email'  => $m['email'],
				'first_name'     => $m['name'],
				'customer_note'  => $m['freetext'],
				'coupon_codes'   => array( $m['code'] ),
				'ip'             => '203.0.113.9',
				'phone'          => '+49 30 1234567',
			);
		}
		public function get_id() { return $this->p['id']; }
		public function get_status() { return $this->p['status']; }
		public function get_currency() {
			if ( 'throw' === $this->p['currency'] ) {
				throw new RuntimeException( AdapterConformance::MARKERS['email'] );
			}
			return $this->p['currency'];
		}
		public function get_total() { return $this->p['total']; }
		public function get_item_count() { return $this->p['item_count']; }
		public function get_created_via() { return $this->p['created_via']; }
		public function get_customer_id() { return $this->p['customer_id']; }
		public function get_payment_method() { return $this->p['payment_method']; }
		public function get_date_paid() { return $this->p['date_paid']; }
		public function is_paid() { return in_array( $this->p['status'], array( 'processing', 'completed' ), true ); }
		public function get_total_refunded() { return $this->p['total_refunded']; }
		public function get_billing_email() { return $this->p['billing_email']; }
		public function get_billing_first_name() { return $this->p['first_name']; }
		public function get_customer_note() { return $this->p['customer_note']; }
		public function get_coupon_codes() { return $this->p['coupon_codes']; }
		public function get_customer_ip_address() { return $this->p['ip']; }
		public function get_billing_phone() { return $this->p['phone']; }
	}
}
if ( ! class_exists( 'WC_Order_Refund', false ) ) {
	/** In WooCommerce a refund is not a WC_Order either (both extend WC_Abstract_Order). */
	class WC_Order_Refund {
		public function __construct( public int $id, public int $parent, public string $amount, public string $reason = '' ) {}
		public function get_id() { return $this->id; }
		public function get_parent_id() { return $this->parent; }
		public function get_amount() { return $this->amount; }
		public function get_reason() { return $this->reason; }
	}
}

// --- Helpers ---------------------------------------------------------------------------------------------------

/** A site with both adapters registered; $p->events collects what each adapter handed to the consumer. */
function provider_site(): object {
	$p         = site();
	$p->events = array();
	$sink      = function ( $adapter, array $event ) use ( $p ) {
		$p->events[] = $event;
		return $p->consumer->capture( $adapter, $event );
	};
	$p->wp = new WordPressAdapter( $sink, '6.6' );
	$p->wc = new WooCommerceAdapter( $sink, '8.9.3' );
	$p->registry->add( $p->wp );
	$p->registry->add( $p->wc );
	return $p;
}

function provider_reset(): void {
	$m                           = AdapterConformance::MARKERS;
	$GLOBALS['wfc_current_user'] = 3;
	$GLOBALS['wfc_wc_decimals']  = 2;
	$GLOBALS['wfc_wc_orders']    = array(
		5123 => new WC_Order( array() ),
		5140 => new WC_Order_Refund( 5140, 5123, '19.90', $m['freetext'] ),
	);
	$GLOBALS['wfc_wp_users']     = array(
		42 => (object) array( 'ID' => 42, 'roles' => array( 'customer', 'subscriber' ), 'user_email' => $m['email'], 'display_name' => $m['name'], 'user_login' => $m['name'] ),
	);
	$GLOBALS['wfc_wp_posts']     = array(
		1201 => (object) array( 'ID' => 1201, 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => '3', 'post_parent' => 0, 'post_title' => $m['name'], 'post_content' => $m['freetext'] ),
	);
}

/** Envelopes stored in a provider site, newest first. */
function provider_envelopes( object $p ): array {
	return array_map( static fn( $r ) => $r['envelope'], $p->store->find( array(), 50, 0 ) );
}

/** Data fields of the stored envelope match the catalog: exactly the allowed names, each of the right type. */
function provider_matches_catalog( array $envelope ): bool {
	$entry = AdapterConformance::catalog_entry( $envelope['type'] );
	if ( null === $entry || array_keys( $envelope['data'] ) !== $entry['data_fields'] ) {
		return false;
	}
	if ( $envelope['subject']['type'] !== $entry['subject_type'] || $envelope['subject']['id_kind'] !== $entry['id_kind'] ) {
		return false;
	}
	foreach ( $envelope['data'] as $field => $value ) {
		if ( null === $value ) {
			continue;
		}
		$ok = match ( $entry['data_types'][ $field ] ) {
			'integer'  => is_int( $value ),
			'boolean'  => is_bool( $value ),
			'string'   => is_string( $value ),
			'currency' => is_string( $value ) && 1 === preg_match( '/^[A-Z]{3}$/', $value ),
			default    => false,
		};
		if ( ! $ok ) {
			return false;
		}
	}
	return true;
}

/** No personal data in what was handed over: no "@", no marker, no IP or phone number. */
function provider_clean( array $things ): bool {
	$json = (string) json_encode( $things );
	foreach ( array_merge( array_values( AdapterConformance::MARKERS ), array( '@', '203.0.113.9', '+49', 'Marker' ) ) as $needle ) {
		if ( false !== stripos( $json, $needle ) ) {
			return false;
		}
	}
	return true;
}

// --- All seven types ---------------------------------------------------------------------------------------------

provider_reset();
$p     = provider_site();
$order = $GLOBALS['wfc_wc_orders'][5123];
$p->wc->on_new_order( 5123, $order );
$p->wc->on_payment_complete( 5123 );
$p->wc->on_status_changed( 5123, 'pending', 'processing', $order );
$p->wc->on_refunded( 5123, 5140 );
$p->wc->on_cancelled( 5123, $order, array( 'from' => 'processing', 'to' => 'cancelled' ) );
$p->wp->on_user_register( 42 );
$p->wp->on_transition_post_status( 'publish', 'draft', $GLOBALS['wfc_wp_posts'][1201] );

$stored = array();
foreach ( provider_envelopes( $p ) as $envelope ) {
	$stored[ $envelope['type'] ] = $envelope;
}
$expected = array( 'woocommerce.order.created', 'woocommerce.order.paid', 'woocommerce.order.status_changed', 'woocommerce.order.refunded', 'woocommerce.order.cancelled', 'wordpress.user.registered', 'wordpress.post.published' );
check( 'F-20 seven hooks give seven stored events', 7 === count( $stored ) && array() === array_diff( $expected, array_keys( $stored ) ) );
foreach ( $expected as $type ) {
	$envelope = $stored[ $type ] ?? array();
	check( "F-20 {$type}: valid envelope", array() !== $envelope && Validator::is_valid( $envelope ) );
	check( "F-20 {$type}: exactly the catalog fields with the catalog types", array() !== $envelope && provider_matches_catalog( $envelope ) );
}
foreach ( $p->events as $event ) {
	$fail = AdapterConformance::check_event( 'wordpress' === strstr( $event['type'], '.', true ) ? $p->wp : $p->wc, $event );
	check( 'A-3/A-6 conformance kit passes for ' . $event['type'], array() === $fail );
}
check( 'R-4.9 no personal data in any event (markers, @, IP, phone)', provider_clean( $p->events ) && provider_clean( provider_envelopes( $p ) ) );

$created = $stored['woocommerce.order.created'] ?? array( 'data' => array() );
check(
	'woocommerce.order.created: values from the WC_Order getters',
	array( 'status' => 'processing', 'currency' => 'EUR', 'total_minor' => 5970, 'item_count' => 3, 'created_via' => 'checkout', 'customer_user_id' => 17 ) === $created['data']
);
check( 'woocommerce.order.created: source is woocommerce with its version', array( 'product' => 'woocommerce', 'core_version' => '8.9.3', 'contract_version' => '8.9.3' ) === ( $created['source'] ?? null ) );
check( 'woocommerce.order.created: subject is the order record', array( 'type' => 'order', 'id' => '5123', 'id_kind' => 'record' ) === ( $created['subject'] ?? null ) );
check( 'woocommerce.order.paid: never an actor, even with a signed-in user', ! array_key_exists( 'actor_user_id', $stored['woocommerce.order.paid'] ?? array( 'actor_user_id' => 1 ) ) );
check( 'woocommerce.order.paid: gateway id', 'stripe' === ( $stored['woocommerce.order.paid']['data']['payment_method'] ?? null ) );
check( 'woocommerce.order.status_changed: actor is the signed-in user', 3 === ( $stored['woocommerce.order.status_changed']['actor_user_id'] ?? null ) );
check(
	'woocommerce.order.refunded: refund amounts in minor units',
	array( 'refund_id' => 5140, 'amount_minor' => 1990, 'currency' => 'EUR', 'refunded_total_minor' => 0, 'full_refund' => false ) === ( $stored['woocommerce.order.refunded']['data'] ?? null )
);
check( 'woocommerce.order.cancelled: from_status from the transition', 'processing' === ( $stored['woocommerce.order.cancelled']['data']['from_status'] ?? null ) );
check( 'wordpress.user.registered: first role of the user', array( 'role' => 'customer' ) === ( $stored['wordpress.user.registered']['data'] ?? null ) );
check( 'wordpress.user.registered: source is wordpress with X.Y.Z', array( 'product' => 'wordpress', 'core_version' => '6.6.0', 'contract_version' => '6.6.0' ) === ( $stored['wordpress.user.registered']['source'] ?? null ) );
check( 'wordpress.post.published: fields', array( 'post_type' => 'post', 'author_user_id' => 3, 'previous_status' => 'draft' ) === ( $stored['wordpress.post.published']['data'] ?? null ) );

// Guest order, other price decimals, full refund, currency check.
provider_reset();
$p                          = provider_site();
$GLOBALS['wfc_wc_decimals'] = 0;
$GLOBALS['wfc_wc_orders']   = array(
	77 => new WC_Order( array( 'id' => 77, 'customer_id' => 0, 'currency' => 'JPY', 'total' => '1500', 'total_refunded' => '1500' ) ),
	78 => new WC_Order_Refund( 78, 77, '1500' ),
);
$p->wc->on_new_order( 77 );
$p->wc->on_refunded( 77, 78 );
$by = array();
foreach ( provider_envelopes( $p ) as $envelope ) {
	$by[ $envelope['type'] ] = $envelope;
}
check( 'guest order: customer_user_id is null, never 0', array_key_exists( 'customer_user_id', $by['woocommerce.order.created']['data'] ?? array() ) && null === $by['woocommerce.order.created']['data']['customer_user_id'] );
check( 'price decimals from wc_get_price_decimals()', 1500 === ( $by['woocommerce.order.created']['data']['total_minor'] ?? null ) );
check( 'full refund recognised', true === ( $by['woocommerce.order.refunded']['data']['full_refund'] ?? null ) && Validator::is_valid( $by['woocommerce.order.refunded'] ?? array() ) );

$GLOBALS['wfc_current_user'] = 0;
$p                           = provider_site();
$p->wp->on_user_register( 42 );
check( 'no signed-in user: actor_user_id not set', array() !== provider_envelopes( $p ) && ! array_key_exists( 'actor_user_id', provider_envelopes( $p )[0] ) );

// --- What does not count -----------------------------------------------------------------------------------------

provider_reset();
$p    = provider_site();
$post = static fn( array $o ) => (object) ( $o + array( 'ID' => 1300, 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => 3, 'post_parent' => 0 ) );
$p->wp->on_transition_post_status( 'publish', 'publish', $post( array() ) );
$p->wp->on_transition_post_status( 'draft', 'auto-draft', $post( array() ) );
$p->wp->on_transition_post_status( 'future', 'draft', $post( array() ) );
$p->wp->on_transition_post_status( 'publish', 'draft', $post( array( 'post_type' => 'revision', 'post_parent' => 1201 ) ) );
$p->wp->on_transition_post_status( 'publish', 'draft', $post( array( 'post_type' => 'nav_menu_item' ) ) );
$p->wp->on_transition_post_status( 'publish', 'draft', $post( array( 'post_type' => 'shop_order' ) ) );
$p->wp->on_transition_post_status( 'publish', 'draft', $post( array( 'post_type' => 'wp_template' ) ) );
$p->wp->on_transition_post_status( 'publish', 'draft', null );
check( 'post.published: update of a published post, other statuses, revisions, menu items, non-public types skipped', array() === $p->events );
$p->wp->on_transition_post_status( 'publish', 'auto-draft', $post( array( 'ID' => 1301, 'post_type' => 'page' ) ) );
$p->wp->on_transition_post_status( 'publish', 'future', $post( array( 'ID' => 1302 ) ) );
check( 'post.published: publishing straight from the editor (auto-draft) and a scheduled post count', 2 === count( $p->events ) && 'auto-draft' === $p->events[0]['data']['previous_status'] );
$p->wp->on_user_register( 999 );
$p->wc->on_new_order( 999 );
$p->wc->on_refunded( 5123, 999 );
$p->wc->on_new_order( 5140 );
check( 'unknown user, order or refund, or a refund passed as order: no event', 2 === count( $p->events ) );

// --- Discriminator and idempotency (7.3) -------------------------------------------------------------------------

provider_reset();
$p     = provider_site();
$order = $GLOBALS['wfc_wc_orders'][5123];
$p->wc->on_status_changed( 5123, 'pending', 'on-hold', $order );
$p->wc->on_status_changed( 5123, 'on-hold', 'processing', $order );
check( 'two status changes give two stored events', 2 === count( provider_envelopes( $p ) ) );
check( 'status_changed discriminator is to_status', array( 'on-hold', 'processing' ) === array_column( $p->events, 'discriminator' ) );
$p->wc->on_status_changed( 5123, 'pending', 'processing', $order );
check( 'the same transition again is a duplicate', 2 === count( provider_envelopes( $p ) ) );
$p->wc->on_refunded( 5123, 5140 );
$p->wc->on_refunded( 5123, 5140 );
check( 'the same refund twice gives one event', 1 === count( array_filter( provider_envelopes( $p ), static fn( $e ) => 'woocommerce.order.refunded' === $e['type'] ) ) );
$GLOBALS['wfc_wc_orders'][5141] = new WC_Order_Refund( 5141, 5123, '5.00' );
$p->wc->on_refunded( 5123, 5141 );
check( 'a second refund is a new event', 2 === count( array_filter( provider_envelopes( $p ), static fn( $e ) => 'woocommerce.order.refunded' === $e['type'] ) ) );
$p->wc->on_new_order( 5123, $order );
$p->wc->on_new_order( 5123, $order );
$p->wc->on_payment_complete( 5123 );
$p->wc->on_payment_complete( 5123 );
$p->wp->on_user_register( 42 );
$p->wp->on_user_register( 42 );
check( 'created, paid and user.registered: one event per object', 7 === count( provider_envelopes( $p ) ) );
$fail = AdapterConformance::check_idempotency( $p->events[0], $p->events[0], $p->events[1] );
check( 'A-7 conformance: same action same key, two changes two keys', array() === $fail );

// --- Re-read (R-7.4) --------------------------------------------------------------------------------------------

provider_reset();
$p     = provider_site();
$order = $GLOBALS['wfc_wc_orders'][5123];
$p->wc->on_status_changed( 5123, 'pending', 'processing', $order );
$p->wc->on_refunded( 5123, 5140 );
$p->wp->on_transition_post_status( 'publish', 'draft', $GLOBALS['wfc_wp_posts'][1201] );
$p->wp->on_user_register( 42 );
foreach ( $p->events as $event ) {
	check( 're-read is required for ' . $event['type'], $p->registry->get( strstr( $event['type'], '.', true ) )->needs_reread( $event['type'] ) );
}
$envelopes = array();
foreach ( provider_envelopes( $p ) as $envelope ) {
	$envelopes[ $envelope['type'] ] = $envelope;
}
check( 'reread true while the state holds', $p->wc->reread( $envelopes['woocommerce.order.status_changed'] ) && $p->wc->reread( $envelopes['woocommerce.order.refunded'] ) && $p->wp->reread( $envelopes['wordpress.post.published'] ) && $p->wp->reread( $envelopes['wordpress.user.registered'] ) );
$order->p['status'] = 'completed';
check( 'reread false: order no longer has that status', false === $p->wc->reread( $envelopes['woocommerce.order.status_changed'] ) );
unset( $GLOBALS['wfc_wc_orders'][5140] );
check( 'reread false: refund gone', false === $p->wc->reread( $envelopes['woocommerce.order.refunded'] ) );
$GLOBALS['wfc_wp_posts'][1201]->post_status = 'draft';
check( 'reread false: post no longer published', false === $p->wp->reread( $envelopes['wordpress.post.published'] ) );
unset( $GLOBALS['wfc_wp_users'][42] );
check( 'reread false: user deleted', false === $p->wp->reread( $envelopes['wordpress.user.registered'] ) );
$p->consumer->process();
$states = array_unique( array_column( $p->store->find( array(), 50, 0 ), 'state' ) );
check( 'worker discards all four events after the re-read', array( EventStore::DISCARDED ) === array_values( $states ) );

provider_reset();
$p     = provider_site();
$order = $GLOBALS['wfc_wc_orders'][5123];
$p->wc->on_payment_complete( 5123 );
$p->wc->on_cancelled( 5123, $order, array( 'from' => 'pending' ) );
$p->wc->on_new_order( 5123, $order );
$order->p['status'] = 'cancelled';
$p->consumer->process();
$rows = array();
foreach ( $p->store->find( array(), 50, 0 ) as $row ) {
	$rows[ $row['type'] ] = $row['state'];
}
check( 'reread: paid without a paid date and no longer paid is discarded', EventStore::DISCARDED === ( $rows['woocommerce.order.paid'] ?? '' ) );
check( 'reread: cancelled order still cancelled, created order still exists → done', EventStore::DONE === ( $rows['woocommerce.order.cancelled'] ?? '' ) && EventStore::DONE === ( $rows['woocommerce.order.created'] ?? '' ) );
$order->p['date_paid'] = '2026-11-16 18:02:11';
check( 'reread: a paid date keeps order.paid true', true === $p->wc->reread( array( 'type' => 'woocommerce.order.paid', 'subject' => array( 'id' => '5123' ), 'data' => array() ) ) );
check( 'reread: unknown type or missing order is false', false === $p->wc->reread( array( 'type' => 'woocommerce.order.other', 'subject' => array( 'id' => '5123' ) ) ) && false === $p->wc->reread( array( 'type' => 'woocommerce.order.created', 'subject' => array( 'id' => '1' ) ) ) );

// --- Exceptions never reach WordPress or WooCommerce (R-7.6, A-5) -----------------------------------------------

provider_reset();
$GLOBALS['wfc_wc_orders'][5123]->p['currency'] = 'throw';
$GLOBALS['wfc_wc_orders'][600]                 = static function () {
	throw new LogicException( 'database gone' );
};
$throwing = new WooCommerceAdapter(
	static function () {
		throw new RuntimeException( 'sink broken' );
	},
	'9.0.0'
);
$p        = provider_site();
$fail     = array_merge(
	AdapterConformance::check_callback_isolated( array( $p->wc, 'on_new_order' ), array( 5123 ) ),
	AdapterConformance::check_callback_isolated( array( $p->wc, 'on_payment_complete' ), array( 600 ) ),
	AdapterConformance::check_callback_isolated( array( $p->wc, 'on_status_changed' ), array( 5123, 'a', 'b', null ) ),
	AdapterConformance::check_callback_isolated( array( $p->wc, 'on_refunded' ), array( 600, 5140 ) ),
	AdapterConformance::check_callback_isolated( array( $p->wc, 'on_cancelled' ), array( 5123, null, 'not-an-array' ) ),
	AdapterConformance::check_callback_isolated( array( $p->wp, 'on_transition_post_status' ), array( 'publish', 'draft', (object) array( 'ID' => 5, 'post_type' => new stdClass() ) ) ),
	AdapterConformance::check_callback_isolated( array( $throwing, 'on_status_changed' ), array( 5123, 'a', 'b' ) )
);
$GLOBALS['wfc_wc_orders'][5123]->p['currency'] = 'EUR';
$fail = array_merge( $fail, AdapterConformance::check_callback_isolated( array( $throwing, 'on_new_order' ), array( 5123 ) ) );
check( 'A-5 exceptions in getters, wc_get_order() and the sink do not propagate', array() === $fail );
check( 'A-5 nothing stored from a broken order', array() === provider_envelopes( $p ) );
$GLOBALS['wfc_wc_orders'][5123]->p['currency'] = 'Euro';
$p->wc->on_new_order( 5123 );
check( 'a currency that is not an ISO code is not passed on', array() === provider_envelopes( $p ) );

// --- Registration ------------------------------------------------------------------------------------------------

$noop = static function () {};
check( 'version normalised to X.Y.Z', '6.6.0' === CoreAdapter::normalize_version( '6.6' ) && '8.9.3' === CoreAdapter::normalize_version( '8.9.3-beta.1' ) && '6.7.0' === CoreAdapter::normalize_version( '6.7-RC1-59000' ) && '' === CoreAdapter::normalize_version( 'trunk' ) );
check( 'WooCommerce adapter not available without WooCommerce', false === ( new WooCommerceAdapter( $noop ) )->available() );
check( 'WooCommerce adapter not available with WooCommerce 8.1', false === ( new WooCommerceAdapter( $noop, '8.1.9' ) )->available() );
check( 'WooCommerce adapter available with WooCommerce 8.2', true === ( new WooCommerceAdapter( $noop, '8.2' ) )->available() );
check( 'WordPress adapter not available with WordPress 6.3', false === ( new WordPressAdapter( $noop, '6.3.5' ) )->available() );
check( 'WordPress adapter available with WordPress 6.4', true === ( new WordPressAdapter( $noop, '6.4' ) )->available() );

$GLOBALS['wfc_test_filters'] = array();
$registry                    = new AdapterRegistry();
$registered                  = CoreAdapter::register( $registry, array( new WordPressAdapter( $noop, '6.6.2' ), new WooCommerceAdapter( $noop ) ) );
check( 'without WooCommerce only the WordPress adapter registers', array( 'wordpress' ) === $registered && null === $registry->get( 'woocommerce' ) );
check( 'no WooCommerce hook attached without WooCommerce', array( 'user_register', 'transition_post_status' ) === array_keys( $GLOBALS['wfc_test_filters'] ) );
$registered = CoreAdapter::register( new AdapterRegistry(), array( new WooCommerceAdapter( $noop, '8.1.0' ) ) );
check( 'too old WooCommerce does not register', array() === $registered );
$GLOBALS['wfc_test_filters'] = array();
$registry                    = new AdapterRegistry();
$registered                  = CoreAdapter::register( $registry, array( new WordPressAdapter( $noop, '6.6.2' ), new WooCommerceAdapter( $noop, '8.9.3' ) ) );
$hooks                       = array_keys( $GLOBALS['wfc_test_filters'] );
check( 'both register with WooCommerce 8.9', array( 'wordpress', 'woocommerce' ) === $registered && '8.9.3' === $registry->get( 'woocommerce' )->contract_version() );
check( 'exactly the seven hooks of 4.7 are attached', array( 'user_register', 'transition_post_status', 'woocommerce_new_order', 'woocommerce_payment_complete', 'woocommerce_order_status_changed', 'woocommerce_order_refunded', 'woocommerce_order_status_cancelled' ) === $hooks );
check( 'A-4 conformance: hooks are the catalog hooks', array() === array_merge( AdapterConformance::check_hooks( 'wordpress', array_slice( $hooks, 0, 2 ), array() ), AdapterConformance::check_hooks( 'woocommerce', array_slice( $hooks, 2 ), array() ) ) );
check( 'R-7.5 hook priority above 10', CoreAdapter::PRIORITY > 10 );
check( 'a second registration of the same product is refused', array() === CoreAdapter::register( $registry, array( new WordPressAdapter( $noop, '6.6.2' ) ) ) );
$GLOBALS['wfc_test_filters'] = array();
