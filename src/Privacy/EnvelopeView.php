<?php
/**
 * Redacted view of an envelope for the run page and support (docs/UI-ENTWUERFE.md, section 5).
 *
 * Shows only the routing fields of the envelope and, from data, only fields the catalogue allows for the event type
 * (assets/data/catalog-fields.json, non-personal connector fields). No actor, no user IDs, no address-like strings, no
 * non-scalar values (Engine::redact, R-12.3), and secret values the vault decrypted in this request are scrubbed
 * (Redact::scrub, konzept 9.1).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Privacy;

use WorkflowCaptain\Engine\Engine;
use WorkflowCaptain\Security\Redact;

defined( 'ABSPATH' ) || exit;

final class EnvelopeView {

	/** Top-level fields that carry no personal data (actor_user_id is left out on purpose). */
	const TOP_LEVEL = array( 'id', 'type', 'spec_version', 'source', 'occurred_at', 'site', 'subject', 'idempotency_key', 'correlation_id', 'causation_id', 'caused_by_workflow_id', 'chain_depth' );

	/**
	 * @param array<string,mixed>                $envelope The stored envelope (run context or event).
	 * @param array<string,array<string,string>> $allowed  Allowed data fields per event type (type => field => data type).
	 * @return array{envelope:array<string,mixed>,hidden:int} The redacted envelope and how many data fields were left out.
	 */
	public static function redacted( array $envelope, array $allowed ): array {
		$out = array();
		foreach ( self::TOP_LEVEL as $key ) {
			if ( array_key_exists( $key, $envelope ) ) {
				$out[ $key ] = self::scrub( $envelope[ $key ] );
			}
		}
		$data   = is_array( $envelope['data'] ?? null ) ? $envelope['data'] : array();
		$fields = (array) ( $allowed[ (string) ( $envelope['type'] ?? '' ) ] ?? array() );
		$keep   = array();
		foreach ( $data as $field => $value ) {
			$field = (string) $field;
			if ( isset( $fields[ $field ] ) && ! preg_match( '/(^|_)user_id$/', $field ) ) {
				$keep[ $field ] = $value;
			}
		}
		$keep        = array_map( array( self::class, 'scrub' ), Engine::redact( $keep ) );
		$out['data'] = $keep;
		return array( 'envelope' => $out, 'hidden' => count( $data ) - count( $keep ) );
	}

	/**
	 * Allowed data fields: the core catalogue plus the given non-personal connector fields.
	 *
	 * @param array<string,array<string,string>> $extra
	 * @return array<string,array<string,string>>
	 */
	public static function allowed_fields( array $extra = array() ): array {
		$file    = WORKFLOW_CAPTAIN_PATH . 'assets/data/catalog-fields.json';
		$catalog = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array();
		return array_merge( is_array( $catalog ) ? $catalog : array(), $extra );
	}

	/** @param mixed $value */
	private static function scrub( $value ) {
		if ( is_string( $value ) ) {
			return Redact::scrub( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'scrub' ), $value );
		}
		return $value;
	}
}
