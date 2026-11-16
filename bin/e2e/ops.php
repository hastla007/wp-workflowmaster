<?php
/**
 * Second E2E run (not shipped: /bin is export-ignore): backup/restore, schema migrations, load and parallel workers,
 * post actions with Yoast. Driven by bin/e2e/backup-restore.sh, bin/e2e/load.sh and bin/e2e/seo.sh.
 *
 *     wp eval-file bin/e2e/ops.php <section> [param]
 *
 * Uses the helpers of checks.php (e2e_check(), e2e_publish(), e2e_order(), ...). A restored copy of the database is
 * reached with E2E_DB=<database> (www/wp-config.php reads it), mails go to $E2E_MAIL_FILE.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Install;
use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

define( 'E2E_LIB_ONLY', true );
require __DIR__ . '/checks.php';

$e2e_section = $args[0] ?? '';
$e2e_param   = $args[1] ?? '';
define( 'E2E_LOGS', ( getenv( 'E2E_ROOT' ) ?: '/home/user/wp-e2e' ) . '/logs' );

/** Small state file shared between the sections of one script run (workflow and order IDs). */
function e2e_state( ?array $set = null ): array {
	$file = E2E_LOGS . '/ops-state.json';
	$cur  = is_readable( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array();
	if ( null !== $set ) {
		$cur = array_merge( $cur, $set );
		file_put_contents( $file, wp_json_encode( $cur ) );
	}
	return $cur;
}

/** Saves and publishes any graph; returns the workflow ID (0 on failure). */
function e2e_publish_graph( string $name, array $nodes, array $edges ): int {
	$wfs = Plugin::instance()->workflows();
	list( $id, $violations ) = $wfs->save( null, $name, array( 'nodes' => $nodes, 'edges' => $edges ) );
	$pub = $id ? $wfs->publish( (int) $id, e2e_admin_id() ) : array( 'not_saved' );
	if ( array() !== $violations || array() !== $pub ) {
		e2e_check( 'publish', 'publish ' . $name, false, array_merge( (array) $violations, (array) $pub ) );
		return 0;
	}
	return (int) $id;
}

function e2e_paid_order(): int {
	$o = e2e_order( 19.90 );
	$o->payment_complete();
	return (int) $o->get_id();
}

/** Mails whose subject contains $needle, from $file (default $E2E_MAIL_FILE). */
function e2e_mail_count( string $needle, ?string $file = null ): int {
	if ( null !== $file ) {
		putenv( 'E2E_MAIL_FILE=' . $file );
	}
	return count( array_filter( e2e_mails(), static fn( $m ) => str_contains( (string) $m['subject'], $needle ) ) );
}

/** Rows per Workflow Captain table, as a map. */
function e2e_counts(): array {
	global $wpdb;
	$out = array();
	foreach ( e2e_tables() as $t ) {
		$out[ str_replace( $wpdb->prefix . 'workflow_captain_', '', $t ) ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore
	}
	return $out;
}

function e2e_state_counts( string $table, string $column = 'state' ): array {
	global $wpdb;
	$out = array();
	foreach ( (array) $wpdb->get_results( "SELECT {$column} AS s, COUNT(*) AS n FROM {$wpdb->prefix}workflow_captain_{$table} GROUP BY {$column}", ARRAY_A ) as $r ) { // phpcs:ignore
		$out[ $r['s'] ] = (int) $r['n'];
	}
	ksort( $out );
	return $out;
}

/** CREATE TABLE statements of all plugin tables (schema fingerprint). */
function e2e_schema_hash(): string {
	global $wpdb;
	$sql = '';
	foreach ( e2e_existing_tables() as $t ) {
		$row  = $wpdb->get_row( "SHOW CREATE TABLE {$t}", ARRAY_N ); // phpcs:ignore
		$sql .= preg_replace( '/ AUTO_INCREMENT=\d+/', '', (string) ( $row[1] ?? '' ) ) . "\n";
	}
	return md5( $sql );
}

switch ( $e2e_section ) {

	// ----- R12 / WF-CORE-07: backup and restore ----------------------------------------------------------------
	case 'br_prep':
		// W1: waits one day after payment (runs stay in "delay"), W2: mails right away, W3: mail on a published post.
		$w1 = e2e_publish_graph(
			'BR Warten',
			array(
				array( 'id' => 't', 'type' => 'trigger', 'event' => 'woocommerce.order.paid' ),
				array( 'id' => 'tag', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'br-start' ) ),
				array( 'id' => 'wait', 'type' => 'delay', 'unit' => 'days', 'amount' => 1 ),
				array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'BR nach Warten {{subject.id}}', 'message' => 'x' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			array( array( 'from' => 't', 'to' => 'tag' ), array( 'from' => 'tag', 'to' => 'wait' ), array( 'from' => 'wait', 'to' => 'mail' ), array( 'from' => 'mail', 'to' => 'end' ) )
		);
		$w2 = e2e_publish( 'BR Sofort', array( 'event' => 'woocommerce.order.paid' ), array( array( 'notify.admin', array( 'subject' => 'BR sofort {{subject.id}}', 'message' => 'x' ) ) ) );
		$w3 = e2e_publish( 'BR Absturz', array( 'event' => 'wordpress.post.published' ), array( array( 'notify.admin', array( 'subject' => 'BR Beitrag {{subject.id}}', 'message' => 'x' ) ) ) );
		$o1 = e2e_paid_order();
		$o2 = e2e_paid_order();
		e2e_work( 4 );
		e2e_state( compact( 'w1', 'w2', 'w3', 'o1', 'o2' ) );
		$runs = e2e_runs( $w1 );
		e2e_check( 'r1', 'before backup: two runs of "BR Warten" wait in their delay step', 2 === count( $runs ) && array( 'running', 'running' ) === array_column( $runs, 'state' ), array_column( $runs, 'state' ) );
		e2e_check( 'r1b', 'before backup: "BR Sofort" mailed once per order', 1 === e2e_mail_count( 'BR sofort ' . $o1 ) && 1 === e2e_mail_count( 'BR sofort ' . $o2 ) );
		break;

	case 'br_dead': // Runs with E2E_FAIL_DISPATCH=1: the next order's event ends in dead_letter.
		$o4 = e2e_paid_order();
		e2e_work( 6 );
		$ev = e2e_events( 'woocommerce.order.paid', (string) $o4 );
		e2e_state( array( 'o4' => $o4, 'dead' => (int) ( $ev[0]['id'] ?? 0 ) ) );
		e2e_check( 'r2', 'before backup: one event in dead_letter', 'dead_letter' === ( $ev[0]['state'] ?? '' ), $ev[0]['last_error'] ?? '' );
		break;

	case 'br_crash': // The worker dies while sending the mail of "BR Absturz" (effect started, lease held).
		$post = wp_insert_post( array( 'post_title' => 'BR Absturz', 'post_status' => 'publish', 'post_content' => 'x' ) );
		e2e_state( array( 'post' => (int) $post ) );
		Plugin::instance()->consumer()->process( 10 );
		add_filter(
			'pre_wp_mail',
			static function ( $short, $atts ) {
				if ( str_contains( (string) $atts['subject'], 'BR Beitrag' ) ) {
					echo "worker killed while sending\n";
					exit( 0 ); // Simulates a killed PHP process in the middle of wp_mail().
				}
				return $short;
			},
			1,
			2
		);
		Plugin::instance()->engine()->process( 10 );
		break;

	case 'br_waiting': // An order whose event the worker has not seen yet when the backup is taken.
		$o3 = e2e_paid_order();
		e2e_state( array( 'o3' => $o3 ) );
		$ev = e2e_events( 'woocommerce.order.paid', (string) $o3 );
		e2e_check( 'r3', 'before backup: one event waits (received)', 'received' === ( $ev[0]['state'] ?? '' ) );
		global $wpdb;
		$crash = $wpdb->get_row( "SELECT state, effect, lease_until FROM {$wpdb->prefix}workflow_captain_steps WHERE node_id = 'a0' AND state = 'running'", ARRAY_A );
		e2e_check( 'r3b', 'before backup: the killed worker left its mail step running with effect "started"', is_array( $crash ) && 'started' === $crash['effect'], $crash );
		file_put_contents( E2E_LOGS . '/br-before.json', wp_json_encode( array( 'counts' => e2e_counts(), 'events' => e2e_state_counts( 'events' ), 'runs' => e2e_state_counts( 'runs' ), 'steps' => e2e_state_counts( 'steps' ), 'schema' => e2e_schema_hash() ) ) );
		break;

	case 'br_restored': // On the restored copy (E2E_DB), before anything ran there.
		$before = json_decode( (string) file_get_contents( E2E_LOGS . '/br-before.json' ), true );
		global $wpdb;
		$now = array( 'counts' => e2e_counts(), 'events' => e2e_state_counts( 'events' ), 'runs' => e2e_state_counts( 'runs' ), 'steps' => e2e_state_counts( 'steps' ), 'schema' => e2e_schema_hash() );
		e2e_check( 'r5', 'restored copy is a different database with the same rows, states and schema', DB_NAME !== 'wp_e2e' && $before === $now, array( 'db' => DB_NAME ) + $now );
		e2e_check( 'r5b', 'consistency check on the copy finds nothing', array() === array_filter( Plugin::instance()->engine()->store()->consistency() ), Plugin::instance()->engine()->store()->consistency() );
		break;

	case 'br_continue': // Worker on the restored copy, several passes: the waiting event runs, nothing done runs again.
		$s = e2e_state();
		e2e_work( 6 );
		$ev = e2e_events( 'woocommerce.order.paid', (string) $s['o3'] );
		e2e_check( 'r7', 'restored copy: the waiting event is processed (done)', 'done' === ( $ev[0]['state'] ?? '' ) );
		e2e_check( 'r7b', 'restored copy: "BR Sofort" mails only the waiting order, not the two done before the backup', 1 === e2e_mail_count( 'BR sofort ' . $s['o3'] ) && 0 === e2e_mail_count( 'BR sofort ' . $s['o1'] ) && 0 === e2e_mail_count( 'BR sofort ' . $s['o2'] ) );
		$w1 = array_column( e2e_runs( (int) $s['w1'] ), 'state' );
		e2e_check( 'r7c', 'restored copy: three "BR Warten" runs wait in their delay (two from the backup, one new)', array( 'running', 'running', 'running' ) === $w1, $w1 );
		e2e_check( 'r7d', 'restored copy: no "BR nach Warten" mail before the delay is due', 0 === e2e_mail_count( 'BR nach Warten' ) );
		global $wpdb;
		// Five minutes later the lease of the killed worker has expired.
		$wpdb->query( "UPDATE {$wpdb->prefix}workflow_captain_steps SET lease_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE state = 'running' AND node_id = 'a0'" );
		e2e_work( 2 );
		$crash = array_values( array_filter( e2e_runs( (int) $s['w3'] ), static fn( $r ) => (string) $s['post'] === (string) ( json_decode( $r['context'], true )['subject']['id'] ?? '' ) ) );
		unset( $crash[0]['context'] );
		e2e_check( 'r8', 'restored copy: the mail step of the killed worker is not repeated (action_required, unclear_outcome, no mail)', 'action_required' === ( $crash[0]['state'] ?? '' ) && 'unclear_outcome' === ( $crash[0]['last_error'] ?? '' ) && 0 === e2e_mail_count( 'BR Beitrag ' . $s['post'] ), $crash[0] ?? array() );
		break;

	case 'br_due': // On the restored copy: a day later (due_at of the waiting steps moved to now), then repeated passes.
		$s = e2e_state();
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->prefix}workflow_captain_steps SET due_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE state = 'queued' AND node_id = 'mail'" );
		e2e_work( 4 );
		$per = array();
		foreach ( array( 'o1', 'o2', 'o3', 'o4' ) as $k ) {
			$per[ $k ] = e2e_mail_count( 'BR nach Warten ' . $s[ $k ] );
		}
		e2e_check( 'r10', 'restored copy: after the delay every order gets exactly one "BR nach Warten" mail (dead letter requeued before)', array( 'o1' => 1, 'o2' => 1, 'o3' => 1, 'o4' => 1 ) === $per, $per );
		e2e_check( 'r10b', 'restored copy: every run of "BR Warten" and "BR Sofort" succeeded', array( 'succeeded' ) === array_values( array_unique( array_merge( array_column( e2e_runs( (int) $s['w1'] ), 'state' ), array_column( e2e_runs( (int) $s['w2'] ), 'state' ) ) ) ) );
		$dupes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT step_key FROM {$wpdb->prefix}workflow_captain_steps GROUP BY step_key HAVING COUNT(*) > 1 ) d" );
		e2e_check( 'r10c', 'restored copy: no step exists twice', 0 === $dupes );
		e2e_check( 'r10d', 'restored copy: "BR Sofort" mailed the requeued order once', 1 === e2e_mail_count( 'BR sofort ' . $s['o4'] ) );
		break;

	case 'mig_newer': // Downgrade protection on the copy: the stored schema claims a newer version.
		update_option( Install::DB_OPTION, (string) ( (int) WORKFLOW_CAPTAIN_DB_VERSION + 1 ), false );
		file_put_contents( E2E_LOGS . '/mig-schema.txt', e2e_schema_hash() );
		break;

	case 'mig_newer_check':
		$hash = (string) file_get_contents( E2E_LOGS . '/mig-schema.txt' );
		e2e_check( 'm4', 'downgrade: the tables are unchanged and the option still says the newer version', $hash === e2e_schema_hash() && (string) ( (int) WORKFLOW_CAPTAIN_DB_VERSION + 1 ) === get_option( Install::DB_OPTION ) );
		$diag = Plugin::instance()->diagnostics();
		e2e_check( 'm4b', 'downgrade: diagnostics report schema_state "newer"', 'newer' === ( $diag['environment']['schema_state'] ?? '' ), $diag['environment']['schema_state'] ?? '' );
		$ev = e2e_events( 'woocommerce.order.paid', (string) $e2e_param );
		e2e_check( 'm4c', 'downgrade: an order paid meanwhile is not processed (event stays received or is not written)', array() === $ev || 'received' === $ev[0]['state'], $ev[0]['state'] ?? 'no event row' );
		break;

	case 'mig_interrupt': // An upgrade that broke off: older version number, one index and one table missing.
		global $wpdb;
		file_put_contents( E2E_LOGS . '/mig-counts.json', wp_json_encode( e2e_counts() ) );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}workflow_captain_runs DROP INDEX state_updated" );
		$wpdb->query( "DROP TABLE {$wpdb->prefix}workflow_captain_notes" );
		update_option( Install::DB_OPTION, (string) ( (int) WORKFLOW_CAPTAIN_DB_VERSION - 1 ), false );
		break;

	case 'mig_interrupt_check': // Runs in a new WP-CLI process: boot() ran maybe_upgrade() already.
		global $wpdb;
		$counts = json_decode( (string) file_get_contents( E2E_LOGS . '/mig-counts.json' ), true );
		$idx    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = '{$wpdb->prefix}workflow_captain_runs' AND index_name = 'state_updated'" );
		$now    = e2e_counts();
		unset( $now['notes'], $counts['notes'] );
		e2e_check( 'm6', 'interrupted upgrade: the next WP-CLI call finishes it (index and table back, version current, no row lost)', $idx > 0 && in_array( $wpdb->prefix . 'workflow_captain_notes', e2e_existing_tables(), true ) && WORKFLOW_CAPTAIN_DB_VERSION === get_option( Install::DB_OPTION ) && $counts === $now, array( 'index' => $idx, 'version' => get_option( Install::DB_OPTION ) ) + $now );
		file_put_contents( E2E_LOGS . '/mig-schema.txt', e2e_schema_hash() );
		break;

	case 'mig_repeat_check': // After "wp workflow-captain migrate" ran twice more.
		$hash = (string) file_get_contents( E2E_LOGS . '/mig-schema.txt' );
		e2e_check( 'm8', 'repeated migrate changes nothing (same schema, same rows, consistency clean)', $hash === e2e_schema_hash() && array() === array_filter( Plugin::instance()->engine()->store()->consistency() ), e2e_counts() );
		break;


	// ----- WF-CORE-05: load, batch limits, system cron, two workers at once ----------------------------------------
	case 'load_setup': // Workflow of docs/LAST.md: order paid -> mail to the operator -> tag.
		$id = e2e_publish( 'LOAD', array( 'event' => 'woocommerce.order.paid' ), array( array( 'notify.admin', array( 'subject' => 'LOAD {{subject.id}}', 'message' => 'Bestellung {{subject.id}} bezahlt.' ) ), array( 'workflow.tag.set', array( 'tag' => 'load' ) ) ) );
		$product = new \WC_Product_Simple();
		$product->set_name( 'E2E Last' );
		$product->set_regular_price( '9.90' );
		$product->set_status( 'publish' );
		$product->save();
		e2e_state( array( 'load_wf' => $id, 'load_product' => $product->get_id() ) );
		echo wp_json_encode( array( 'workflow' => $id ) ), "\n";
		break;

	case 'load_orders': // Creates <n> paid orders through WC CRUD; prints the time it took.
		$n       = max( 1, (int) $e2e_param );
		$product = wc_get_product( (int) ( e2e_state()['load_product'] ?? 0 ) );
		$t0      = microtime( true );
		for ( $i = 0; $i < $n; $i++ ) {
			$order = wc_create_order( array( 'status' => 'pending' ) );
			$order->add_product( $product, 1 );
			$order->set_billing_email( 'last' . $i . '@example.test' );
			$order->set_payment_method( 'bacs' );
			$order->calculate_totals();
			$order->save();
			$order->payment_complete();
			if ( 0 === $i % 50 ) {
				wp_cache_flush_runtime(); // Keeps the memory of one long CLI process flat.
			}
		}
		echo wp_json_encode( array( 'orders' => $n, 'seconds' => round( microtime( true ) - $t0, 2 ), 'peak_mb' => round( memory_get_peak_usage( true ) / 1048576, 1 ) ) ), "\n";
		break;

	case 'load_pass': // One worker pass like "wp workflow-captain process --limit=<n>", timed in the process.
		$limit = max( 1, (int) $e2e_param );
		$m0    = memory_get_usage( true );
		$t0    = microtime( true );
		$done  = Plugin::instance()->work( $limit );
		echo wp_json_encode(
			array(
				'limit'   => $limit,
				'events'  => $done[0] ?? null,
				'steps'   => $done[1] ?? null,
				'seconds' => round( microtime( true ) - $t0, 3 ),
				'peak_mb' => round( memory_get_peak_usage( true ) / 1048576, 1 ),
				'boot_mb' => round( $m0 / 1048576, 1 ),
			)
		), "\n";
		break;

	case 'load_backlog':
		global $wpdb;
		echo wp_json_encode(
			array(
				'events_open' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}workflow_captain_events WHERE state IN ('received','retry_wait','processing')" ),
				'steps_open'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}workflow_captain_steps WHERE state IN ('queued','retry_wait','running')" ),
			)
		), "\n";
		break;

	case 'load_check': // After draining: every paid order exactly one run, one mail, one tag; no step twice.
		global $wpdb;
		$wf     = (int) ( e2e_state()['load_wf'] ?? 0 );
		$p      = $wpdb->prefix . 'workflow_captain_';
		$paid   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}events WHERE type = 'woocommerce.order.paid'" );
		$runs   = e2e_state_counts( 'runs' );
		$dupkey = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT step_key FROM {$p}steps GROUP BY step_key HAVING COUNT(*) > 1 ) d" );
		$dupev  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT version_id, event_key FROM {$p}runs GROUP BY version_id, event_key HAVING COUNT(*) > 1 ) d" );
		$tags   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tags WHERE tag = 'load'" );
		$mails  = array_count_values( array_map( static fn( $m ) => (string) $m['subject'], array_filter( e2e_mails(), static fn( $m ) => str_starts_with( (string) $m['subject'], 'LOAD ' ) ) ) );
		$twice  = array_filter( $mails, static fn( $c ) => $c > 1 );
		$open   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}events WHERE state NOT IN ('done','discarded')" );
		$label  = (string) $e2e_param;
		e2e_check( 'l-' . $label, "load {$label}: every paid order has exactly one run, one LOAD mail and one tag; no step or run twice; nothing left open", $paid > 0 && array( 'succeeded' => $paid ) === $runs && count( $mails ) === $paid && array() === $twice && $tags === $paid && 0 === $dupkey && 0 === $dupev && 0 === $open, compact( 'paid', 'runs', 'tags', 'dupkey', 'dupev', 'open' ) + array( 'mails' => count( $mails ), 'mails_twice' => count( $twice ), 'workflow' => $wf ) );
		break;


	// ----- WF-BUILDER-12: helpers for bin/e2e/builder-flows.js (real runs of workflows built in the browser) -------
	case 'b12_order': // <total>,<payment method>: one paid order; prints ORDER=<id>.
		list( $total, $method ) = array_pad( explode( ',', (string) $e2e_param ), 2, 'bacs' );
		$o = e2e_order( (float) $total );
		$o->set_payment_method( $method );
		$o->save();
		$o->payment_complete();
		echo 'ORDER=' . $o->get_id() . "\n";
		break;

	case 'b12_due': // <workflow id>: the waiting steps of this workflow are due now (time travel, "5 minutes later").
		global $wpdb;
		$p = $wpdb->prefix . 'workflow_captain_';
		echo 'MOVED=' . (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}steps s JOIN {$p}runs r ON r.id = s.run_id SET s.due_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE r.workflow_id = %d AND s.state IN ('queued','retry_wait')", (int) $e2e_param ) ) . "\n";
		break;

	case 'b12_runs': // <workflow id>: runs with their steps as JSON (subject id, state, reason, steps).
		$out = array();
		foreach ( e2e_runs( (int) $e2e_param ) as $r ) {
			$ctx   = json_decode( $r['context'], true );
			$steps = array();
			foreach ( Plugin::instance()->engine()->store()->steps_for_run( (int) $r['id'] ) as $st ) {
				$steps[] = array( $st['node_id'], $st['state'], (int) $st['attempts'], $st['last_error'], $st['result'] );
			}
			$out[] = array( 'id' => (int) $r['id'], 'order' => (string) ( $ctx['subject']['id'] ?? '' ), 'state' => $r['state'], 'reason' => $r['last_error'], 'steps' => $steps );
		}
		echo 'JSON=' . wp_json_encode( $out ) . "\n";
		break;

	case 'b12_crash': // The next mail of a "B12" workflow kills the worker (see br_crash).
		add_filter(
			'pre_wp_mail',
			static function ( $short, $atts ) {
				if ( str_contains( (string) $atts['subject'], 'B12 Absturz' ) ) {
					exit( 0 );
				}
				return $short;
			},
			1,
			2
		);
		Plugin::instance()->work( 25 );
		break;


	// ----- WF-SEO-08: post actions with Yoast SEO in the block and the classic editor (bin/e2e/seo.js) ------------
	case 'seo_setup': // Workflow on published posts: tag, note and mail on the post (what the core offers).
		global $wpdb;
		foreach ( (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}workflow_captain_workflows WHERE status = 'active'" ) as $old ) {
			Plugin::instance()->workflows()->pause( (int) $old ); // clean slate: only this workflow reacts
		}
		$id = e2e_publish_graph(
			'SEO Beitrag',
			array(
				array( 'id' => 't', 'type' => 'trigger', 'event' => 'wordpress.post.published' ),
				array( 'id' => 'c', 'type' => 'condition', 'rules' => array( 'op' => 'and', 'items' => array( array( 'field' => 'data.post_type', 'operator' => 'eq', 'value' => 'post' ) ) ), 'missing' => 'false' ),
				array( 'id' => 'tag', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'seo-gesehen' ) ),
				array( 'id' => 'note', 'type' => 'action', 'action' => 'workflow.note.add', 'config' => array( 'text' => 'Beitrag {{subject.id}} veröffentlicht' ) ),
				array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'SEO Beitrag {{subject.id}}', 'message' => 'x' ) ),
				array( 'id' => 'end', 'type' => 'end' ),
			),
			array( array( 'from' => 't', 'to' => 'c' ), array( 'from' => 'c', 'to' => 'tag', 'branch' => 'then' ), array( 'from' => 'c', 'to' => 'end', 'branch' => 'else' ), array( 'from' => 'tag', 'to' => 'note' ), array( 'from' => 'note', 'to' => 'mail' ), array( 'from' => 'mail', 'to' => 'end' ) )
		);
		echo 'WF=' . $id . "\n";
		break;

	case 'seo_draft': // <title>: a draft with Yoast SEO title, description, focus keyphrase and canonical.
		$post = wp_insert_post( array( 'post_title' => (string) $e2e_param, 'post_status' => 'draft', 'post_content' => "<!-- wp:paragraph -->\n<p>Inhalt für den SEO-Test.</p>\n<!-- /wp:paragraph -->" ) );
		update_post_meta( $post, '_yoast_wpseo_title', 'SEO Titel ' . $e2e_param . ' %%sep%% %%sitename%%' );
		update_post_meta( $post, '_yoast_wpseo_metadesc', 'Beschreibung für ' . $e2e_param );
		update_post_meta( $post, '_yoast_wpseo_focuskw', 'testfokus' );
		update_post_meta( $post, '_yoast_wpseo_canonical', 'http://127.0.0.1:8080/kanonisch-' . $post . '/' );
		echo 'POST=' . $post . "\n";
		break;

	case 'seo_snapshot': // <post>: Yoast meta, post row, Yoast indexable and Workflow Captain tags/notes of the post.
		global $wpdb;
		$post = (int) $e2e_param;
		$meta = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s ORDER BY meta_key, meta_id", $post, '\_yoast%' ), ARRAY_A );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT post_title, post_content, post_excerpt, post_status, post_name, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $post ), ARRAY_A );
		$idx  = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}yoast_indexable'" ) ? $wpdb->get_row( $wpdb->prepare( "SELECT title, description, canonical, primary_focus_keyword, is_robots_noindex, post_status, permalink, object_last_modified FROM {$wpdb->prefix}yoast_indexable WHERE object_type = 'post' AND object_id = %d", $post ), ARRAY_A ) : null;
		$p    = $wpdb->prefix . 'workflow_captain_';
		echo 'JSON=' . wp_json_encode(
			array(
				'meta'      => $meta,
				'post'      => $row,
				'indexable' => $idx,
				'tags'      => (array) $wpdb->get_col( $wpdb->prepare( "SELECT tag FROM {$p}tags WHERE subject_type = 'post' AND subject_id = %s", (string) $post ) ),
				'notes'     => (array) $wpdb->get_col( $wpdb->prepare( "SELECT note FROM {$p}notes WHERE subject_type = 'post' AND subject_id = %s", (string) $post ) ),
				'events'    => count( e2e_events( 'wordpress.post.published', (string) $post ) ),
			)
		) . "\n";
		break;

	default:
		echo "unknown section {$e2e_section}\n";
}
