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
