<?php
/**
 * Outbound HTTP for webhooks and n8n (WF-SEC-01, WF-N8N-07): every target and every redirect target goes through
 * OutboundPolicy, the connection is pinned to the checked IPs (cURL CURLOPT_RESOLVE), redirects are followed by hand
 * (at most 3), there is a timeout and a limit on the response size, and errors come back as fixed codes without
 * URLs, headers or bodies.
 *
 * Profiles per kind of connection:
 * - "webhook": public HTTPS only.
 * - "n8n": HTTPS by default; a private or local target (localhost, LAN, Docker, VPN) and plain HTTP only with the
 *   exception of that one connection (host, port, CIDR), never cloud metadata addresses.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Security;

defined( 'ABSPATH' ) || exit;

final class SafeHttp {

	const MAX_REDIRECTS = 3;
	const TIMEOUT       = 10;
	const MAX_BYTES     = 262144;

	/** @var callable(string,array):array Transport: (url, args) → array{code:int,headers:array,body:string}|array{error:string}. */
	private $transport;

	public function __construct( private OutboundPolicy $policy, ?callable $transport = null ) {
		$this->transport = $transport ?? array( self::class, 'wp_transport' );
	}

	/**
	 * Whether a URL may be used for a profile, before any request (shown when saving a connection).
	 *
	 * @param array{allow_private?:bool,host?:string,port?:int,cidr?:string[],allow_http?:bool} $exception
	 * @return array{ok:bool,reason:string,ips:string[]}
	 */
	public function allowed( string $profile, string $url, array $exception = array() ): array {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'webhook' === $profile ) {
			$exception = array();
		} elseif ( 'n8n' !== $profile ) {
			return array( 'ok' => false, 'reason' => 'unknown_profile', 'ips' => array() );
		}
		if ( 'https' !== $scheme && ! ( 'n8n' === $profile && 'http' === $scheme && ! empty( $exception['allow_http'] ) ) ) {
			return array( 'ok' => false, 'reason' => 'https_required', 'ips' => array() );
		}
		return $this->policy->check( $url, $exception );
	}

	/**
	 * Sends a request. Returns code and body (cut to MAX_BYTES), or a fixed error code.
	 *
	 * @param array<string,string> $headers
	 * @return array{ok:bool,code:int,body:string,error:string}
	 */
	public function request( string $profile, string $method, string $url, array $headers = array(), string $body = '', array $exception = array() ): array {
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$check = $this->allowed( $profile, $url, $exception );
			if ( ! $check['ok'] ) {
				return self::fail( 0 === $hop ? 'target:' . $check['reason'] : 'redirect:' . $check['reason'] );
			}
			$response = ( $this->transport )(
				$url,
				array(
					'method'              => $method,
					'headers'             => $headers,
					'body'                => $body,
					'timeout'             => self::TIMEOUT,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_BYTES,
					'pin'                 => $check['ips'],
				)
			);
			if ( isset( $response['error'] ) ) {
				// Only the leading code (for example "http_request_failed"), never a message with URL or token.
				return self::fail( 'transport:' . ( preg_match( '/^[a-z0-9_]{1,40}/', strtolower( (string) $response['error'] ), $m ) ? $m[0] : 'error' ) );
			}
			$code = (int) ( $response['code'] ?? 0 );
			if ( $code >= 300 && $code < 400 ) {
				$location = (string) ( $response['headers']['location'] ?? '' );
				if ( '' === $location ) {
					return self::fail( 'redirect:no_location' );
				}
				$url = self::absolute( $url, $location );
				if ( 303 === $code ) {
					$method = 'GET';
					$body   = '';
				}
				continue;
			}
			return array( 'ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => substr( (string) ( $response['body'] ?? '' ), 0, self::MAX_BYTES ), 'error' => $code >= 200 && $code < 300 ? '' : 'http:' . $code );
		}
		return self::fail( 'redirect:too_many' );
	}

	/** Hint for local n8n URLs (WF-N8N-15): the request starts on the WordPress server, not in the browser. */
	public static function local_hint( string $url ): string {
		$host = strtolower( trim( (string) parse_url( $url, PHP_URL_HOST ), '[]' ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return __( '„localhost“ ist der WordPress-Server selbst, nicht Ihr Rechner. Läuft n8n in einem anderen Container oder auf einem anderen Gerät, dessen Namen oder Adresse im Netz eintragen (zum Beispiel den Docker-Dienstnamen).', 'workflow-captain' );
		}
		$ip = OutboundPolicy::normalize_ip( $host );
		if ( null !== $ip || ! str_contains( $host, '.' ) ) {
			return __( 'Private Adresse oder interner Name: Die Verbindung braucht eine Ausnahme für genau diesen Host und Port. Der Test läuft vom WordPress-Server aus; Firewall, Docker-Netz oder Reverse Proxy müssen ihn durchlassen.', 'workflow-captain' );
		}
		return '';
	}

	private static function absolute( string $base, string $location ): string {
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		$b = parse_url( $base );
		$origin = $b['scheme'] . '://' . $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		return str_starts_with( $location, '/' ) ? $origin . $location : $origin . '/' . ltrim( $location, '/' );
	}

	private static function fail( string $error ): array {
		return array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => $error );
	}

	/** Default transport: WordPress HTTP API, pinned to the checked IPs. */
	public static function wp_transport( string $url, array $args ): array {
		$host = (string) parse_url( $url, PHP_URL_HOST );
		$port = (int) ( parse_url( $url, PHP_URL_PORT ) ?: ( 'https' === parse_url( $url, PHP_URL_SCHEME ) ? 443 : 80 ) );
		$pin  = static function ( $handle ) use ( $host, $port, $args ): void {
			if ( defined( 'CURLOPT_RESOLVE' ) && array() !== $args['pin'] ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, array_map( static fn( $ip ) => $host . ':' . $port . ':' . ( str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip ), $args['pin'] ) );
			}
			curl_setopt( $handle, CURLOPT_PROXY, '' ); // No proxy: the pinned IP is the one that was checked.
		};
		add_action( 'http_api_curl', $pin );
		$response = wp_remote_request( $url, array_diff_key( $args, array( 'pin' => 1 ) ) + array( 'sslverify' => true ) );
		remove_action( 'http_api_curl', $pin );
		if ( is_wp_error( $response ) ) {
			return array( 'error' => (string) $response->get_error_code() );
		}
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => array( 'location' => (string) wp_remote_retrieve_header( $response, 'location' ) ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
