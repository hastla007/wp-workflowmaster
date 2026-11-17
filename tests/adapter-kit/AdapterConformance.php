<?php
/**
 * Conformance kit for adapters (docs/SPEC-suite-events.md, 14.2, A-1 to A-11; TODO WF-SPEC-13).
 *
 * Adapters live in add-ons (captain-pro). Their test suites include this file and call the checks below; every check
 * returns a list of failures (empty = passed). The kit needs the plugin's src/ (autoloaded) and the catalog
 * docs/catalog/suite-events-v1.json. See README.md next to this file for the checks that need the add-on itself.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\AdapterKit;

use WorkflowCaptain\Adapter\Adapter;
use WorkflowCaptain\Clock;
use WorkflowCaptain\Envelope\Builder;
use WorkflowCaptain\Envelope\Validator;

final class AdapterConformance {

	/** Marker values a test passes in action arguments; none may reach an event (A-3). */
	const MARKERS = array(
		'code'     => 'MARKER-CODE-7Q2X',
		'token'    => 'MARKER-TOKEN-9W4Z',
		'email'    => 'marker.person@example.invalid',
		'name'     => 'Marker Mustername',
		'freetext' => 'MARKER-FREITEXT-GRUND',
		'key'      => 'MARKER-LICENSE-KEY-1234',
	);

	/** Command keys that must never be offered (8.2), as patterns. */
	const FORBIDDEN_COMMANDS = '/(refund|payout|commission\.(create|adjust|approve|reject|set)|affiliate\.status|license\.|activation|order\.(cancel|refund)|ticket\.(issue|transfer|checkin|checkout|revoke)|voucher\.(reissue|topup|credit|exchange)|mail\.(suppress|replace))/';

	private static ?array $catalog = null;

	public static function catalog(): array {
		if ( null === self::$catalog ) {
			$file          = dirname( __DIR__, 2 ) . '/docs/catalog/suite-events-v1.json';
			self::$catalog = (array) json_decode( (string) file_get_contents( $file ), true );
		}
		return self::$catalog;
	}

	/** @return array|null Catalog entry of an event type. */
	public static function catalog_entry( string $type ): ?array {
		foreach ( self::catalog()['products'] ?? array() as $product ) {
			foreach ( $product['events'] as $event ) {
				if ( $event['type'] === $type ) {
					return $event;
				}
			}
		}
		return null;
	}

	/**
	 * A-3, A-6, R-5.2: one event an adapter handed to Consumer::capture() for a provider action called with the markers.
	 *
	 * @param array $event What the adapter passed (type, subject, data, actor_user_id, discriminator).
	 * @return string[]
	 */
	public static function check_event( Adapter $adapter, array $event, array $markers = self::MARKERS ): array {
		$fail = array();
		$json = (string) json_encode( $event );
		foreach ( $markers as $what => $marker ) {
			if ( '' !== $marker && false !== stripos( $json, $marker ) ) {
				$fail[] = 'A-3: the event contains the ' . $what . ' marker';
			}
		}
		$entry = self::catalog_entry( (string) ( $event['type'] ?? '' ) );
		if ( null === $entry ) {
			$fail[] = 'A-4: type not in the catalog: ' . ( $event['type'] ?? '' );
		} else {
			$prefix = (string) ( $entry['data_field_prefix'] ?? '' );
			foreach ( array_keys( (array) ( $event['data'] ?? array() ) ) as $field ) {
				// Released answers of a form (spec 4.6.2): <prefix><key>, still checked by the schema below.
				if ( '' !== $prefix && 0 === strpos( (string) $field, $prefix ) && strlen( (string) $field ) > strlen( $prefix ) ) {
					continue;
				}
				if ( ! in_array( $field, $entry['data_fields'], true ) ) {
					$fail[] = 'R-5.2: field not on the allow-list of ' . $entry['type'] . ': ' . $field;
				}
			}
			if ( ( $event['subject']['type'] ?? '' ) !== '' && ! in_array( $event['subject']['type'], explode( '|', $entry['subject_type'] ), true ) ) {
				$fail[] = 'subject type does not match the catalog';
			}
			if ( 'none' === ( $entry['actor_user_id'] ?? '' ) && isset( $event['actor_user_id'] ) ) {
				$fail[] = 'actor_user_id must not be set for ' . $entry['type'];
			}
		}
		if ( ! isset( $event['discriminator'] ) || '' === (string) $event['discriminator'] ) {
			$fail[] = 'A-7: discriminator missing';
		}
		$envelope = Builder::build( $event, array( 'product' => $adapter->product(), 'core_version' => $adapter->core_version(), 'contract_version' => $adapter->contract_version() ), 1, new Clock() );
		foreach ( Validator::violations( $envelope ) as $code ) {
			$fail[] = 'A-6: schema: ' . $code;
		}
		return $fail;
	}

	/**
	 * A-7: the same action twice gives the same key, two real events give different keys.
	 *
	 * @return string[]
	 */
	public static function check_idempotency( array $first, array $repeat, array $other ): array {
		$key  = static fn( array $e ) => Builder::build( $e, array( 'product' => 'pass-captain', 'core_version' => '1.0.0', 'contract_version' => null ), 1, new Clock() )['idempotency_key'];
		$fail = array();
		if ( $key( $first ) !== $key( $repeat ) ) {
			$fail[] = 'A-7: a repetition of the same action got a different idempotency key';
		}
		if ( $key( $first ) === $key( $other ) ) {
			$fail[] = 'A-7: two real events got the same idempotency key';
		}
		return $fail;
	}

	/**
	 * A-5: an exception in the consumer or adapter must not reach the provider. $callback is the adapter's hook
	 * callback; $break makes the next capture fail (for example by passing a consumer whose store throws).
	 *
	 * @return string[]
	 */
	public static function check_callback_isolated( callable $callback, array $args ): array {
		try {
			$callback( ...$args );
			return array();
		} catch ( \Throwable $e ) {
			return array( 'A-5: the callback let an exception through: ' . get_class( $e ) );
		}
	}

	/**
	 * A-4: hooks the adapter attaches to, compared with the catalog. $attached are PHP action names.
	 *
	 * @param string[] $attached
	 * @param string[] $excluded Actions the spec forbids attaching to (for example wplc/license_issued).
	 * @return string[]
	 */
	public static function check_hooks( string $product, array $attached, array $excluded ): array {
		$catalog = array();
		foreach ( self::catalog()['products'][ $product ]['events'] ?? array() as $event ) {
			$catalog[] = explode( ':', $event['hook'] )[0];
		}
		$fail = array();
		foreach ( $attached as $hook ) {
			if ( in_array( $hook, $excluded, true ) ) {
				$fail[] = 'A-4: attached to an excluded action: ' . $hook;
			} elseif ( ! in_array( $hook, $catalog, true ) ) {
				$fail[] = 'A-4: attached to an action outside the catalog: ' . $hook;
			}
		}
		return $fail;
	}

	/**
	 * A-8: an adapter must not register for a provider whose contract is older than it needs or of a higher MAJOR.
	 *
	 * @return string[]
	 */
	public static function check_contract_gate( callable $would_register, string $min, int $known_major ): array {
		$fail = array();
		list( $maj, $min_minor ) = array_map( 'intval', explode( '.', $min ) );
		$older                   = $maj . '.' . max( 0, $min_minor - 1 ) . '.0';
		if ( $min_minor > 0 && $would_register( $older ) ) {
			$fail[] = 'A-8: registers with an older contract ' . $older;
		}
		if ( $would_register( ( $known_major + 1 ) . '.0.0' ) ) {
			$fail[] = 'A-8: registers with a higher MAJOR';
		}
		if ( ! $would_register( $min ) ) {
			$fail[] = 'A-8: does not register with the minimum contract ' . $min;
		}
		if ( $would_register( null ) ) {
			$fail[] = 'A-8: registers although the provider has no contract constant (R-3.3)';
		}
		return $fail;
	}

	/**
	 * A-9: no command of the forbidden list 8.2.
	 *
	 * @param string[] $action_keys Keys the adapter registers as actions.
	 * @return string[]
	 */
	public static function check_commands( array $action_keys ): array {
		$fail = array();
		foreach ( $action_keys as $key ) {
			if ( preg_match( self::FORBIDDEN_COMMANDS, $key ) ) {
				$fail[] = 'A-9: forbidden command offered: ' . $key;
			}
		}
		return $fail;
	}
}
