<?php
/**
 * Request-scoped list of secret values that must never appear in logs, results, errors or exports (konzept 9.1).
 * Every value the vault decrypts is added; scrub() replaces them in any text before it is stored or shown.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Security;

defined( 'ABSPATH' ) || exit;

final class Redact {

	/** @var array<string,true> */
	private static array $values = array();

	public static function remember( string $value ): void {
		if ( strlen( $value ) >= 4 ) {
			self::$values[ $value ] = true;
		}
	}

	public static function scrub( string $text ): string {
		if ( array() === self::$values ) {
			return $text;
		}
		$values = array_keys( self::$values );
		usort( $values, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		return str_replace( $values, '[redacted]', $text );
	}

	/** For tests. */
	public static function reset(): void {
		self::$values = array();
	}
}
