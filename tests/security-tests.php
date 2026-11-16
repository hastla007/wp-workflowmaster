<?php
/**
 * Tests of src/Security (included by tests/run.php).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Security\OutboundPolicy;

$dns = array(
	'hooks.example.com'  => array( '93.184.216.34' ),
	'rebind.example.com' => array( '93.184.216.34', '10.0.0.5' ),
	'n8n.lan'            => array( '192.168.1.20' ),
	'meta.example.com'   => array( '169.254.169.254' ),
	'v6.example.com'     => array( '2606:4700::6810:84e5' ),
	'v6local.example'    => array( 'fd12::1' ),
);
$policy = new OutboundPolicy( fn( $h ) => $dns[ $h ] ?? array() );
$deny   = static fn( string $url, string $reason, array $ex = array() ) => $policy->check( $url, $ex )['reason'] === $reason;

check( 'SEC-01 public https target allowed', $policy->check( 'https://hooks.example.com/x' )['ok'] );
check( 'SEC-01 public IPv6 target allowed', $policy->check( 'https://v6.example.com/x' )['ok'] );
check( 'SEC-01 plain http to public target refused', $deny( 'http://hooks.example.com/x', 'https_required' ) );
check( 'SEC-01 other scheme refused', $deny( 'file:///etc/passwd', 'invalid_url' ) || $deny( 'file:///etc/passwd', 'scheme_not_allowed' ) );
check( 'SEC-01 gopher refused', $deny( 'gopher://hooks.example.com/', 'scheme_not_allowed' ) );
check( 'SEC-01 credentials in URL refused', $deny( 'https://u:p@hooks.example.com/', 'credentials_in_url' ) );
check( 'SEC-01 loopback refused', $deny( 'https://127.0.0.1/', 'private_address' ) );
check( 'SEC-01 localhost name without DNS refused', $deny( 'https://localhost/', 'unresolvable' ) );
check( 'SEC-01 private network refused', $deny( 'https://n8n.lan/', 'private_address' ) );
check( 'SEC-01 IPv6 unique local refused', $deny( 'https://v6local.example/', 'private_address' ) );
check( 'SEC-01 IPv6 loopback refused', $deny( 'https://[::1]/', 'private_address' ) );
check( 'SEC-01 decimal IP form refused', $deny( 'https://2130706433/', 'private_address' ) );
check( 'SEC-01 hex IP form refused', $deny( 'https://0x7f000001/', 'private_address' ) );
check( 'SEC-01 octal IP form refused', $deny( 'https://0177.0.0.1/', 'private_address' ) );
check( 'SEC-01 short IP form refused', $deny( 'https://127.1/', 'private_address' ) );
check( 'SEC-01 IPv4-mapped IPv6 refused', $deny( 'https://[::ffff:127.0.0.1]/', 'private_address' ) );
check( 'SEC-01 DNS answer with one private address refused (rebinding)', $deny( 'https://rebind.example.com/', 'private_address' ) );
check( 'SEC-01 metadata address refused', $deny( 'https://169.254.169.254/latest/', 'metadata_address' ) );
check( 'SEC-01 metadata by name refused', $deny( 'https://meta.example.com/', 'metadata_address' ) );
$ex = array( 'allow_private' => true, 'host' => 'n8n.lan', 'port' => 5678, 'cidr' => array( '192.168.1.0/24' ) );
check( 'SEC-01 local n8n with exception allowed, also over http', $policy->check( 'http://n8n.lan:5678/webhook/x', $ex )['ok'] );
check( 'SEC-01 exception is bound to the port', $deny( 'http://n8n.lan:8080/', 'exception_not_for_this_target', $ex ) );
check( 'SEC-01 exception is bound to the host', $deny( 'http://127.0.0.1:5678/', 'exception_not_for_this_target', $ex ) );
check( 'SEC-01 exception is bound to the CIDR', $deny( 'http://n8n.lan:5678/', 'address_outside_exception', array( 'cidr' => array( '10.0.0.0/8' ) ) + $ex ) );
check( 'SEC-01 metadata stays blocked with an exception', $deny( 'http://169.254.169.254:80/', 'metadata_address', array( 'allow_private' => true, 'host' => '169.254.169.254', 'port' => 80, 'cidr' => array( '0.0.0.0/0' ) ) ) );
check( 'SEC-01 CIDR match IPv6', OutboundPolicy::in_cidr( 'fe80::1', 'fe80::/10' ) && ! OutboundPolicy::in_cidr( '2001:db9::1', '2001:db8::/32' ) );
check( 'SEC-01 checked IPs are returned for pinning', array( '93.184.216.34' ) === $policy->check( 'https://hooks.example.com/' )['ips'] );

// ----- Vault (WF-SEC-02) ---------------------------------------------------------------------------------------

use WorkflowCaptain\Security\Redact;
use WorkflowCaptain\Security\Vault;

$vault  = new Vault( str_repeat( 'ab', 32 ) );
$stored = $vault->encrypt( 'geheimes-token-123', 'connection:1:7:token' );
check( 'SEC-02 vault available with a key', $vault->available() );
check( 'SEC-02 ciphertext has the format and hides the value', str_starts_with( $stored, 'wcv1:' ) && ! str_contains( $stored, 'geheim' ) );
check( 'SEC-02 decrypts in the same context', 'geheimes-token-123' === $vault->decrypt( $stored, 'connection:1:7:token' ) );
check( 'SEC-02 other connection cannot decrypt', null === $vault->decrypt( $stored, 'connection:1:8:token' ) );
check( 'SEC-02 other site cannot decrypt', null === $vault->decrypt( $stored, 'connection:2:7:token' ) );
check( 'SEC-02 other key cannot decrypt (key lost or changed)', null === ( new Vault( 'other' ) )->decrypt( $stored, 'connection:1:7:token' ) );
check( 'SEC-02 tampered value is refused', null === $vault->decrypt( substr( $stored, 0, -2 ) . 'AA', 'connection:1:7:token' ) );
$rotated = new Vault( 'new-key', str_repeat( 'ab', 32 ) );
check( 'SEC-02 rotation: old values readable with the previous key', 'geheimes-token-123' === $rotated->decrypt( $stored, 'connection:1:7:token' ) );
$re = $rotated->reencrypt( $stored, 'connection:1:7:token' );
check( 'SEC-02 rotation: re-encrypted with the new key', null !== $re && 'geheimes-token-123' === ( new Vault( 'new-key' ) )->decrypt( $re, 'connection:1:7:token' ) );
check( 'SEC-02 rotation: current values need no re-encryption', null === $rotated->reencrypt( $re, 'connection:1:7:token' ) );
check( 'SEC-02 no key means not available', ! ( new Vault( '' ) )->available() );
check( 'SEC-02 decrypted values are scrubbed from text', 'Fehler bei [redacted] beim Senden' === Redact::scrub( 'Fehler bei geheimes-token-123 beim Senden' ) );
Redact::reset();

// ----- Adapter kit self-test (WF-SPEC-13) ----------------------------------------------------------------------

require __DIR__ . '/adapter-kit/AdapterConformance.php';
use WorkflowCaptain\AdapterKit\AdapterConformance as Kit;

$kit_adapter = new TestAdapter();
$good        = array( 'type' => 'pass.ticket.checked_in', 'subject' => array( 'type' => 'ticket', 'id' => '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'id_kind' => 'public' ), 'data' => array( 'checkin_source' => 'scanner', 'device_id' => 4 ), 'actor_user_id' => 17, 'discriminator' => 'scan-1' );
check( 'kit: a clean event passes', array() === Kit::check_event( $kit_adapter, $good ) );
$leak = $good;
$leak['data']['checkin_source'] = 'Grund: ' . Kit::MARKERS['freetext'];
check( 'kit: A-3 finds a marker hidden in another string', array() !== Kit::check_event( $kit_adapter, $leak ) );
$extra = $good;
$extra['data']['seat'] = 'A12';
check( 'kit: R-5.2 finds a field outside the allow-list', in_array( 'R-5.2: field not on the allow-list of pass.ticket.checked_in: seat', Kit::check_event( $kit_adapter, $extra ), true ) );
$aff = array( 'type' => 'affiliate.commission.approved', 'subject' => array( 'type' => 'commission', 'id' => '1', 'id_kind' => 'record' ), 'data' => array(), 'actor_user_id' => 3, 'discriminator' => 'x' );
check( 'kit: actor_user_id refused where the catalog says none', array() !== Kit::check_event( new TestAdapter( 'affiliate-captain', '1.4.0' ), $aff ) );
check( 'kit: A-7 detects equal keys for two real events', array() !== Kit::check_idempotency( $good, $good, $good ) && array() === Kit::check_idempotency( $good, $good, array( 'discriminator' => 'scan-2' ) + $good ) );
check( 'kit: A-5 detects an escaping exception', array() !== Kit::check_callback_isolated( function () { throw new RuntimeException( 'x' ); }, array() ) );
check( 'kit: A-4 detects excluded and foreign hooks', 2 === count( Kit::check_hooks( 'wp-license', array( 'wplc/license_issued', 'wplc/license_owner_changed', 'wplc/license_status_changed' ), array( 'wplc/license_issued' ) ) ) );
$gate = static fn( ?string $v ) => null !== $v && version_compare( $v, '1.1.0', '>=' ) && (int) $v < 2;
check( 'kit: A-8 accepts a correct contract gate', array() === Kit::check_contract_gate( $gate, '1.1.0', 1 ) );
check( 'kit: A-8 detects a gate that ignores the MAJOR', array() !== Kit::check_contract_gate( static fn( $v ) => null !== $v, '1.1.0', 1 ) );
check( 'kit: A-9 detects forbidden commands', array( 'A-9: forbidden command offered: affiliate.payout.complete' ) === Kit::check_commands( array( 'notify.admin', 'voucher.void', 'affiliate.payout.complete' ) ) );

// ----- WF-BUILDER-09: contrast of the builder colours (WCAG 2.1) -----------------------------------------------

function wfc_luminance( string $hex ): float {
	$hex = ltrim( $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	$c = array_map( static function ( $v ) {
		$v = hexdec( $v ) / 255;
		return $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
	}, str_split( $hex, 2 ) );
	return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function wfc_contrast( string $a, string $b ): float {
	$l = array( wfc_luminance( $a ), wfc_luminance( $b ) );
	return ( max( $l ) + 0.05 ) / ( min( $l ) + 0.05 );
}
$css = file_get_contents( WORKFLOW_CAPTAIN_PATH . 'assets/css/builder.css' );
preg_match_all( '/\.wfc-node(?:-(\w+))? rect \{ fill: (#[0-9a-f]{3,6}); stroke: (#[0-9a-f]{3,6})/', $css, $nodes, PREG_SET_ORDER );
$text   = array( 'title' => '#1d2327', 'id' => '#50575e' );
preg_match( '/\.wfc-node-title \{[^}]*fill: (#[0-9a-f]{6})/', $css, $m );
$text['title'] = $m[1];
preg_match( '/\.wfc-node-id \{[^}]*fill: (#[0-9a-f]{6})/', $css, $m );
$text['id'] = $m[1];
preg_match( '/\.wfc-canvas-wrap \{[^}]*background: (#[0-9a-f]{6})/', $css, $m );
$canvas = $m[1];
check( 'contrast: node styles found in builder.css', count( $nodes ) >= 6 );
$low = array();
foreach ( $nodes as $n ) {
	foreach ( $text as $what => $colour ) {
		if ( wfc_contrast( $colour, $n[2] ) < 4.5 ) {
			$low[] = $what . ' on ' . ( $n[1] ?: 'node' ) . ' ' . round( wfc_contrast( $colour, $n[2] ), 2 );
		}
	}
	if ( wfc_contrast( $n[3], $canvas ) < 3 ) {
		$low[] = 'border of ' . ( $n[1] ?: 'node' ) . ' ' . round( wfc_contrast( $n[3], $canvas ), 2 );
	}
}
foreach ( array( 'wfc-edge-then' => null, 'wfc-edge-else' => null ) as $class => $unused ) {
	preg_match( '/\.' . $class . ' \{ stroke: (#[0-9a-f]{6})/', $css, $m );
	if ( wfc_contrast( $m[1], $canvas ) < 3 ) {
		$low[] = $class . ' ' . round( wfc_contrast( $m[1], $canvas ), 2 );
	}
}
check( 'contrast: text at least 4.5:1, borders and edges at least 3:1' . ( $low ? ' (' . implode( '; ', $low ) . ')' : '' ), array() === $low );

// ----- WF-SEO-03 / WF-SEO-02: no front-end output, no public data surface -------------------------------------

$frontend = array(
	'wp_head', 'wp_footer', 'template_redirect', 'the_content', 'the_title', 'wp_enqueue_scripts', 'wp_robots', 'robots_txt',
	'wp_sitemaps', 'document_title_parts', 'pre_get_document_title', 'body_class', 'init_rewrite', 'rewrite_rules_array',
	'query_vars', 'parse_request', 'rest_api_init', 'wp_ajax_nopriv_',
);
$calls = array( 'register_rest_route', 'add_shortcode', 'add_rewrite_rule', 'add_rewrite_endpoint', 'register_post_type', 'wp_register_sitemap_provider' );
$hits  = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WORKFLOW_CAPTAIN_PATH . 'src', FilesystemIterator::SKIP_DOTS ) );
foreach ( array_merge( iterator_to_array( $it ), array( new SplFileInfo( WORKFLOW_CAPTAIN_PATH . 'workflow-captain.php' ) ) ) as $file ) {
	$code = file_get_contents( $file->getPathname() );
	foreach ( $frontend as $hook ) {
		if ( preg_match( "/add_(action|filter)\\(\\s*'" . preg_quote( $hook, '/' ) . '/', $code ) ) {
			$hits[] = basename( $file ) . ': ' . $hook;
		}
	}
	foreach ( $calls as $call ) {
		if ( preg_match( '/\b' . $call . '\s*\(/', $code ) ) {
			$hits[] = basename( $file ) . ': ' . $call;
		}
	}
}
check( 'SEO-03 no front-end hooks, REST routes, shortcodes, rewrites, post types or sitemaps' . ( $hits ? ' (' . implode( '; ', $hits ) . ')' : '' ), array() === $hits );

// ----- WF-SEO-04 / WF-SEO-05: no direct post writes, no coupling to Yoast internals ------------------------------

$seo_hits = array();
$it       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WORKFLOW_CAPTAIN_PATH . 'src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	$code = file_get_contents( $file->getPathname() );
	// Yoast internals: indexables, internal classes and undocumented meta keys (WF-SEO-05).
	if ( preg_match( '/_yoast_wpseo_|Yoast\\\\WP\\\\SEO|WPSEO_|wpseo_|yoast_indexable/i', $code ) ) {
		$seo_hits[] = basename( $file ) . ': Yoast internals';
	}
	// Posts are written only through the WordPress API, never with SQL on the posts tables (WF-SEO-04).
	if ( preg_match( '/\$wpdb->(posts|postmeta|term_relationships)\b/', $code ) && preg_match( '/\b(INSERT|UPDATE|DELETE)\b/', $code ) ) {
		$seo_hits[] = basename( $file ) . ': SQL on posts';
	}
	if ( preg_match( '/\b(wp_insert_post|wp_update_post|update_post_meta|wp_set_object_terms)\s*\(/', $code ) ) {
		$seo_hits[] = basename( $file ) . ': post write (needs a reviewed post action, WF-SEO-04)';
	}
}
check( 'SEO-04/05 no Yoast internals, no SQL on posts, no post writes without a reviewed action' . ( $seo_hits ? ' (' . implode( '; ', $seo_hits ) . ')' : '' ), array() === $seo_hits );

// ----- WF-SEC-01 / WF-N8N-07 / WF-N8N-15: safe outbound HTTP with profiles, pinning and redirect checks ----------

use WorkflowCaptain\Security\SafeHttp;
$dns2   = array( 'hooks.example.com' => array( '93.184.216.34' ), 'evil.example.com' => array( '10.0.0.5' ), 'n8n' => array( '172.18.0.4' ), 'meta.example.com' => array( '169.254.169.254' ) );
$sent   = array();
$replies = array();
$fake   = function ( string $url, array $args ) use ( &$sent, &$replies ) {
	$sent[] = array( $url, $args );
	return array_shift( $replies ) ?? array( 'code' => 200, 'headers' => array(), 'body' => 'ok' );
};
$http   = new SafeHttp( new OutboundPolicy( fn( $h ) => $dns2[ $h ] ?? array() ), $fake );
check( 'SafeHttp webhook: public HTTPS allowed, pinned to the checked IP, no automatic redirects', true === $http->request( 'webhook', 'POST', 'https://hooks.example.com/x', array(), '{}' )['ok'] && array( '93.184.216.34' ) === $sent[0][1]['pin'] && 0 === $sent[0][1]['redirection'] && SafeHttp::MAX_BYTES === $sent[0][1]['limit_response_size'] );
check( 'SafeHttp webhook: plain HTTP refused', 'target:https_required' === $http->request( 'webhook', 'POST', 'http://hooks.example.com/x' )['error'] );
check( 'SafeHttp webhook: private target refused even with an exception', 'target:not_public' === ( $r = $http->request( 'webhook', 'POST', 'https://evil.example.com/x', array(), '', array( 'allow_private' => true, 'host' => 'evil.example.com', 'port' => 443 ) ) )['error'] || str_starts_with( $r['error'], 'target:' ) );
$sent    = array();
$replies = array( array( 'code' => 302, 'headers' => array( 'location' => 'https://evil.example.com/steal' ), 'body' => '' ) );
$r       = $http->request( 'webhook', 'POST', 'https://hooks.example.com/x' );
check( 'SafeHttp: redirect to a private address is refused, nothing sent there', ! $r['ok'] && str_starts_with( $r['error'], 'redirect:' ) && 1 === count( $sent ) );
$replies = array( array( 'code' => 302, 'headers' => array( 'location' => '/y' ) ), array( 'code' => 302, 'headers' => array( 'location' => '/y' ) ), array( 'code' => 302, 'headers' => array( 'location' => '/y' ) ), array( 'code' => 302, 'headers' => array( 'location' => '/y' ) ) );
check( 'SafeHttp: at most 3 redirects', 'redirect:too_many' === $http->request( 'webhook', 'GET', 'https://hooks.example.com/x' )['error'] );
$exc = array( 'allow_private' => true, 'host' => 'n8n', 'port' => 5678, 'cidr' => array( '172.18.0.0/16' ), 'allow_http' => true );
check( 'SafeHttp n8n: local Docker target allowed with the exception of this connection', true === $http->allowed( 'n8n', 'http://n8n:5678/webhook/abc', $exc )['ok'] );
check( 'SafeHttp n8n: without exception refused, HTTP needs allow_http', ! $http->allowed( 'n8n', 'https://n8n:5678/webhook/abc' )['ok'] && 'https_required' === $http->allowed( 'n8n', 'http://n8n:5678/webhook/abc', array( 'allow_private' => true, 'host' => 'n8n', 'port' => 5678, 'cidr' => array( '172.18.0.0/16' ) ) )['reason'] );
check( 'SafeHttp n8n: cloud metadata blocked even with an exception', ! $http->allowed( 'n8n', 'http://meta.example.com/', array( 'allow_private' => true, 'host' => 'meta.example.com', 'port' => 80, 'cidr' => array( '169.254.0.0/16' ), 'allow_http' => true ) )['ok'] );
$replies = array( array( 'error' => 'http_request_failed: https://hooks.example.com/secret?token=abc' ) );
$r       = $http->request( 'webhook', 'POST', 'https://hooks.example.com/x' );
check( 'SafeHttp: transport errors become fixed codes without URL or token', ! str_contains( $r['error'], 'token' ) && ! str_contains( $r['error'], 'example' ) );
check( 'SafeHttp: hint for localhost explains it is the WordPress server', str_contains( SafeHttp::local_hint( 'http://localhost:5678' ), 'WordPress-Server' ) && '' === SafeHttp::local_hint( 'https://hooks.example.com' ) );

// ----- WF-SEC-03: inbound guard (HMAC, window, replay, rate limit, allowlist) ----------------------------------

use WorkflowCaptain\Security\InboundGuard;
$conns = array( 'n8n-main' => array( 'secret' => 's3cr3t-value', 'active' => true, 'triggers' => array( 'order.export' ), 'rate' => 3 ), 'old' => array( 'secret' => 'x', 'active' => false, 'triggers' => array() ) );
$seen  = array();
$cnt   = array();
$guard = new InboundGuard(
	fn( $k ) => $conns[ $k ] ?? null,
	function ( $k, $ttl ) use ( &$seen ) { if ( isset( $seen[ $k ] ) ) { return false; } $seen[ $k ] = 1; return true; },
	function ( $k, $ttl ) use ( &$cnt ) { return $cnt[ $k ] = ( $cnt[ $k ] ?? 0 ) + 1; }
);
$now   = 1763287200;
$body  = '{"order":5}';
$req   = fn( array $over = array() ) => array_merge( array( 'connection' => 'n8n-main', 'timestamp' => (string) $now, 'nonce' => 'abcdefghijklmnop', 'signature' => InboundGuard::sign( 's3cr3t-value', $now, 'abcdefghijklmnop', $body ), 'trigger' => 'order.export' ), $over );
check( 'SEC-03 inbound: valid signed request accepted', true === $guard->check( $req(), $body, $now )['ok'] );
check( 'SEC-03 inbound: same nonce again is a replay', 'replay' === $guard->check( $req(), $body, $now )['reason'] );
check( 'SEC-03 inbound: wrong signature refused (constant-time compare)', 'bad_signature' === $guard->check( $req( array( 'nonce' => 'qrstuvwxyzabcdef' ) ), $body . ' ', $now )['reason'] );
check( 'SEC-03 inbound: rate limit per connection', 'rate_limited' === $guard->check( $req( array( 'nonce' => 'zzzzzzzzzzzzzzzz' ) ), $body, $now )['reason'] );
$cnt = array();
check( 'SEC-03 inbound: timestamp outside the window refused', 'stale_timestamp' === $guard->check( $req( array( 'nonce' => 'yyyyyyyyyyyyyyyy' ) ), $body, $now + 301 )['reason'] );
$sig = InboundGuard::sign( 's3cr3t-value', $now, 'wwwwwwwwwwwwwwww', $body );
check( 'SEC-03 inbound: trigger not on the allowlist refused', 'trigger_not_allowed' === $guard->check( $req( array( 'nonce' => 'wwwwwwwwwwwwwwww', 'signature' => $sig, 'trigger' => 'user.delete' ) ), $body, $now )['reason'] );
check( 'SEC-03 inbound: revoked or unknown connection refused, oversized body refused first', 'unknown_connection' === $guard->check( $req( array( 'connection' => 'old' ) ), $body, $now )['reason'] && 'unknown_connection' === $guard->check( $req( array( 'connection' => 'nope' ) ), $body, $now )['reason'] && 413 === $guard->check( $req(), str_repeat( 'a', InboundGuard::MAX_BYTES + 1 ), $now )['status'] );

// ----- WF-SEC-02: re-encryption after a key change
use WorkflowCaptain\Security\SecretStores;
$old_vault = new Vault( 'old-key-for-test' );
$saved     = array( 'a' => $old_vault->encrypt( 'token-a', 'webhook' ), 'b' => 'wcv1:garbage', 'c' => ( new Vault( 'new-key-for-test' ) )->encrypt( 'token-c', 'webhook' ) );
$store     = array( 'webhooks' => array( 'context' => 'webhook', 'load' => function () use ( &$saved ) { return $saved; }, 'save' => function ( $id, $v ) use ( &$saved ) { $saved[ $id ] = $v; } ) );
$rot       = new Vault( 'new-key-for-test', 'old-key-for-test' );
$dry       = SecretStores::rekey( $rot, $store, true );
check( 'SEC-02 rekey dry run counts and changes nothing', array( 'current' => 1, 'rekeyed' => 1, 'unreadable' => 1 ) === $dry['webhooks'] && null !== $old_vault->decrypt( $saved['a'], 'webhook' ) );
SecretStores::rekey( $rot, $store );
check( 'SEC-02 rekey: old values readable with the new key only afterwards', 'token-a' === ( new Vault( 'new-key-for-test' ) )->decrypt( $saved['a'], 'webhook' ) && null === $old_vault->decrypt( $saved['a'], 'webhook' ) );
check( 'SEC-02 rekey result carries no secret', ! str_contains( (string) json_encode( SecretStores::rekey( $rot, $store, true ) ), 'token' ) );
