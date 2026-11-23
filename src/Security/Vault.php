<?php
/**
 * Encrypted storage of secrets (WF-SEC-02, konzept 9.1), the same scheme as Pass Captain's Security\Vault.
 *
 * Format "wcv1:" . base64( nonce(24) . secretbox(plaintext) ), libsodium XSalsa20-Poly1305. Every value is encrypted
 * with a sub key derived for its own context ("connection:{blog_id}:{connection_id}:{field}"), so a ciphertext copied to
 * another connection or site does not decrypt. Master key: constant or environment variable
 * WORKFLOW_CAPTAIN_SECRET_KEY, else derived from the WordPress salts. Rotation: the old key in
 * WORKFLOW_CAPTAIN_SECRET_KEY_PREVIOUS; values readable only with it are re-encrypted by reencrypt().
 *
 * Decrypted values are registered with Redact for the rest of the request.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Security;

defined( 'ABSPATH' ) || exit;

final class Vault {

	const PREFIX = 'wcv1:';

	private ?string $key;
	private ?string $previous;

	/** True when the master key comes from the WordPress salts (no own key given or configured). */
	private bool $from_salts;

	/**
	 * @param string|null $key      Master key material; null = from the environment (see class comment).
	 * @param string|null $previous Previous master key material for rotation; null = from the environment.
	 */
	public function __construct( ?string $key = null, ?string $previous = null ) {
		$own              = $key ?? self::configured( 'WORKFLOW_CAPTAIN_SECRET_KEY' );
		$this->from_salts = null === $own;
		$this->key        = self::material( $own ?? self::from_salts() );
		$this->previous = self::material( $previous ?? self::configured( 'WORKFLOW_CAPTAIN_SECRET_KEY_PREVIOUS' ) );
	}

	/** Whether secrets can be stored at all (libsodium and a key). Without, connections needing one stay inactive. */
	public function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && null !== $this->key;
	}

	/**
	 * Whether new secrets may be stored: always with an own key; with the key derived from the salts only while the
	 * operator has not switched on "only with an own key" (setting `require_own_key`, SET-WC-24). Reading stays possible.
	 */
	public function may_store(): bool {
		return ! $this->from_salts || 1 !== (int) \WorkflowCaptain\Settings::get()['require_own_key'];
	}

	/** Whether an own key is configured (recommended; the admin shows a hint otherwise). */
	public static function own_key_configured(): bool {
		return null !== self::configured( 'WORKFLOW_CAPTAIN_SECRET_KEY' );
	}

	public function encrypt( string $plaintext, string $context ): string {
		if ( ! $this->available() ) {
			throw new \RuntimeException( 'vault_unavailable' );
		}
		if ( ! $this->may_store() ) {
			throw new \RuntimeException( 'own_key_required' );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plaintext, $nonce, self::sub_key( (string) $this->key, $context ) );
		return self::PREFIX . base64_encode( $nonce . $box );
	}

	/**
	 * @return string|null The plaintext, or null if the value cannot be decrypted (other context, key lost or changed).
	 */
	public function decrypt( string $stored, string $context ): ?string {
		foreach ( array( $this->key, $this->previous ) as $key ) {
			if ( null === $key ) {
				continue;
			}
			$plain = self::open( $stored, $key, $context );
			if ( null !== $plain ) {
				Redact::remember( $plain );
				return $plain;
			}
		}
		return null;
	}

	/**
	 * Returns the value encrypted with the current key if it is readable only with the previous one, else null.
	 */
	public function reencrypt( string $stored, string $context ): ?string {
		if ( null === $this->key || null === $this->previous || null !== self::open( $stored, $this->key, $context ) ) {
			return null;
		}
		$plain = self::open( $stored, $this->previous, $context );
		return null === $plain ? null : $this->encrypt( $plain, $context );
	}

	private static function open( string $stored, string $key, string $context ): ?string {
		if ( ! str_starts_with( $stored, self::PREFIX ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::sub_key( $key, $context ) );
		return false === $plain ? null : $plain;
	}

	private static function sub_key( string $key, string $context ): string {
		return sodium_crypto_generichash( 'workflow-captain|' . $context, $key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/** 64 hex characters are used as they are; anything else is hashed to 32 bytes. */
	private static function material( ?string $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return preg_match( '/^[0-9a-f]{64}$/i', $value ) ? (string) hex2bin( $value ) : hash( 'sha256', $value, true );
	}

	private static function configured( string $name ): ?string {
		if ( defined( $name ) && '' !== (string) constant( $name ) ) {
			return (string) constant( $name );
		}
		$env = getenv( $name );
		return false === $env || '' === $env ? null : $env;
	}

	private static function from_salts(): ?string {
		if ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) && '' !== AUTH_KEY && 'put your unique phrase here' !== AUTH_KEY ) {
			return 'salts|' . AUTH_KEY . '|' . SECURE_AUTH_KEY;
		}
		return null;
	}
}
