<?php
/**
 * End-to-end checks against a real WordPress + WooCommerce site (not shipped: /bin is export-ignore).
 *
 *     wp eval-file bin/e2e/checks.php <section> [param]
 *
 *     wp eval-file bin/e2e/checks.php order off   (classic posts storage)
 *
 * Sections: activation, providers, order, wc_more, wp_events, schedule, pause, deadletter_make, deadletter_requeue,
 * deactivate, uninstall, counts (paging and counts on the real database). Each check appends one JSON line to
 * $E2E_RESULTS (default /home/user/wp-e2e/logs/results.jsonl) and prints PASS/FAIL. Runs as part of bin/e2e/run-checks.sh.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Install;
use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

// Log directory of the site under test (bin/e2e/env.sh exports E2E_LOGS).
defined( 'E2E_LOG_DIR' ) || define( 'E2E_LOG_DIR', getenv( 'E2E_LOGS' ) ?: '/home/user/wp-e2e/logs' );

$e2e_section = $args[0] ?? 'activation';
$e2e_param   = $args[1] ?? '';

/** Records one result line. */
function e2e_check( string $id, string $name, bool $ok, $detail = '' ): bool {
	$e2e_label = (string) getenv( 'E2E_LABEL' );
	$file = getenv( 'E2E_RESULTS' ) ?: E2E_LOG_DIR . '/results.jsonl';
	$row  = array(
		'id'     => $id . ( '' !== $e2e_label ? '/' . $e2e_label : '' ),
		'name'   => $name,
		'result' => $ok ? 'PASS' : 'FAIL',
		'detail' => is_string( $detail ) ? $detail : wp_json_encode( $detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
	);
	file_put_contents( $file, wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND );
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $row['id'] . ' - ' . $name . ( '' !== $row['detail'] ? ' :: ' . $row['detail'] : '' ) . "\n";
	return $ok;
}

function e2e_tables(): array {
	global $wpdb;
	// Listed here, not read from the plugin: the checks also run while the plugin is inactive or deleted.
	$out = array( $wpdb->prefix . 'workflow_captain_events', $wpdb->prefix . 'workflow_captain_causes' );
	foreach ( array( 'workflows', 'versions', 'runs', 'steps', 'tags', 'notes', 'sends' ) as $t ) {
		$out[] = $wpdb->prefix . 'workflow_captain_' . $t;
	}
	return $out;
}

function e2e_existing_tables(): array {
	global $wpdb;
	return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'workflow_captain_' ) . '%' ) );
}

/** Runs the worker like the scheduled action does, until nothing is left (bounded). */
function e2e_work( int $passes = 12 ): void {
	for ( $i = 0; $i < $passes; $i++ ) {
		Plugin::instance()->run_worker();
	}
}

function e2e_admin_id(): int {
	$u = get_user_by( 'login', 'admin' );
	return $u ? (int) $u->ID : 1;
}

/** Saves and publishes a linear workflow trigger -> actions -> end; returns its ID (0 on failure). */
function e2e_publish( string $name, array $trigger, array $actions ): int {
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
	$pub = $wfs->publish( (int) $id, e2e_admin_id() );
	if ( array() !== $violations || array() !== $pub ) {
		e2e_check( 'publish', 'publish ' . $name, false, array_merge( $violations, $pub ) );
		return 0;
	}
	return (int) $id;
}

function e2e_runs( int $workflow_id ): array {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, state, last_error, context FROM {$wpdb->prefix}workflow_captain_runs WHERE workflow_id = %d ORDER BY id", $workflow_id ), ARRAY_A );
}

function e2e_tag( string $product, string $type, string $id, string $tag ): bool {
	global $wpdb;
	return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}workflow_captain_tags WHERE product = %s AND subject_type = %s AND subject_id = %s AND tag = %s", $product, $type, $id, $tag ) );
}

function e2e_mails(): array {
	$file = getenv( 'E2E_MAIL_FILE' ) ?: E2E_LOG_DIR . '/mail.jsonl';
	if ( ! is_readable( $file ) ) {
		return array();
	}
	return array_values( array_filter( array_map( static fn( $l ) => json_decode( $l, true ), file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ) ) );
}

/** Text dump of every Workflow Captain table, for the personal-data grep. */
function e2e_dump(): string {
	global $wpdb;
	$out = '';
	foreach ( e2e_existing_tables() as $t ) {
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$t}", ARRAY_A ) as $row ) { // phpcs:ignore
			$out .= implode( "\t", array_map( 'strval', $row ) ) . "\n";
		}
	}
	return $out;
}

function e2e_events( string $type, ?string $subject_id = null ): array {
	global $wpdb;
	$sql = $wpdb->prepare( "SELECT id, type, state, last_error, subject_id, envelope FROM {$wpdb->prefix}workflow_captain_events WHERE type = %s", $type );
	if ( null !== $subject_id ) {
		$sql .= $wpdb->prepare( ' AND subject_id = %s', $subject_id );
	}
	return (array) $wpdb->get_results( $sql . ' ORDER BY id', ARRAY_A ); // phpcs:ignore
}

function e2e_set_hpos( bool $on ): bool {
	$sync = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
	if ( ! $sync->check_orders_table_exists() ) {
		$sync->create_database_tables();
	}
	// Switching the authoritative storage needs both stores in sync: turn on compatibility mode and sync first.
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'yes' );
	for ( $i = 0; $i < 50 && $sync->get_current_orders_pending_sync_count() > 0; $i++ ) {
		$sync->process_batch( $sync->get_next_batch_to_process( 100 ) );
	}
	update_option( 'woocommerce_custom_orders_table_enabled', $on ? 'yes' : 'no' );
	update_option( 'woocommerce_feature_custom_order_tables_enabled', $on ? 'yes' : 'no' );
	return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() === $on;
}

