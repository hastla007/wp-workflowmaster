<?php
/**
 * Webhooks with a license against a local receiver, license expiry and renewal (WF-CONNECTOR-12). Not shipped.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

global $wpdb;

/** Sends a signed inbound request over real HTTP to the suite site. */
function s_inbound( string $key, string $trigger, array $data, string $secret, ?string $nonce = null, int $ts = 0 ): array {
	$body  = wp_json_encode( array( 'data' => $data ) );
	$ts    = $ts ? $ts : time();
	$nonce = $nonce ?? bin2hex( random_bytes( 12 ) );
	$res   = wp_remote_post(
		'http://127.0.0.1:8083/wp-json/workflow-captain-pro/v1/hooks/' . $key,
		array(
			'timeout' => 20,
			'headers' => array(
				'Content-Type'                  => 'application/json',
				'X-Workflow-Captain-Timestamp'  => (string) $ts,
				'X-Workflow-Captain-Nonce'      => $nonce,
				'X-Workflow-Captain-Signature'  => \WorkflowCaptain\Security\InboundGuard::sign( $secret, $ts, $nonce, $body ),
				'X-Workflow-Captain-Trigger'    => $trigger,
			),
			'body'    => $body,
		)
	);
	return array( 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_body( $res ), 'nonce' => $nonce );
}

function s_header( array $row, string $name ): string {
	foreach ( (array) ( $row['headers'] ?? array() ) as $k => $v ) {
		if ( 0 === strcasecmp( $k, $name ) ) {
			return (string) $v;
		}
	}
	return '';
}

/** Whether a receiver row carries a valid signature for the secret. */
function s_signed( array $row, string $secret ): bool {
	$sig = s_header( $row, 'X-Workflow-Captain-Signature' );
	return '' !== $sig && hash_equals( \WorkflowCaptain\Security\InboundGuard::sign( $secret, (int) s_header( $row, 'X-Workflow-Captain-Timestamp' ), s_header( $row, 'X-Workflow-Captain-Nonce' ), (string) $row['body'] ), $sig );
}

