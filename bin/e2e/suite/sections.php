<?php
/**
 * Sections of bin/e2e/suite/checks.php (single site). Multisite sections: ms-sections.php. Not shipped.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

global $wpdb;
wp_set_current_user( s_admin_id() );

const S_WCP_ALL = array( 'workflow.suite_integrations', 'workflow.webhooks', 'workflow.event_correlation', 'workflow.advanced_logic', 'workflow.recipe_templates', 'workflow.extended_logs', 'workflow.custom_connectors', 'workflow.multisite_network' );

if ( is_file( __DIR__ . '/ms-sections.php' ) && str_starts_with( $s_section, 'ms_' ) ) {
	require __DIR__ . '/ms-sections.php';
	return;
}

switch ( $s_section ) {

	// ---- Vendor shop: plans and licenses (WP License Captain) -------------------------------------------------------
	case 'shop':
		s_check( 's1', 'WP License Captain active with its tables (vendor shop on the suite site)', defined( 'WPLC_VERSION' ) && null !== $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wplc_licenses'" ), defined( 'WPLC_VERSION' ) ? WPLC_VERSION : 'missing' );
		$plans = array(
			'wcp_full'  => s_plan( 'Workflow Captain Pro Agentur', 'workflow-captain-pro', 'agency', S_WCP_ALL, 3 ),
			'wcp_basic' => s_plan( 'Workflow Captain Pro Basis', 'workflow-captain-pro', 'basic', array( 'workflow.suite_integrations', 'workflow.webhooks' ), 3 ),
			'wcp_one'   => s_plan( 'Workflow Captain Pro Einzelplatz', 'workflow-captain-pro', 'single', S_WCP_ALL, 1 ),
			// Multisite: four subsites take part, each with its own activation (B2), so four live sites.
			'wcp_net'   => s_plan( 'Workflow Captain Pro Netzwerk', 'workflow-captain-pro', 'network4', S_WCP_ALL, 4 ),
			'pass'      => s_plan( 'Pass Captain Pro', 'pass-captain-pro', 'pro', array( 'pass.write_api' ), 3 ),
			'voucher'   => s_plan( 'Voucher Captain Pro', 'voucher-captain-pro', 'pro', array( 'voucher.white_label' ), 3 ),
			'affiliate' => s_plan( 'Affiliate Captain Pro', 'affiliate-captain-pro', 'pro', array( 'affiliate.extended_api' ), 3 ),
		);
		$customer = s_customer( 'suitekunde' );
		$keys     = array();
		$ids      = array();
		foreach ( $plans as $name => $product_id ) {
			$license = s_buy( $customer, $product_id );
			s_check( 's2/' . $name, 'paid order issues a license (' . $name . ')', array() !== $license && 'active' === $license['status'], $license ? $license['product_key'] . ' ' . $license['status'] . ' until ' . $license['paid_until'] : 'none' );
			if ( $license ) {
				$keys[ $name ] = s_license_key( $license );
				$ids[ $name ]  = (int) $license['id'];
			}
		}
		s_state( 'keys', $keys );
		s_state( 'license_ids', $ids );
		s_state( 'plan_products', $plans );
		$ent = (array) json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT entitlements_json FROM {$wpdb->prefix}wplc_plans WHERE plan_key = %s", 'agency' ) ), true );
		s_check( 's3', 'agency plan grants all eight workflow.* rights', array() === array_diff( S_WCP_ALL, array_keys( array_filter( $ent ) ) ), array_keys( $ent ) );
		break;

	// ---- T96 matrix, row "Free": only the core ------------------------------------------------------------------
	case 'free':
		s_check( 'f1', 'Free: Workflow Captain Pro not active', ! function_exists( '\\WorkflowCaptainPro\\addon' ) );
		$r = s_registered();
		s_check( 'f2', 'Free: only the core adapters are registered (wordpress, woocommerce, schedules; plus the starter connector)', array() === array_diff( $r, array( 'wordpress', 'woocommerce', 'workflow-captain', 'starter-connector' ) ) && in_array( 'woocommerce', $r, true ), implode( ',', $r ) );
		$types = Plugin::instance()->available_event_types();
		$suite = array_values( array_filter( $types, static fn( $t ) => preg_match( '/^(pass|voucher|affiliate|license)\./', $t ) ) );
		s_check( 'f3', 'Free: no event type of Pass, Voucher, Affiliate or the license server is offered', array() === $suite, $suite );
		$before = count( s_events( 'voucher.%' ) );
		$v      = vcap()->issuer->issue_standalone( array( 'amount' => 15, 'source' => 'admin', 'recipient_email' => 'gutschein-free@customer.test', 'send' => false ) );
		s_work( 2 );
		s_check( 'f4', 'Free: a voucher issued in Voucher Captain stores no event (no adapter, nothing captured)', ! is_wp_error( $v ) && count( s_events( 'voucher.%' ) ) === $before, is_wp_error( $v ) ? $v->get_error_code() : 'issued' );
		$c = Plugin::instance()->connectors();
		s_check( 'f5', 'Free: the custom connector runtime is open by default (core default, no Pro installed)', null !== $c->get( 'starter-connector' ) || array() !== $c->all(), array_keys( $c->all() ) + array( 'refused' => array_keys( $c->refused() ) ) );
		break;

	// ---- T96 matrix, row "Pro without license" ----------------------------------------------------------------------
	case 'pro_nolicense':
		$pro = s_pro();
		s_check( 'n1', 'Pro active, license state is not active (no activation yet)', $pro && ! $pro->license()->status()->access, $pro ? $pro->license()->status()->state : 'no addon' );
		foreach ( array( 'workflow.suite_integrations', 'workflow.webhooks', 'workflow.custom_connectors', 'workflow.multisite_network' ) as $k ) {
			s_check( 'n2/' . $k, 'Pro without license: feature off (' . $k . ')', $pro && ! $pro->features()->is_active( $k ) );
		}
		$r = s_registered();
		s_check( 'n3', 'Pro without license: no suite adapter registered', array() === array_intersect( $r, array( 'pass-captain', 'voucher-captain', 'affiliate-captain', 'wp-license' ) ), implode( ',', $r ) );
		$c = Plugin::instance()->connectors();
		s_check( 'n4', 'Pro without license: custom connector refused with runtime_not_allowed (WF-CONNECTOR-12)', 'runtime_not_allowed' === $c->reason( 'starter-connector' ), (string) $c->reason( 'starter-connector' ) );
		$res = rest_do_request( new WP_REST_Request( 'POST', '/workflow-captain-pro/v1/hooks/suite-n8n' ) );
		s_check( 'n5', 'Pro without license: inbound webhook route answers 403 license_inactive first', 403 === $res->get_status() && str_contains( wp_json_encode( $res->get_data() ), 'license_inactive' ), $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );
		ob_start();
		$pro->render_license_screen();
		$html = (string) ob_get_clean();
		s_check( 'n6', 'Pro license screen renders the activation form', str_contains( $html, 'license_key' ), substr( wp_strip_all_tags( $html ), 0, 200 ) );
		break;

	// ---- Activate a license of a Pro add-on: param = <add-on namespace>:<plan> --------------------------------------
	case 'activate':
		list( $ns, $plan ) = explode( ':', $s_param );
		$addon             = s_addon( $ns );
		$keys              = (array) s_state( 'keys' );
		if ( $addon && $addon->has_activation() && 'WorkflowCaptainPro' === $ns ) {
			$addon->license()->deactivate();
		}
		$result = s_activate( $addon, (string) ( $keys[ $plan ] ?? '' ) );
		$st     = $addon ? $addon->license()->status() : null;
		s_check( 'l/' . $plan, 'license ' . $plan . ' activated in ' . $ns . ' against the local shop (signed answer, pinned key)', 'ok' === $result && $st && $st->access, $result . ' ' . ( $st ? $st->state . ' ' . implode( ',', array_keys( array_filter( (array) $st->entitlements, static fn( $v ) => true === $v ) ) ) : '' ) );
		break;

	// ---- T88/T96: license of Workflow Captain Pro without the add-ons of the products --------------------------------
	case 'basic':
		$pro = s_pro();
		s_check( 'b1', 'basic license: suite integrations and webhooks on, custom connectors and network off', $pro->features()->is_active( 'workflow.suite_integrations' ) && $pro->features()->is_active( 'workflow.webhooks' ) && ! $pro->features()->is_active( 'workflow.custom_connectors' ) && ! $pro->features()->is_active( 'workflow.multisite_network' ) );
		$r = s_registered();
		s_check( 'b2', 'basic license: license server adapter registered (no product add-on needed)', in_array( 'wp-license', $r, true ), implode( ',', $r ) );
		s_check( 'b3', 'Pro-Recht des Fachplugins fehlt: Pass, Voucher, Affiliate adapters not registered', array() === array_intersect( $r, array( 'pass-captain', 'voucher-captain', 'affiliate-captain' ) ), implode( ',', $r ) );
		$lines = s_suite_lines();
		s_check( 'b4', 'license screen says which product add-on is missing (Pass/Voucher/Affiliate Captain Pro not active)', 3 === count( array_filter( $lines, static fn( $l ) => str_contains( $l, 'is not active' ) || str_contains( $l, 'ist nicht aktiv' ) ) ), $lines );
		$c = Plugin::instance()->connectors();
		s_check( 'b5', 'basic license without workflow.custom_connectors: connector refused runtime_not_allowed (WF-CONNECTOR-12)', 'runtime_not_allowed' === $c->reason( 'starter-connector' ), (string) $c->reason( 'starter-connector' ) );
		break;

	case 'addons_unlicensed':
		$r = s_registered();
		s_check( 'u1', 'product add-ons active without license: Pass, Voucher, Affiliate adapters still not registered', array() === array_intersect( $r, array( 'pass-captain', 'voucher-captain', 'affiliate-captain' ) ), implode( ',', $r ) );
		$lines = s_suite_lines();
		s_check( 'u2', 'license screen names the unlicensed add-ons', 3 === count( array_filter( $lines, static fn( $l ) => str_contains( $l, 'Pro' ) && ( str_contains( $l, 'licen' ) || str_contains( $l, 'Lizenz' ) ) && ! str_contains( $l, 'is not active' ) ) ), $lines );
		break;

	case 'adapters_on':
		$r = s_registered();
		s_check( 'o1', 'all four suite adapters registered (pass-captain, voucher-captain, affiliate-captain, wp-license)', array() === array_diff( array( 'pass-captain', 'voucher-captain', 'affiliate-captain', 'wp-license' ), $r ), implode( ',', $r ) );
		$refused = (array) ( Plugin::instance()->diagnostics()['adapters']['refused'] ?? array() );
		s_check( 'o2', 'no refused adapters', array() === $refused, $refused );
		$types = Plugin::instance()->available_event_types();
		$count = array();
		foreach ( $types as $t ) {
			$p           = explode( '.', $t )[0];
			$count[ $p ] = ( $count[ $p ] ?? 0 ) + 1;
		}
		s_check( 'o3', 'event types offered per provider (catalog: pass 13, voucher 16, affiliate 21, license 3)', 13 === ( $count['pass'] ?? 0 ) && 16 === ( $count['voucher'] ?? 0 ) && 21 === ( $count['affiliate'] ?? 0 ) && 3 === ( $count['license'] ?? 0 ), $count );
		s_check( 'o4', 'license screen: every product "on"', 4 === count( array_filter( s_suite_lines(), static fn( $l ) => str_contains( $l, ': on' ) || str_contains( $l, ': an' ) ) ), s_suite_lines() );
		break;

	case 'connector':
		$c      = Plugin::instance()->connectors();
		$reason = $c->reason( 'starter-connector' );
		if ( 'accepted' === $s_param ) {
			s_check( 'k1', 'license with workflow.custom_connectors: connector accepted (only waits for the operator release)', null !== $c->get( 'starter-connector' ) && in_array( $reason, array( null, 'not_enabled' ), true ), (string) $reason );
		} else {
			s_check( 'k2/' . $s_param, 'connector refused runtime_not_allowed (' . $s_param . ')', 'runtime_not_allowed' === $reason, (string) $reason );
		}
		break;

	default:
		require __DIR__ . '/sections-more.php';
}