const E2E_PII = array(
	'email'   => 'erika.musterfrau.e2e@example.test',
	'first'   => 'Erikae2e',
	'last'    => 'Musterfraue2e',
	'address' => 'Teststrasse 42e2e',
	'phone'   => '+49301234e2e',
	'city'    => 'Musterstadte2e',
);

function e2e_order( float $price = 49.90 ): \WC_Order {
	$product = new \WC_Product_Simple();
	$product->set_name( 'E2E Produkt ' . wp_generate_password( 4, false ) );
	$product->set_regular_price( (string) $price );
	$product->set_status( 'publish' );
	$product->save();
	$order = wc_create_order( array( 'status' => 'pending' ) );
	$order->add_product( $product, 1 );
	$order->set_billing_email( E2E_PII['email'] );
	$order->set_billing_first_name( E2E_PII['first'] );
	$order->set_billing_last_name( E2E_PII['last'] );
	$order->set_billing_address_1( E2E_PII['address'] );
	$order->set_billing_city( E2E_PII['city'] );
	$order->set_billing_phone( E2E_PII['phone'] );
	$order->set_billing_country( 'DE' );
	$order->set_shipping_first_name( E2E_PII['first'] );
	$order->set_shipping_address_1( E2E_PII['address'] );
	$order->set_payment_method( 'bacs' );
	$order->set_currency( 'EUR' );
	$order->calculate_totals();
	$order->save();
	return $order;
}

// Other check files (bin/e2e/ops.php) load only the helpers above.
if ( defined( 'E2E_LIB_ONLY' ) ) {
	return;
}

// Clean slate: workflows left active by an earlier (aborted) section must not react to this section's events.
if ( in_array( $e2e_section, array( 'order', 'wc_more', 'schedule', 'pause', 'as_prep', 'wcoff_prep' ), true ) ) {
	global $wpdb;
	foreach ( (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}workflow_captain_workflows WHERE status = 'active'" ) as $e2e_wf ) {
		Plugin::instance()->workflows()->pause( (int) $e2e_wf );
	}
}

