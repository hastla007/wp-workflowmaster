<?php
/**
 * Router for "php -S" serving a subdirectory multisite (not shipped): /<site>/wp-admin/..., /<site>/wp-includes/...
 * and /<site>/*.php map to the WordPress root, like the rewrite rules WordPress prints for nginx/Apache.
 *
 *     php -S 127.0.0.1:8081 -t ms bin/e2e/router.php
 */
$root = $_SERVER['DOCUMENT_ROOT'];
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( preg_match( '#^/[_0-9a-zA-Z-]+(/wp-(?:admin|includes|content)/.*|/[^/]+\.php)$#', $path, $m ) && ! is_file( $root . $path ) ) {
	$path = $m[1];
}
if ( '/' !== $path && is_file( $root . $path ) && ! str_ends_with( $path, '.php' ) ) {
	if ( $path === parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ) {
		return false; // Static file at its own address: the built-in server sends it.
	}
	$types = array( 'css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'gif' => 'image/gif', 'jpg' => 'image/jpeg', 'json' => 'application/json' );
	header( 'Content-Type: ' . ( $types[ strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ] ?? 'application/octet-stream' ) );
	readfile( $root . $path );
	return true;
}
if ( str_ends_with( $path, '/' ) && is_file( $root . $path . 'index.php' ) ) {
	$path .= 'index.php';
}
$file = is_file( $root . $path ) && str_ends_with( $path, '.php' ) ? $root . $path : $root . '/index.php';
$_SERVER['SCRIPT_NAME']     = substr( $file, strlen( $root ) );
$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
chdir( dirname( $file ) );
require $file;
