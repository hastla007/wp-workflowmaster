<?php
/**
 * Modifiers of placeholders (SET-WC-32): {{occurred_at|date}}, {{occurred_at|day}}, {{data.count|number}},
 * {{data.share|number:2}}, {{data.total_minor|money}} and {{data.total_minor|money:EUR}}, written the way the language
 * of the message writes them (SET-WC-18). Without a modifier a placeholder keeps its raw value.
 *
 * With ext-intl the formats come from ICU (IntlDateFormatter, NumberFormatter). Without it a fixed fallback applies:
 * dates as "Y-m-d H:i" (day: "Y-m-d") in the time zone of Workflow Captain, numbers with a decimal comma and a dot
 * between thousands for de, es, it, nl, pt, da, tr and id, a decimal comma and a space for fr, sv, nb, fi, cs, pl and
 * ru, else a decimal point and a comma; amounts as the number followed by the ISO code ("1.234,50 EUR"), in English
 * the code first ("EUR 1,234.50").
 *
 * Amounts: an integer (or a string of digits) is the amount in the minor unit of the currency (R-5.1a, `*_minor`),
 * any other number is in the major unit. The currency is the argument, else `data.currency` of the event; the number
 * of decimals comes from ISO 4217 (Money). Without a currency the raw value is written. The wording of price
 * statements in messages is subject to legal review.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

use WorkflowCaptain\Money;

defined( 'ABSPATH' ) || exit;

final class Formatter {

	const MODIFIERS = array( 'date', 'day', 'number', 'money' );

	/** Tests: false forces the fallback without ext-intl; null decides by the extension. */
	public static ?bool $intl = null;

	/** Error code for a modifier and its argument, or null when they are valid. */
	public static function check( string $modifier, ?string $arg ): ?string {
		if ( ! in_array( $modifier, self::MODIFIERS, true ) ) {
			return 'unknown_modifier';
		}
		$ok = match ( $modifier ) {
			'number' => null === $arg || 1 === preg_match( '/^[0-6]$/', $arg ),
			'money'  => null === $arg || 1 === preg_match( '/^[A-Z]{3}$/', $arg ),
			default  => null === $arg,
		};
		return $ok ? null : 'invalid_modifier';
	}

	/**
	 * Writes a value with a modifier. A value that does not fit the modifier (not a date, not a number) is written raw.
	 *
	 * @param mixed $value Scalar from the event.
	 */
	public static function format( string $modifier, ?string $arg, $value, array $envelope, string $locale, string $tz ): string {
		$raw = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : ( is_scalar( $value ) ? (string) $value : '' );
		if ( null !== self::check( $modifier, $arg ) || ! is_scalar( $value ) || is_bool( $value ) ) {
			return $raw;
		}
		$locale = self::locale( $locale );
		if ( 'date' === $modifier || 'day' === $modifier ) {
			return self::date( $value, 'day' === $modifier, $locale, $tz ) ?? $raw;
		}
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_numeric( $value ) ) {
			return $raw;
		}
		if ( 'number' === $modifier ) {
			return self::number( (float) $value, null === $arg ? self::decimals_of( $value ) : (int) $arg, $locale );
		}
		$currency = $arg ?? ( is_string( $envelope['data']['currency'] ?? null ) ? strtoupper( $envelope['data']['currency'] ) : '' );
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return $raw;
		}
		$exp   = Money::exponent( $currency );
		$minor = is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', $value ) );
		$major = $minor ? (int) $value / ( 10 ** $exp ) : (float) $value;
		return self::money( $major, $currency, $exp, $locale );
	}

	/** "de_DE_formal" → "de_DE"; anything unusable → "en_US". */
	public static function locale( string $locale ): string {
		return 1 === preg_match( '/^([a-z]{2,3})(_[A-Z]{2})?/', $locale, $m ) ? $m[1] . ( $m[2] ?? '' ) : 'en_US';
	}

	private static function intl(): bool {
		return self::$intl ?? ( class_exists( \NumberFormatter::class ) && class_exists( \IntlDateFormatter::class ) );
	}

	/** @param int|float|string $value Unix time or a date string (ISO 8601 as in occurred_at). */
	private static function date( $value, bool $day_only, string $locale, string $tz ): ?string {
		try {
			$zone = new \DateTimeZone( '' === $tz ? 'UTC' : $tz );
			if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\d{9,11}$/', $value ) ) ) {
				$date = new \DateTimeImmutable( '@' . (int) $value );
			} elseif ( is_string( $value ) && 1 === preg_match( '/^\d{4}-\d\d-\d\d/', $value ) ) {
				$date = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
			} else {
				return null;
			}
			$date = $date->setTimezone( $zone );
		} catch ( \Exception $e ) {
			return null;
		}
		if ( self::intl() ) {
			$f   = new \IntlDateFormatter( $locale, \IntlDateFormatter::MEDIUM, $day_only ? \IntlDateFormatter::NONE : \IntlDateFormatter::SHORT, $zone );
			$out = $f->format( $date );
			if ( is_string( $out ) ) {
				return $out;
			}
		}
		return $date->format( $day_only ? 'Y-m-d' : 'Y-m-d H:i' );
	}

	/** Decimals the value has (at most 3), for a number without a fixed number of decimals. */
	private static function decimals_of( $value ): int {
		if ( is_int( $value ) ) {
			return 0;
		}
		$s = is_float( $value ) ? rtrim( rtrim( sprintf( '%.3F', $value ), '0' ), '.' ) : (string) $value;
		$p = strpos( $s, '.' );
		return false === $p ? 0 : min( 3, strlen( $s ) - $p - 1 );
	}

	private static function number( float $value, int $decimals, string $locale ): string {
		if ( self::intl() ) {
			$f = new \NumberFormatter( $locale, \NumberFormatter::DECIMAL );
			$f->setAttribute( \NumberFormatter::MIN_FRACTION_DIGITS, $decimals );
			$f->setAttribute( \NumberFormatter::MAX_FRACTION_DIGITS, $decimals );
			$out = $f->format( $value );
			if ( is_string( $out ) ) {
				return $out;
			}
		}
		list( $point, $thousands ) = self::separators( $locale );
		return number_format( $value, $decimals, $point, $thousands );
	}

	private static function money( float $major, string $currency, int $exp, string $locale ): string {
		if ( self::intl() ) {
			$f = new \NumberFormatter( $locale, \NumberFormatter::CURRENCY );
			$f->setAttribute( \NumberFormatter::MIN_FRACTION_DIGITS, $exp );
			$f->setAttribute( \NumberFormatter::MAX_FRACTION_DIGITS, $exp );
			$out = $f->formatCurrency( $major, $currency );
			if ( is_string( $out ) ) {
				return $out;
			}
		}
		$number = self::number( $major, $exp, $locale );
		return str_starts_with( $locale, 'en' ) ? $currency . ' ' . $number : $number . ' ' . $currency;
	}

	/** Fallback without ext-intl: [decimal point, thousands separator] of a language. */
	private static function separators( string $locale ): array {
		$lang = strtok( $locale, '_' );
		if ( in_array( $lang, array( 'de', 'es', 'it', 'nl', 'pt', 'da', 'tr', 'id' ), true ) ) {
			return array( ',', '.' );
		}
		if ( in_array( $lang, array( 'fr', 'sv', 'nb', 'fi', 'cs', 'pl', 'ru' ), true ) ) {
			return array( ',', "\u{00A0}" );
		}
		return array( '.', ',' );
	}
}