switch ( $s_section ) {

	case 'hooks_setup':
		$f    = s_hooks_feature();
		$page = $f ? $f->page() : null;
		$base = array(
			'kind'          => 'n8n',
			'base_url'      => 'http://127.0.0.1:8086',
			'allow_private' => 1,
			'allow_http'    => 1,
			'cidr'          => '127.0.0.1/32',
		);
		$a = $page ? $page->dispatch( 'create', '', $base + array( 'name' => 'Suite n8n', 'key' => 'suite-n8n', 'direction' => 'both', 'url' => '/forward', 'events' => array( 'pass.order.paid' ), 'fields' => array( 'pass.order.paid' => 'currency, total_minor, ticket_count' ), 'forward' => 1, 'triggers' => 'crm_lead: lead_id:integer, source' ) ) : array();
		$b = $page ? $page->dispatch( 'create', '', $base + array( 'name' => 'Suite Schritt', 'key' => 'suite-step', 'direction' => 'outbound', 'url' => '/step', 'events' => array( 'woocommerce.order.paid' ), 'fields' => array( 'woocommerce.order.paid' => 'currency, total_minor' ) ) ) : array();
		s_check( 'h1', 'two n8n connections created (private exception for 127.0.0.1:8086, HTTP)', 'created' === ( $a['wcp_notice'] ?? '' ) && 'created' === ( $b['wcp_notice'] ?? '' ), array( $a, $b ) );
		$ta = $page->dispatch( 'test', 'suite-n8n', array() );
		$tb = $page->dispatch( 'test', 'suite-step', array() );
		s_check( 'h2', 'connection test reaches the local receiver (ok:200)', 'test_ok' === ( $ta['wcp_notice'] ?? '' ) && 'test_ok' === ( $tb['wcp_notice'] ?? '' ), array( $ta, $tb ) );
		$rows = array_values( array_filter( s_hook_rows(), static fn( $r ) => 'workflow.connection.test' === s_header( $r, 'X-Workflow-Captain-Event-Type' ) && str_starts_with( (string) $r['uri'], '/forward' ) ) );
		s_check( 'h3', 'test request signed with the HMAC secret from wp-config.php (InboundGuard::sign)', $rows && s_signed( end( $rows ), S_HMAC ), $rows ? s_header( end( $rows ), 'X-Workflow-Captain-Signature' ) : 'no request' );
		$aa = $page->dispatch( 'activate', 'suite-n8n', array() );
		$ab = $page->dispatch( 'activate', 'suite-step', array() );
		s_check( 'h4', 'both connections activated after the test', 'activated' === ( $aa['wcp_notice'] ?? '' ) && 'activated' === ( $ab['wcp_notice'] ?? '' ), array( $aa, $ab ) );
		s_pause_all_workflows();
		$wf = s_publish( 'Suite Webhook', array( 'event' => 'woocommerce.order.paid' ), array( array( 'workflow.webhook.send', array( 'connection' => 'suite-step' ) ) ) );
		s_state( 'hook_wf', $wf );
		s_check( 'h5', 'workflow with the step workflow.webhook.send published', $wf > 0 );
		break;

	case 'hooks_send':
		$products = (array) s_state( 'products' );
		$order    = s_wc_order( array( (int) $products['ticket'] ) );
		s_state( 'hook_order', $order->get_id() );
		s_work( 6 );
		$out = s_outbox_rows();
		s_check( 'h6', 'outbox: one row for pass.order.paid of the forwarding connection', 1 === count( array_filter( $out, static fn( $r ) => 'suite-n8n' === ( $r['connection'] ?? $r['connection_key'] ?? '' ) ) ), array_map( static fn( $r ) => array_intersect_key( $r, array_flip( array( 'id', 'connection', 'connection_key', 'event_id', 'state', 'status' ) ) ), $out ) );
		$f = s_hooks_feature();
		$f->outbox()->run();
		$rows  = s_hook_rows();
		$fw    = array_values( array_filter( $rows, static fn( $r ) => 'pass.order.paid' === s_header( $r, 'X-Workflow-Captain-Event-Type' ) ) );
		$event = s_events( 'pass.order.paid' );
		$last  = $event ? s_env( end( $event ) ) : array();
		$body  = $fw ? (array) json_decode( (string) end( $fw )['body'], true ) : array();
		s_check( 'h7', 'outbox sent pass.order.paid to the receiver, signed, with the event ID of Workflow Captain', $fw && s_signed( end( $fw ), S_HMAC ) && s_header( end( $fw ), 'X-Workflow-Captain-Event-Id' ) === ( $body['id'] ?? '' ), $fw ? array( 'id' => s_header( end( $fw ), 'X-Workflow-Captain-Event-Id' ), 'signed' => s_signed( end( $fw ), S_HMAC ) ) : 'none' );
		s_check( 'h8', 'forwarded data holds only the selected fields', array() === array_diff( array_keys( (array) ( $body['data'] ?? array() ) ), array( 'currency', 'total_minor', 'ticket_count' ) ), $body['data'] ?? null );
		$st = array_values( array_filter( $rows, static fn( $r ) => 'woocommerce.order.paid' === s_header( $r, 'X-Workflow-Captain-Event-Type' ) ) );
		$runs = array_column( s_runs( (int) s_state( 'hook_wf' ) ), 'state' );
		s_check( 'h9', 'workflow step sent woocommerce.order.paid to the receiver, run succeeded', $st && in_array( 'succeeded', $runs, true ), array( 'requests' => count( $st ), 'runs' => $runs ) );
		$sent = array_values( array_filter( s_outbox_rows(), static fn( $r ) => in_array( $r['state'] ?? $r['status'] ?? '', array( 'sent' ), true ) ) );
		s_check( 'h10', 'outbox row marked sent', 1 <= count( $sent ), array_column( s_outbox_rows(), 'state' ) );
		break;

	case 'hooks_inbound':
		$r = s_inbound( 'suite-n8n', 'crm_lead', array( 'lead_id' => 7, 'source' => 'web', 'email' => 'lead@customer.test' ), S_INBOUND );
		s_check( 'h11', 'inbound: signed request accepted with 202 after the core stored it', 202 === $r['code'] && str_contains( $r['body'], 'captured' ), $r );
		$ev  = s_events( 'workflow.inbound.crm_lead' );
		$env = $ev ? s_env( end( $ev ) ) : array();
		s_check( 'h12', 'inbound event workflow.inbound.crm_lead stored with only the declared fields (no email)', $ev && array( 'lead_id' => 7, 'source' => 'web' ) == ( $env['data'] ?? null ), $env['data'] ?? 'none' );
		$replay = s_inbound( 'suite-n8n', 'crm_lead', array( 'lead_id' => 7, 'source' => 'web' ), S_INBOUND, $r['nonce'] );
		s_check( 'h13', 'inbound replay of the same nonce refused (409)', 409 === $replay['code'], $replay );
		$bad = s_inbound( 'suite-n8n', 'crm_lead', array( 'lead_id' => 8 ), 'wrong-secret-0123456789abcdef0123456789' );
		s_check( 'h14', 'inbound with a wrong signature refused (401)', 401 === $bad['code'], $bad );
		$undeclared = s_inbound( 'suite-n8n', 'other_trigger', array( 'x' => 1 ), S_INBOUND );
		s_check( 'h15', 'inbound with an undeclared trigger refused (403)', 403 === $undeclared['code'], $undeclared );
		break;

	// ---- License expiry: new Pro runs pause, webhooks pause, connectors stop (WF-CONNECTOR-12) --------------------
	case 'expire':
		$ids = (array) s_state( 'license_ids' );
		s_move_paid_until( (int) $ids['wcp_full'], 20 );
		\WPLC\License\LicenseLifecycle::expire_due_licenses_now();
		$row = s_license_row( (int) $ids['wcp_full'] );
		s_check( 'x0', 'shop: the agency license of Workflow Captain Pro expired (paid period ended 20 days ago, grace of 14 days over) [time simulated]', 'expired' === $row['status'], $row['status'] . ' until ' . $row['paid_until'] );
		$pro = s_pro();
		$ref = s_refresh( $pro );
		$st  = $pro->license()->status();
		s_check( 'x1', 'Pro refreshes from the shop: no access any more', ! $st->access, $ref . ' ' . $st->state . ' ' . $st->license_status );
		break;

	case 'expired_check':
		$pro = s_pro();
		foreach ( array( 'workflow.suite_integrations', 'workflow.webhooks', 'workflow.custom_connectors', 'workflow.multisite_network' ) as $k ) {
			s_check( 'x2/' . $k, 'expired license: feature off (' . $k . ')', ! $pro->features()->is_active( $k ) );
		}
		$r = s_registered();
		s_check( 'x3', 'expired license: no suite adapter registered (Pass, Voucher, Affiliate, license server)', array() === array_intersect( $r, array( 'pass-captain', 'voucher-captain', 'affiliate-captain', 'wp-license' ) ), implode( ',', $r ) );
		$c = Plugin::instance()->connectors();
		s_check( 'x4', 'expired license: custom connector refused runtime_not_allowed; its release stays stored', 'runtime_not_allowed' === $c->reason( 'starter-connector' ), (string) $c->reason( 'starter-connector' ) );
		// A WooCommerce order still starts the workflow of the core, but the Pro step cannot send.
		$before   = count( s_hook_rows() );
		$products = (array) s_state( 'products' );
		$order    = s_wc_order( array( (int) $products['ticket'] ) );
		s_work( 6 );
		$runs = s_runs( (int) s_state( 'hook_wf' ) );
		$last = end( $runs );
		$steps = $last ? s_steps( (int) $last['id'] ) : array();
		$step_states = array_map( static fn( $s ) => ( $s['node_id'] ?? '' ) . ':' . ( $s['state'] ?? '' ) . ':' . ( $s['last_error'] ?? $s['error'] ?? '' ), $steps );
		s_check( 'x5', 'expired license: the new run with the webhook step pauses (action_unavailable), nothing is sent', $last && str_contains( (string) $last['state'], 'paus' ) && count( s_hook_rows() ) === $before, array( 'run' => $last, 'steps' => $step_states ) );
		s_state( 'paused_run', $last ? (int) $last['id'] : 0 );
		$out = array_filter( s_outbox_rows(), static fn( $r ) => (int) ( $r['id'] ?? 0 ) > 0 );
		s_check( 'x6', 'expired license: the outbox queues nothing new (no pass events captured)', count( $out ) === count( array_filter( $out, static fn( $r ) => 'sent' === ( $r['state'] ?? '' ) ) ), array_column( $out, 'state' ) );
		$in = s_inbound( 'suite-n8n', 'crm_lead', array( 'lead_id' => 9 ), S_INBOUND );
		s_check( 'x7', 'expired license: inbound route answers 403 license_inactive', 403 === $in['code'] && str_contains( $in['body'], 'license_inactive' ), $in );
		$conns = s_hooks_feature()->connections()->all();
		s_check( 'x8', 'expired license: connections and their settings stay readable (nothing deleted)', isset( $conns['suite-n8n'], $conns['suite-step'] ), array_keys( $conns ) );
		$html = '';
		ob_start();
		$pro->render_license_screen();
		$html = (string) ob_get_clean();
		s_check( 'x9', 'license screen says the license expired', 1 === preg_match( '/expired|abgelaufen/i', wp_strip_all_tags( $html ) ), substr( wp_strip_all_tags( $html ), 0, 300 ) );
		break;

	case 'renew':
		$ids = (array) s_state( 'license_ids' );
		// The customer renews in the shop (manual renewal inside the 30-day window after the end) and pays.
		$lic   = s_license_row( (int) $ids['wcp_full'] );
		$order = \WPLC\Billing\ManualRenewal::start( (int) $ids['wcp_full'], (int) $lic['customer_id'] );
		if ( $order instanceof \WC_Order ) {
			$order->payment_complete( 'suite-renewal' );
		}
		s_run_as();
		$row = s_license_row( (int) $ids['wcp_full'] );
		s_check( 'y0', 'shop: the customer renews the expired license (manual renewal order paid, same key)', $order instanceof \WC_Order && 'active' === $row['status'] && $row['paid_until'] > gmdate( 'Y-m-d H:i:s' ), is_wp_error( $order ) ? $order->get_error_code() : $row['status'] . ' ' . $row['paid_until'] );
		$pro = s_pro();
		s_refresh( $pro );
		s_check( 'y1', 'Pro refreshes: access again', $pro->license()->status()->access, $pro->license()->status()->state );
		break;

	case 'renewed_check':
		$r = s_registered();
		s_check( 'y2', 'renewed: suite adapters registered again', array() === array_diff( array( 'pass-captain', 'voucher-captain', 'affiliate-captain', 'wp-license' ), $r ), implode( ',', $r ) );
		$run_id = (int) s_state( 'paused_run' );
		$run    = $wpdb->get_row( $wpdb->prepare( "SELECT state FROM {$wpdb->prefix}workflow_captain_runs WHERE id = %d", $run_id ), ARRAY_A );
		s_work( 2 );
		$run2 = $wpdb->get_row( $wpdb->prepare( "SELECT state FROM {$wpdb->prefix}workflow_captain_runs WHERE id = %d", $run_id ), ARRAY_A );
		s_check( 'y3', 'renewed: the paused run does not continue by itself (needs a human, R-10.8)', $run && $run2 && $run['state'] === $run2['state'] && str_contains( (string) $run2['state'], 'paus' ), array( $run, $run2 ) );
		$before = count( s_hook_rows() );
		$paused  = array_values( array_filter( s_steps( $run_id ), static fn( $st ) => ! in_array( $st['state'] ?? '', array( 'succeeded', 'skipped' ), true ) ) );
		$resumed = $paused && Plugin::instance()->workflows()->continue_step( (int) $paused[0]['id'], false );
		s_work( 3 );
		$run3 = $wpdb->get_row( $wpdb->prepare( "SELECT state FROM {$wpdb->prefix}workflow_captain_runs WHERE id = %d", $run_id ), ARRAY_A );
		s_check( 'y4', 'renewed: resuming the run sends the webhook with the same event ID, run succeeds', 'succeeded' === ( $run3['state'] ?? '' ) && count( s_hook_rows() ) > $before, array( 'resume' => $resumed, 'run' => $run3 ) );
		$c = Plugin::instance()->connectors();
		s_check( 'y5', 'renewed: custom connector accepted again with its stored release', 'runtime_not_allowed' !== $c->reason( 'starter-connector' ), (string) $c->reason( 'starter-connector' ) );
		break;

	// Shop side of the multisite expiry (ms.sh): the agency license ends and is renewed again.
	case 'shop_expire_ms':
		$ids = (array) s_state( 'license_ids' );
		s_move_paid_until( (int) $ids['wcp_full'], 20 );
		\WPLC\License\LicenseLifecycle::expire_due_licenses_now();
		s_check( 'ms-shop1', 'shop: agency license expired for the multisite test [time simulated]', 'expired' === s_license_row( (int) $ids['wcp_full'] )['status'] );
		break;

	case 'shop_renew_ms':
		$ids   = (array) s_state( 'license_ids' );
		$lic   = s_license_row( (int) $ids['wcp_full'] );
		$order = \WPLC\Billing\ManualRenewal::start( (int) $ids['wcp_full'], (int) $lic['customer_id'] );
		if ( $order instanceof \WC_Order ) {
			$order->payment_complete( 'suite-renewal-ms' );
		}
		s_run_as();
		s_check( 'ms-shop2', 'shop: agency license renewed by the customer', 'active' === s_license_row( (int) $ids['wcp_full'] )['status'], is_wp_error( $order ) ? $order->get_error_code() : s_license_row( (int) $ids['wcp_full'] )['status'] );
		break;

	// ---- Release gate (release.sh): shop side on the suite site, client side on the ZIP site --------------------------
	case 'zip_shop_buy':
		$plans   = (array) s_state( 'plan_products' );
		$license = s_buy( s_customer( 'zipkunde' ), (int) $plans['wcp_full'] );
		s_state( 'zip_license', array( 'id' => (int) ( $license['id'] ?? 0 ), 'key' => $license ? s_license_key( $license ) : '', 'order' => (int) ( $license['source_order_id'] ?? 0 ) ) );
		s_check( 'g9', 'shop: a new customer buys the agency plan for the ZIP site', $license && 'active' === $license['status'], $license['status'] ?? 'none' );
		break;

	case 'zip_licenses':
		$zip  = (array) s_state( 'zip_license' );
		$keys = (array) s_state( 'keys' );
		$r    = s_activate( s_pro(), (string) $zip['key'] );
		s_check( 'g10', 'ZIP site: Workflow Captain Pro activated with the purchased license (all workflow.* rights)', 'ok' === $r && s_pro()->features()->is_active( 'workflow.multisite_network' ) && s_pro()->features()->is_active( 'workflow.custom_connectors' ), $r );
		foreach ( array( 'PassCaptainPro' => 'pass', 'VoucherCaptainPro' => 'voucher', 'AffiliateCaptainPro' => 'affiliate' ) as $ns => $plan ) {
			$r = s_activate( s_addon( $ns ), (string) $keys[ $plan ] );
			s_check( 'g11/' . $plan, 'ZIP site: ' . $ns . ' activated (third seat of its license)', 'ok' === $r, $r );
		}
		break;

	case 'zip_events':
		$r = s_registered();
		s_check( 'g12', 'ZIP site: all suite adapters registered from the release builds', array() === array_diff( array( 'pass-captain', 'voucher-captain', 'affiliate-captain', 'wp-license' ), $r ), implode( ',', $r ) );
		$order = s_wc_order( array( s_pass_ticket_product(), s_voucher_product() ), s_affiliate() );
		s_run_as();
		s_work( 6 );
		$seen = array_values( array_unique( array_column( array_filter( s_events( '%' ), static fn( $e ) => preg_match( '/^(pass|voucher|affiliate)\./', $e['type'] ) && 'done' === $e['state'] ), 'type' ) ) );
		s_check( 'g13', 'ZIP site: one WooCommerce order produces processed Pass, Voucher and Affiliate events', (bool) preg_grep( '/^pass\./', $seen ) && (bool) preg_grep( '/^voucher\./', $seen ) && (bool) preg_grep( '/^affiliate\./', $seen ), $seen );
		break;

	case 'zip_shop_release':
		// Version 0.1.1 of the Pro ZIP (header and constant changed), registered and published in the shop.
		$src = (string) $s_param;
		$dst = dirname( $src ) . '/workflow-captain-pro-0.1.1.zip';
		copy( $src, $dst );
		$z = new ZipArchive();
		$z->open( $dst );
		foreach ( array( 'workflow-captain-pro/workflow-captain-pro.php', 'workflow-captain-pro/config.php' ) as $f ) {
			$z->addFromString( $f, str_replace( array( 'Version:           0.1.0', "'WORKFLOW_CAPTAIN_PRO_VERSION', '0.1.0'" ), array( 'Version:           0.1.1', "'WORKFLOW_CAPTAIN_PRO_VERSION', '0.1.1'" ), (string) $z->getFromName( $f ) ) );
		}
		$z->close();
		$out = \WP_CLI::runcommand( 'wplc release create --product=workflow-captain-pro --version=0.1.1 --zip=' . escapeshellarg( $dst ) . ' --publish --porcelain', array( 'return' => 'all', 'exit_error' => false ) );
		s_check( 'g14', 'shop: release 0.1.1 of Workflow Captain Pro created and published (wp wplc release create)', 0 === $out->return_code || str_contains( $out->stderr, 'already exists' ), trim( $out->stdout . ' ' . $out->stderr ) );
		break;

	case 'zip_update':
		$meta = s_pro()->license()->updater()->metadata( true );
		s_check( 'g15', 'ZIP site: the updater of Pro sees version 0.1.1 from the shop', '0.1.1' === (string) ( $meta['version'] ?? $meta['new_version'] ?? '' ), $meta );
		// WordPress asks the Update URI hosts only after a successful request to wordpress.org (wp_update_plugins()
		// returns early otherwise; blocked here). The answer of the updater's own hook is stored as WordPress would.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$updater = s_pro()->license()->updater();
		$item    = $updater->filter_update( false, get_plugin_data( WP_PLUGIN_DIR . '/' . $updater->basename(), false, false ), $updater->basename() );
		$t       = get_site_transient( 'update_plugins' );
		$t       = is_object( $t ) ? $t : (object) array( 'response' => array(), 'checked' => array(), 'no_update' => array() );
		$t->last_checked                      = time();
		$t->response[ $updater->basename() ] = (object) $item;
		set_site_transient( 'update_plugins', $t );
		s_check( 'g15b', 'ZIP site: the updater offers 0.1.1 with its own package placeholder (download only through the license client)', is_array( $item ) && '0.1.1' === $item['version'] && '' !== $item['package'], $item );
		break;

	case 'zip_shop_refund':
		$zip    = (array) s_state( 'zip_license' );
		$order  = wc_get_order( (int) $zip['order'] );
		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => $order->get_total(), 'reason' => 'Suite E2E Widerruf' ) );
		s_run_as();
		$row = s_license_row( (int) $zip['id'] );
		s_check( 'g17', 'shop: refund of the purchase revokes the license', ! is_wp_error( $refund ) && 'revoked' === $row['status'], $row['status'] ?? '' );
		break;

	case 'zip_refunded':
		$pro = s_pro();
		s_refresh( $pro );
		s_check( 'g18', 'ZIP site: after the refund Pro has no access, its features are off', ! $pro->license()->status()->access && ! $pro->features()->is_active( 'workflow.suite_integrations' ), $pro->license()->status()->state );
		$r = $pro->license()->deactivate();
		foreach ( array( 'PassCaptainPro', 'VoucherCaptainPro', 'AffiliateCaptainPro' ) as $ns ) {
			s_addon( $ns ) && s_addon( $ns )->license()->deactivate(); // Releases the seats for the next run.
		}
		s_check( 'g19', 'ZIP site: the license can be removed from the site (deactivation)', ! $pro->has_activation(), is_wp_error( $r ) ? $r->get_error_code() : 'ok' );
		break;

	default:
		s_check( '?', 'unknown section ' . $s_section, false );
}
