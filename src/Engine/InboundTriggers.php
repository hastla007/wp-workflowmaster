<?php
/**
 * Inbound triggers (WF-CONNECTOR-06, WF-N8N-05): an add-on that receives external calls (Workflow Captain Pro:
 * webhooks, n8n) declares its triggers with typed fields on the filter "workflow_captain_inbound_triggers_v1".
 * The core stores a checked call as the event "workflow.inbound.<trigger>" of its own product and starts workflows.
 *
 *     add_filter( 'workflow_captain_inbound_triggers_v1', function ( $triggers ) {
 *         $triggers['order_export'] = array( 'label' => 'Bestellexport', 'fields' => array( 'order_id' => 'integer', 'status' => 'string' ) );
 *         return $triggers;
 *     } );
 *
 * The caller must have checked the request before (InboundGuard); the core checks names, types and section 5 again.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

use WorkflowCaptain\Envelope\Validator;

defined( 'ABSPATH' ) || exit;

final class InboundTriggers {

	const PREFIX = 'workflow.inbound.';
	const TYPES  = array( 'integer', 'number', 'boolean', 'string', 'currency', 'datetime' );

	/** @return array<string,array{label:string,fields:array<string,string>}> Valid declarations only. */
	public static function declared( ?array $raw = null ): array {
		$raw = $raw ?? ( function_exists( 'apply_filters' ) ? (array) apply_filters( 'workflow_captain_inbound_triggers_v1', array() ) : array() );
		$out = array();
		foreach ( $raw as $key => $def ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]{1,39}$/', $key ) || ! is_array( $def ) || ! is_array( $def['fields'] ?? null ) || count( $def['fields'] ) > 24 ) {
				continue;
			}
			$fields = array();
			foreach ( $def['fields'] as $name => $type ) {
				if ( is_string( $name ) && preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $name ) && ! preg_match( Validator::FORBIDDEN_DATA_NAME, $name ) && in_array( $type, self::TYPES, true ) ) {
					$fields[ $name ] = $type;
				}
			}
			$out[ $key ] = array( 'label' => is_string( $def['label'] ?? null ) ? $def['label'] : $key, 'fields' => $fields );
		}
		return $out;
	}

	/** @return string[] Event types of the declared triggers. */
	public static function types( ?array $declared = null ): array {
		return array_map( static fn( $k ) => self::PREFIX . $k, array_keys( $declared ?? self::declared() ) );
	}

	/**
	 * The event for a checked call: only declared fields with a matching type; null when the trigger is unknown.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function event( string $trigger, array $data, string $request_id, ?array $declared = null ): ?array {
		$declared = $declared ?? self::declared();
		if ( ! isset( $declared[ $trigger ] ) || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $request_id ) ) {
			return null;
		}
		$clean = array();
		foreach ( $declared[ $trigger ]['fields'] as $name => $type ) {
			if ( ! array_key_exists( $name, $data ) ) {
				continue;
			}
			$v  = $data[ $name ];
			$ok = match ( $type ) {
				'integer'  => is_int( $v ),
				'number'   => is_int( $v ) || is_float( $v ),
				'boolean'  => is_bool( $v ),
				'currency' => is_string( $v ) && 1 === preg_match( '/^[A-Z]{3}$/', $v ),
				'datetime' => is_string( $v ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $v ),
				default    => is_string( $v ) && mb_strlen( $v ) <= 191 && ! str_contains( $v, '@' ),
			};
			if ( $ok ) {
				$clean[ $name ] = $v;
			}
		}
		return array(
			'type'          => self::PREFIX . $trigger,
			'subject'       => array( 'type' => 'inbound', 'id' => $request_id, 'id_kind' => 'record' ),
			'data'          => $clean,
			'discriminator' => $request_id,
		);
	}

	/** Field types for the builder (event type => field => type). */
	public static function builder_fields( ?array $declared = null ): array {
		$out = array();
		foreach ( $declared ?? self::declared() as $key => $def ) {
			$out[ self::PREFIX . $key ] = $def['fields'];
		}
		return $out;
	}
}
