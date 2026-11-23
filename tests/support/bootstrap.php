<?php
/**
 * Test bootstrap without WordPress: constants, the few WordPress functions the tested classes call, the autoloader.
 *
 * @package WorkflowCaptain
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'WORKFLOW_CAPTAIN_PATH', dirname( __DIR__, 2 ) . '/' );

spl_autoload_register(
	static function ( string $class ): void {
		if ( str_starts_with( $class, 'WorkflowCaptain\\' ) ) {
			$file = WORKFLOW_CAPTAIN_PATH . 'src/' . str_replace( '\\', '/', substr( $class, 16 ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}
);

$GLOBALS['wfc_test_filters'] = array();
$GLOBALS['wfc_test_users']   = array();

/** wp_parse_url() of WordPress: parse_url() with the same component argument (PHP >= 8 behaves the same). */
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test stub.
}

/** wp_kses() stand-in for the tests: keeps the markup (the callers pass escaped HTML). */
function wp_kses( $content, $allowed_html ) {
	return (string) $content;
}

function __( $text, $domain = '' ) {
	return $text;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['wfc_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function add_filter( $hook, $callback ) {
	$GLOBALS['wfc_test_filters'][ $hook ][] = $callback;
	return true;
}

function add_action( $hook, $callback ) {
	return add_filter( $hook, $callback );
}

function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['wfc_test_users'] as $id => $email ) {
		if ( 'email' === $field && $email === $value ) {
			return (object) array( 'ID' => $id );
		}
	}
	return false;
}

require __DIR__ . '/FakeWpdb.php';
require __DIR__ . '/TestAdapter.php';
