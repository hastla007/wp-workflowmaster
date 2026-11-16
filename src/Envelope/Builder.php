<?php
/**
 * Builds the envelope (R-6.1, R-6.2): the adapter supplies type, subject, data, the optional actor and the
 * discriminator; the consumer sets every other field.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Envelope;

use WorkflowCaptain\Clock;
use WorkflowCaptain\Consumer\Causation;

defined( 'ABSPATH' ) || exit;

final class Builder {

	/** Version of docs/SPEC-suite-events.md the envelopes follow (field spec_version). */
	const SPEC_VERSION = '1.0';

	const SPEC_MAJOR = 1;

	/**
	 * @param array{type:string,subject:array,data?:array,actor_user_id?:int|null,discriminator:string} $event From the adapter.
	 * @param array{product:string,core_version:string,contract_version:?string}                      $source Of the adapter's provider.
	 */
	public static function build( array $event, array $source, int $blog_id, Clock $clock, ?Causation $cause = null ): array {
		$id       = Uuid::v4();
		$subject  = (array) ( $event['subject'] ?? array() );
		$envelope = array(
			'id'           => $id,
			'type'         => (string) ( $event['type'] ?? '' ),
			'spec_version' => self::SPEC_VERSION,
			'source'       => array(
				'product'          => (string) $source['product'],
				'core_version'     => (string) $source['core_version'],
				'contract_version' => isset( $source['contract_version'] ) ? (string) $source['contract_version'] : null,
			),
			'occurred_at'  => $clock->rfc3339(),
			'site'         => array( 'blog_id' => $blog_id ),
			'subject'      => array(
				'type'    => (string) ( $subject['type'] ?? '' ),
				'id'      => (string) ( $subject['id'] ?? '' ),
				'id_kind' => (string) ( $subject['id_kind'] ?? '' ),
			),
		);
		$actor = $event['actor_user_id'] ?? null;
		if ( is_int( $actor ) && $actor > 0 ) {
			$envelope['actor_user_id'] = $actor;
		}
		$envelope['data']            = (array) ( $event['data'] ?? array() );
		$envelope['idempotency_key'] = IdempotencyKey::make(
			self::SPEC_MAJOR,
			$blog_id,
			$envelope['type'],
			$envelope['subject']['type'],
			$envelope['subject']['id'],
			(string) ( $event['discriminator'] ?? '' )
		);
		if ( null === $cause ) {
			$envelope['correlation_id'] = $id;
			$envelope['causation_id']   = null;
			$envelope['chain_depth']    = 0;
		} else {
			$envelope['correlation_id']        = $cause->correlation_id;
			$envelope['causation_id']          = $cause->command_id;
			$envelope['caused_by_workflow_id'] = $cause->workflow_id;
			// The schema caps the depth at 8; beyond the limit of R-7.13 the event is stored and starts nothing.
			$envelope['chain_depth'] = min( 8, $cause->depth + 1 );
		}
		return $envelope;
	}
}
