<?php
/**
 * Second suite run, single site: own connector (starter connector) with the Pro license (WF-CONNECTOR-13), timeout
 * after a successful call of the target, restore from a backup, secret rotation (WF-CONNECTOR-12/-13, T88, WF-INT-17).
 * Not shipped.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

global $wpdb;

const S_TICKET_TYPE = 'xstarterconnector.ticket.closed';

/** Notes the starter connector wrote, per ticket ID. */
function s_notes( int $ticket ): array {
	return array_values( array_filter( (array) get_option( 'wfc_starter_connector_notes', array() ), static fn( $n ) => (int) ( $n['ticket_id'] ?? 0 ) === $ticket ) );
}

/** Events of the starter connector for one ticket. */
function s_ticket_events( int $ticket ): array {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, state, last_error FROM {$wpdb->prefix}workflow_captain_events WHERE type = %s AND subject_id = %s ORDER BY id", S_TICKET_TYPE, (string) $ticket ), ARRAY_A );
}

/** Newest run of a workflow with its steps (state:last_error). */
function s_last_run( int $wf ): array {
	$runs = s_runs( $wf );
	$last = $runs ? end( $runs ) : null;
	if ( ! $last ) {
		return array();
	}
	return array(
		'id'    => (int) $last['id'],
		'state' => $last['state'] . ( '' !== (string) $last['last_error'] ? ':' . $last['last_error'] : '' ),
		'steps' => array_map( static fn( $s ) => $s['node_id'] . ':' . $s['state'] . ( '' !== (string) ( $s['last_error'] ?? '' ) ? ':' . $s['last_error'] : '' ), s_steps( (int) $last['id'] ) ),
	);
}

/** The first step of a run that did not succeed. */
function s_open_step( int $run_id ): array {
	foreach ( s_steps( $run_id ) as $s ) {
		if ( ! in_array( $s['state'], array( 'succeeded', 'skipped' ), true ) ) {
			return $s;
		}
	}
	return array();
}

/** Make the waiting steps of a run due now (instead of waiting for the retry delay) [time simulated]. */
function s_due_now( int $run_id ): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}workflow_captain_steps SET due_at = %s WHERE run_id = %d AND state IN ('retry_wait','pending')", gmdate( 'Y-m-d H:i:s', time() - 1 ), $run_id ) );
}

/** Close a ticket as the given user (the demo hook of the starter connector). */
function s_close_ticket( int $ticket, string $priority = 'high', int $user = 0 ): void {
	wp_set_current_user( $user ? $user : s_admin_id() );
	do_action( 'wfc_starter_ticket_closed', $ticket, $priority );
	wp_set_current_user( s_admin_id() );
}

function s_conn_wf(): int {
	return (int) s_state( 'conn_wf' );
}