switch ( $e2e_section ) {

	case 'activation':
		$missing = array_diff( e2e_tables(), e2e_existing_tables() );
		e2e_check( 'a1', 'all workflow_captain_* tables exist', array() === $missing, $missing ? 'missing: ' . implode( ',', $missing ) : count( e2e_tables() ) . ' tables' );
		e2e_check( 'a2', 'schema option set to current version', WORKFLOW_CAPTAIN_DB_VERSION === get_option( Install::DB_OPTION ), (string) get_option( Install::DB_OPTION ) );
		$adapters = (array) get_option( Plugin::ADAPTERS_OPTION, array() );
		e2e_check( 'a3', 'adapters option lists registered providers', in_array( 'wordpress', $adapters, true ) && in_array( 'woocommerce', $adapters, true ), implode( ',', $adapters ) );
		$role    = get_role( 'administrator' );
		$lacking = array_values( array_filter( Install::CAPS, static fn( $c ) => ! $role->has_cap( $c ) ) );
		e2e_check( 'a4', 'administrator has all capabilities', array() === $lacking, $lacking ? 'lacking: ' . implode( ',', $lacking ) : implode( ',', Install::CAPS ) );
		$admin = get_user_by( 'login', 'admin' );
		e2e_check( 'a5', 'admin user can manage_workflow_captain', user_can( $admin, 'manage_workflow_captain' ) );
		$as_pending = function_exists( 'as_get_scheduled_actions' ) ? as_get_scheduled_actions(
			array( 'hook' => Install::CRON_PROCESS, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'group' => 'workflow-captain', 'per_page' => 10 ),
			'ids'
		) : array();
		e2e_check( 'a6', 'worker scheduled in Action Scheduler exactly once', 1 === count( $as_pending ), count( $as_pending ) . ' pending AS actions' );
		e2e_check( 'a7', 'no WP-Cron worker event besides Action Scheduler (no double schedule)', false === wp_next_scheduled( Install::CRON_PROCESS ) );
		e2e_check( 'a8', 'daily maintenance on WP-Cron', false !== wp_next_scheduled( Install::CRON_DAILY ) );
		e2e_check( 'a9', 'scheduler backend reported as action_scheduler', 'action_scheduler' === \WorkflowCaptain\Scheduler::backend() );
		break;

	case 'providers':
		$d = Plugin::instance()->diagnostics();
		$r = (array) $d['adapters']['registered'];
		e2e_check( 'b1', 'wordpress adapter registered (diagnostics)', in_array( 'wordpress', $r, true ), implode( ',', $r ) );
		e2e_check( 'b2', 'woocommerce adapter registered (diagnostics)', in_array( 'woocommerce', $r, true ) );
		e2e_check( 'b3', 'schedule adapter (workflow-captain) registered', in_array( 'workflow-captain', $r, true ) );
		e2e_check( 'b4', 'no refused adapters', array() === (array) $d['adapters']['refused'], $d['adapters']['refused'] );
		$types = Plugin::instance()->available_event_types();
		$want  = array( 'woocommerce.order.paid', 'woocommerce.order.status_changed', 'woocommerce.order.refunded', 'woocommerce.order.cancelled', 'wordpress.user.registered', 'wordpress.post.published', 'workflow.schedule.due' );
		e2e_check( 'b5', 'event types offered by the adapters', array() === array_diff( $want, $types ), array_values( array_diff( $want, $types ) ) );
		break;

	case 'order':
		$hpos = 'off' !== $e2e_param;
		e2e_check( 'c0', 'HPOS ' . ( $hpos ? 'on' : 'off' ), e2e_set_hpos( $hpos ) );
		$mails_before = count( e2e_mails() );
		$tag          = 'e2e-paid-' . ( $hpos ? 'hpos' : 'posts' );
		$wf           = e2e_publish(
			'E2E bezahlt ' . ( $hpos ? 'HPOS' : 'Posts' ),
			array( 'event' => 'woocommerce.order.paid' ),
			array(
				array( 'notify.admin', array( 'to' => 'site_admin', 'subject' => 'E2E Bestellung {{subject.id}} bezahlt: {{data.total_minor}} {{data.currency}}', 'message' => 'Typ {{type}}, Zahlart {{data.payment_method}}, Artikel {{data.item_count}}.' ) ),
				array( 'workflow.tag.set', array( 'tag' => $tag ) ),
			)
		);
		e2e_check( 'c1', 'workflow saved and published via Workflows API', $wf > 0, 'workflow ' . $wf );
		$order = e2e_order();
		$oid   = (string) $order->get_id();
		$storage = \Automattic\WooCommerce\Utilities\OrderUtil::get_order_type( (int) $oid ) ? ( $hpos ? 'wc_orders' : get_post_type( (int) $oid ) ) : '?';
		e2e_check( 'c1b', 'order stored in ' . ( $hpos ? 'HPOS tables' : 'classic posts (shop_order)' ), $hpos ? \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() : 'shop_order' === get_post_type( (int) $oid ), $storage );
		$order->payment_complete( 'e2e-txn-1' );
		$events = e2e_events( 'woocommerce.order.paid', $oid );
		e2e_check( 'c2', 'order.paid event captured on payment_complete()', 1 === count( $events ), count( $events ) . ' events, order ' . $oid );
		$envelope = $events ? json_decode( $events[0]['envelope'], true ) : array();
		e2e_check( 'c3', 'stored envelope valid (schema validator)', \WorkflowCaptain\Envelope\Validator::is_valid( $envelope ), \WorkflowCaptain\Envelope\Validator::violations( $envelope ) );
		e2e_work();
		$events = e2e_events( 'woocommerce.order.paid', $oid );
		e2e_check( 'c4', 'event processed to done', $events && 'done' === $events[0]['state'], $events ? $events[0]['state'] . ' ' . $events[0]['last_error'] : 'none' );
		$runs = e2e_runs( $wf );
		e2e_check( 'c5', 'exactly one run, succeeded', 1 === count( $runs ) && 'succeeded' === $runs[0]['state'], array_map( static fn( $r ) => $r['state'] . ' ' . $r['last_error'], $runs ) );
		$total   = (int) round( (float) $order->get_total() * 100 );
		$subject = 'E2E Bestellung ' . $oid . ' bezahlt: ' . $total . ' EUR';
		$found   = array_values( array_filter( array_slice( e2e_mails(), $mails_before ), static fn( $m ) => $m['subject'] === $subject ) );
		e2e_check( 'c6', 'admin mail captured with rendered subject', 1 === count( $found ), $subject . ( $found ? ' -> ' . $found[0]['to'] . ' / ' . $found[0]['message'] : ' (not found)' ) );
		e2e_check( 'c7', 'message placeholders rendered', $found && str_contains( $found[0]['message'], 'woocommerce.order.paid' ) && str_contains( $found[0]['message'], 'bacs' ) && ! str_contains( $found[0]['message'], '{{' ) );
		e2e_check( 'c8', 'tag set on the order', e2e_tag( 'woocommerce', 'order', $oid, $tag ) );
		$dump = e2e_dump();
		$hits = array_keys( array_filter( E2E_PII, static fn( $v ) => str_contains( $dump, $v ) ) );
		e2e_check( 'c9', 'no personal data (billing email/name/address/phone/city) in any workflow_captain_* table', array() === $hits, $hits ? 'found: ' . implode( ',', $hits ) : strlen( $dump ) . ' bytes scanned' );
		$sc = e2e_events( 'woocommerce.order.status_changed', $oid );
		e2e_check( 'c10', 'status_changed (pending->processing) captured alongside', count( $sc ) >= 1, count( $sc ) . ' events' );
		$order->payment_complete( 'e2e-txn-2' );
		e2e_work( 3 );
		e2e_check( 'c11', 'second payment_complete() does not start a second run', 1 === count( e2e_runs( $wf ) ) && 1 === count( e2e_events( 'woocommerce.order.paid', $oid ) ) );
		Plugin::instance()->workflows()->pause( $wf );
		break;

	case 'wc_more':
		$wf_sc  = e2e_publish( 'E2E Status', array( 'event' => 'woocommerce.order.status_changed' ), array( array( 'workflow.tag.set', array( 'tag' => 'e2e-status' ) ) ) );
		$wf_ref = e2e_publish( 'E2E Erstattet', array( 'event' => 'woocommerce.order.refunded' ), array( array( 'workflow.note.add', array( 'text' => 'Erstattet: {{data.amount_minor}} {{data.currency}}' ) ) ) );
		$wf_can = e2e_publish( 'E2E Storniert', array( 'event' => 'woocommerce.order.cancelled' ), array( array( 'workflow.tag.set', array( 'tag' => 'e2e-cancelled' ) ) ) );
		$order  = e2e_order( 30 );
		$oid    = (string) $order->get_id();
		$order->update_status( 'processing' );
		e2e_work( 3 );
		$order->update_status( 'completed' );
		$refund = wc_create_refund( array( 'order_id' => (int) $oid, 'amount' => 10, 'reason' => 'E2E' ) );
		e2e_check( 'd0', 'wc_create_refund() succeeded', ! is_wp_error( $refund ), is_wp_error( $refund ) ? $refund->get_error_message() : 'refund ' . $refund->get_id() );
		// Observation (spec R-7.4): a status that moved on before the worker ran is discarded on re-read.
		$order3 = e2e_order( 5 );
		$order3->update_status( 'processing' );
		$order3->update_status( 'completed' );
		e2e_work( 3 );
		$ev3 = e2e_events( 'woocommerce.order.status_changed', (string) $order3->get_id() );
		e2e_check( 'd1b', 'status passed before the worker ran is discarded on re-read (R-7.4, by design)', 2 === count( $ev3 ) && 'discarded' === $ev3[0]['state'] && 'reread_mismatch' === $ev3[0]['last_error'] && 'done' === $ev3[1]['state'], array_map( static fn( $e ) => $e['state'] . ' ' . $e['last_error'], $ev3 ) );
		$order2 = e2e_order( 12 );
		$oid2   = (string) $order2->get_id();
		$order2->update_status( 'cancelled' );
		e2e_work();
		$sc = e2e_events( 'woocommerce.order.status_changed', $oid );
		e2e_check( 'd1', 'status_changed events for processing and completed', count( $sc ) >= 2 && array() === array_filter( $sc, static fn( $e ) => 'done' !== $e['state'] ), array_map( static fn( $e ) => $e['state'], $sc ) );
		$runs = e2e_runs( $wf_sc );
		e2e_check( 'd2', 'status_changed workflow runs succeeded and tag set', $runs && array() === array_filter( $runs, static fn( $r ) => 'succeeded' !== $r['state'] ) && e2e_tag( 'woocommerce', 'order', $oid, 'e2e-status' ), array_map( static fn( $r ) => $r['state'], $runs ) );
		$ref = e2e_events( 'woocommerce.order.refunded', $oid );
		e2e_check( 'd3', 'refunded event done', 1 === count( $ref ) && 'done' === $ref[0]['state'], $ref ? $ref[0]['state'] . ' ' . $ref[0]['last_error'] : 'none' );
		global $wpdb;
		$note = $wpdb->get_var( $wpdb->prepare( "SELECT note FROM {$wpdb->prefix}workflow_captain_notes WHERE subject_id = %s AND workflow_id = %d", $oid, $wf_ref ) );
		e2e_check( 'd4', 'refund workflow note with rendered amount', 'Erstattet: 1000 EUR' === $note, (string) $note );
		$can = e2e_events( 'woocommerce.order.cancelled', $oid2 );
		e2e_check( 'd5', 'cancelled event done, workflow tagged order', 1 === count( $can ) && 'done' === $can[0]['state'] && e2e_tag( 'woocommerce', 'order', $oid2, 'e2e-cancelled' ), $can ? $can[0]['state'] : 'none' );

		$wf_u  = e2e_publish( 'E2E Nutzer', array( 'event' => 'wordpress.user.registered' ), array( array( 'workflow.tag.set', array( 'tag' => 'e2e-user' ) ) ) );
		$wf_p  = e2e_publish( 'E2E Beitrag', array( 'event' => 'wordpress.post.published' ), array( array( 'notify.admin', array( 'subject' => 'Beitrag {{subject.id}} veröffentlicht', 'message' => '{{type}}' ) ) ) );
		$login = 'e2euser' . wp_generate_password( 5, false, false );
		$uid   = wp_create_user( $login, wp_generate_password(), $login . '@example.test' );
		$pid   = wp_insert_post( array( 'post_title' => 'E2E Beitrag', 'post_status' => 'publish', 'post_content' => 'Hallo' ) );
		$draft = wp_insert_post( array( 'post_title' => 'E2E Entwurf', 'post_status' => 'draft' ) );
		e2e_work();
		$ue = e2e_events( 'wordpress.user.registered', (string) $uid );
		e2e_check( 'd6', 'wordpress.user.registered captured and done; tag set', 1 === count( $ue ) && 'done' === $ue[0]['state'] && e2e_tag( 'wordpress', 'user', (string) $uid, 'e2e-user' ), $ue ? $ue[0]['state'] . ' ' . $ue[0]['last_error'] : 'none' );
		$pe = e2e_events( 'wordpress.post.published', (string) $pid );
		e2e_check( 'd7', 'wordpress.post.published captured and done', 1 === count( $pe ) && 'done' === $pe[0]['state'], $pe ? $pe[0]['state'] . ' ' . $pe[0]['last_error'] : 'none' );
		e2e_check( 'd8', 'draft post creates no published event', array() === e2e_events( 'wordpress.post.published', (string) $draft ) );
		$pr   = e2e_runs( $wf_p );
		$mail = array_filter( e2e_mails(), static fn( $m ) => 'Beitrag ' . $pid . ' veröffentlicht' === $m['subject'] );
		e2e_check( 'd9', 'post workflow ran and sent mail', 1 === count( $pr ) && 'succeeded' === $pr[0]['state'] && 1 === count( $mail ), $pr ? $pr[0]['state'] : 'no run' );
		$dump = e2e_dump();
		e2e_check( 'd10', 'no user login/email of the new user in workflow tables', ! str_contains( $dump, $login ) );
		foreach ( array( $wf_sc, $wf_ref, $wf_can, $wf_u, $wf_p ) as $id ) {
			Plugin::instance()->workflows()->pause( $id );
		}
		break;

	case 'schedule':
		$tz    = wp_timezone();
		$now   = new DateTimeImmutable( 'now', $tz );
		$time  = $now->modify( '-1 minute' )->format( 'H:i' );
		$wf    = e2e_publish( 'E2E Zeitplan', array( 'event' => 'workflow.schedule.due', 'schedule' => array( 'frequency' => 'daily', 'time' => $time ) ), array( array( 'workflow.tag.set', array( 'tag' => 'e2e-schedule' ) ) ) );
		global $wpdb;
		// Published "earlier today": the version's creation time is the lower bound for the first due time.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}workflow_captain_versions SET created_at = %s WHERE workflow_id = %d", gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ), $wf ) );
		e2e_check( 'f0', 'schedule workflow published (daily ' . $time . ' site time ' . $tz->getName() . ')', $wf > 0 );
		file_put_contents( E2E_LOG_DIR . '/schedule-wf.txt', (string) $wf );
		if ( 'cli' === $e2e_param ) {
			break; // The CLI variant is run by run-checks.sh with "wp workflow-captain process" and checked in schedule_check.
		}
		e2e_work( 3 );
		$runs = e2e_runs( $wf );
		e2e_check( 'f1', 'schedule fires once on worker run', 1 === count( $runs ) && 'succeeded' === $runs[0]['state'], array_map( static fn( $r ) => $r['state'], $runs ) );
		e2e_work( 3 );
		e2e_check( 'f2', 'further worker runs do not fire again', 1 === count( e2e_runs( $wf ) ) );
		Plugin::instance()->workflows()->pause( $wf );
		break;

	case 'schedule_check':
		$wf   = (int) file_get_contents( E2E_LOG_DIR . '/schedule-wf.txt' );
		$runs = e2e_runs( $wf );
		e2e_check( 'f3', 'schedule fires via "wp workflow-captain process" (system cron path)', 1 === count( $runs ), count( $runs ) . ' runs' );
		Plugin::instance()->workflows()->pause( $wf );
		break;

	case 'pause':
		$wf = e2e_publish( 'E2E Pause', array( 'event' => 'wordpress.post.published' ), array( array( 'workflow.tag.set', array( 'tag' => 'e2e-pause' ) ) ) );
		\WorkflowCaptain\EmergencyPause::set( true, e2e_admin_id() );
		e2e_check( 'g0', 'emergency pause on', \WorkflowCaptain\EmergencyPause::active() );
		break;

	case 'pause_check':
		// Second process: the engine reads the pause at construction, as in production.
		global $wpdb;
		$wf  = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}workflow_captain_workflows WHERE name = 'E2E Pause' ORDER BY id DESC LIMIT 1" );
		$pid = wp_insert_post( array( 'post_title' => 'E2E Pause Beitrag', 'post_status' => 'publish' ) );
		e2e_work( 4 );
		$ev = e2e_events( 'wordpress.post.published', (string) $pid );
		e2e_check( 'g1', 'event still stored during pause', 1 === count( $ev ), $ev ? $ev[0]['state'] : 'none' );
		e2e_check( 'g2', 'no step executed during pause (no tag)', ! e2e_tag( 'wordpress', 'post', (string) $pid, 'e2e-pause' ) );
		file_put_contents( E2E_LOG_DIR . '/pause-post.txt', $wf . ' ' . $pid );
		\WorkflowCaptain\EmergencyPause::set( false, e2e_admin_id() );
		break;

	case 'pause_resume':
		list( $wf, $pid ) = array_map( 'intval', explode( ' ', (string) file_get_contents( E2E_LOG_DIR . '/pause-post.txt' ) ) );
		e2e_work( 4 );
		e2e_check( 'g3', 'after lifting the pause the step runs (tag set)', e2e_tag( 'wordpress', 'post', (string) $pid, 'e2e-pause' ), array_map( static fn( $r ) => $r['state'], e2e_runs( $wf ) ) );
		Plugin::instance()->workflows()->pause( $wf );
		break;

	case 'deadletter_make':
		$pid = wp_insert_post( array( 'post_title' => 'E2E DLQ', 'post_status' => 'publish' ) );
		e2e_work( 2 );
		$ev = e2e_events( 'wordpress.post.published', (string) $pid );
		e2e_check( 'g4', 'permanently failing dispatcher moves event to dead_letter', $ev && 'dead_letter' === $ev[0]['state'], $ev ? $ev[0]['state'] . ' ' . $ev[0]['last_error'] : 'none' );
		file_put_contents( E2E_LOG_DIR . '/dlq-event.txt', $ev ? $ev[0]['id'] : '0' );
		break;

	case 'deadletter_check':
		$id = (int) file_get_contents( E2E_LOG_DIR . '/dlq-event.txt' );
		global $wpdb;
		$state = $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$wpdb->prefix}workflow_captain_events WHERE id = %d", $id ) );
		e2e_check( 'g6', 'requeued dead-letter event processed to done', 'done' === $state, (string) $state );
		break;

	case 'deactivated':
		$missing = array_diff( e2e_tables(), e2e_existing_tables() );
		e2e_check( 'j1', 'after deactivation all tables still exist', array() === $missing, $missing );
		global $wpdb;
		e2e_check( 'j2', 'after deactivation workflows still stored', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}workflow_captain_workflows" ) > 0 );
		e2e_check( 'j3', 'after deactivation options and caps kept', false !== get_option( 'workflow_captain_db_version' ) && get_role( 'administrator' )->has_cap( 'manage_workflow_captain' ) );
		$as = as_get_scheduled_actions( array( 'hook' => 'workflow_captain_process_events', 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
		e2e_check( 'j4', 'after deactivation no worker scheduled (AS and WP-Cron)', array() === $as && false === wp_next_scheduled( 'workflow_captain_process_events' ) && false === wp_next_scheduled( 'workflow_captain_daily_maintenance' ), count( $as ) . ' AS' );
		break;

	case 'uninstall_kept':
		global $wpdb;
		$missing = array_diff( e2e_tables(), e2e_existing_tables() );
		e2e_check( 'j5c', 'uninstall without opt-in keeps all tables, options and capabilities (SET-WC-05)', array() === $missing && false !== get_option( 'workflow_captain_db_version' ) && get_role( 'administrator' )->has_cap( 'manage_workflow_captain' ), $missing );
		break;

	case 'uninstalled':
		global $wpdb;
		$left = e2e_existing_tables();
		e2e_check( 'j6', 'after uninstall no workflow_captain_* tables', array() === $left, $left );
		$opts = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'workflow\\_captain%'" );
		e2e_check( 'j7', 'after uninstall no workflow_captain options', array() === $opts, $opts );
		$caps = array();
		foreach ( wp_roles()->role_objects as $name => $role ) {
			foreach ( array( 'manage_workflow_captain', 'edit_workflows', 'activate_workflows', 'view_workflow_logs', 'retry_workflow_runs', 'manage_workflow_integrations' ) as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$caps[] = $name . ':' . $cap;
				}
			}
		}
		e2e_check( 'j8', 'after uninstall no capabilities left on any role', array() === $caps, $caps );
		$as = as_get_scheduled_actions( array( 'hook' => 'workflow_captain_process_events', 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
		e2e_check( 'j9', 'after uninstall nothing scheduled', array() === $as && false === wp_next_scheduled( 'workflow_captain_daily_maintenance' ) );
		break;

	case 'as_prep':
		$wf  = e2e_publish( 'E2E Action Scheduler', array( 'event' => 'wordpress.post.published' ), array( array( 'workflow.tag.set', array( 'tag' => 'e2e-as' ) ) ) );
		$pid = wp_insert_post( array( 'post_title' => 'E2E AS Beitrag', 'post_status' => 'publish' ) );
		file_put_contents( E2E_LOG_DIR . '/as-post.txt', $wf . ' ' . $pid );
		e2e_check( 'a10', 'event waits for the worker (received)', 'received' === ( e2e_events( 'wordpress.post.published', (string) $pid )[0]['state'] ?? '' ) );
		break;

	case 'as_check':
		list( $wf, $pid ) = array_map( 'intval', explode( ' ', (string) file_get_contents( E2E_LOG_DIR . '/as-post.txt' ) ) );
		e2e_check( 'a11', 'the Action Scheduler action runs the worker (event done, step ran)', 'done' === ( e2e_events( 'wordpress.post.published', (string) $pid )[0]['state'] ?? '' ) && e2e_tag( 'wordpress', 'post', (string) $pid, 'e2e-as' ) );
		$pending = as_get_scheduled_actions( array( 'hook' => Install::CRON_PROCESS, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
		e2e_check( 'a12', 'recurring action rescheduled exactly once after running', 1 === count( $pending ), count( $pending ) . ' pending' );
		Plugin::instance()->workflows()->pause( $wf );
		break;

	case 'wcoff_prep':
		$wf    = e2e_publish( 'E2E WC aus', array( 'event' => 'woocommerce.order.paid' ), array( array( 'workflow.tag.set', array( 'tag' => 'e2e-wcoff' ) ) ) );
		$order = e2e_order( 7 );
		$order->payment_complete( 'e2e-wcoff' );
		$oid = (string) $order->get_id();
		file_put_contents( E2E_LOG_DIR . '/wcoff.txt', $wf . ' ' . $oid );
		e2e_check( 't0', 'order.paid event queued (received), worker not yet run', 'received' === ( e2e_events( 'woocommerce.order.paid', $oid )[0]['state'] ?? '' ) );
		break;

	case 'wcoff_check':
		// WooCommerce is deactivated now; this request registered the adapters without it.
		list( $wf, $oid ) = array_map( 'intval', explode( ' ', (string) file_get_contents( E2E_LOG_DIR . '/wcoff.txt' ) ) );
		e2e_work( 2 );
		$ev = e2e_events( 'woocommerce.order.paid', (string) $oid );
		e2e_check( 't1', 'WooCommerce deactivated: queued event is paused (adapter_unregistered / R-10.7), not lost or failed', $ev && 'paused' === $ev[0]['state'], $ev ? $ev[0]['state'] . ' ' . $ev[0]['last_error'] : 'none' );
		e2e_check( 't2', 'WooCommerce deactivated: no run started', array() === e2e_runs( $wf ) );
		e2e_check( 't3', 'diagnostics no longer list woocommerce as registered', ! in_array( 'woocommerce', Plugin::instance()->diagnostics()['adapters']['registered'], true ) );
		wp_set_current_user( e2e_admin_id() );
		ob_start();
		( new \WorkflowCaptain\Admin\ProvidersPage( Plugin::instance() ) )->render();
		$html = (string) ob_get_clean();
		e2e_check( 't4', 'Providers page shows woocommerce as missing with its stored events', 1 === preg_match( '#<code>woocommerce</code></td><td>[^<]*fehlt#u', $html ) || ( str_contains( $html, '<code>woocommerce</code>' ) && str_contains( $html, 'paused' ) ), substr( (string) strstr( $html, '<code>woocommerce</code>' ), 0, 200 ) );
		break;

	case 'wcoff_resume':
		list( $wf, $oid ) = array_map( 'intval', explode( ' ', (string) file_get_contents( E2E_LOG_DIR . '/wcoff.txt' ) ) );
		$ev = e2e_events( 'woocommerce.order.paid', (string) $oid );
		e2e_check( 't5', 'WooCommerce active again: requeue of the paused event accepted', $ev && Plugin::instance()->consumer()->requeue( (int) $ev[0]['id'] ) );
		e2e_work( 4 );
		$ev   = e2e_events( 'woocommerce.order.paid', (string) $oid );
		$runs = e2e_runs( $wf );
		e2e_check( 't6', 'requeued event processed, run succeeded, tag set', $ev && 'done' === $ev[0]['state'] && 1 === count( $runs ) && 'succeeded' === $runs[0]['state'] && e2e_tag( 'woocommerce', 'order', (string) $oid, 'e2e-wcoff' ), ( $ev[0]['state'] ?? '' ) . ' / ' . implode( ',', array_column( $runs, 'state' ) ) );
		Plugin::instance()->workflows()->pause( $wf );
		break;

	case 'refusals':
		wp_set_current_user( e2e_admin_id() );
		ob_start();
		( new \WorkflowCaptain\Admin\ProvidersPage( Plugin::instance() ) )->render();
		$html = (string) ob_get_clean();
		e2e_check( 't7', 'Providers page shows the refused adapter with its reason (invalid_contract_version)', str_contains( $html, '<code>pass-captain</code>' ) && str_contains( $html, 'invalid_contract_version' ), substr( (string) strstr( $html, '<code>pass-captain</code>' ), 0, 160 ) );
		ob_start();
		( new \WorkflowCaptain\Admin\ConnectorsPage( Plugin::instance() ) )->render();
		$html = (string) ob_get_clean();
		e2e_check( 't8', 'Connectors page shows the connector refused for its contract (needs newer core)', str_contains( $html, 'e2e-newer' ), substr( (string) strstr( $html, 'e2e-newer' ), 0, 300 ) );
		break;

	case 'counts':
		// Paging and counts of the admin lists (EventStore::count/find, EngineStore::count_runs/find_runs) against
		// plain SQL on the real database; 23 more published posts make sure several pages exist.
		global $wpdb;
		$wf = e2e_publish( 'E2E Zaehlung', array( 'event' => 'wordpress.post.published' ), array( array( 'workflow.tag.set', array( 'tag' => 'e2e-count' ) ) ) );
		for ( $i = 0; $i < 23; $i++ ) {
			wp_insert_post( array( 'post_title' => 'E2E Zaehlung ' . $i, 'post_status' => 'publish' ) );
		}
		e2e_work();
		Plugin::instance()->workflows()->pause( $wf );
		$events = Plugin::instance()->store();
		$et     = $wpdb->prefix . 'workflow_captain_events';
		$sql_n  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$et}" ); // phpcs:ignore
		$by     = $events->count_by_state();
		$sum    = 0;
		foreach ( array_keys( $by ) as $state ) {
			$sum += $events->count( array( 'state' => $state ) );
		}
		e2e_check( 'n1', 'EventStore::count() equals SQL COUNT(*); the state filters add up; count_by_state() agrees', $sql_n === $events->count( array() ) && $sql_n === $sum && $sql_n === array_sum( $by ), array( 'sql' => $sql_n, 'count' => $events->count( array() ), 'by_state' => $by ) );
		$wc_n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$et} WHERE product = %s", 'woocommerce' ) ); // phpcs:ignore
		e2e_check( 'n2', 'EventStore::count( product ) equals SQL', $wc_n === $events->count( array( 'product' => 'woocommerce' ) ) && $wc_n > 0, $wc_n . ' woocommerce events' );
		$ids = array();
		$pg  = \WorkflowCaptain\Admin\Pagination::state( $events->count( array() ), 7, 1 );
		for ( $p = 1; $p <= $pg['pages']; $p++ ) {
			$st  = \WorkflowCaptain\Admin\Pagination::state( $pg['total'], 7, $p );
			$ids = array_merge( $ids, array_map( static fn( $r ) => (int) $r['id'], $events->find( array(), $st['per_page'], $st['offset'] ) ) );
		}
		$sorted = $ids;
		rsort( $sorted );
		e2e_check( 'n3', 'paging through EventStore::find() (7 per page) returns every row once, newest first', count( $ids ) === $sql_n && count( array_unique( $ids ) ) === $sql_n && $sorted === $ids && $pg['pages'] > 1, $pg['pages'] . ' pages, ' . count( $ids ) . ' rows' );
		$env  = json_decode( (string) $wpdb->get_var( "SELECT envelope FROM {$et} ORDER BY id DESC LIMIT 1" ), true ); // phpcs:ignore
		$corr = (string) ( $env['correlation_id'] ?? '' );
		$c_n  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$et} WHERE envelope LIKE %s", '%' . $wpdb->esc_like( '"correlation_id":"' . $corr . '"' ) . '%' ) ); // phpcs:ignore
		e2e_check( 'n4', 'EventStore::count( correlation_id ) equals SQL; a value that is no UUID matches nothing', '' !== $corr && $c_n >= 1 && $c_n === $events->count( array( 'correlation_id' => $corr ) ) && 0 === $events->count( array( 'correlation_id' => '%' ) ), $corr . ': ' . $c_n );
		$engine = Plugin::instance()->engine_store();
		$rt     = $wpdb->prefix . 'workflow_captain_runs';
		$runs_n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rt}" ); // phpcs:ignore
		$states = (array) $wpdb->get_col( "SELECT DISTINCT state FROM {$rt}" ); // phpcs:ignore
		$sum    = 0;
		foreach ( $states as $state ) {
			$sum += $engine->count_runs( array( 'state' => $state ) );
		}
		e2e_check( 'n5', 'EngineStore::count_runs() equals SQL COUNT(*); the state filters add up', $runs_n === $engine->count_runs( array() ) && $runs_n === $sum && $runs_n > 0, array( 'sql' => $runs_n, 'states' => $states ) );
		$wf_n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rt} WHERE workflow_id = %d", $wf ) ); // phpcs:ignore
		e2e_check( 'n6', 'EngineStore::count_runs( workflow_id ) equals SQL (23 runs of "E2E Zaehlung")', 23 === $wf_n && $wf_n === $engine->count_runs( array( 'workflow_id' => $wf ) ), $wf_n . ' runs' );
		$since = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$until = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$t_n   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rt} WHERE created_at >= %s AND created_at <= %s", $since, $until ) ); // phpcs:ignore
		e2e_check( 'n7', 'EngineStore::count_runs( since, until ) equals SQL; a malformed bound is ignored', $t_n === $engine->count_runs( array( 'since' => $since, 'until' => $until ) ) && $runs_n === $engine->count_runs( array( 'since' => 'yesterday' ) ) && 0 === $engine->count_runs( array( 'since' => $until ) ), $t_n . ' runs in the last hour' );
		$ids = array();
		$pg  = \WorkflowCaptain\Admin\Pagination::state( $engine->count_runs( array() ), 10, 1 );
		for ( $p = 1; $p <= $pg['pages']; $p++ ) {
			$st  = \WorkflowCaptain\Admin\Pagination::state( $pg['total'], 10, $p );
			$ids = array_merge( $ids, array_map( static fn( $r ) => (int) $r['id'], $engine->find_runs( array(), $st['per_page'], $st['offset'] ) ) );
		}
		$sorted = $ids;
		rsort( $sorted );
		e2e_check( 'n8', 'paging through EngineStore::find_runs() (10 per page) returns every run once, newest first; a page past the end is clamped', count( $ids ) === $runs_n && count( array_unique( $ids ) ) === $runs_n && $sorted === $ids && $pg['pages'] > 1 && \WorkflowCaptain\Admin\Pagination::state( $runs_n, 10, 999 )['paged'] === $pg['pages'], $pg['pages'] . ' pages, ' . count( $ids ) . ' runs' );
		break;

	case 'ms_network':
		// Multisite: run on the main site after "wp plugin activate --network".
		global $wpdb;
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
		$miss  = array();
		foreach ( $sites as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			$missing = array_diff( e2e_tables(), e2e_existing_tables() );
			if ( $missing ) {
				$miss[ $blog_id ] = array_values( $missing );
			}
			$schema = get_option( 'workflow_captain_db_version' );
			if ( WORKFLOW_CAPTAIN_DB_VERSION !== (string) $schema ) {
				$miss[ $blog_id ][] = 'schema option ' . var_export( $schema, true );
			}
			if ( ! get_role( 'administrator' )->has_cap( 'manage_workflow_captain' ) ) {
				$miss[ $blog_id ][] = 'caps';
			}
			restore_current_blog();
		}
		e2e_check( 'k1', 'network activation creates per-site tables, schema option and caps on every existing site', array() === $miss, $miss ?: count( $sites ) . ' sites' );
		break;

	case 'ms_new_site':
		// A site created after network activation.
		$blog_id = (int) $e2e_param;
		switch_to_blog( $blog_id );
		$missing = array_diff( e2e_tables(), e2e_existing_tables() );
		restore_current_blog();
		e2e_check( 'k2', 'site created after network activation has its tables at once (wp_initialize_site, before any request on it)', array() === $missing, $missing ? 'missing on blog ' . $blog_id . ': ' . count( $missing ) . ' tables' : 'blog ' . $blog_id );
		break;

	case 'ms_new_site_after_admin':
		$blog_id = (int) $e2e_param;
		switch_to_blog( $blog_id );
		$missing = array_diff( e2e_tables(), e2e_existing_tables() );
		restore_current_blog();
		e2e_check( 'k3', 'new site has its tables after the first admin request (maybe_upgrade)', array() === $missing, $missing ? count( $missing ) . ' missing' : 'blog ' . $blog_id );
		break;

	case 'ms_dropped':
		global $wpdb;
		$blog_id = (int) $e2e_param;
		$left    = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix . $blog_id . '_workflow_captain_' ) . '%' ) );
		e2e_check( 'k4', 'wpmu_delete_blog( drop ) removes the site tables (wpmu_drop_tables)', array() === $left, $left );
		break;

	case 'ms_uninstalled':
		global $wpdb;
		// Tables of Workflow Captain Pro (workflow_captain_pro_*) belong to the add-on, which is only deactivated here.
		$left = array_values( array_filter( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', '%' . $wpdb->esc_like( 'workflow_captain_' ) . '%' ) ), static fn( $t ) => ! str_contains( $t, 'workflow_captain_pro_' ) ) );
		e2e_check( 'k5', 'network uninstall removes the tables of every site', array() === $left, $left );
		break;

	default:
		echo "unknown section {$e2e_section}\n";
}
