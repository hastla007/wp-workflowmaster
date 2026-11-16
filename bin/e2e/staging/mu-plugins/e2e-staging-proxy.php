<?php
/**
 * Staging only (not part of the GitHub workflow, whose runner has direct internet access and DNS):
 * - trust the CA of the container's egress proxy for outgoing requests;
 * - the container has no DNS, so wp_http_validate_url() (gethostbyname) rejects every "safe" download such as
 *   downloads.wordpress.org; skip that check for wordpress.org hosts only. The proxy still enforces its allow list.
 */
add_filter( 'http_request_args', function ( $args, $url ) {
	if ( is_readable( '/root/.ccr/ca-bundle.crt' ) ) {
		$args['sslcertificates'] = '/root/.ccr/ca-bundle.crt';
	}
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( 'wordpress.org' === $host || str_ends_with( $host, '.wordpress.org' ) ) {
		$args['reject_unsafe_urls'] = false;
	}
	return $args;
}, 10, 2 );
