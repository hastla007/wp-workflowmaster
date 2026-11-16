<?php
/**
 * Idempotency key of an event (docs/SPEC-suite-events.md, R-7.8 and R-7.9).
 *
 * SHA-256 (hex) over MAJOR of the specification, blog ID, type, subject type, subject ID and the discriminator, joined
 * with "|". The discriminator comes from the adapter: it is equal for a repetition of the same provider action and
 * different for two real events of the same type on the same object.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Envelope;

defined( 'ABSPATH' ) || exit;

final class IdempotencyKey {

	/** Discriminator of an event that happens once per object (R-7.9). */
	const ONCE = 'once';

	public static function make( int $spec_major, int $blog_id, string $type, string $subject_type, string $subject_id, string $discriminator ): string {
		return hash( 'sha256', implode( '|', array( (string) $spec_major, (string) $blog_id, $type, $subject_type, $subject_id, $discriminator ) ) );
	}

	/** Discriminator of a status change: old status, new status and the moment to the second (R-7.9). */
	public static function status_change( string $old, string $new, string $occurred_at ): string {
		return $old . '>' . $new . '@' . substr( $occurred_at, 0, 19 );
	}
}