switch ( $s_section ) {

	// ---- WF-CONNECTOR-13: own connector with the Pro license ----------------------------------------------------
	case 'conn_setup':
		$c   = Plugin::instance()->connectors();
		$all = (array) get_option( \WorkflowCaptain\Connector\ConnectorRegistry::OPTION, array() );
		$all['starter-connector'] = $c->release_entry( 'starter-connector', true, array() );
		update_option( \WorkflowCaptain\Connector\ConnectorRegistry::OPTION, $all, false );
		$u = get_user_by( 'login', 'connadmin' );
		$u = $u ? (int) $u->ID : (int) wp_insert_user( array( 'user_login' => 'connadmin', 'user_pass' => 'connadmin-e2e-pass', 'user_email' => 'connadmin@example.test', 'role' => 'administrator' ) );
		s_state( 'conn_user', $u );
		s_pause_all_workflows();
		$wfs                     = Plugin::instance()->workflows();
		list( $id, $violations ) = $wfs->save(
			null,
			'Suite Connector',
			array(
				'nodes' => array(
					array( 'id' => 't', 'type' => 'trigger', 'event' => S_TICKET_TYPE ),
					array( 'id' => 'a0', 'type' => 'action', 'action' => 'xstarterconnector.ticket.note', 'config' => array( 'label' => 'suite' ) ),
					array( 'id' => 'end', 'type' => 'end' ),
				),
				'edges' => array( array( 'from' => 't', 'to' => 'a0' ), array( 'from' => 'a0', 'to' => 'end' ) ),
			)
		);
		$pub = $id ? $wfs->publish( (int) $id, $u ) : array( 'not_saved' );
		s_state( 'conn_wf', (int) $id );
		s_check( 'kc0', 'connector released by the operator; an admin publishes "ticket closed -> note" (Pro license with workflow.custom_connectors)', null === $c->reason( 'starter-connector' ) && $id && array() === $violations && array() === $pub, array( 'reason' => $c->reason( 'starter-connector' ), 'violations' => $violations, 'publish' => $pub ) );
		break;

	case 'conn_run':
		s_close_ticket( 42 );
		s_work( 4 );
		$run = s_last_run( s_conn_wf() );
		s_check( 'kc1', 'ticket 42 closed: event captured, run succeeded, one note', 'succeeded' === ( $run['state'] ?? '' ) && 1 === count( s_notes( 42 ) ) && 1 === count( s_ticket_events( 42 ) ), array( 'run' => $run, 'notes' => count( s_notes( 42 ) ) ) );
		$runs = count( s_runs( s_conn_wf() ) );
		s_close_ticket( 42 );
		s_close_ticket( 42 );
		s_work( 4 );
		s_check( 'kc2', 'duplicate: the same ticket closed twice more stores no new event and starts no run (discriminator "once")', 1 === count( s_ticket_events( 42 ) ) && count( s_runs( s_conn_wf() ) ) === $runs && 1 === count( s_notes( 42 ) ), array( 'events' => count( s_ticket_events( 42 ) ), 'runs' => count( s_runs( s_conn_wf() ) ) ) );
		break;

	case 'conn_timeout':
		// The note is written, then the call fails like a timeout (suite mu-plugin, option e2e_suite_note_timeout).
		update_option( 'e2e_suite_note_timeout', 1, false );
		s_close_ticket( 43 );
		s_work( 4 );
		$run  = s_last_run( s_conn_wf() );
		$step = s_open_step( (int) $run['id'] );
		s_check( 'kc3', 'timeout after the note was written: the idempotent step waits for a retry (no failure, no decision needed)', 'retry_wait' === ( $step['state'] ?? '' ) && 1 === count( s_notes( 43 ) ), array( 'run' => $run, 'notes' => count( s_notes( 43 ) ) ) );
		s_due_now( (int) $run['id'] );
		s_work( 3 );
		$after = s_last_run( s_conn_wf() );
		$steps = s_steps( (int) $run['id'] );
		$res   = (array) json_decode( (string) ( array_values( array_filter( $steps, static fn( $s ) => 'a0' === $s['node_id'] ) )[0]['result'] ?? '' ), true );
		s_check( 'kc4', 'retry with the same idempotency key: the connector sees the key, writes no second note, run succeeded', 'succeeded' === ( $after['state'] ?? '' ) && 1 === count( s_notes( 43 ) ) && true === ( $res['repeated'] ?? null ), array( 'run' => $after, 'result' => $res, 'notes' => count( s_notes( 43 ) ) ) );
		break;

	case 'conn_rights':
		$u    = (int) s_state( 'conn_user' );
		$user = new WP_User( $u );
		$user->set_role( 'editor' );
		s_close_ticket( 44 );
		s_work( 4 );
		$run = s_last_run( s_conn_wf() );
		s_check( 'kc5', 'publisher lost manage_options: the connector step pauses (not_authorized), no note', str_starts_with( (string) ( $run['state'] ?? '' ), 'paused:not_authorized' ) && 0 === count( s_notes( 44 ) ), $run );
		$user->set_role( 'administrator' );
		$ok = Plugin::instance()->workflows()->continue_step( (int) s_open_step( (int) $run['id'] )['id'], false );
		s_work( 3 );
		s_check( 'kc6', 'right back: continuing the step writes the note, run succeeded', $ok && 'succeeded' === ( s_last_run( s_conn_wf() )['state'] ?? '' ) && 1 === count( s_notes( 44 ) ), s_last_run( s_conn_wf() ) );
		break;

	case 'conn_app_prep':
		// An event of the connector waits for the worker; then the connector plugin is deactivated.
		s_close_ticket( 45 );
		s_state( 'conn_app_event', (int) ( s_ticket_events( 45 )[0]['id'] ?? 0 ) );
		break;

	case 'conn_app_off':
		s_work( 3 );
		$ev = s_ticket_events( 45 );
		s_check( 'kc7', 'connector plugin deactivated: its waiting event pauses (adapter_unregistered), nothing lost, no run', 'paused' === ( $ev[0]['state'] ?? '' ) && 'adapter_unregistered' === ( $ev[0]['last_error'] ?? '' ) && 0 === count( s_notes( 45 ) ), $ev );
		$wf = s_publish( 'Suite Connector fehlt', array( 'event' => S_TICKET_TYPE ), array( array( 'workflow.tag.set', array( 'tag' => 'x' ) ) ), true );
		s_check( 'kc8', 'connector deactivated: a new workflow with its trigger cannot be published', 0 === $wf );
		break;

	case 'conn_app_on':
		$n = Plugin::instance()->consumer()->requeue_many( array( (int) s_state( 'conn_app_event' ) ) );
		s_work( 4 );
		s_check( 'kc9', 'connector active again: requeue processes the paused event, note written once', 1 === $n && 'done' === ( s_ticket_events( 45 )[0]['state'] ?? '' ) && 1 === count( s_notes( 45 ) ), array( 'requeued' => $n, 'event' => s_ticket_events( 45 ), 'notes' => count( s_notes( 45 ) ) ) );
		break;

	case 'conn_major':
		// The connector was updated to contract 2.0.0 (copy of the example with a new MAJOR).
		$c = Plugin::instance()->connectors();
		s_close_ticket( 46 );
		s_work( 4 );
		$run = s_last_run( s_conn_wf() );
		s_check( 'kc10', 'connector updated to contract 2.0.0: the run of the workflow published on 1.x pauses at its step (provider_contract_changed), no note', str_contains( implode( ',', (array) ( $run['steps'] ?? array() ) ), 'provider_contract_changed' ) && 0 === count( s_notes( 46 ) ) && '2.0.0' === $c->get( 'starter-connector' )->contract_version(), $run );
		s_state( 'conn_major_run', (int) $run['id'] );
		break;

	case 'conn_major_back':
		$wf = s_publish( 'Suite Connector 2', array( 'event' => S_TICKET_TYPE ), array( array( 'xstarterconnector.ticket.note', array( 'label' => 'neu' ) ) ), true );
		s_check( 'kc11', 'back on contract 1.0.0: the connector is registered again with its old MAJOR', '1.0.0' === Plugin::instance()->connectors()->get( 'starter-connector' )->contract_version() && $wf > 0, $wf );
		Plugin::instance()->workflows()->pause( $wf );
		Plugin::instance()->workflows()->pause( s_conn_wf() );
		break;

	// ---- T88 / WF-INT-17 / WF-CONNECTOR-13: timeout after a successful call of the target ------------------------
	case 'slow_send':
		// The receiver answers the next request on /step only after 12 s (logs/suite-hooks-slow); SafeHttp waits 10 s.
		Plugin::instance()->workflows()->resume( (int) s_state( 'hook_wf' ) );
		file_put_contents( '/home/user/wp-e2e/logs/suite-hooks-slow', '/step' );
		$before   = count( s_hook_rows() );
		$products = (array) s_state( 'products' );
		$order    = s_wc_order( array( (int) $products['ticket'] ) );
		s_work( 4 );
		$rows = array_slice( s_hook_rows(), $before );
		$step = array_values( array_filter( $rows, static fn( $r ) => 'woocommerce.order.paid' === s_header( $r, 'X-Workflow-Captain-Event-Type' ) ) );
		$run  = s_last_run( (int) s_state( 'hook_wf' ) );
		s_state( 'slow_run', (int) $run['id'] );
		s_check( 'tm1', 'receiver got the webhook but answered after the timeout: the step waits for a retry (transport error), the run did not fail', 1 === count( $step ) && str_contains( implode( ',', $run['steps'] ), 'retry_wait' ), array( 'requests' => count( $step ), 'run' => $run ) );
		s_state( 'slow_event_id', $step ? s_header( $step[0], 'X-Workflow-Captain-Event-Id' ) : '' );
		break;

	case 'slow_retry':
		$before = count( s_hook_rows() );
		s_due_now( (int) s_state( 'slow_run' ) );
		s_work( 3 );
		$rows = array_values( array_filter( array_slice( s_hook_rows(), $before ), static fn( $r ) => 'woocommerce.order.paid' === s_header( $r, 'X-Workflow-Captain-Event-Type' ) ) );
		$run  = s_last_run( (int) s_state( 'hook_wf' ) );
		$id   = $rows ? s_header( $rows[0], 'X-Workflow-Captain-Event-Id' ) : '';
		s_check( 'tm2', 'retry sends again with the same event ID (the receiver can drop the duplicate), run succeeded', 1 === count( $rows ) && '' !== $id && $id === (string) s_state( 'slow_event_id' ) && 'succeeded' === $run['state'] && s_signed( $rows[0], S_HMAC ), array( 'requests' => count( $rows ), 'same_id' => $id === (string) s_state( 'slow_event_id' ), 'run' => $run ) );
		break;

	// ---- T88 / WF-INT-17: restore from a backup -----------------------------------------------------------------
	case 'restore_prep':
		// An order was paid; its event waits for the worker. run-suite.sh dumps the tables of Workflow Captain now.
		$products = (array) s_state( 'products' );
		$order    = s_wc_order( array( (int) $products['ticket'] ) );
		$ev       = s_events( 'woocommerce.order.paid', 'subject_id = ' . (int) $order->get_id() );
		s_state( 'restore_order', $order->get_id() );
		s_state( 'restore_event', $ev ? s_env( $ev[0] )['id'] : '' );
		s_check( 'rs0', 'restore: the event of a new order waits for the worker (backup taken now)', $ev && 'received' === $ev[0]['state'], $ev ? $ev[0]['state'] : 'none' );
		break;

	case 'restore_run':
		// Param 1: before the restore, 2: after the tables were restored from the backup.
		$before = count( s_hook_rows() );
		s_work( 4 );
		$rows = array_values( array_filter( array_slice( s_hook_rows(), $before ), static fn( $r ) => 'woocommerce.order.paid' === s_header( $r, 'X-Workflow-Captain-Event-Type' ) ) );
		$id   = $rows ? s_header( $rows[0], 'X-Workflow-Captain-Event-Id' ) : '';
		$ev   = s_events( 'woocommerce.order.paid', 'subject_id = ' . (int) s_state( 'restore_order' ) );
		if ( '1' === $s_param ) {
			s_check( 'rs1', 'before the restore: the webhook went out once with the event ID of Workflow Captain', 1 === count( $rows ) && $id === (string) s_state( 'restore_event' ) && 'done' === ( $ev[0]['state'] ?? '' ), array( 'requests' => count( $rows ), 'event' => $ev[0]['state'] ?? null ) );
		} else {
			s_check( 'rs2', 'after the restore: the event is processed again (at least once), the repeated webhook carries the same event ID; one event, no second event row', 1 === count( $rows ) && $id === (string) s_state( 'restore_event' ) && 1 === count( $ev ) && 'done' === $ev[0]['state'], array( 'requests' => count( $rows ), 'same_id' => $id === (string) s_state( 'restore_event' ), 'events' => count( $ev ) ) );
			Plugin::instance()->workflows()->pause( (int) s_state( 'hook_wf' ) );
		}
		break;

	// ---- WF-CONNECTOR-13 / WF-SEC-02: secret rotation --------------------------------------------------------------
	case 'rotate_conn':
		$f    = s_hooks_feature();
		$page = $f->page();
		$a    = $page->dispatch( 'create', '', array( 'kind' => 'n8n', 'name' => 'Suite Rotation', 'key' => 'suite-rot', 'direction' => 'inbound', 'triggers' => 'rot_lead: lead_id:integer' ) );
		$old  = (array) ( $f->connections()->take_reveal( get_current_user_id() )['secrets'] ?? array() );
		$act  = $page->dispatch( 'activate', 'suite-rot', array() );
		$r1   = s_inbound( 'suite-rot', 'rot_lead', array( 'lead_id' => 1 ), (string) ( $old['inbound'] ?? '' ) );
		s_check( 'sr1', 'inbound connection with a secret in the vault (not wp-config): signed request accepted', 'created' === ( $a['wcp_notice'] ?? '' ) && 'activated' === ( $act['wcp_notice'] ?? '' ) && 202 === $r1['code'], array( $a, $act, $r1['code'] ) );
		$g   = $page->dispatch( 'regenerate', 'suite-rot', array( 'field' => 'inbound' ) );
		$new = (array) ( $f->connections()->take_reveal( get_current_user_id() )['secrets'] ?? array() );
		$r2  = s_inbound( 'suite-rot', 'rot_lead', array( 'lead_id' => 2 ), (string) ( $old['inbound'] ?? '' ) );
		$r3  = s_inbound( 'suite-rot', 'rot_lead', array( 'lead_id' => 3 ), (string) ( $new['inbound'] ?? '' ) );
		s_check( 'sr2', 'secret rotated: the old secret is refused at once (401), the new one accepted (202)', 'regenerated' === ( $g['wcp_notice'] ?? '' ) && 401 === $r2['code'] && 202 === $r3['code'] && ( $new['inbound'] ?? '' ) !== ( $old['inbound'] ?? '' ), array( $g, $r2['code'], $r3['code'] ) );
		s_state( 'rot_secret', (string) ( $new['inbound'] ?? '' ) );
		break;

	case 'rotate_key':
		// Param before|rekeyed|final: the master key of Workflow Captain changed in wp-config.php (suite-keys.php).
		$state = s_hooks_feature()->connections()->secret_state( 'suite-rot', 'inbound' );
		$r     = s_inbound( 'suite-rot', 'rot_lead', array( 'lead_id' => 4 ), (string) s_state( 'rot_secret' ) );
		if ( 'before' === $s_param ) {
			s_check( 'sr3', 'new master key, old one as WORKFLOW_CAPTAIN_SECRET_KEY_PREVIOUS: the secret is still readable and accepted before re-encryption', 'set' === $state && 202 === $r['code'], array( $state, $r['code'] ) );
		} elseif ( 'rekeyed' === $s_param ) {
			s_check( 'sr4', '"wp workflow-captain secrets" re-encrypted the stores; after removing the previous key the secret works with the new key alone', 'set' === $state && 202 === $r['code'], array( $state, $r['code'] ) );
		}
		break;

	default:
		s_check( '?', 'unknown section ' . $s_section, false );
}
