<?php
/**
 * Event store in the database of the current site ($wpdb->prefix): tables {prefix}workflow_captain_events and
 * {prefix}workflow_captain_causes. A site sees only its own tables (R-12.6, C-16).
 *
 * The SQL avoids MySQL-only syntax (no ON DUPLICATE KEY, no INSERT IGNORE) so tests/run.php can run it on SQLite.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Store;

use WorkflowCaptain\Consumer\Causation;

defined( 'ABSPATH' ) || exit;

final class WpdbEventStore implements EventStore {

	/** @var \wpdb */
	private $db;

	public function __construct( $db ) {
		$this->db = $db;
	}

	public static function events_table( $db ): string {
		return $db->prefix . 'workflow_captain_events';
	}

	public static function causes_table( $db ): string {
		return $db->prefix . 'workflow_captain_causes';
	}

	private function t(): string {
		return self::events_table( $this->db );
	}

	public function insert( array $envelope, string $state, string $now ): string {
		$row = array(
			'event_id'        => $envelope['id'],
			'blog_id'         => (int) $envelope['site']['blog_id'],
			'idempotency_key' => $envelope['idempotency_key'],
			'type'            => $envelope['type'],
			'product'         => $envelope['source']['product'],
			'subject_type'    => $envelope['subject']['type'],
			'subject_id'      => $envelope['subject']['id'],
			'actor_user_id'   => isset( $envelope['actor_user_id'] ) ? (int) $envelope['actor_user_id'] : null,
			'envelope'        => self::encode( $envelope ),
			'state'           => $state,
			'attempts'        => 0,
			'next_attempt_at' => $now,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$suppress = $this->db->suppress_errors( true );
		$ok       = $this->db->insert( $this->t(), $row );
		$this->db->suppress_errors( $suppress );
		if ( false !== $ok ) {
			return self::STORED;
		}
		// The unique index (blog_id, idempotency_key) rejected it, or the write failed for another reason.
		$exists = $this->db->get_var(
			$this->db->prepare( "SELECT id FROM {$this->t()} WHERE blog_id = %d AND idempotency_key = %s", $row['blog_id'], $row['idempotency_key'] )
		);
		return null !== $exists ? self::DUPLICATE : self::FAILED;
	}

	public function claim_due( int $limit, string $now, string $owner, string $lease_until ): array {
		$ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM {$this->t()}
				WHERE ( state IN ('received','retry_wait') AND next_attempt_at <= %s )
				   OR ( state = 'processing' AND lease_until < %s )
				ORDER BY id ASC LIMIT %d",
				$now,
				$now,
				$limit
			)
		);
		$claimed = array();
		foreach ( (array) $ids as $id ) {
			$hit = $this->db->query(
				$this->db->prepare(
					"UPDATE {$this->t()} SET state = 'processing', lease_owner = %s, lease_until = %s, updated_at = %s
					WHERE id = %d AND ( ( state IN ('received','retry_wait') AND next_attempt_at <= %s ) OR ( state = 'processing' AND lease_until < %s ) )",
					$owner,
					$lease_until,
					$now,
					(int) $id,
					$now,
					$now
				)
			);
			if ( 1 === (int) $hit ) {
				$row = $this->get( (int) $id );
				if ( null !== $row ) {
					$claimed[] = $row;
				}
			}
		}
		return $claimed;
	}

	public function finish( int $id, string $owner, array $change, string $now ): bool {
		$set  = array(
			'state'       => $change['state'],
			'lease_owner' => null,
			'lease_until' => null,
			'updated_at'  => $now,
		);
		if ( array_key_exists( 'attempts', $change ) ) {
			$set['attempts'] = (int) $change['attempts'];
		}
		if ( array_key_exists( 'next_attempt_at', $change ) ) {
			$set['next_attempt_at'] = $change['next_attempt_at'] ?? $now;
		}
		if ( array_key_exists( 'last_error', $change ) ) {
			$set['last_error'] = null === $change['last_error'] ? null : substr( (string) $change['last_error'], 0, 191 );
		}
		if ( ! empty( $change['minimize'] ) ) {
			$row = $this->get( $id );
			if ( null !== $row ) {
				$set['envelope']      = self::encode( self::minimized( $row['envelope'] ) );
				$set['actor_user_id'] = null;
			}
		}
		$updated = $this->db->update( $this->t(), $set, array( 'id' => $id, 'lease_owner' => $owner, 'state' => self::PROCESSING ) );
		return 1 === (int) $updated;
	}

	public function pause_product( string $product, string $now ): int {
		return (int) $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->t()} SET state = 'paused', updated_at = %s, last_error = 'adapter_unregistered' WHERE product = %s AND state IN ('received','retry_wait')",
				$now,
				$product
			)
		);
	}

	public function requeue( int $id, string $now ): bool {
		return 1 === (int) $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->t()} SET state = 'received', attempts = 0, next_attempt_at = %s, last_error = NULL, updated_at = %s WHERE id = %d AND state IN ('dead_letter','paused')",
				$now,
				$now,
				$id
			)
		);
	}

	public function get( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->t()} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public function find( array $filter, int $limit, int $offset ): array {
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filter['state'] ) ) {
			$where[] = 'state = %s';
			$args[]  = (string) $filter['state'];
		}
		if ( ! empty( $filter['product'] ) ) {
			$where[] = 'product = %s';
			$args[]  = (string) $filter['product'];
		}
		$args[] = $limit;
		$args[] = $offset;
		$rows   = $this->db->get_results(
			$this->db->prepare( "SELECT * FROM {$this->t()} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d', $args ),
			ARRAY_A
		);
		return array_map( array( self::class, 'hydrate' ), (array) $rows );
	}

	public function count_by_state(): array {
		$counts = array_fill_keys( self::STATES, 0 );
		foreach ( (array) $this->db->get_results( "SELECT state, COUNT(*) AS n FROM {$this->t()} GROUP BY state", ARRAY_A ) as $row ) {
			$counts[ $row['state'] ] = (int) $row['n'];
		}
		return $counts;
	}

	public function count_by_product(): array {
		$out = array();
		foreach ( (array) $this->db->get_results( "SELECT product, state, COUNT(*) AS n FROM {$this->t()} GROUP BY product, state", ARRAY_A ) as $row ) {
			$out[ $row['product'] ][ $row['state'] ] = (int) $row['n'];
		}
		return $out;
	}

	public function by_actor( int $user_id, int $limit, int $offset ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( "SELECT * FROM {$this->t()} WHERE actor_user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", $user_id, $limit, $offset ),
			ARRAY_A
		);
		return array_map( array( self::class, 'hydrate' ), (array) $rows );
	}

	public function anonymize_actor( int $user_id, string $now ): int {
		$count = 0;
		do {
			$rows = $this->by_actor( $user_id, 200, 0 );
			foreach ( $rows as $row ) {
				$envelope = $row['envelope'];
				unset( $envelope['actor_user_id'] );
				$this->db->update(
					$this->t(),
					array( 'envelope' => self::encode( $envelope ), 'actor_user_id' => null, 'updated_at' => $now ),
					array( 'id' => $row['id'] )
				);
				++$count;
			}
		} while ( count( $rows ) === 200 );
		return $count;
	}

	public function anonymize_subject( string $product, string $subject_type, string $subject_id, string $data_field, string $now ): int {
		$count = 0;
		$last  = 0;
		do {
			// Checked in PHP rather than with LIKE on the JSON: portable, and erasure is rare.
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->t()} WHERE id > %d AND product = %s ORDER BY id ASC LIMIT 200",
					$last,
					$product
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $raw ) {
				$row  = self::hydrate( $raw );
				$last = $row['id'];
				$env  = $row['envelope'];
				$hit  = ( $row['subject_type'] === $subject_type && $row['subject_id'] === $subject_id )
					|| ( isset( $env['data'][ $data_field ] ) && (string) $env['data'][ $data_field ] === $subject_id );
				if ( ! $hit ) {
					continue;
				}
				$this->db->update(
					$this->t(),
					array( 'envelope' => self::encode( self::minimized( $env ) ), 'actor_user_id' => null, 'updated_at' => $now ),
					array( 'id' => $row['id'] )
				);
				++$count;
			}
		} while ( is_array( $rows ) && count( $rows ) === 200 );
		return $count;
	}

	public function delete_older( array $states, string $before ): int {
		$states = array_values( array_intersect( $states, self::STATES ) );
		if ( array() === $states ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		return (int) $this->db->query(
			$this->db->prepare( "DELETE FROM {$this->t()} WHERE state IN ($in) AND updated_at < %s", array_merge( $states, array( $before ) ) )
		);
	}

	public function remember_cause( string $subject_type, string $subject_id, Causation $cause, string $expires ): void {
		$table = self::causes_table( $this->db );
		$this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE subject_type = %s AND subject_id = %s", $subject_type, $subject_id ) );
		$this->db->insert(
			$table,
			array(
				'subject_type'   => $subject_type,
				'subject_id'     => $subject_id,
				'command_id'     => $cause->command_id,
				'workflow_id'    => $cause->workflow_id,
				'correlation_id' => $cause->correlation_id,
				'depth'          => $cause->depth,
				'expires_at'     => $expires,
			)
		);
	}

	public function find_cause( string $subject_type, string $subject_id, string $now ): ?Causation {
		$table = self::causes_table( $this->db );
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$table} WHERE subject_type = %s AND subject_id = %s AND expires_at > %s", $subject_type, $subject_id, $now ),
			ARRAY_A
		);
		return $row ? Causation::from_array( $row ) : null;
	}

	public function purge_causes( string $now ): int {
		$table = self::causes_table( $this->db );
		return (int) $this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE expires_at <= %s", $now ) );
	}

	/** Envelope without personal data: no actor, empty data (R-12.4). Still valid against the schema. */
	public static function minimized( array $envelope ): array {
		unset( $envelope['actor_user_id'] );
		$envelope['data'] = array();
		return $envelope;
	}

	public static function encode( array $envelope ): string {
		$data = $envelope['data'] ?? array();
		// An empty data must stay a JSON object, not a list.
		if ( array() === $data ) {
			$envelope['data'] = new \stdClass();
		}
		return (string) json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** @return array<string,mixed> */
	private static function hydrate( array $row ): array {
		$envelope = json_decode( (string) $row['envelope'], true );
		return array(
			'id'              => (int) $row['id'],
			'state'           => (string) $row['state'],
			'attempts'        => (int) $row['attempts'],
			'envelope'        => is_array( $envelope ) ? $envelope : array(),
			'last_error'      => isset( $row['last_error'] ) ? (string) $row['last_error'] : null,
			'product'         => (string) $row['product'],
			'type'            => (string) $row['type'],
			'subject_type'    => (string) $row['subject_type'],
			'subject_id'      => (string) $row['subject_id'],
			'actor_user_id'   => isset( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
			'next_attempt_at' => isset( $row['next_attempt_at'] ) ? (string) $row['next_attempt_at'] : null,
			'created_at'      => (string) $row['created_at'],
			'updated_at'      => (string) $row['updated_at'],
		);
	}
}
