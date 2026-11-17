<?php
/**
 * Condition of an If/Else node (WF-BUILDER-05, konzept 15.3 "Bedingungen und Verzweigungen").
 *
 * Rules: { "op": "and"|"or", "items": [ rule | group ] }, rule: { "field", "operator", "value" }. At most two levels of
 * groups and 20 rules. Fields: "type", "subject.type", "subject.id", "subject.id_kind", "source.product",
 * "actor_user_id", "data.<name>". No casting: a number compares only with a number.
 *
 * Mode for a missing field (visible in the editor): "false" (the rule is false), "stop" (the run ends without error),
 * "error" (the step fails).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class Conditions {

	const OPERATORS = array( 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'exists', 'not_exists' );
	const MISSING   = array( 'false', 'stop', 'error' );

	const MAX_RULES = 20;
	const MAX_DEPTH = 2;

	const TRUE  = 'true';
	const FALSE = 'false';
	const STOP  = 'stop';
	const ERROR = 'error';

	/** @return string[] Violation codes of a condition configuration. */
	public static function validate( $rules ): array {
		$count = 0;
		$v     = array();
		self::validate_group( $rules, 1, $count, $v );
		if ( $count > self::MAX_RULES ) {
			$v[] = 'condition:too_many_rules';
		}
		return array_values( array_unique( $v ) );
	}

	/**
	 * Evaluates once (WF-BUILDER-07). Returns self::TRUE, self::FALSE, self::STOP or self::ERROR.
	 */
	public static function evaluate( array $rules, array $envelope, string $missing ): string {
		try {
			return self::group( $rules, $envelope, $missing ) ? self::TRUE : self::FALSE;
		} catch ( MissingField $e ) {
			return 'stop' === $missing ? self::STOP : self::ERROR;
		}
	}

	private static function validate_group( $group, int $depth, int &$count, array &$v ): void {
		if ( ! is_array( $group ) || ! in_array( $group['op'] ?? null, array( 'and', 'or' ), true ) || ! isset( $group['items'] ) || ! is_array( $group['items'] ) || array() === $group['items'] ) {
			$v[] = 'condition:invalid_group';
			return;
		}
		if ( $depth > self::MAX_DEPTH ) {
			$v[] = 'condition:too_deep';
			return;
		}
		foreach ( $group['items'] as $item ) {
			if ( is_array( $item ) && isset( $item['op'] ) ) {
				self::validate_group( $item, $depth + 1, $count, $v );
				continue;
			}
			++$count;
			if ( ! is_array( $item ) || ! is_string( $item['field'] ?? null ) || ! self::valid_field( $item['field'] ) ) {
				$v[] = 'condition:invalid_field';
			}
			$op = $item['operator'] ?? null;
			if ( ! in_array( $op, self::OPERATORS, true ) ) {
				$v[] = 'condition:invalid_operator';
				continue;
			}
			$value = $item['value'] ?? null;
			if ( in_array( $op, array( 'in', 'not_in' ), true ) && ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 50 ) ) {
				$v[] = 'condition:list_expected';
			} elseif ( in_array( $op, array( 'gt', 'gte', 'lt', 'lte' ), true ) && ! is_int( $value ) && ! is_float( $value ) ) {
				$v[] = 'condition:number_expected';
			} elseif ( in_array( $op, array( 'eq', 'neq', 'contains' ), true ) && ! is_scalar( $value ) && null !== $value ) {
				$v[] = 'condition:scalar_expected';
			}
		}
	}

	private static function valid_field( string $field ): bool {
		return in_array( $field, array( 'type', 'subject.type', 'subject.id', 'subject.id_kind', 'source.product', 'actor_user_id' ), true )
			|| 1 === preg_match( '/^data\.[a-z][a-z0-9_]*$/', $field );
	}

	private static function group( array $group, array $envelope, string $missing ): bool {
		$and = 'and' === $group['op'];
		foreach ( $group['items'] as $item ) {
			$result = isset( $item['op'] ) ? self::group( $item, $envelope, $missing ) : self::rule( $item, $envelope, $missing );
			if ( $and && ! $result ) {
				return false;
			}
			if ( ! $and && $result ) {
				return true;
			}
		}
		return $and;
	}

	private static function rule( array $rule, array $envelope, string $missing ): bool {
		$op     = $rule['operator'];
		$exists = self::lookup( $envelope, $rule['field'], $value );
		if ( 'exists' === $op ) {
			return $exists;
		}
		if ( 'not_exists' === $op ) {
			return ! $exists;
		}
		if ( ! $exists ) {
			if ( 'false' === $missing ) {
				return false;
			}
			throw new MissingField();
		}
		$expected = $rule['value'] ?? null;
		switch ( $op ) {
			case 'eq':
				return $value === $expected || ( self::is_number( $value ) && self::is_number( $expected ) && (float) $value === (float) $expected );
			case 'neq':
				return ! ( $value === $expected || ( self::is_number( $value ) && self::is_number( $expected ) && (float) $value === (float) $expected ) );
			case 'gt':
				return self::is_number( $value ) && $value > $expected;
			case 'gte':
				return self::is_number( $value ) && $value >= $expected;
			case 'lt':
				return self::is_number( $value ) && $value < $expected;
			case 'lte':
				return self::is_number( $value ) && $value <= $expected;
			case 'in':
				return in_array( $value, (array) $expected, true );
			case 'not_in':
				return ! in_array( $value, (array) $expected, true );
			case 'contains':
				return is_string( $value ) && is_string( $expected ) && '' !== $expected && str_contains( $value, $expected );
		}
		return false;
	}

	private static function is_number( $value ): bool {
		return is_int( $value ) || is_float( $value );
	}

	/** Reads a field; false if it is absent or null. */
	private static function lookup( array $envelope, string $field, &$value ): bool {
		$node = $envelope;
		foreach ( explode( '.', $field ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				$value = null;
				return false;
			}
			$node = $node[ $part ];
		}
		$value = $node;
		return null !== $node;
	}
}

/** Internal: a field a rule needs is missing. */
final class MissingField extends \RuntimeException {}
