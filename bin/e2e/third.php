<?php
/**
 * Third E2E run (docs/E2E-RESULTS.md, "Dritter Lauf"; not shipped: /bin is export-ignore): upgrade of an existing site
 * to the version with Migrator, and realistic data for the new admin screens.
 *
 *     wp eval-file bin/e2e/third.php <section> [param]
 *
 * Sections: old_data (runs with the OLD plugin code), snapshot, upgrade_check, interrupt, interrupt_check, lock <age>,
 * lock_check, data, data_check. Results go to $E2E_RESULTS (default logs/results-3.jsonl).
 *
 * @package WorkflowCaptain
 */

defined( 'ABSPATH' ) || exit;

if ( ! getenv( 'E2E_RESULTS' ) ) {
	putenv( 'E2E_RESULTS=/home/user/wp-e2e/logs/results-3.jsonl' );
}
define( 'E2E_LIB_ONLY', true );
require __DIR__ . '/checks.php';

use WorkflowCaptain\Plugin;

const E3_LOGS = '/home/user/wp-e2e/logs';

$e3_section = $args[0] ?? '';
$e3_param   = $args[1] ?? '';

function e3_counts(): array {
	global $wpdb;
	$out = array();
	$have = e2e_existing_tables();
	foreach ( array( 'events', 'causes', 'workflows', 'versions', 'runs', 'steps', 'tags', 'notes' ) as $t ) {
		// A table dropped on purpose (interrupted upgrade) counts as missing, without a database error in the log.
		$out[ $t ] = in_array( $wpdb->prefix . 'workflow_captain_' . $t, $have, true ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}workflow_captain_{$t}" ) : null; // phpcs:ignore
	}
	return $out;
}

/** Hash of every CREATE TABLE of the plugin, without AUTO_INCREMENT counters. */
function e3_schema(): string {
	global $wpdb;
	$out = '';
	foreach ( e2e_existing_tables() as $t ) {
		$row  = $wpdb->get_row( "SHOW CREATE TABLE {$t}", ARRAY_N ); // phpcs:ignore
		$out .= preg_replace( '/ AUTO_INCREMENT=\d+/', '', (string) $row[1] ) . "\n";
	}
	return md5( $out );
}

/** Saves and publishes any graph; returns the workflow ID (0 and a FAIL line on a violation). */
function e3_publish( string $name, array $nodes, array $edges, bool $publish = true ): int {
	$wfs                     = Plugin::instance()->workflows();
	list( $id, $violations ) = $wfs->save( null, $name, array( 'nodes' => $nodes, 'edges' => $edges ) );
	$pub                     = $publish && $id ? $wfs->publish( (int) $id, e2e_admin_id() ) : array();
	if ( array() !== $violations || array() !== $pub ) {
		e2e_check( 'publish', 'publish ' . $name, false, array_merge( (array) $violations, (array) $pub ) );
		return 0;
	}
	return (int) $id;
}

function e3_line( array $ids ): array {
	$edges = array();
	for ( $i = 1; $i < count( $ids ); $i++ ) {
		$edges[] = array( 'from' => $ids[ $i - 1 ], 'to' => $ids[ $i ] );
	}
	return $edges;
}

function e3_paid( float $price, string $method = 'bacs' ): int {
	$order = e2e_order( $price );
	$order->set_payment_method( $method );
	$order->save();
	$order->payment_complete();
	return (int) $order->get_id();
}

