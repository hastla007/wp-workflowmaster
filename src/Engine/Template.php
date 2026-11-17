<?php
/**
 * Fills {{placeholders}} in the text of a message or note from the event: type, occurred_at, subject.type,
 * subject.id, source.product and data.<field>. The envelope holds no personal data or codes (section 5), so neither
 * does the text. Unknown placeholders become empty.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class Template {

	public static function render( string $text, array $envelope ): string {
		return (string) preg_replace_callback(
			'/\{\{\s*([a-z_]+(?:\.[a-z0-9_]+)?)\s*\}\}/',
			static function ( array $m ) use ( $envelope ): string {
				$path = $m[1];
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
				if ( is_bool( $node ) ) {
					return $node ? 'true' : 'false';
				}
				return is_scalar( $node ) ? (string) $node : '';
			},
			$text
		);
	}

	/** Checks a text field of a configuration. */
	public static function validate( $text, string $field, int $max ): array {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return array( $field . ':required' );
		}
		return mb_strlen( $text ) > $max ? array( $field . ':too_long' ) : array();
	}
}
