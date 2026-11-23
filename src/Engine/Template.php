<?php
/**
 * Fills {{placeholders}} in the text of a message or note from the event: type, occurred_at, subject.type,
 * subject.id, source.product and data.<field>. The envelope holds no personal data or codes (section 5), so neither
 * does the text. Unknown placeholders become empty.
 *
 * A placeholder without a modifier is replaced by the raw value. With a modifier ({{occurred_at|date}},
 * {{data.total_minor|money:EUR}}, {{data.count|number}}) the value is written in the language of the message
 * (SET-WC-32, see Formatter). An unknown modifier is refused at publishing; in an older published text it stays as
 * written.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class Template {

	/** Placeholder with an optional modifier and argument. */
	const PATTERN = '/\{\{\s*([a-z_]+(?:\.[a-z0-9_]+)?)\s*(?:\|\s*([a-z_]+)(?::([A-Za-z0-9_]+))?\s*)?\}\}/';

	/**
	 * @param string|null $locale Language of the text for modifiers (e.g. de_DE); null: the site's language.
	 * @param string|null $tz     Time zone for dates; null: the time zone of Workflow Captain.
	 */
	public static function render( string $text, array $envelope, ?string $locale = null, ?string $tz = null ): string {
		return (string) preg_replace_callback(
			self::PATTERN,
			static function ( array $m ) use ( $envelope, $locale, $tz ): string {
				$path     = $m[1];
				$modifier = ( $m[2] ?? '' ) === '' ? null : $m[2];
				$arg      = ( $m[3] ?? '' ) === '' ? null : $m[3];
				if ( null !== $modifier && null !== Formatter::check( $modifier, $arg ) ) {
					return $m[0];
				}
				if ( ! in_array( $path, array( 'type', 'occurred_at', 'subject.type', 'subject.id', 'source.product' ), true ) && ! str_starts_with( $path, 'data.' ) ) {
					return '';
				}
				$node = $envelope;
				foreach ( explode( '.', $path ) as $part ) {
					if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
						return '';
					}
					$node = $node[ $part ];
				}
				if ( null !== $modifier ) {
					return Formatter::format( $modifier, $arg, $node, $envelope, $locale ?? self::site_locale(), $tz ?? \WorkflowCaptain\Settings::timezone() );
				}
				if ( is_bool( $node ) ) {
					return $node ? 'true' : 'false';
				}
				return is_scalar( $node ) ? (string) $node : '';
			},
			$text
		);
	}

	/** Checks a text field of a configuration, including the modifiers of its placeholders (SET-WC-32). */
	public static function validate( $text, string $field, int $max ): array {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return array( $field . ':required' );
		}
		if ( mb_strlen( $text ) > $max ) {
			return array( $field . ':too_long' );
		}
		preg_match_all( self::PATTERN, $text, $all, PREG_SET_ORDER );
		foreach ( $all as $m ) {
			if ( ( $m[2] ?? '' ) !== '' ) {
				$code = Formatter::check( $m[2], ( $m[3] ?? '' ) === '' ? null : $m[3] );
				if ( null !== $code ) {
					return array( $field . ':' . $code );
				}
			}
		}
		return array();
	}

	/** Language of the site, for texts without a recipient language. */
	public static function site_locale(): string {
		return function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
	}
}
