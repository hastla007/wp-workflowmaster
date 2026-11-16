<?php
/**
 * Checks an envelope against docs/schema/suite-event-v1.schema.json (R-5.5, R-6.3).
 *
 * The JSON schema is normative; this class mirrors it rule by rule so the consumer can check every envelope before it
 * stores it, without a JSON-schema library at runtime. tests/run.php checks that this class and the schema agree on
 * every example and counter-example of docs/examples.
 *
 * The result is a list of violation codes. A code names the rule and the field, never the value: violations are
 * logged, and a value could be exactly the secret the rule keeps out (R-5.3, R-12.3).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Envelope;

defined( 'ABSPATH' ) || exit;

final class Validator {

	const UUID4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	const TYPE = '/^(voucher|pass|affiliate|license|form|review|course|workflow)\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

	const PRODUCTS = array( 'voucher-captain', 'pass-captain', 'affiliate-captain', 'form-captain', 'review-captain', 'course-captain', 'workflow-captain', 'wp-license' );

	const TOP_LEVEL = array( 'id', 'type', 'spec_version', 'source', 'occurred_at', 'site', 'subject', 'actor_user_id', 'data', 'idempotency_key', 'correlation_id', 'causation_id', 'caused_by_workflow_id', 'chain_depth' );

	const REQUIRED = array( 'id', 'type', 'spec_version', 'source', 'occurred_at', 'site', 'subject', 'data', 'idempotency_key', 'correlation_id', 'causation_id', 'chain_depth' );

	/**
	 * Field names that are never allowed in data (schema: data.propertyNames). The public partner code is the one
	 * exception (decision D8b, R-5.1a).
	 */
	const FORBIDDEN_DATA_NAME = '/(^|_)(code|codes|token|tokens|secret|password|license_key|api_key|email|e_mail|mail|name|first_name|last_name|address|phone|iban|bic|message|note|path|url|ip)$/';

	/**
	 * @param mixed $envelope Decoded envelope.
	 * @return string[] Violation codes; empty when the envelope is valid.
	 */
	public static function violations( $envelope ): array {
		if ( ! is_array( $envelope ) || ( array() !== $envelope && array_is_list( $envelope ) ) ) {
			return array( 'envelope:not_object' );
		}
		$v = array();
		foreach ( array_keys( $envelope ) as $key ) {
			if ( ! in_array( $key, self::TOP_LEVEL, true ) ) {
				$v[] = 'envelope:unknown_field';
			}
		}
		foreach ( self::REQUIRED as $key ) {
			if ( ! array_key_exists( $key, $envelope ) ) {
				$v[] = $key . ':missing';
			}
		}

		self::uuid( $envelope, 'id', false, $v );
		if ( isset( $envelope['type'] ) && ! self::matches( $envelope['type'], self::TYPE, 96 ) ) {
			$v[] = 'type:invalid';
		}
		if ( isset( $envelope['spec_version'] ) && ! self::matches( $envelope['spec_version'], '/^1\.(0|[1-9][0-9]*)$/' ) ) {
			$v[] = 'spec_version:invalid';
		}
		if ( array_key_exists( 'source', $envelope ) ) {
			self::source( $envelope['source'], $v );
		}
		if ( isset( $envelope['occurred_at'] ) && ! self::matches( $envelope['occurred_at'], '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?Z$/' ) ) {
			$v[] = 'occurred_at:invalid';
		}
		if ( array_key_exists( 'site', $envelope ) ) {
			$site = $envelope['site'];
			if ( ! self::is_object( $site ) || array_diff( array_keys( $site ), array( 'blog_id' ) ) || ! isset( $site['blog_id'] ) || ! is_int( $site['blog_id'] ) || $site['blog_id'] < 1 ) {
				$v[] = 'site:invalid';
			}
		}
		if ( array_key_exists( 'subject', $envelope ) ) {
			self::subject( $envelope['subject'], $v );
		}
		if ( array_key_exists( 'actor_user_id', $envelope ) && ( ! is_int( $envelope['actor_user_id'] ) || $envelope['actor_user_id'] < 1 ) ) {
			$v[] = 'actor_user_id:invalid';
		}
		if ( array_key_exists( 'data', $envelope ) ) {
			self::data( $envelope['data'], $v );
		}
		if ( isset( $envelope['idempotency_key'] ) && ! self::matches( $envelope['idempotency_key'], '/^[0-9a-f]{64}$/' ) ) {
			$v[] = 'idempotency_key:invalid';
		}
		self::uuid( $envelope, 'correlation_id', false, $v );
		self::uuid( $envelope, 'causation_id', true, $v );
		if ( array_key_exists( 'caused_by_workflow_id', $envelope ) && ( ! is_int( $envelope['caused_by_workflow_id'] ) || $envelope['caused_by_workflow_id'] < 1 ) ) {
			$v[] = 'caused_by_workflow_id:invalid';
		}
		$depth = $envelope['chain_depth'] ?? null;
		if ( array_key_exists( 'chain_depth', $envelope ) && ( ! is_int( $depth ) || $depth < 0 || $depth > 8 ) ) {
			$v[] = 'chain_depth:invalid';
		}

		// Schema allOf: with a causation, the workflow and a depth of at least 1 are required; without one, depth is 0
		// and no workflow is named.
		if ( isset( $envelope['causation_id'] ) && is_string( $envelope['causation_id'] ) ) {
			if ( ! array_key_exists( 'caused_by_workflow_id', $envelope ) ) {
				$v[] = 'caused_by_workflow_id:missing';
			}
			if ( is_int( $depth ) && $depth < 1 ) {
				$v[] = 'chain_depth:causation_needs_depth';
			}
		} else {
			if ( array_key_exists( 'caused_by_workflow_id', $envelope ) ) {
				$v[] = 'caused_by_workflow_id:without_causation';
			}
			if ( array_key_exists( 'chain_depth', $envelope ) && 0 !== $depth ) {
				$v[] = 'chain_depth:without_causation';
			}
		}
		return array_values( array_unique( $v ) );
	}

	public static function is_valid( $envelope ): bool {
		return array() === self::violations( $envelope );
	}

	private static function source( $source, array &$v ): void {
		if ( ! self::is_object( $source ) || array_diff( array_keys( $source ), array( 'product', 'core_version', 'contract_version' ) ) ) {
			$v[] = 'source:invalid';
			return;
		}
		if ( ! isset( $source['product'] ) || ! in_array( $source['product'], self::PRODUCTS, true ) ) {
			$v[] = 'source.product:invalid';
		}
		if ( ! isset( $source['core_version'] ) || ! self::matches( $source['core_version'], '/^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$/' ) ) {
			$v[] = 'source.core_version:invalid';
		}
		if ( ! array_key_exists( 'contract_version', $source ) ) {
			$v[] = 'source.contract_version:missing';
		} elseif ( null !== $source['contract_version'] && ! self::matches( $source['contract_version'], '/^[0-9]+\.[0-9]+\.[0-9]+$/' ) ) {
			$v[] = 'source.contract_version:invalid';
		}
	}

	private static function subject( $subject, array &$v ): void {
		if ( ! self::is_object( $subject ) || array_diff( array_keys( $subject ), array( 'type', 'id', 'id_kind' ) ) ) {
			$v[] = 'subject:invalid';
			return;
		}
		if ( ! isset( $subject['type'] ) || ! self::matches( $subject['type'], '/^[a-z][a-z0-9_]*$/', 40 ) ) {
			$v[] = 'subject.type:invalid';
		}
		$id = $subject['id'] ?? null;
		if ( ! is_string( $id ) || '' === $id || mb_strlen( $id ) > 191 || str_contains( $id, '@' ) ) {
			$v[] = 'subject.id:invalid';
		}
		if ( ! isset( $subject['id_kind'] ) || ! in_array( $subject['id_kind'], array( 'public', 'record' ), true ) ) {
			$v[] = 'subject.id_kind:invalid';
		}
	}

	private static function data( $data, array &$v ): void {
		if ( ! self::is_object( $data ) ) {
			$v[] = 'data:not_object';
			return;
		}
		if ( count( $data ) > 24 ) {
			$v[] = 'data:too_many_fields';
		}
		foreach ( $data as $name => $value ) {
			$name = (string) $name;
			if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $name ) || strlen( $name ) > 48 ) {
				$v[] = 'data:invalid_field_name';
			} elseif ( 'partner_code' !== $name && preg_match( self::FORBIDDEN_DATA_NAME, $name ) ) {
				$v[] = 'data:forbidden_field_name';
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				$v[] = 'data:nested_value';
			} elseif ( is_string( $value ) && mb_strlen( $value ) > 191 ) {
				$v[] = 'data:value_too_long';
			} elseif ( is_float( $value ) && ! is_finite( $value ) ) {
				$v[] = 'data:invalid_number';
			}
		}
	}

	private static function uuid( array $envelope, string $key, bool $nullable, array &$v ): void {
		if ( ! array_key_exists( $key, $envelope ) ) {
			return;
		}
		$value = $envelope[ $key ];
		if ( null === $value && $nullable ) {
			return;
		}
		if ( ! self::matches( $value, self::UUID4 ) ) {
			$v[] = $key . ':invalid';
		}
	}

	private static function matches( $value, string $pattern, int $max_length = 0 ): bool {
		return is_string( $value ) && ( 0 === $max_length || mb_strlen( $value ) <= $max_length ) && 1 === preg_match( $pattern, $value );
	}

	/** A JSON object decodes to an associative array; an empty object to array(). */
	private static function is_object( $value ): bool {
		return is_array( $value ) && ( array() === $value || ! array_is_list( $value ) );
	}
}
