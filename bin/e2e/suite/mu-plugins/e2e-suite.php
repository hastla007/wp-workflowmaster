<?php
/**
 * Plugin Name: E2E suite site helpers (test site only, never ship)
 * Routes https://shop.test (the license server, WP License Captain on the suite site) to the local "php -S" server on
 * 127.0.0.1:8083 without TLS: the license client of Workflow Captain Pro accepts only https addresses. The request keeps
 * its path, body and headers, so request signatures and nonces are real.
 */
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		static $busy = false;
		if ( $busy || 0 !== strpos( (string) $url, 'https://shop.test/' ) ) {
			return $pre;
		}
		$busy = true;
		$port = getenv( 'E2E_SHOP_PORT' ) ? (int) getenv( 'E2E_SHOP_PORT' ) : 8083;
		// The shop is a plain-http WordPress at 127.0.0.1:8083; WordPress routes by path, the host does not matter.
		$args['sslverify'] = false;
		// The package download of the license client uses wp_safe_remote_get(); 127.0.0.1 is the shop itself here.
		$args['reject_unsafe_urls'] = false;
		$response          = wp_remote_request( 'http://127.0.0.1:' . $port . substr( (string) $url, strlen( 'https://shop.test' ) ), $args );
		$busy              = false;
		return $response;
	},
	1,
	3
);

// T88 "Contract-Major": with the option e2e_suite_contract_major the Affiliate Captain contract constant claims a new
// MAJOR before the plugin defines it (mu-plugins load first); Affiliate Captain's own define() is then a no-op warning.
if ( get_option( 'e2e_suite_contract_major' ) && ! defined( 'WPAC_CONTRACT_VERSION' ) ) {
	define( 'WPAC_CONTRACT_VERSION', '2.0.0' );
}

// Shop side (WP License Captain): update packages must be https URLs of the shop's own host; the test shop runs on
// plain http://127.0.0.1:8083. For the update CHECK the package address is given as https://shop.test. The download
// itself is not possible here (the release registry compares the stored address), see docs/E2E-SUITE.md.
add_filter(
	'wplc_update_packages',
	static function ( $packages ) {
		foreach ( (array) $packages as $key => $package ) {
			if ( is_array( $package ) && ! empty( $package['package_url'] ) ) {
				$packages[ $key ]['package_url'] = preg_replace( '#^http://127\.0\.0\.1:8083#', 'https://shop.test', (string) $package['package_url'] );
			}
		}
		return $packages;
	},
	99
);
add_filter( 'wplc_update_package_hosts', static fn( $hosts ) => array_merge( (array) $hosts, array( 'shop.test' ) ) );

// WF-MS-23 "plugin missing on site B": a network contract for voucher.issue (requires Voucher Captain, contract 1.x).
// No product declares a network contract yet (WFC-PRO-17); the suite declares one while the network option is set.
add_filter(
	'workflow_captain_pro_network_action_contracts_v1',
	static function ( $contracts ) {
		if ( ! function_exists( 'get_site_option' ) || ! get_site_option( 'e2e_suite_net_contract' ) ) {
			return $contracts;
		}
		$contracts['voucher.issue'] = array(
			'network_safe' => true,
			'contract'     => array( 'constant' => 'VCAP_CONTRACT_VERSION', 'min' => '1.3.0', 'major' => 1 ),
			'requires'     => array( array( 'constant' => 'VCAP_VERSION', 'min' => '1.3.0' ) ),
			'contexts'     => array( 'main_site', 'sub_site' ),
			'side_effects' => array( 'voucher_issued' ),
			'data_classes' => array( 'operational', 'reference' ),
			'idempotent'   => true,
			'retry'        => 'transient_only',
			'replay'       => 'never_after_side_effect',
			'identity'     => 'none',
			'credential'   => '',
			'handler'      => 'VoucherCaptain\\Issuer',
		);
		return $contracts;
	}
);

// Timeout after the side effect: with the option e2e_suite_mail_timeout on a site, a mail is handed over (captured by
// e2e-test-site.php at priority 10) and the call then fails like a timeout of the mail transport.
add_filter(
	'pre_wp_mail',
	static function ( $return ) {
		if ( true === $return && get_option( 'e2e_suite_mail_timeout' ) ) {
			throw new \RuntimeException( 'e2e: mail transport timed out after the hand-over' );
		}
		return $return;
	},
	99
);

// Network workflows need an event type a provider declares network-publishable (WF-MS-05). Only the suite adapters of
// Pro declare theirs; the events of the core (wordpress.*, woocommerce.*) are not declared. With the network option
// e2e_suite_net_publish the suite declares wordpress.post.published with its field post_type (operational) through
// the documented filter, so the multisite checks can start runs by publishing a post.
add_filter(
	'workflow_captain_pro_network_publishable_v1',
	static function ( $declared ) {
		if ( function_exists( 'get_site_option' ) && get_site_option( 'e2e_suite_net_publish' ) ) {
			$declared['wordpress.post.published'] = array(
				'provider' => 'wordpress',
				'fields'   => array( 'post_type' => 'operational' ),
			);
		}
		return $declared;
	}
);

// Timeout after a successful call of an own connector (WF-CONNECTOR-13): with the option e2e_suite_note_timeout the
// starter connector's note is stored, then the call fails once like a timeout.
$e2e_suite_note_timeout = static function ( $option ) {
	if ( 'wfc_starter_connector_notes' === $option && get_option( 'e2e_suite_note_timeout' ) ) {
		delete_option( 'e2e_suite_note_timeout' );
		throw new \RuntimeException( 'e2e: connector call timed out after the note was stored' );
	}
};
add_action( 'added_option', $e2e_suite_note_timeout );
add_action( 'updated_option', $e2e_suite_note_timeout );
