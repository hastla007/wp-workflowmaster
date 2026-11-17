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
	 * Export of a run for support ("Lauf exportieren", docs/UI-ENTWUERFE.md, section 5): the run's own fields, the
	 * redacted envelope and the step timeline. Left out on purpose: actor_user_id, the serialisation key (it can be a
	 * user ID), the network origin and the event row. Step results are redacted (Engine::redact) and every string is
	 * scrubbed of decrypted secrets.
	 *
	 * @param array<string,mixed>                $run      From EngineStore::get_run().
	 * @param array<int,array<string,mixed>>     $timeline From WorkflowsPage::timeline().
	 * @param array<string,array<string,string>> $allowed  Allowed data fields per event type.
	 * @return array<string,mixed>
	 */
	public static function run_export( array $run, array $timeline, ?string $workflow_name, ?int $version, array $allowed, string $plugin_version, string $now ): array {
		$view  = self::redacted( (array) ( $run['context'] ?? array() ), $allowed );
		$steps = array();
		foreach ( $timeline as $row ) {
			$steps[] = array(
				'node'       => (string) $row['node'],
				'state'      => (string) $row['state'],
				'started'    => $row['started'],
				'wait_until' => $row['wait_until'],
				'ended'      => $row['ended'],
				'attempts'   => (int) $row['attempts'],
				'result'     => self::scrub( Engine::redact( (array) $row['result'] ) ),
				'error'      => null === $row['error'] ? null : Redact::scrub( (string) $row['error'] ),
			);
		}
		return array(
			'format'             => 'workflow-captain-run-export',
			'format_version'     => 1,
			'exported_at'        => $now,
			'plugin'             => $plugin_version,
			'times'              => 'UTC',
			'run'                => array(
				'id'            => (int) $run['id'],
				'workflow_id'   => (int) $run['workflow_id'],
				'workflow_name' => null === $workflow_name ? null : Redact::scrub( $workflow_name ),
				'version'       => $version,
				'state'         => (string) $run['state'],
				'reason'        => null === ( $run['last_error'] ?? null ) || '' === (string) $run['last_error'] ? null : Redact::scrub( (string) $run['last_error'] ),
				'created_at'    => (string) $run['created_at'],
				'updated_at'    => (string) $run['updated_at'],
			),
			'event'              => array() === $view['envelope']['data'] ? array_merge( $view['envelope'], array( 'data' => new \stdClass() ) ) : $view['envelope'],
			'hidden_data_fields' => $view['hidden'],
			'steps'              => $steps,
		);
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
