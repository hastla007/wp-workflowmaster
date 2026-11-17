<?php
/**
 * More single-site sections of bin/e2e/suite/checks.php: real events, refund (T87), provider states (T88), webhooks,
 * license expiry. Not shipped.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

global $wpdb;

switch ( $s_section ) {

	// ---- Real events of every provider ----------------------------------------------------------------------------
	case 'events':
		s_pause_all_workflows();
		// One workflow per provider, so every event also starts a run (tag on the subject).
		$wfs = array();
		foreach ( array( 'license.license.issued', 'voucher.voucher.issued_standalone', 'pass.order.tickets_issued', 'affiliate.commission.created', 'voucher.order.vouchers_issued' ) as $type ) {
			$wfs[ $type ] = s_publish( 'Suite ' . $type, array( 'event' => $type ), array( array( 'workflow.tag.set', array( 'tag' => 'suite-' . str_replace( '.', '-', $type ) ) ) ) );
		}
		s_state( 'event_workflows', $wfs );
		// License server: a new license (shop of the vendor) and a status change by the admin.
		$plans   = (array) s_state( 'plan_products' );
		$license = s_buy( s_customer( 'suitekunde2' ), (int) $plans['wcp_basic'] );
		s_state( 'event_license', (int) ( $license['id'] ?? 0 ) );
		\WPLC\License\LicenseLifecycle::admin_set_status( (int) $license['id'], 'suspended', 'suite e2e', s_admin_id() );
		// Voucher Captain: a voucher issued by the admin.
		$v = vcap()->issuer->issue_standalone( array( 'amount' => 25, 'source' => 'admin', 'recipient_email' => 'gutschein@customer.test', 'send' => false ) );
		s_state( 'event_voucher', is_wp_error( $v ) ? 0 : (int) ( is_object( $v ) ? $v->id : 0 ) );
		// One WooCommerce order: a Pass Captain ticket, a Voucher Captain voucher, attributed to an affiliate.
		$ticket    = s_pass_ticket_product();
		$voucher   = s_voucher_product();
		$affiliate = s_affiliate();
		$order     = s_wc_order( array( $ticket, $voucher ), $affiliate );
		s_state( 'event_order', $order->get_id() );
		s_state( 'products', array( 'ticket' => $ticket, 'voucher' => $voucher ) );
		s_run_as();
		s_check( 'e0', 'setup: license, voucher, ticket product, affiliate and a paid WooCommerce order', $license && ! is_wp_error( $v ) && $affiliate > 0 && $order->is_paid(), array( 'license' => $license['id'] ?? 0, 'voucher' => is_wp_error( $v ) ? $v->get_error_code() : 'ok', 'affiliate' => $affiliate, 'order' => $order->get_id() . ' ' . $order->get_status() ) );
		break;

	case 'events_check':
		s_run_as();
		// Envelopes are read before the worker runs: after processing the core minimizes data (R-12.4).
		$val  = array();
		$dump = '';
		foreach ( s_events( '%' ) as $row ) {
			$dump .= $row['envelope'];
			if ( ! preg_match( '/^(pass|voucher|affiliate|license)\./', $row['type'] ) ) {
				continue;
			}
			$violations = \WorkflowCaptain\Envelope\Validator::violations( s_env( $row ) );
			if ( array() !== $violations ) {
				$val[] = $row['type'] . ':' . implode( ',', (array) $violations );
			}
		}
		s_check( 'e6', 'every stored suite envelope is valid (core validator, before processing)', array() === $val, $val );
		$pii = array_values( array_filter( array( 'suite-buyer@customer.test', 'gutschein@customer.test', 'suitekunde2@customer.test', 'Kaeufer' ), static fn( $n ) => str_contains( $dump, $n ) ) );
		s_check( 'e8', 'no e-mail address or name of buyer, recipient or customer in any stored envelope (R-5, before processing)', array() === $pii, $pii );
		s_work( 8 );
		$seen = array();
		foreach ( array( 'license', 'voucher', 'pass', 'affiliate', 'woocommerce' ) as $p ) {
			$seen[ $p ] = s_types_seen( $p . '.' );
		}
		s_check( 'e1', 'license server events captured (issued, status_changed)', in_array( 'license.license.issued', $seen['license'], true ) && in_array( 'license.license.status_changed', $seen['license'], true ), $seen['license'] );
		s_check( 'e2', 'Voucher Captain events captured (standalone voucher, vouchers of the order)', in_array( 'voucher.voucher.issued_standalone', $seen['voucher'], true ) && in_array( 'voucher.order.vouchers_issued', $seen['voucher'], true ), $seen['voucher'] );
		s_check( 'e3', 'Pass Captain events captured from the WooCommerce order (order paid, tickets issued)', in_array( 'pass.order.tickets_issued', $seen['pass'], true ) && in_array( 'pass.order.paid', $seen['pass'], true ), $seen['pass'] );
		s_check( 'e4', 'Affiliate Captain events captured (commission created)', in_array( 'affiliate.commission.created', $seen['affiliate'], true ), $seen['affiliate'] );
		$bad = array();
		foreach ( s_events( '%' ) as $row ) {
			if ( preg_match( '/^(pass|voucher|affiliate|license)\./', $row['type'] ) && 'done' !== $row['state'] ) {
				$bad[] = $row['type'] . ':' . $row['state'] . ':' . $row['last_error'];
			}
		}
		s_check( 'e5', 'every suite event processed (done), none paused or failed', array() === $bad, $bad );
		$runs = array();
		foreach ( (array) s_state( 'event_workflows' ) as $type => $wf ) {
			$runs[ $type ] = implode( ',', array_column( s_runs( (int) $wf ), 'state' ) );
		}
		s_check( 'e7', 'each provider workflow ran and succeeded', array() === array_filter( $runs, static fn( $x ) => '' === $x || str_contains( $x, 'fail' ) || ! str_contains( $x, 'succeeded' ) ), $runs );
		s_pause_all_workflows();
		break;

	// ---- The four voucher commands (VC-1 in Voucher Captain, actions in Workflow Captain Pro) -------------------------
	case 'commands':
		s_pause_all_workflows();
		$actions = array_keys( (array) Plugin::instance()->engine()->actions()->all() );
		$want    = array( 'voucher.issue', 'voucher.void', 'voucher.reactivate', 'voucher.redeem' );
		s_check( 'c1', 'Workflow Captain Pro offers the four voucher commands as actions', array() === array_diff( $want, $actions ), implode( ',', $actions ) );
		s_check( 'c2', 'Voucher Captain core has the four entry points (contract >= 1.3.0)', function_exists( 'vcap_command_issue' ) && function_exists( 'vcap_command_void' ) && function_exists( 'vcap_command_reactivate' ) && function_exists( 'vcap_command_redeem' ) && version_compare( VCAP_CONTRACT_VERSION, '1.3.0', '>=' ), VCAP_CONTRACT_VERSION );
		update_option( 'vcap_command_void_enabled', 'no' );
		$wf_void = s_publish( 'Suite Gutschein sperren', array( 'event' => 'voucher.voucher.issued_standalone' ), array( array( 'voucher.void', array( 'reason' => 'cleanup', 'voucher_field' => 'subject.id' ) ) ) );
		$v1      = vcap()->issuer->issue_standalone( array( 'amount' => 10, 'source' => 'admin', 'recipient_email' => 'cmd1@customer.test', 'send' => false ) );
		s_work( 4 );
		$runs = s_runs( $wf_void );
		$st   = $runs ? s_steps( (int) end( $runs )['id'] ) : array();
		s_check( 'c3', 'voucher.void switched off in Voucher Captain: the step is refused (command_disabled, final), the run needs a decision, voucher stays active', $runs && in_array( end( $runs )['state'], array( 'failed', 'action_required' ), true ) && 'active' === vcap()->vouchers->get( $v1->id )->status, array( 'run' => end( $runs ), 'steps' => array_map( static fn( $x ) => ( $x['state'] ?? '' ) . ':' . ( $x['last_error'] ?? '' ), $st ) ) );
		update_option( 'vcap_command_void_enabled', 'yes' );
		$v2 = vcap()->issuer->issue_standalone( array( 'amount' => 10, 'source' => 'admin', 'recipient_email' => 'cmd2@customer.test', 'send' => false ) );
		s_work( 4 );
		$runs = s_runs( $wf_void );
		$void = s_events( 'voucher.voucher.voided', $wpdb->prepare( 'subject_id = %s', (string) $v2->id ) );
		s_check( 'c4', 'voucher.void switched on: the run voids the voucher; its voided event is captured as caused by the workflow', 'succeeded' === end( $runs )['state'] && 'void' === vcap()->vouchers->get( $v2->id )->status && $void && (int) ( s_env( $void[0] )['chain_depth'] ?? 0 ) >= 1, array( 'run' => end( $runs )['state'], 'status' => vcap()->vouchers->get( $v2->id )->status, 'event' => $void ? s_env( $void[0] )['chain_depth'] ?? null : 'none' ) );
		$proto = (array) vcap()->commands->protocol();
		$mine  = array_values( array_filter( $proto, static fn( $p ) => 'voucher.void' === ( $p['command'] ?? '' ) ) );
		s_check( 'c5', 'protocol of Voucher Captain lists both calls (refused and done) with workflow, run and step, without code', 2 <= count( $mine ) && ! str_contains( wp_json_encode( $mine ), (string) $v2->code ), array_map( static fn( $p ) => ( $p['status'] ?? '' ) . ':' . ( $p['code'] ?? '' ) . ':wf' . ( $p['workflow_id'] ?? '' ), $mine ) );
		Plugin::instance()->workflows()->pause( $wf_void );
		update_option( 'vcap_command_issue_enabled', 'yes' );
		$too_much = s_publish( 'Suite Gutschein zu hoch', array( 'event' => 'wordpress.user.registered' ), array( array( 'voucher.issue', array( 'reason' => 'goodwill', 'recipient_field' => 'actor_user_id', 'amount_minor' => 20000, 'currency' => 'EUR' ) ) ), true );
		s_check( 'c6', 'voucher.issue above the ceiling of 100 EUR cannot be published', 0 === $too_much );
		$wf_issue = s_publish( 'Suite Gutschein ausstellen', array( 'event' => 'wordpress.user.registered' ), array( array( 'voucher.issue', array( 'reason' => 'goodwill', 'recipient_field' => 'actor_user_id', 'amount_minor' => 500, 'currency' => 'EUR' ) ) ) );
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vcap_vouchers" );
		wp_create_user( 'cmduser' . wp_generate_password( 4, false, false ), wp_generate_password(), 'cmduser-' . wp_generate_password( 4, false, false ) . '@customer.test' );
		s_work( 4 );
		$runs  = s_runs( $wf_issue );
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vcap_vouchers" );
		s_check( 'c7', 'voucher.issue: a new user (by the admin) gets a 5 EUR voucher through the command, run succeeds', $runs && 'succeeded' === end( $runs )['state'] && $after === $before + 1, array( 'runs' => array_column( $runs, 'state' ), 'vouchers' => $after - $before ) );
		s_work( 2 );
		s_pause_all_workflows();
		update_option( 'vcap_command_issue_enabled', 'no' );
		update_option( 'vcap_command_void_enabled', 'no' );
		break;

	// ---- T87: one WooCommerce refund, linked to Voucher, Pass and Affiliate as one canonical source -----------------
	case 'refund':
		$order_id = (int) s_state( 'event_order' );
		$order    = wc_get_order( $order_id );
		s_state( 'refund_mark', (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}workflow_captain_events" ) );
		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$items[ $item_id ] = array( 'qty' => $item->get_quantity(), 'refund_total' => $item->get_total(), 'refund_tax' => array() );
		}
		$refund = wc_create_refund( array( 'order_id' => $order_id, 'amount' => $order->get_total(), 'reason' => 'Suite E2E Rückgabe', 'line_items' => $items, 'refund_payment' => false, 'restock_items' => false ) );
		s_state( 'refund_id', is_wp_error( $refund ) ? 0 : $refund->get_id() );
		s_check( 'r0', 'full WooCommerce refund of the order with ticket, voucher and affiliate', ! is_wp_error( $refund ), is_wp_error( $refund ) ? $refund->get_error_code() . ' ' . $refund->get_error_message() : 'refund ' . $refund->get_id() . ', order ' . wc_get_order( $order_id )->get_status() );
		break;

	case 'refund_check':
		s_run_as();
		$mark      = (int) s_state( 'refund_mark' );
		$order_id  = (string) s_state( 'event_order' );
		$refund_id = (int) s_state( 'refund_id' );
		$after     = array_values( array_filter( s_events( '%', 'id > ' . $mark ), static fn( $r ) => ! str_starts_with( $r['type'], 'wordpress.' ) ) );
		$by_type   = array();
		foreach ( $after as $row ) {
			$env                        = s_env( $row );
			$by_type[ $row['type'] ][] = array(
				'subject' => ( $env['subject']['type'] ?? '' ) . ':' . ( $env['subject']['id'] ?? '' ),
				'data'    => array_intersect_key( (array) ( $env['data'] ?? array() ), array_flip( array( 'refund_id', 'order_id', 'void_reason', 'reason_class', 'refund_source', 'source', 'full', 'full_refund', 'adjustment_type' ) ) ),
				'state'   => $row['state'],
			);
		}
		s_state( 'refund_events', $by_type );
		$wc = $by_type['woocommerce.order.refunded'] ?? array();
		s_check( 'r1', 'exactly one woocommerce.order.refunded with the refund ID (the canonical refund)', 1 === count( $wc ) && (int) ( $wc[0]['data']['refund_id'] ?? 0 ) === $refund_id && 'order:' . $order_id === $wc[0]['subject'], $wc );
		$voided = $by_type['voucher.voucher.voided'] ?? array();
		s_check( 'r2', 'Voucher Captain: voucher voided because of the refund (void_reason refund, same order)', 1 <= count( $voided ) && 'refund' === ( $voided[0]['data']['void_reason'] ?? '' ) && (string) ( $voided[0]['data']['order_id'] ?? '' ) === $order_id, $voided ? $voided : array_keys( $by_type ) );
		$pass     = $by_type['pass.order.refunded'] ?? array();
		$pass_ref = array_values( array_filter( $pass, static fn( $p ) => 'woocommerce' === ( $p['data']['reason_class'] ?? '' ) ) );
		s_check( 'r3', 'Pass Captain: refund mirrored from WooCommerce (pass.order.refunded with reason_class woocommerce, pass.refund.completed)', array() !== $pass_ref && array() !== ( $by_type['pass.refund.completed'] ?? array() ), array( 'order_refunded' => $pass, 'refund_completed' => $by_type['pass.refund.completed'] ?? array() ) );
		$adj = $by_type['affiliate.commission.adjusted'] ?? array();
		s_check( 'r4', 'Affiliate Captain: commission adjusted on the same WooCommerce order (subject order, adjustment_type refund)', 1 <= count( $adj ) && 'order:' . $order_id === $adj[0]['subject'] && 'refund' === ( $adj[0]['data']['adjustment_type'] ?? '' ), $adj ? $adj : array_keys( $by_type ) );
		s_check( 'r4b', 'Affiliate Captain: the full refund carries the WooCommerce refund_id (optional field; full reversal by order status has none)', 1 <= count( $adj ) && (int) ( $adj[0]['data']['refund_id'] ?? 0 ) === $refund_id, $adj );
		$corr = array();
		foreach ( s_events( '%', 'id > ' . $mark ) as $row ) {
			$corr[ (string) ( s_env( $row )['correlation_id'] ?? '' ) ] = true;
		}
		s_check( 'r4c', 'one refund, one correlation? Each provider event starts its own correlation (spec 6: correlation_id only along workflow chains)', count( $corr ) > 1, count( $corr ) . ' correlation IDs for ' . count( $after ) . ' events' );
		// One source: every product event of this refund points back to the WooCommerce order (subject or order_id).
		$foreign = array();
		foreach ( $by_type as $type => $rows ) {
			foreach ( $rows as $r ) {
				$order_ref = 'order:' . $order_id === $r['subject'] || (string) ( $r['data']['order_id'] ?? '' ) === $order_id;
				if ( preg_match( '/^(voucher|affiliate)\\..*(refund|voided|adjusted)/', $type ) && ! $order_ref ) {
					$foreign[] = $type . ' ' . $r['subject'];
				}
			}
		}
		s_check( 'r5', 'no refund event of Voucher or Affiliate without a link to the WooCommerce order', array() === $foreign, $foreign );
		s_work( 8 );
		$after      = array_values( array_filter( s_events( '%', 'id > ' . $mark ), static fn( $r ) => ! str_starts_with( $r['type'], 'wordpress.' ) ) );
		$wc_refunds = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE parent_order_id = %d AND type = 'shop_order_refund'", (int) $order_id ) );
		s_check( 'r6', 'WooCommerce holds exactly one refund (no product plugin created a second refund)', 1 === $wc_refunds, $wc_refunds );
		$bad = array_values( array_filter( $after, static fn( $r ) => 'done' !== $r['state'] ) );
		s_check( 'r7', 'all refund events processed (done)', array() === $bad, array_map( static fn( $r ) => $r['type'] . ':' . $r['state'] . ':' . $r['last_error'], $bad ) );
		echo wp_json_encode( $by_type, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
		break;

	default:
		require __DIR__ . '/sections-ops.php';
}
