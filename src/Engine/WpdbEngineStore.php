<?php
/**
 * Engine storage in the tables of the current site. Portable SQL (runs on SQLite in tests/run.php).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class WpdbEngineStore implements EngineStore {

	const TABLES = array( 'workflows', 'versions', 'runs', 'steps', 'tags', 'notes', 'sends' );

	/** @var \wpdb */
	private $db;

	public function __construct( $db ) {
		$this->db = $db;
	}

	public static function table( $db, string $name ): string {
		return $db->prefix . 'workflow_captain_' . $name;
	}

	private function t( string $name ): string {
		return self::table( $this->db, $name );
	}

	private static function json( array $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private static function decode( $json ): array {
		$value = json_decode( (string) $json, true );
		return is_array( $value ) ? $value : array();
	}

	// ----- Workflows ------------------------------------------------------------------------------------------

	public function create_workflow( string $name, array $graph, string $now ): int {
		$this->db->insert(
			$this->t( 'workflows' ),
			array(
				'name'       => $name,
				'status'     => self::WF_DRAFT,
				'draft'      => self::json( $graph ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return (int) $this->db->insert_id;
	}

	public function update_draft( int $id, string $name, array $graph, string $now ): bool {
		return false !== $this->db->update( $this->t( 'workflows' ), array( 'name' => $name, 'draft' => self::json( $graph ), 'updated_at' => $now ), array( 'id' => $id ) );
	}

	public function get_workflow( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->t( 'workflows' )} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::workflow_row( $row ) : null;
	}

	public function list_workflows( int $limit, int $offset ): array {
		// Anchors of runs from other sites (status "network") are not workflows of this site and are not listed.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->t( 'workflows' )} WHERE status <> %s ORDER BY id DESC LIMIT %d OFFSET %d", self::WF_NETWORK, $limit, $offset ), ARRAY_A );
		return array_map( array( self::class, 'workflow_row' ), (array) $rows );
	}

	public function count_workflows(): int {
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->t( 'workflows' )} WHERE status <> %s", self::WF_NETWORK ) );
	}

	public function set_workflow_status( int $id, string $status, string $now ): bool {
		return false !== $this->db->update( $this->t( 'workflows' ), array( 'status' => $status, 'updated_at' => $now ), array( 'id' => $id ) );
	}

	public function add_version( int $workflow_id, array $graph, string $trigger_type, int $authorized_by_user_id, string $now ): int {
		$max = (int) $this->db->get_var( $this->db->prepare( "SELECT MAX(version) FROM {$this->t( 'versions' )} WHERE workflow_id = %d", $workflow_id ) );
		$this->db->insert(
			$this->t( 'versions' ),
			array(
				'workflow_id'           => $workflow_id,
				'version'               => $max + 1,
				'graph'                 => self::json( $graph ),
				'trigger_type'          => $trigger_type,
				'authorized_by_user_id' => $authorized_by_user_id,
				'created_at'            => $now,
			)
		);
		$version_id = (int) $this->db->insert_id;
		$this->db->update( $this->t( 'workflows' ), array( 'published_version_id' => $version_id, 'status' => self::WF_ACTIVE, 'updated_at' => $now ), array( 'id' => $workflow_id ) );
		return $version_id;
	}

	public function get_version( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->t( 'versions' )} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::version_row( $row ) : null;
	}

	public function active_versions_for_trigger( string $type ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT v.* FROM {$this->t( 'versions' )} v JOIN {$this->t( 'workflows' )} w ON w.published_version_id = v.id
				WHERE w.status = %s AND v.trigger_type = %s ORDER BY v.workflow_id ASC",
				self::WF_ACTIVE,
				$type
			),
			ARRAY_A
		);
		return array_map( array( self::class, 'version_row' ), (array) $rows );
	}

	public function published_for_trigger( string $type ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT w.*, v.id AS v_id, v.workflow_id AS v_workflow_id, v.version AS v_version, v.graph AS v_graph, v.trigger_type AS v_trigger_type, v.authorized_by_user_id AS v_authorized_by_user_id, v.created_at AS v_created_at
				FROM {$this->t( 'versions' )} v JOIN {$this->t( 'workflows' )} w ON w.published_version_id = v.id
				WHERE w.status IN (%s, %s) AND v.trigger_type = %s ORDER BY w.name ASC, w.id ASC",
				self::WF_ACTIVE,
				self::WF_PAUSED,
				$type
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$version = array();
			foreach ( array( 'id', 'workflow_id', 'version', 'graph', 'trigger_type', 'authorized_by_user_id', 'created_at' ) as $key ) {
				$version[ $key ] = $row[ 'v_' . $key ];
			}
			$out[] = array( 'workflow' => self::workflow_row( $row ), 'version' => self::version_row( $version ) );
		}
		return $out;
	}

	// ----- Runs -----------------------------------------------------------------------------------------------

	public function create_run( array $run, string $now ): array {
		$existing = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->t( 'runs' )} WHERE version_id = %d AND event_key = %s", $run['version_id'], $run['event_key'] ) );
		if ( null !== $existing ) {
			return array( (int) $existing, false );
		}
		$suppress = $this->db->suppress_errors( true );
		$ok       = $this->db->insert(
			$this->t( 'runs' ),
			array(
				'workflow_id'   => (int) $run['workflow_id'],
				'version_id'    => (int) $run['version_id'],
				'event_row_id'  => (int) $run['event_row_id'],
				'event_key'     => (string) $run['event_key'],
				'context'       => self::json( $run['context'] ),
				'actor_user_id' => $run['actor_user_id'] ?? null,
				'serial_key'    => $run['serial_key'] ?? null,
				'network'       => isset( $run['network'] ) && is_array( $run['network'] ) ? self::json( $run['network'] ) : null,
				'state'         => self::RUN_RUNNING,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		$this->db->suppress_errors( $suppress );
		if ( false === $ok ) {
			// Lost a race against another worker: the unique index (version_id, event_key) kept one run.
			$existing = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->t( 'runs' )} WHERE version_id = %d AND event_key = %s", $run['version_id'], $run['event_key'] ) );
			if ( null === $existing ) {
				throw new \WorkflowCaptain\Consumer\TransientFailure( 'run_insert_failed' );
			}
			return array( (int) $existing, false );
		}
		return array( (int) $this->db->insert_id, true );
	}

	public function ensure_anchor( string $origin, string $name, string $now ): int {
		$origin = substr( $origin, 0, 64 );
		$found  = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->t( 'workflows' )} WHERE status = %s AND draft = %s", self::WF_NETWORK, self::json( array( 'origin' => $origin ) ) ) );
		if ( null !== $found ) {
			$this->db->update( $this->t( 'workflows' ), array( 'name' => substr( $name, 0, 191 ) ), array( 'id' => (int) $found ) );
			return (int) $found;
		}
		$this->db->insert(
			$this->t( 'workflows' ),
			array(
				'name'       => substr( $name, 0, 191 ),
				'status'     => self::WF_NETWORK,
				'draft'      => self::json( array( 'origin' => $origin ) ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return (int) $this->db->insert_id;
	}

	public function foreign_run( string $event_key ): ?array {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->t( 'runs' )} WHERE version_id = 0 AND event_key = %s", $event_key ), ARRAY_A );
		return $row ? self::run_row( $row ) : null;
	}

	public function get_run( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->t( 'runs' )} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::run_row( $row ) : null;
	}

	public function older_open_run( int $workflow_id, int $run_id, string $serial_key ): bool {
		return null !== $this->db->get_var(
			$this->db->prepare(
				"SELECT id FROM {$this->t( 'runs' )} WHERE workflow_id = %d AND serial_key = %s AND id < %d AND state IN ('running','action_required','paused','waiting') LIMIT 1",
				$workflow_id,
				$serial_key,
				$run_id
			)
		);
	}

	public function due_steps( int $limit, string $now ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT s.id, s.run_id, r.workflow_id, r.serial_key FROM {$this->t( 'steps' )} s JOIN {$this->t( 'runs' )} r ON r.id = s.run_id
				WHERE ( ( s.state IN ('queued','retry_wait','waiting') AND s.due_at <= %s ) OR ( s.state = 'running' AND s.lease_until < %s ) )
				ORDER BY s.due_at ASC, s.id ASC LIMIT %d",
				$now,
				$now,
				$limit
			),
			ARRAY_A
		);
		return array_map(
			static fn( $r ) => array( 'id' => (int) $r['id'], 'run_id' => (int) $r['run_id'], 'workflow_id' => (int) $r['workflow_id'], 'serial_key' => isset( $r['serial_key'] ) ? (string) $r['serial_key'] : null ),
			(array) $rows
		);
	}

	public function running_counts( string $now ): array {
		$rows  = $this->db->get_results(
			$this->db->prepare(
				"SELECT r.workflow_id, COUNT(*) AS n FROM {$this->t( 'steps' )} s JOIN {$this->t( 'runs' )} r ON r.id = s.run_id WHERE s.state = 'running' AND s.lease_until >= %s GROUP BY r.workflow_id",
				$now
			),
			ARRAY_A
		);
		$by    = array();
		$total = 0;
		foreach ( (array) $rows as $row ) {
			$by[ (int) $row['workflow_id'] ] = (int) $row['n'];
			$total                          += (int) $row['n'];
		}
		return array( 'total' => $total, 'by_workflow' => $by );
	}

	public function claim_step( int $id, string $now, string $owner, string $lease_until ): ?array {
		$hit = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->t( 'steps' )} SET state = 'running', lease_owner = %s, lease_until = %s, updated_at = %s
				WHERE id = %d AND ( ( state IN ('queued','retry_wait','waiting') AND due_at <= %s ) OR ( state = 'running' AND lease_until < %s ) )",
				$owner,
				$lease_until,
				$now,
				$id,
				$now,
				$now
			)
		);
		return 1 === (int) $hit ? $this->get_step( $id ) : null;
	}

	public function consistency(): array {
		$w   = $this->t( 'workflows' );
		$v   = $this->t( 'versions' );
		$r   = $this->t( 'runs' );
		$st  = $this->t( 'steps' );
		$ids = fn( string $sql ): array => array_map( 'intval', (array) $this->db->get_col( $sql . ' LIMIT 100' ) );
		return array(
			// Runs from another site of the network have no version here (version_id 0, ForeignRuns).
			'runs_without_version'      => $ids( "SELECT r.id FROM {$r} r LEFT JOIN {$v} v ON v.id = r.version_id WHERE v.id IS NULL AND r.network IS NULL" ),
			'steps_without_run'         => $ids( "SELECT s.id FROM {$st} s LEFT JOIN {$r} r ON r.id = s.run_id WHERE r.id IS NULL" ),
			'versions_without_workflow' => $ids( "SELECT v.id FROM {$v} v LEFT JOIN {$w} w ON w.id = v.workflow_id WHERE w.id IS NULL" ),
			'active_without_version'    => $ids( "SELECT w.id FROM {$w} w LEFT JOIN {$v} v ON v.id = w.published_version_id WHERE w.status = 'active' AND v.id IS NULL" ),
			'running_without_open_step' => $ids( "SELECT r.id FROM {$r} r WHERE r.state = 'running' AND NOT EXISTS ( SELECT 1 FROM {$st} s WHERE s.run_id = r.id AND s.state IN ('queued','retry_wait','running') )" ),
		);
	}

	public function stuck( string $before ): array {
		$expired = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->t( 'steps' )} WHERE state = 'running' AND lease_until < %s", $before ) );
		$runs    = (array) $this->db->get_col(
			$this->db->prepare(
				"SELECT r.id FROM {$this->t( 'runs' )} r WHERE r.state = 'running' AND r.updated_at < %s
				AND NOT EXISTS ( SELECT 1 FROM {$this->t( 'steps' )} s WHERE s.run_id = r.id AND s.state IN ('queued','retry_wait','running') ) LIMIT 100",
				$before
			)
		);
		return array( 'expired_leases' => $expired, 'runs_without_steps' => array_map( 'intval', $runs ) );
	}

	public function extend_lease( int $id, string $owner, string $lease_until, string $now ): bool {
		return 1 === (int) $this->db->update( $this->t( 'steps' ), array( 'lease_until' => $lease_until, 'updated_at' => $now ), array( 'id' => $id, 'lease_owner' => $owner, 'state' => self::STEP_RUNNING ) );
	}

	public function set_run_state( int $id, string $state, ?string $last_error, string $now ): bool {
		return false !== $this->db->update( $this->t( 'runs' ), array( 'state' => $state, 'last_error' => $last_error, 'updated_at' => $now ), array( 'id' => $id ) );
	}

	public function find_runs( array $filter, int $limit, int $offset ): array {
		list( $where, $args ) = self::run_where( $filter );
		$args[]               = $limit;
		$args[]               = $offset;
		$rows                 = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->t( 'runs' )} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A );
		return array_map( array( self::class, 'run_row' ), (array) $rows );
	}

	public function count_runs( array $filter ): int {
		list( $where, $args ) = self::run_where( $filter );
		$sql                  = "SELECT COUNT(*) FROM {$this->t( 'runs' )} WHERE {$where}";
		return (int) $this->db->get_var( array() === $args ? $sql : $this->db->prepare( $sql, $args ) );
	}

	public function workflow_run_summary( array $workflow_ids, string $since ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $workflow_ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$runs = $this->t( 'runs' );
		$out  = array();
		$last = $this->db->get_results( $this->db->prepare( "SELECT r.workflow_id, r.id, r.state, r.created_at FROM {$runs} r JOIN (SELECT workflow_id, MAX(id) AS m FROM {$runs} WHERE workflow_id IN ({$in}) GROUP BY workflow_id) x ON r.id = x.m", $ids ), ARRAY_A );
		foreach ( (array) $last as $row ) {
			$out[ (int) $row['workflow_id'] ] = array( 'last_run_id' => (int) $row['id'], 'last_state' => (string) $row['state'], 'last_at' => (string) $row['created_at'], 'errors' => 0 );
		}
		$args   = array_merge( $ids, array( self::RUN_FAILED, self::RUN_ACTION_REQUIRED, $since ) );
		$errors = $this->db->get_results( $this->db->prepare( "SELECT workflow_id, COUNT(*) AS n FROM {$runs} WHERE workflow_id IN ({$in}) AND state IN (%s, %s) AND created_at >= %s GROUP BY workflow_id", $args ), ARRAY_A );
		foreach ( (array) $errors as $row ) {
			if ( isset( $out[ (int) $row['workflow_id'] ] ) ) {
				$out[ (int) $row['workflow_id'] ]['errors'] = (int) $row['n'];
			}
		}
		return $out;
	}

	/**
	 * WHERE clause of find_runs() and count_runs(). A correlation ID that is no UUID matches nothing.
	 *
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function run_where( array $filter ): array {
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filter['workflow_id'] ) ) {
			$where[] = 'workflow_id = %d';
			$args[]  = (int) $filter['workflow_id'];
		}
		if ( ! empty( $filter['state'] ) ) {
			$where[] = 'state = %s';
			$args[]  = (string) $filter['state'];
		}
		// Time range of the run list (docs/UI-ENTWUERFE.md, section 1); MySQL datetime strings compare in order.
		foreach ( array( 'since' => '>=', 'until' => '<=' ) as $key => $cmp ) {
			if ( isset( $filter[ $key ] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $filter[ $key ] ) ) {
				$where[] = 'created_at ' . $cmp . ' %s';
				$args[]  = (string) $filter[ $key ];
			}
		}
		if ( isset( $filter['correlation_id'] ) && '' !== (string) $filter['correlation_id'] ) {
			if ( preg_match( \WorkflowCaptain\Envelope\Validator::UUID4, (string) $filter['correlation_id'] ) ) {
				// The context (the event snapshot) is stored as JSON by json(); a UUID holds no LIKE wildcards.
				$where[] = 'context LIKE %s';
				$args[]  = '%"correlation_id":"' . $filter['correlation_id'] . '"%';
			} else {
				$where[] = '1=0';
			}
		}
		return array( implode( ' AND ', $where ), $args );
	}

	// ----- Steps ----------------------------------------------------------------------------------------------

	public function ensure_step( int $run_id, string $node_id, string $key, string $due_at, string $now ): int {
		$existing = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->t( 'steps' )} WHERE step_key = %s", $key ) );
		if ( null !== $existing ) {
			return (int) $existing;
		}
		$suppress = $this->db->suppress_errors( true );
		$ok       = $this->db->insert(
			$this->t( 'steps' ),
			array(
				'run_id'     => $run_id,
				'node_id'    => $node_id,
				'step_key'   => $key,
				'state'      => self::STEP_QUEUED,
				'attempts'   => 0,
				'effect'     => self::EFFECT_NONE,
				'due_at'     => $due_at,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$this->db->suppress_errors( $suppress );
		$id = false === $ok
			? $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->t( 'steps' )} WHERE step_key = %s", $key ) )
			: $this->db->insert_id;
		if ( null === $id ) {
			throw new \WorkflowCaptain\Consumer\TransientFailure( 'step_insert_failed' );
		}
		return (int) $id;
	}

	public function claim_steps( int $limit, string $now, string $owner, string $lease_until ): array {
		$cond = "( ( state IN ('queued','retry_wait','waiting') AND due_at <= %s ) OR ( state = 'running' AND lease_until < %s ) )";
		$ids  = $this->db->get_col( $this->db->prepare( "SELECT id FROM {$this->t( 'steps' )} WHERE {$cond} ORDER BY due_at ASC, id ASC LIMIT %d", $now, $now, $limit ) );
		$out  = array();
		foreach ( (array) $ids as $id ) {
			$hit = $this->db->query(
				$this->db->prepare(
					"UPDATE {$this->t( 'steps' )} SET state = 'running', lease_owner = %s, lease_until = %s, updated_at = %s WHERE id = %d AND {$cond}",
					$owner,
					$lease_until,
					$now,
					(int) $id,
					$now,
					$now
				)
			);
			if ( 1 === (int) $hit ) {
				$step = $this->get_step( (int) $id );
				if ( null !== $step ) {
					$out[] = $step;
				}
			}
		}
		return $out;
	}

	public function finish_step( int $id, string $owner, array $change, string $now ): bool {
		$set = array(
			'state'       => $change['state'],
			'lease_owner' => null,
			'lease_until' => null,
			'updated_at'  => $now,
		);
		foreach ( array( 'attempts', 'due_at', 'effect' ) as $key ) {
			if ( array_key_exists( $key, $change ) ) {
				$set[ $key ] = $change[ $key ];
			}
		}
		if ( array_key_exists( 'last_error', $change ) ) {
			$set['last_error'] = null === $change['last_error'] ? null : substr( (string) $change['last_error'], 0, 191 );
		}
		if ( array_key_exists( 'result', $change ) ) {
			$set['result'] = null === $change['result'] ? null : self::json( $change['result'] );
		}
		return 1 === (int) $this->db->update( $this->t( 'steps' ), $set, array( 'id' => $id, 'lease_owner' => $owner, 'state' => self::STEP_RUNNING ) );
	}

	public function set_effect( int $id, string $owner, string $effect, string $now ): bool {
		return 1 === (int) $this->db->update( $this->t( 'steps' ), array( 'effect' => $effect, 'updated_at' => $now ), array( 'id' => $id, 'lease_owner' => $owner, 'state' => self::STEP_RUNNING ) );
	}

	public function steps_for_run( int $run_id ): array {
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->t( 'steps' )} WHERE run_id = %d ORDER BY id ASC", $run_id ), ARRAY_A );
		return array_map( array( self::class, 'step_row' ), (array) $rows );
	}

	public function get_step( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->t( 'steps' )} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::step_row( $row ) : null;
	}

	public function requeue_step( int $id, bool $confirmed, string $now ): bool {
		$set = "state = 'queued', attempts = 0, due_at = %s, last_error = NULL, updated_at = %s";
		if ( $confirmed ) {
			$set .= ", effect = 'none'";
		}
		return 1 === (int) $this->db->query(
			$this->db->prepare( "UPDATE {$this->t( 'steps' )} SET {$set} WHERE id = %d AND state IN ('action_required','paused')", $now, $now, $id )
		);
	}

	public function wake_step( int $id, string $now ): bool {
		$hit = $this->db->query( $this->db->prepare( "UPDATE {$this->t( 'steps' )} SET state = 'queued', due_at = %s, updated_at = %s WHERE id = %d AND state = 'waiting'", $now, $now, $id ) );
		if ( 1 !== (int) $hit ) {
			return false;
		}
		$run_id = (int) $this->db->get_var( $this->db->prepare( "SELECT run_id FROM {$this->t( 'steps' )} WHERE id = %d", $id ) );
		$this->db->query( $this->db->prepare( "UPDATE {$this->t( 'runs' )} SET state = 'running', last_error = NULL, updated_at = %s WHERE id = %d AND state = 'waiting'", $now, $run_id ) );
		return true;
	}

	public function cancel_steps( int $run_id, string $now ): int {
		return (int) $this->db->query(
			$this->db->prepare( "UPDATE {$this->t( 'steps' )} SET state = 'cancelled', lease_owner = NULL, lease_until = NULL, updated_at = %s WHERE run_id = %d AND state IN ('queued','retry_wait','action_required','paused','waiting')", $now, $run_id )
		);
	}

	// ----- Tags and notes (commands that write only in the consumer, 8.1) --------------------------------------

	public function set_tag( string $product, string $subject_type, string $subject_id, string $tag, int $workflow_id, string $now ): void {
		$exists = $this->db->get_var(
			$this->db->prepare( "SELECT id FROM {$this->t( 'tags' )} WHERE product = %s AND subject_type = %s AND subject_id = %s AND tag = %s", $product, $subject_type, $subject_id, $tag )
		);
		if ( null === $exists ) {
			$suppress = $this->db->suppress_errors( true );
			$this->db->insert( $this->t( 'tags' ), array( 'product' => $product, 'subject_type' => $subject_type, 'subject_id' => $subject_id, 'tag' => $tag, 'workflow_id' => $workflow_id, 'created_at' => $now ) );
			$this->db->suppress_errors( $suppress );
		}
	}

	public function remove_tag( string $product, string $subject_type, string $subject_id, string $tag ): void {
		$this->db->query( $this->db->prepare( "DELETE FROM {$this->t( 'tags' )} WHERE product = %s AND subject_type = %s AND subject_id = %s AND tag = %s", $product, $subject_type, $subject_id, $tag ) );
	}

	public function tags_for( string $product, string $subject_type, string $subject_id ): array {
		return array_map(
			'strval',
			(array) $this->db->get_col( $this->db->prepare( "SELECT tag FROM {$this->t( 'tags' )} WHERE product = %s AND subject_type = %s AND subject_id = %s ORDER BY tag ASC", $product, $subject_type, $subject_id ) )
		);
	}

	public function add_note( string $key, string $product, string $subject_type, string $subject_id, string $note, int $workflow_id, int $run_id, string $now ): void {
		if ( null !== $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->t( 'notes' )} WHERE note_key = %s", $key ) ) ) {
			return;
		}
		$suppress = $this->db->suppress_errors( true );
		$this->db->insert(
			$this->t( 'notes' ),
			array( 'note_key' => $key, 'product' => $product, 'subject_type' => $subject_type, 'subject_id' => $subject_id, 'note' => $note, 'workflow_id' => $workflow_id, 'run_id' => $run_id, 'created_at' => $now )
		);
		$this->db->suppress_errors( $suppress );
	}

	public function notes_for( string $product, string $subject_type, string $subject_id ): array {
		return (array) $this->db->get_results(
			$this->db->prepare( "SELECT * FROM {$this->t( 'notes' )} WHERE product = %s AND subject_type = %s AND subject_id = %s ORDER BY id ASC", $product, $subject_type, $subject_id ),
			ARRAY_A
		);
	}

	public function delete_tags_notes_older( string $before ): int {
		$tags  = (int) $this->db->query( $this->db->prepare( "DELETE FROM {$this->t( 'tags' )} WHERE created_at < %s", $before ) );
		$notes = (int) $this->db->query( $this->db->prepare( "DELETE FROM {$this->t( 'notes' )} WHERE created_at < %s", $before ) );
		return $tags + $notes;
	}

	public function tags_notes_of_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array( 'tags' => array(), 'notes' => array() );
		}
		return array(
			'tags'  => (array) $this->db->get_results( $this->db->prepare( "SELECT product, tag, created_at FROM {$this->t( 'tags' )} WHERE subject_type = %s AND subject_id = %s ORDER BY id ASC", 'user', (string) $user_id ), ARRAY_A ),
			'notes' => (array) $this->db->get_results( $this->db->prepare( "SELECT id, product, note, created_at FROM {$this->t( 'notes' )} WHERE subject_type = %s AND subject_id = %s ORDER BY id ASC", 'user', (string) $user_id ), ARRAY_A ),
		);
	}

	public function erase_tags_notes_of_user( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		$tags  = (int) $this->db->query( $this->db->prepare( "DELETE FROM {$this->t( 'tags' )} WHERE subject_type = %s AND subject_id = %s", 'user', (string) $user_id ) );
		$notes = (int) $this->db->query( $this->db->prepare( "DELETE FROM {$this->t( 'notes' )} WHERE subject_type = %s AND subject_id = %s", 'user', (string) $user_id ) );
		return $tags + $notes;
	}

	// ----- Privacy --------------------------------------------------------------------------------------------

	public function runs_by_actor( int $user_id, int $limit, int $offset ): array {
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->t( 'runs' )} WHERE actor_user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", $user_id, $limit, $offset ), ARRAY_A );
		return array_map( array( self::class, 'run_row' ), (array) $rows );
	}

	public function anonymize_runs( int $user_id, string $now ): int {
		$count = 0;
		do {
			$rows = $this->runs_by_actor( $user_id, 200, 0 );
			foreach ( $rows as $run ) {
				$context = $run['context'];
				unset( $context['actor_user_id'] );
				$this->db->update( $this->t( 'runs' ), array( 'context' => self::json( $context ), 'actor_user_id' => null, 'updated_at' => $now ), array( 'id' => $run['id'] ) );
				++$count;
			}
		} while ( count( $rows ) === 200 );
		return $count;
	}

	public function delete_runs_older( array $states, string $before ): int {
		$states = array_values( array_intersect( $states, array( self::RUN_SUCCEEDED, self::RUN_STOPPED, self::RUN_FAILED, self::RUN_CANCELLED ) ) );
		if ( array() === $states ) {
			return 0;
		}
		$in  = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		$ids = (array) $this->db->get_col( $this->db->prepare( "SELECT id FROM {$this->t( 'runs' )} WHERE state IN ($in) AND updated_at < %s", array_merge( $states, array( $before ) ) ) );
		foreach ( array_chunk( array_map( 'intval', $ids ), 200 ) as $chunk ) {
			$list = implode( ',', $chunk );
			$this->db->query( "DELETE FROM {$this->t( 'steps' )} WHERE run_id IN ($list)" );
			$this->db->query( "DELETE FROM {$this->t( 'runs' )} WHERE id IN ($list)" );
		}
		return count( $ids );
	}

	// ----- Rows -----------------------------------------------------------------------------------------------

	private static function workflow_row( array $row ): array {
		return array(
			'id'                   => (int) $row['id'],
			'name'                 => (string) $row['name'],
			'status'               => (string) $row['status'],
			'draft'                => self::decode( $row['draft'] ),
			'published_version_id' => isset( $row['published_version_id'] ) ? (int) $row['published_version_id'] : null,
			'created_at'           => (string) $row['created_at'],
			'updated_at'           => (string) $row['updated_at'],
		);
	}

	private static function version_row( array $row ): array {
		return array(
			'id'                    => (int) $row['id'],
			'workflow_id'           => (int) $row['workflow_id'],
			'version'               => (int) $row['version'],
			'graph'                 => self::decode( $row['graph'] ),
			'trigger_type'          => (string) $row['trigger_type'],
			'authorized_by_user_id' => (int) $row['authorized_by_user_id'],
			'created_at'            => (string) $row['created_at'],
		);
	}

	private static function run_row( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'workflow_id'   => (int) $row['workflow_id'],
			'version_id'    => (int) $row['version_id'],
			'event_row_id'  => (int) $row['event_row_id'],
			'event_key'     => (string) $row['event_key'],
			'context'       => self::decode( $row['context'] ),
			'actor_user_id' => isset( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
			'serial_key'    => isset( $row['serial_key'] ) ? (string) $row['serial_key'] : null,
			'network'       => isset( $row['network'] ) && '' !== (string) $row['network'] ? self::decode( $row['network'] ) : null,
			'state'         => (string) $row['state'],
			'last_error'    => isset( $row['last_error'] ) ? (string) $row['last_error'] : null,
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
		);
	}

	private static function step_row( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'run_id'     => (int) $row['run_id'],
			'node_id'    => (string) $row['node_id'],
			'step_key'   => (string) $row['step_key'],
			'state'      => (string) $row['state'],
			'attempts'   => (int) $row['attempts'],
			'effect'     => (string) $row['effect'],
			'due_at'     => (string) $row['due_at'],
			'result'     => isset( $row['result'] ) ? self::decode( $row['result'] ) : null,
			'last_error' => isset( $row['last_error'] ) ? (string) $row['last_error'] : null,
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		);
	}

	// ----- Daily limit per recipient (SET-WC-08) ----------------------------------------------------------------

	public function take_send_slot( string $day, int $user_id, int $max ): bool {
		if ( $max <= 0 || $user_id <= 0 ) {
			return $max <= 0;
		}
		// The row may exist already (primary key day + user): the failed insert is expected and silent.
		$suppress = $this->db->suppress_errors( true );
		$this->db->insert( $this->t( 'sends' ), array( 'day' => $day, 'user_id' => $user_id, 'sent' => 0 ) );
		$this->db->suppress_errors( $suppress );
		// One statement decides: two workers cannot both take the last slot.
		return 1 === (int) $this->db->query( $this->db->prepare( "UPDATE {$this->t( 'sends' )} SET sent = sent + 1 WHERE day = %s AND user_id = %d AND sent < %d", $day, $user_id, $max ) );
	}

	public function release_send_slot( string $day, int $user_id ): void {
		$this->db->query( $this->db->prepare( "UPDATE {$this->t( 'sends' )} SET sent = sent - 1 WHERE day = %s AND user_id = %d AND sent > 0", $day, $user_id ) );
	}

	public function purge_send_counts( string $day ): int {
		return (int) $this->db->query( $this->db->prepare( "DELETE FROM {$this->t( 'sends' )} WHERE day < %s", $day ) );
	}

	public function erase_send_counts( int $user_id ): int {
		return (int) $this->db->query( $this->db->prepare( "DELETE FROM {$this->t( 'sends' )} WHERE user_id = %d", $user_id ) );
	}
}
