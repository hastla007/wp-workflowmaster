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
