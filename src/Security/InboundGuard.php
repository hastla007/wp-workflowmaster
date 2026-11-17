<?php
/**
 * Check of inbound requests for webhooks and n8n (WF-SEC-03, WF-CONNECTOR-06, WF-N8N-05/06). One order for every
 * entry point, so no route forgets a step:
 *
 *   1. size of the raw body (before anything is parsed)
 *   2. connection exists, is active and not revoked
 *   3. rate limit per connection (fixed window)
 *   4. timestamp within the window (default ±300 s)
 *   5. HMAC-SHA256 over "timestamp.nonce.raw body" with the secret of the connection, compared in constant time
 *   6. nonce not seen before within the window (replay)
 *   7. trigger on the allowlist of the connection
 *
 * Every refusal is a fixed code; nothing of the request is echoed back or logged.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Security;

defined( 'ABSPATH' ) || exit;

final class InboundGuard {

	const MAX_BYTES = 65536;
	const WINDOW    = 300;
	const RATE      = 60;

	/**
	 * @param callable(string):?array $connection Connection by key: array{secret:string,active:bool,triggers:string[],rate?:int} or null.
	 * @param callable(string,int):bool $remember Stores a value for N seconds; returns false when it was already stored.
	 * @param callable(string,int):int  $count    Increments a counter that expires after N seconds; returns the new value.
	 */
	public function __construct( private $connection, private $remember, private $count ) {}

	/** Signature a sender sends in the header (lowercase hex). */
	public static function sign( string $secret, int $timestamp, string $nonce, string $body ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, $secret );
	}

	/**
	 * @param array{connection:string,timestamp:string,nonce:string,signature:string,trigger:string} $h Values of the request headers.
	 * @return array{ok:bool,reason:string,status:int}
	 */
	public function check( array $h, string $raw_body, int $now ): array {
		if ( strlen( $raw_body ) > self::MAX_BYTES ) {
			return self::deny( 'too_large', 413 );
		}
		$key = (string) ( $h['connection'] ?? '' );
		$c   = preg_match( '/^[a-z0-9-]{3,64}$/', $key ) ? ( $this->connection )( $key ) : null;
		if ( ! is_array( $c ) || empty( $c['active'] ) || ! is_string( $c['secret'] ?? null ) || '' === $c['secret'] ) {
			return self::deny( 'unknown_connection', 401 );
		}
		if ( ( $this->count )( 'rate:' . $key . ':' . intdiv( $now, 60 ), 60 ) > (int) ( $c['rate'] ?? self::RATE ) ) {
			return self::deny( 'rate_limited', 429 );
		}
		$ts = (string) ( $h['timestamp'] ?? '' );
		if ( ! ctype_digit( $ts ) || abs( $now - (int) $ts ) > self::WINDOW ) {
			return self::deny( 'stale_timestamp', 401 );
		}
		$nonce = (string) ( $h['nonce'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
			return self::deny( 'invalid_nonce', 401 );
		}
		if ( ! hash_equals( self::sign( $c['secret'], (int) $ts, $nonce, $raw_body ), strtolower( (string) ( $h['signature'] ?? '' ) ) ) ) {
			return self::deny( 'bad_signature', 401 );
		}
		// Only after a valid signature, so a stranger cannot burn nonces of the sender.
		if ( ! ( $this->remember )( 'nonce:' . $key . ':' . $nonce, 2 * self::WINDOW ) ) {
			return self::deny( 'replay', 409 );
		}
		if ( ! in_array( (string) ( $h['trigger'] ?? '' ), (array) ( $c['triggers'] ?? array() ), true ) ) {
			return self::deny( 'trigger_not_allowed', 403 );
		}
		return array( 'ok' => true, 'reason' => '', 'status' => 202 );
	}

	private static function deny( string $reason, int $status ): array {
		return array( 'ok' => false, 'reason' => $reason, 'status' => $status );
	}

	/** Stores for WordPress: transients (object cache when present). */
	public static function wp_remember( string $key, int $ttl ): bool {
		$k = 'wfc_in_' . md5( $key );
		if ( false !== get_transient( $k ) ) {
			return false;
		}
		set_transient( $k, 1, $ttl );
		return true;
	}

	public static function wp_count( string $key, int $ttl ): int {
		$k = 'wfc_rl_' . md5( $key );
		$n = (int) get_transient( $k ) + 1;
		set_transient( $k, $n, $ttl );
		return $n;
	}
}