function e3_state( array $s = null ): array {
	$file = E3_LOGS . '/third-state.json';
	if ( null !== $s ) {
		file_put_contents( $file, wp_json_encode( $s ) );
		return $s;
	}
	return is_readable( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array();
}

global $wpdb;
$e3_p = $wpdb->prefix . 'workflow_captain_';

switch ( $e3_section ) {

	// ----- 1. upgrade of an existing site ------------------------------------------------------------------------
	case 'old_data': // Runs on the code of the second run (symlink points to an export of that commit).
		$wf = e2e_publish( 'Alt: Bestellung bezahlt', array( 'event' => 'woocommerce.order.paid' ), array( array( 'notify.admin', array( 'subject' => 'Alt {{subject.id}}', 'message' => 'Bestellung {{subject.id}}' ) ), array( 'workflow.tag.set', array( 'tag' => 'alt' ) ) ) );
		$orders = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$orders[] = e3_paid( 19.90 + $i );
		}
		e2e_work( 6 );
		$pid = wp_insert_post( array( 'post_title' => 'Alt Beitrag', 'post_status' => 'publish' ) );
		e3_state( array( 'old_wf' => $wf, 'old_orders' => $orders, 'old_post' => $pid ) );
		e2e_check( 'u0', 'old version (second run, ' . WORKFLOW_CAPTAIN_VERSION . ', no Migrator class) stored workflows, events and runs', $wf > 0 && ! class_exists( '\\WorkflowCaptain\\Migrator' ) && 3 === count( e2e_runs( $wf ) ), array( 'runs' => array_column( e2e_runs( $wf ), 'state' ) ) + e3_counts() );
		break;

	case 'snapshot':
		file_put_contents( E3_LOGS . '/third-snapshot.json', wp_json_encode( array( 'counts' => e3_counts(), 'schema' => e3_schema(), 'option' => get_option( 'workflow_captain_db_version' ) ) ) );
		break;

	case 'upgrade_check': // First WP-CLI process with the new code; boot() already ran the upgrade.
		$snap = json_decode( (string) file_get_contents( E3_LOGS . '/third-snapshot.json' ), true );
		e2e_check( 'u2', 'after the update: rows, table definitions and schema option unchanged, no upgrade lock left', $snap['counts'] === e3_counts() && $snap['schema'] === e3_schema() && '4' === get_option( 'workflow_captain_db_version' ) && false === get_option( \WorkflowCaptain\Migrator::LOCK_OPTION ), e3_counts() );
		$s   = e3_state();
		$o   = e3_paid( 24.90 );
		e2e_work( 4 );
		$runs = e2e_runs( (int) $s['old_wf'] );
		e2e_check( 'u3', 'the workflow published with the old version keeps working after the update (new order -> run succeeded)', 4 === count( $runs ) && 'succeeded' === end( $runs )['state'], array_column( $runs, 'state' ) );
		$diag = Plugin::instance()->diagnostics();
		e2e_check( 'u5', 'diagnostics: schema 4/4 current, upgrade lock free', 'current' === $diag['environment']['schema_state'] && 'free' === ( $diag['environment']['upgrade_lock'] ?? '' ), array_intersect_key( $diag['environment'], array_flip( array( 'schema', 'schema_expected', 'schema_state', 'upgrade_lock' ) ) ) );
		break;

	case 'interrupt': // An upgrade that broke off before the version moved: schema 3, one index and one table missing.
		file_put_contents( E3_LOGS . '/third-counts.json', wp_json_encode( e3_counts() ) );
		$wpdb->query( "ALTER TABLE {$e3_p}runs DROP INDEX state_updated" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE {$e3_p}notes" ); // phpcs:ignore
		update_option( 'workflow_captain_db_version', '3', false );
		if ( 'stale' === $e3_param || 'busy' === $e3_param ) {
			// Lock of an upgrade that is running (busy) or stopped half-way 11 minutes ago (stale).
			add_option( \WorkflowCaptain\Migrator::LOCK_OPTION, (string) ( time() - ( 'stale' === $e3_param ? 660 : 30 ) ), '', false );
		}
		break;

	case 'interrupt_check': // A new process after the upgrade ran.
		$before = json_decode( (string) file_get_contents( E3_LOGS . '/third-counts.json' ), true );
		$idx    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = '{$e3_p}runs' AND index_name = 'state_updated'" ); // phpcs:ignore
		$now    = e3_counts();
		unset( $now['notes'], $before['notes'] );
		$ok = $idx > 0 && in_array( $e3_p . 'notes', e2e_existing_tables(), true ) && '4' === get_option( 'workflow_captain_db_version' ) && $before == $now && false === get_option( \WorkflowCaptain\Migrator::LOCK_OPTION ); // phpcs:ignore Universal.Operators.StrictComparisons -- order of keys
		e2e_check( (string) $e3_param, 'upgrade from schema 3 finished: index and table back, version 4, no row lost, lock released', $ok, array( 'index' => $idx, 'version' => get_option( 'workflow_captain_db_version' ), 'lock' => get_option( \WorkflowCaptain\Migrator::LOCK_OPTION ) ) + $now );
		break;

	case 'busy_check': // While another request holds the lock: nothing changes, the worker waits, events are stored.
		$diag = Plugin::instance()->diagnostics();
		$o    = e3_paid( 33.00 );
		e2e_work( 2 );
		$ev = e2e_events( 'woocommerce.order.paid', (string) $o );
		e2e_check( 'u8', 'lock held by another request: schema stays 3, diagnostics say "upgrade"/"held", an order paid meanwhile is stored but not processed', '3' === get_option( 'workflow_captain_db_version' ) && 'upgrade' === $diag['environment']['schema_state'] && 'held' === $diag['environment']['upgrade_lock'] && $ev && 'received' === $ev[0]['state'], array( 'schema_state' => $diag['environment']['schema_state'], 'lock' => $diag['environment']['upgrade_lock'], 'event' => $ev[0]['state'] ?? 'none' ) );
		file_put_contents( E3_LOGS . '/third-busy-order.txt', (string) $o );
		file_put_contents( E3_LOGS . '/third-counts.json', wp_json_encode( e3_counts() ) ); // With the order stored meanwhile.
		break;

	case 'resumed_check':
		$audit = array_values( array_filter( (array) get_option( 'workflow_captain_audit', array() ), static fn( $e ) => 'upgrade_resumed' === ( $e['what'] ?? '' ) ) );
		e2e_check( 'u11', 'audit log has the entry "upgrade_resumed" with from/to', array() !== $audit && '3' === (string) ( $audit[0]['detail']['from'] ?? '' ) && '4' === (string) ( $audit[0]['detail']['to'] ?? '' ), $audit[0] ?? 'none' );
		$o  = (int) file_get_contents( E3_LOGS . '/third-busy-order.txt' );
		e2e_work( 4 );
		$ev = e2e_events( 'woocommerce.order.paid', (string) $o );
		e2e_check( 'u12', 'after the upgrade the order paid during the lock is processed', $ev && 'done' === $ev[0]['state'], $ev[0]['state'] ?? 'none' );
		break;

	// ----- 2. realistic data ---------------------------------------------------------------------------------------
	case 'data': // Needs the option e2e_addon_action = 1 set before this process (the mu-plugin reads it at load).
		$s = array();
		// W1: every paid order: mail and tag (succeeded).
		$s['w_paid'] = e3_publish(
			'Bestellung bezahlt: Betreiber informieren',
			array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'woocommerce.order.paid' ),
				array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Bestellung {{subject.id}} bezahlt', 'message' => 'Betrag {{data.total_minor}} {{data.currency}}' ) ),
				array( 'id' => 'tag', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'bezahlt' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			e3_line( array( 'start', 'mail', 'tag', 'end' ) )
		);
		// W2: condition with a group: total >= 50 EUR AND (bacs OR cod) -> mail, else tag.
		$s['w_group'] = e3_publish(
			'Große Bestellung (Bedingung mit Gruppe)',
			array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'woocommerce.order.paid', 'x' => 40, 'y' => 30 ),
				array(
					'id'      => 'check',
					'type'    => 'condition',
					'missing' => 'false',
					'rules'   => array(
						'op'    => 'and',
						'items' => array(
							array( 'field' => 'data.total_minor', 'operator' => 'gte', 'value' => 5000 ),
							array( 'op' => 'or', 'items' => array( array( 'field' => 'data.payment_method', 'operator' => 'eq', 'value' => 'bacs' ), array( 'field' => 'data.payment_method', 'operator' => 'eq', 'value' => 'cod' ) ) ),
						),
					),
				),
				array( 'id' => 'big', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Große Bestellung {{subject.id}}', 'message' => 'Bitte prüfen.' ) ),
				array( 'id' => 'small', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'klein' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			array( array( 'from' => 'start', 'to' => 'check' ), array( 'from' => 'check', 'to' => 'big', 'branch' => 'then' ), array( 'from' => 'check', 'to' => 'small', 'branch' => 'else' ), array( 'from' => 'big', 'to' => 'end' ), array( 'from' => 'small', 'to' => 'end' ) )
		);
		// W3: refund -> note.
		$s['w_refund'] = e3_publish(
			'Erstattung: Notiz an der Bestellung',
			array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'woocommerce.order.refunded' ),
				array( 'id' => 'note', 'type' => 'action', 'action' => 'workflow.note.add', 'config' => array( 'text' => 'Erstattet: {{data.amount_minor}} {{data.currency}}' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			e3_line( array( 'start', 'note', 'end' ) )
		);
		// W4: new user -> tag.
		$s['w_user'] = e3_publish(
			'Neuer Benutzer: markieren',
			array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'wordpress.user.registered' ),
				array( 'id' => 'tag', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'neu' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			e3_line( array( 'start', 'tag', 'end' ) )
		);
		// W6: cancelled order with a condition on a field the event does not have: "stop" (stopped).
		$s['w_stop'] = e3_publish(
			'Storno: nur mit Gutschein (fehlendes Feld beendet)',
			array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'woocommerce.order.cancelled' ),
				array( 'id' => 'check', 'type' => 'condition', 'missing' => 'stop', 'rules' => array( 'op' => 'and', 'items' => array( array( 'field' => 'data.coupon', 'operator' => 'eq', 'value' => 'WINTER' ) ) ) ),
				array( 'id' => 'tag', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'storno-gutschein' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			array( array( 'from' => 'start', 'to' => 'check' ), array( 'from' => 'check', 'to' => 'tag', 'branch' => 'then' ), array( 'from' => 'check', 'to' => 'end', 'branch' => 'else' ), array( 'from' => 'tag', 'to' => 'end' ) )
		);
		// W8: action of an add-on (mu-plugin e2e-addon-action.php); the add-on goes away later -> paused.
		$s['w_addon'] = e3_publish(
			'Add-on-Aktion: Bestellung kennzeichnen',
			array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'woocommerce.order.refunded' ),
				array( 'id' => 'mark', 'type' => 'action', 'action' => 'woocommerce.e2e.mark', 'config' => array( 'color' => 'red', 'priority' => 2, 'comment' => 'Erstattung prüfen' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			e3_line( array( 'start', 'mark', 'end' ) )
		);
		// W9, W10: schedules (weekly report; daily one whose time has just passed, so it fires once).
		$s['w_weekly'] = e3_publish(
			'Wochenbericht montags',
			array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'workflow.schedule.due', 'schedule' => array( 'frequency' => 'weekly', 'weekday' => 1, 'time' => '07:30' ) ),
				array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Wochenbericht', 'message' => 'Neue Woche.' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			e3_line( array( 'start', 'mail', 'end' ) )
		);
		$time          = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-1 minute' )->format( 'H:i' );
		$s['w_daily']  = e3_publish(
			'Täglicher Abgleich',
			array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'workflow.schedule.due', 'schedule' => array( 'frequency' => 'daily', 'time' => $time ) ),
				array( 'id' => 'note', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'abgleich' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			e3_line( array( 'start', 'note', 'end' ) )
		);
		$wpdb->query( $wpdb->prepare( "UPDATE {$e3_p}versions SET created_at = %s WHERE workflow_id = %d", gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ), $s['w_daily'] ) ); // phpcs:ignore
		$s['w_monthly'] = e3_publish(
			'Monatsabschluss',
			array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'workflow.schedule.due', 'schedule' => array( 'frequency' => 'monthly', 'day' => 1, 'time' => '06:00' ) ),
				array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Monatsabschluss', 'message' => 'Neuer Monat.' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			e3_line( array( 'start', 'mail', 'end' ) )
		);
		Plugin::instance()->workflows()->pause( $s['w_monthly'] );
		// A draft only (never published).
		$s['w_draft'] = e3_publish(
			'Entwurf: Bewertung anfragen',
			array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'woocommerce.order.status_changed' ),
				array( 'id' => 'wait', 'type' => 'delay', 'amount' => 7, 'unit' => 'days' ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			e3_line( array( 'start', 'wait', 'end' ) ),
			false
		);

		// Orders: 70 paid (various totals and methods); W1 and W2 run on each -> 140 runs sharing a correlation per order.
		$methods = array( 'bacs', 'cod', 'cheque', 'bacs', 'bacs' );
		$orders  = array();
		for ( $i = 0; $i < 70; $i++ ) {
			$orders[] = e3_paid( (float) ( 15 + ( $i * 7 ) % 90 ) + 0.90, $methods[ $i % 5 ] );
			if ( 0 === $i % 10 ) {
				e2e_work( 4 );
			}
		}
		e2e_work( 20 );
		// Refunds (W3 note; W8 add-on action) and cancellations (W6 stopped).
		foreach ( array_slice( $orders, 0, 4 ) as $oid ) {
			wc_create_refund( array( 'order_id' => $oid, 'amount' => 5, 'reason' => 'E2E' ) );
		}
		e2e_work( 6 );
		for ( $i = 0; $i < 3; $i++ ) {
			$o = e2e_order( 12.0 );
			$o->update_status( 'cancelled' );
		}
		e2e_work( 6 );
		// Status change -> W7 action_required (condition_field_missing).
		// W7: status change with a condition on a missing field in mode "error" (action_required).
		$s['w_error'] = e3_publish(
			'Statuswechsel: Feld fehlt (Fehler)',
			array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'woocommerce.order.status_changed' ),
				array( 'id' => 'check', 'type' => 'condition', 'missing' => 'error', 'rules' => array( 'op' => 'and', 'items' => array( array( 'field' => 'data.shipping_zone', 'operator' => 'eq', 'value' => 'EU' ) ) ) ),
				array( 'id' => 'tag', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'eu' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			array( array( 'from' => 'start', 'to' => 'check' ), array( 'from' => 'check', 'to' => 'tag', 'branch' => 'then' ), array( 'from' => 'check', 'to' => 'end', 'branch' => 'else' ), array( 'from' => 'tag', 'to' => 'end' ) )
		);
		$o = wc_get_order( $orders[5] );
		$o->update_status( 'completed' );
		$o = wc_get_order( $orders[6] );
		$o->update_status( 'completed' );
		e2e_work( 6 );
		// Users (W4) and posts (W5 running).
		for ( $i = 0; $i < 4; $i++ ) {
			wp_create_user( 'e3kunde' . $i . wp_generate_password( 4, false, false ), wp_generate_password(), 'e3kunde' . $i . '@example.test' );
		}
		// W5: post published -> wait 1 day -> mail (running runs; later overdue -> action_required, unclear outcome).
		$s['w_post'] = e3_publish(
			'Beitrag veröffentlicht: nach einem Tag erinnern',
			array(
				array( 'id' => 'start', 'type' => 'trigger', 'event' => 'wordpress.post.published' ),
				array( 'id' => 'wait', 'type' => 'delay', 'amount' => 1, 'unit' => 'days' ),
				array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Erinnerung: Beitrag {{subject.id}}', 'message' => 'Seit einem Tag online.' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			e3_line( array( 'start', 'wait', 'mail', 'end' ) )
		);
		$posts = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$posts[] = wp_insert_post( array( 'post_title' => 'Neuigkeit ' . ( $i + 1 ), 'post_content' => 'Testbeitrag', 'post_status' => 'publish' ) );
		}
		e2e_work( 8 );
		// Schedules fire (daily one).
		e2e_work( 2 );
		$s['orders'] = $orders;
		$s['posts']  = $posts;
		e3_state( $s );
		break;

	case 'data_states': // Second process: the open runs move into the remaining states through the engine.
		$s     = e3_state();
		$store = Plugin::instance()->engine()->store();
		$post  = array_column( e2e_runs( (int) $s['w_post'] ), 'id' );
		// Two waiting runs are cancelled (as with "Lauf abbrechen").
		Plugin::instance()->workflows()->cancel_run( (int) $post[0] );
		Plugin::instance()->workflows()->cancel_run( (int) $post[1] );
		// Time travel: the waiting mail of one run is due far in the past -> engine: "overdue" (action_required).
		$wpdb->query( $wpdb->prepare( "UPDATE {$e3_p}steps SET due_at = %s WHERE run_id = %d AND node_id = 'mail' AND state = 'queued'", gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), (int) $post[2] ) ); // phpcs:ignore
		// Crash emulation: a worker died after starting the mail of another run (effect "started", lease expired) ->
		// engine: "unclear_outcome" (action_required, box "Erneut senden?").
		$wpdb->query( $wpdb->prepare( "UPDATE {$e3_p}steps SET due_at = %s, effect = 'started' WHERE run_id = %d AND node_id = 'mail' AND state = 'queued'", gmdate( 'Y-m-d H:i:s', time() - 60 ), (int) $post[3] ) ); // phpcs:ignore
		e2e_work( 4 );
		// The add-on goes away: its refund runs that are still to come pause ("action_unavailable").
		delete_option( 'e2e_addon_action' );
		break;

	case 'data_paused': // Without the add-on: two more refunds -> W8 paused, W3 succeeded.
		$s = e3_state();
		foreach ( array_slice( $s['orders'], 10, 2 ) as $oid ) {
			wc_create_refund( array( 'order_id' => $oid, 'amount' => 3, 'reason' => 'E2E' ) );
		}
		e2e_work( 6 );
		// "failed" is never set by the core engine (only listed as a state); two runs are set to it directly so that
		// filter, counts and the error column can be checked (documented as test data).
		$ids = array_column( e2e_runs( (int) $s['w_paid'] ), 'id' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$e3_p}runs SET state = 'failed', last_error = 'e2e_failed' WHERE id IN (%d, %d)", (int) $ids[1], (int) $ids[2] ) ); // phpcs:ignore
		// Time travel for the time range filter: 30 runs of W1 (and their steps) began 35 to 64 days ago.
		$old = array_slice( $ids, 10, 30 );
		foreach ( $old as $k => $rid ) {
			$at = gmdate( 'Y-m-d H:i:s', time() - ( 35 + $k ) * DAY_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( "UPDATE {$e3_p}runs SET created_at = %s, updated_at = %s WHERE id = %d", $at, $at, (int) $rid ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "UPDATE {$e3_p}steps SET created_at = %s, updated_at = %s, due_at = %s WHERE run_id = %d", $at, $at, $at, (int) $rid ) ); // phpcs:ignore
		}
		$s['old_runs'] = $old;
		e3_state( $s );
		break;

	case 'data_check':
		$s      = e3_state();
		$states = array();
		foreach ( (array) $wpdb->get_results( "SELECT state, COUNT(*) AS n FROM {$e3_p}runs GROUP BY state", ARRAY_A ) as $r ) { // phpcs:ignore
			$states[ $r['state'] ] = (int) $r['n'];
		}
		$all = array( 'running', 'action_required', 'paused', 'succeeded', 'stopped', 'failed', 'cancelled' );
		e2e_check( 'd3-1', 'more than 120 runs, every run state present', array_sum( $states ) > 120 && array() === array_diff( $all, array_keys( array_filter( $states ) ) ), $states );
		$reasons = (array) $wpdb->get_col( "SELECT DISTINCT CONCAT(state, ':', IFNULL(last_error,'')) FROM {$e3_p}runs WHERE state IN ('action_required','paused','stopped')" ); // phpcs:ignore
		e2e_check( 'd3-2', 'open states come from the engine: overdue, unclear_outcome, condition_field_missing (action_required), action_unavailable (paused), condition_field_missing (stopped)', array() === array_diff( array( 'action_required:overdue', 'action_required:unclear_outcome', 'action_required:condition_field_missing', 'paused:action_unavailable', 'stopped:condition_field_missing' ), $reasons ), $reasons );
		$ev = array();
		foreach ( (array) $wpdb->get_results( "SELECT product, COUNT(*) AS n FROM {$e3_p}events GROUP BY product", ARRAY_A ) as $r ) { // phpcs:ignore
			$ev[ $r['product'] ] = (int) $r['n'];
		}
		e2e_check( 'd3-3', 'events of WooCommerce, WordPress and the schedule adapter, more than 100 (paging)', array_sum( $ev ) > 100 && isset( $ev['woocommerce'], $ev['wordpress'], $ev['workflow-captain'] ), $ev );
		// Correlation: both order.paid workflows ran on the same event.
		$corr = (array) $wpdb->get_col( $wpdb->prepare( "SELECT context FROM {$e3_p}runs WHERE workflow_id = %d ORDER BY id DESC LIMIT 1", (int) $s['w_group'] ) ); // phpcs:ignore
		$c    = json_decode( (string) ( $corr[0] ?? '' ), true )['correlation_id'] ?? '';
		$same = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$e3_p}runs WHERE context LIKE %s", '%"correlation_id":"' . $c . '"%' ) ); // phpcs:ignore
		e2e_check( 'd3-4', 'one order.paid event started one run per active order.paid workflow (three), all sharing its correlation ID', 3 === $same, $c . ' -> ' . $same );
		file_put_contents( E3_LOGS . '/third-correlation.txt', $c );
		$fired = (array) get_option( 'workflow_captain_schedule_fired', array() );
		e2e_check( 'd3-5', 'the daily schedule fired once (last fired stored), weekly one has not yet', isset( $fired[ (int) $s['w_daily'] ] ) && ! isset( $fired[ (int) $s['w_weekly'] ] ) && 1 === count( e2e_runs( (int) $s['w_daily'] ) ), array_keys( $fired ) );
		$dump = e2e_dump();
		$hits = array_filter( E2E_PII, static fn( $v ) => str_contains( $dump, $v ) );
		e2e_check( 'd3-6', 'no personal data of the orders in any workflow_captain_* table', array() === $hits, strlen( $dump ) . ' bytes scanned' );
		break;
}
