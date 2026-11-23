<?php
/**
 * Helper of bin/e2e/upgrade-gate.sh (not shipped: /bin is export-ignore). Runs inside WordPress with any version of
 * Workflow Captain from 0.2.0 schema 3 on, so it only uses calls that version already had.
 *
 *     wp eval-file bin/e2e/upgrade.php <section>
 *
 * Sections: seed (publish "order paid -> tag", pay one order, run the worker), counts (JSON of rows per table),
 * order (pay one more order and run the worker; prints the order ID), tagged <order-id> (1 when the order carries the
 * tag), schema (stored schema option and the state the running code sees).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Install;
use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

$section = $args[0] ?? 'counts';

/** Pays a new order of one simple product. */
function upg_order( string $txn ): int {
	$product = new WC_Product_Simple();
	$product->set_name( 'Upgrade gate ' . $txn );
	$product->set_regular_price( '19.90' );
	$product->save();
	$order = wc_create_order( array( 'status' => 'pending' ) );
	$order->add_product( $product, 1 );
	$order->set_currency( 'EUR' );
	$order->set_payment_method( 'bacs' );
	$order->calculate_totals();
	$order->save();
	$order->payment_complete( $txn );
	return (int) $order->get_id();
}

function upg_work(): void {
	for ( $i = 0; $i < 12; $i++ ) {
		Plugin::instance()->run_worker();
	}
}

switch ( $section ) {
	case 'seed':
		$user  = get_user_by( 'login', 'admin' );
		$nodes = array(
			array( 'id' => 't', 'type' => 'trigger', 'event' => 'woocommerce.order.paid' ),
			array( 'id' => 'a0', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'upgrade-gate' ) ),
			array( 'id' => 'end', 'type' => 'end' ),
		);
		$edges = array( array( 'from' => 't', 'to' => 'a0' ), array( 'from' => 'a0', 'to' => 'end' ) );
		$wfs   = Plugin::instance()->workflows();
		list( $id, $violations ) = $wfs->save( null, 'Upgrade gate', array( 'nodes' => $nodes, 'edges' => $edges ) );
		$pub = $wfs->publish( (int) $id, $user ? (int) $user->ID : 1 );
		$oid = upg_order( 'upg-old' );
		upg_work();
		echo wp_json_encode( array( 'workflow' => (int) $id, 'violations' => $violations, 'publish' => $pub, 'order' => $oid ) ), "\n";
		break;
	case 'counts':
		global $wpdb;
		$out = array();
		foreach ( array( 'events', 'workflows', 'versions', 'runs', 'steps', 'tags' ) as $t ) {
			$table = $wpdb->prefix . 'workflow_captain_' . $t;
			$out[ $t ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : -1; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$out['runs_succeeded'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}workflow_captain_runs WHERE state = 'succeeded'" );
		$out['events_open']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}workflow_captain_events WHERE state NOT IN ('done','duplicate','rejected','loop_blocked')" );
		echo wp_json_encode( $out ), "\n";
		break;
	case 'order':
		$oid = upg_order( 'upg-' . wp_generate_password( 8, false ) );
		upg_work();
		echo $oid, "\n";
		break;
	case 'order_nowork':
		echo upg_order( 'upg-' . wp_generate_password( 8, false ) ), "\n";
		break;
	case 'tagged':
		global $wpdb;
		echo null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}workflow_captain_tags WHERE subject_id = %s AND tag = 'upgrade-gate'", (string) (int) ( $args[1] ?? 0 ) ) ) ? 1 : 0, "\n";
		break;
	case 'schema':
		echo wp_json_encode(
			array(
				'stored' => (string) get_option( Install::DB_OPTION ),
				'code'   => WORKFLOW_CAPTAIN_DB_VERSION,
				'state'  => Install::schema_state( get_option( Install::DB_OPTION ), WORKFLOW_CAPTAIN_DB_VERSION ),
			)
		), "\n";
		break;
}
