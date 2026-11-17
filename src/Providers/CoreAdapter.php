<?php
/**
 * Common base of the adapters the free core ships for WordPress and WooCommerce (F-20, SPEC-suite-events 4.7).
 *
 * Unlike the adapters of the Captain products these need no add-on and no license (R-2.4 unchanged): WordPress and
 * WooCommerce are not products of the suite, so the core may listen to them directly. Each callback builds the event
 * from a fixed list of allowed fields and hands it to the sink (Consumer::capture()); nothing a callback does may
 * reach WordPress or WooCommerce (R-7.6).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Providers;

use WorkflowCaptain\Adapter\Adapter;
use WorkflowCaptain\Adapter\AdapterRegistry;

defined( 'ABSPATH' ) || exit;

abstract class CoreAdapter implements Adapter {

	/** Priority of every hook: greater than 10 so the provider's own follow-up steps run first (R-7.5). */
	const PRIORITY = 20;

	/** @var callable(Adapter,array):mixed */
	private $sink;

	/** Provider version in the form X.Y.Z, or "" when the provider is missing. */
	protected string $version;

	/**
	 * @param callable(Adapter,array):mixed $sink    Receives each event, normally Consumer::capture().
	 * @param string|null                   $version Raw provider version; null reads it from the running site.
	 */
	public function __construct( callable $sink, ?string $version = null ) {
		$this->sink    = $sink;
		$this->version = self::normalize_version( $version ?? $this->detect_version() );
	}

	/** Raw version of the running provider, or "" when it is not active. */
	abstract protected function detect_version(): string;

	/** Lowest supported version of the provider (X.Y.Z). */
	abstract protected function min_version(): string;

	/** Adds the hook callbacks of 4.7. Called only after the registry accepted the adapter. */
	abstract public function hook(): void;

	/** Whether the provider is active and recent enough for this adapter. */
	public function available(): bool {
		return '' !== $this->version && version_compare( $this->version, $this->min_version(), '>=' );
	}

	public function core_version(): string {
		return '' === $this->version ? '0.0.0' : $this->version;
	}

	/** WordPress and WooCommerce have no contract constant; their version stands in for it (4.7). */
	public function contract_version(): ?string {
		return $this->core_version();
	}

	/** The hooks fire after saving, not proven after a commit: every event is re-read (R-7.4, 4.7). */
	public function needs_reread( string $type ): bool {
		return true;
	}

	/**
	 * Registers each available adapter and hooks it once the registry accepted it.
	 *
	 * @param CoreAdapter[] $adapters
	 * @return string[] Products that were registered.
	 */
	public static function register( AdapterRegistry $registry, array $adapters ): array {
		$registered = array();
		foreach ( $adapters as $adapter ) {
			try {
				if ( ! $adapter instanceof self ) {
					continue;
				}
				// Provider present but older than the minimum: stays unregistered, with the reason on the adapters page.
				if ( '' !== $adapter->version && ! version_compare( $adapter->version, $adapter->min_version(), '>=' ) ) {
					$registry->note_refused( $adapter->product(), 'provider_too_old' );
					continue;
				}
				if ( $adapter->available() && $registry->add( $adapter ) ) {
					$adapter->hook();
					$registered[] = $adapter->product();
				}
			} catch ( \Throwable $e ) {
				continue; // A broken provider must not stop the core from booting.
			}
		}
		return $registered;
	}

	/** "6.6" becomes "6.6.0", "8.9.3-beta.1" becomes "8.9.3"; anything without a leading number becomes "". */
	public static function normalize_version( string $raw ): string {
		if ( ! preg_match( '/^\s*v?([0-9]+)(?:\.([0-9]+))?(?:\.([0-9]+))?/', $raw, $m ) ) {
			return '';
		}
		return (int) $m[1] . '.' . (int) ( $m[2] ?? 0 ) . '.' . (int) ( $m[3] ?? 0 );
	}

	/** Hands an event to the sink; swallows everything so nothing reaches the provider (R-7.6). */
	protected function emit( array $event ): void {
		try {
			( $this->sink )( $this, $event );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** The signed-in user, or null (actor_user_id is never 0, section 6). */
	protected static function current_actor(): ?int {
		$id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return $id > 0 ? $id : null;
	}

	/** A short machine key (status, role, gateway id): lowercase letters, digits, "_" and "-", at most 64 characters. */
	protected static function key( $value ): string {
		return substr( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( is_scalar( $value ) ? (string) $value : '' ) ), 0, 64 );
	}
}
