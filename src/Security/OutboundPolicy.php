<?php
/**
 * Shared check for outbound HTTP targets (WF-SEC-01, konzept 9 and 17.12).
 *
 * Generic targets (custom webhooks) must be public HTTPS. A private target (local n8n) is allowed only with an explicit
 * exception for that one connection, bound to host, port and IP/CIDR. Cloud metadata addresses are always blocked.
 * The check runs on the URL and on every resolved IP, and again for every redirect target (pinning against DNS
 * rebinding is the caller's job: connect only to the IPs this check returned).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Security;

defined( 'ABSPATH' ) || exit;

final class OutboundPolicy {

	/** Always blocked, even with an exception: metadata services of cloud providers. */
	const METADATA = array( '169.254.169.254/32', '169.254.170.2/32', 'fd00:ec2::254/128', '100.100.100.200/32' );

	/** Not public: loopback, private, link-local, CGNAT, documentation, multicast, reserved, unspecified. */
	const NON_PUBLIC = array(
		'0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
		'192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
		'255.255.255.255/32', '::/128', '::1/128', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
	);

	/** @var callable(string):string[] Resolves a host to IPs (A and AAAA). */
	private $resolver;

	public function __construct( ?callable $resolver = null ) {
		$this->resolver = $resolver ?? array( self::class, 'resolve' );
	}

	/**
	 * Checks a target.
	 *
	 * @param array{allow_private?:bool,host?:string,port?:int,cidr?:string[]} $exception Exception of one connection.
	 * @return array{ok:bool,reason:string,ips:string[]} reason is a fixed code; ips are the addresses to connect to.
	 */
	public function check( string $url, array $exception = array() ): array {
		$parts = parse_url( $url );
		if ( false === $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return self::deny( 'invalid_url' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return self::deny( 'credentials_in_url' );
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'https', 'http' ), true ) ) {
			return self::deny( 'scheme_not_allowed' );
		}
		$host    = strtolower( rtrim( trim( $parts['host'], '[]' ), '.' ) );
		$port    = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		$private = ! empty( $exception['allow_private'] );
		if ( $private && ( strtolower( (string) ( $exception['host'] ?? '' ) ) !== $host || (int) ( $exception['port'] ?? 0 ) !== $port ) ) {
			return self::deny( 'exception_not_for_this_target' );
		}

		$literal = self::normalize_ip( $host );
		$ips     = null !== $literal ? array( $literal ) : array_values( array_filter( array_map( array( self::class, 'normalize_ip' ), (array) ( $this->resolver )( $host ) ) ) );
		if ( array() === $ips ) {
			return self::deny( 'unresolvable' );
		}
		foreach ( $ips as $ip ) {
			if ( self::in_any( $ip, self::METADATA ) ) {
				return self::deny( 'metadata_address' );
			}
			$public = ! self::in_any( $ip, self::NON_PUBLIC );
			if ( ! $public ) {
				if ( ! $private ) {
					return self::deny( 'private_address' );
				}
				if ( ! self::in_any( $ip, (array) ( $exception['cidr'] ?? array() ) ) ) {
					return self::deny( 'address_outside_exception' );
				}
			}
		}
		if ( 'http' === $scheme && ! $private ) {
			return self::deny( 'https_required' );
		}
		return array( 'ok' => true, 'reason' => 'ok', 'ips' => $ips );
	}

	/**
	 * Normalises an IP literal, including forms parse_url and inet_pton do not read as one: decimal (2130706433),
	 * hex (0x7f000001), octal parts (0177.0.0.1), short forms (127.1) and IPv4-mapped IPv6 (::ffff:127.0.0.1).
	 * Returns null for a host name.
	 */
	public static function normalize_ip( string $host ): ?string {
		$host = strtolower( trim( $host, '[]' ) );
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $host );
			if ( false !== $packed && str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
				return inet_ntop( substr( $packed, 12 ) );
			}
			return false === $packed ? null : inet_ntop( $packed );
		}
		if ( ! preg_match( '/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+)){0,3}$/', $host ) ) {
			return null;
		}
		$parts = array();
		foreach ( explode( '.', $host ) as $part ) {
			if ( str_starts_with( $part, '0x' ) ) {
				$parts[] = hexdec( substr( $part, 2 ) );
			} elseif ( strlen( $part ) > 1 && '0' === $part[0] ) {
				if ( ! preg_match( '/^[0-7]+$/', $part ) ) {
					return null;
				}
				$parts[] = octdec( $part );
			} else {
				$parts[] = (int) $part;
			}
		}
		$last  = array_pop( $parts );
		$value = 0;
		foreach ( $parts as $i => $p ) {
			if ( $p > 255 ) {
				return null;
			}
			$value += $p << ( 8 * ( 3 - $i ) );
		}
		if ( $last >= 2 ** ( 8 * ( 4 - count( $parts ) ) ) ) {
			return null;
		}
		$value += $last;
		return long2ip( (int) $value );
	}

	public static function in_cidr( string $ip, string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			$cidr .= str_contains( $cidr, ':' ) ? '/128' : '/32';
		}
		list( $net, $bits ) = explode( '/', $cidr, 2 );
		$a = inet_pton( $ip );
		$b = inet_pton( $net );
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		$bits = (int) $bits;
		$full = intdiv( $bits, 8 );
		if ( substr( $a, 0, $full ) !== substr( $b, 0, $full ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
		return ( $a[ $full ] & $mask ) === ( $b[ $full ] & $mask );
	}

	private static function in_any( string $ip, array $cidrs ): bool {
		foreach ( $cidrs as $cidr ) {
			if ( self::in_cidr( $ip, (string) $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] */
	public static function resolve( string $host ): array {
		$ips = array();
		foreach ( (array) @dns_get_record( $host, DNS_A | DNS_AAAA ) as $record ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup returns false.
			if ( isset( $record['ip'] ) ) {
				$ips[] = $record['ip'];
			} elseif ( isset( $record['ipv6'] ) ) {
				$ips[] = $record['ipv6'];
			}
		}
		return $ips;
	}

	private static function deny( string $reason ): array {
		return array( 'ok' => false, 'reason' => $reason, 'ips' => array() );
	}
}
