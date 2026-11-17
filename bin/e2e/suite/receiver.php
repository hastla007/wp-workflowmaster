<?php
/**
 * Webhook receiver for the suite checks (not shipped): "php -S 127.0.0.1:8086 -t hooks". Logs every request as one JSON
 * line to ../logs/suite-hooks.jsonl. The query parameter "status" sets the answer code (e.g. ?status=500), "sleep" waits.
 * When ../logs/suite-hooks-slow holds a path prefix, the next request to it is logged and answered only after 12 s (the
 * sender gives up after 10 s): a timeout after the target accepted the call.
 */
$row = array(
	'time'    => gmdate( 'c' ),
	'method'  => $_SERVER['REQUEST_METHOD'] ?? '',
	'uri'     => $_SERVER['REQUEST_URI'] ?? '',
	'headers' => function_exists( 'getallheaders' ) ? getallheaders() : array(),
	'body'    => file_get_contents( 'php://input' ),
);
if ( 'GET' !== $row['method'] ) {
	file_put_contents( dirname( __DIR__ ) . '/logs/suite-hooks.jsonl', json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND | LOCK_EX );
}
$slow = dirname( __DIR__ ) . '/logs/suite-hooks-slow';
if ( is_file( $slow ) && '' !== trim( (string) file_get_contents( $slow ) ) && 0 === strpos( $row['uri'], trim( (string) file_get_contents( $slow ) ) ) ) {
	unlink( $slow );
	sleep( 12 );
}
if ( isset( $_GET['sleep'] ) ) {
	sleep( min( 10, (int) $_GET['sleep'] ) );
}
http_response_code( isset( $_GET['status'] ) ? (int) $_GET['status'] : 200 );
header( 'Content-Type: application/json' );
echo '{"ok":true}';
