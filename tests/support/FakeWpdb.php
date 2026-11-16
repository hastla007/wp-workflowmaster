<?php
/**
 * Minimal stand-in for WordPress' $wpdb on SQLite (PDO), so tests run the real SQL of WpdbEventStore.
 *
 * Covers what WpdbEventStore uses: prepare, query, get_var, get_col, get_row, get_results, insert, update, esc_like,
 * suppress_errors. Several instances with different prefixes on one PDO model sites of a multisite (C-16).
 *
 * @package WorkflowCaptain
 */

final class FakeWpdb {

	public string $prefix;
	public string $last_error = '';
	public int $insert_id = 0;
	private PDO $pdo;

	public function __construct( PDO $pdo, string $prefix = 'wp_' ) {
		$this->pdo    = $pdo;
		$this->prefix = $prefix;
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}

	public static function memory(): PDO {
		return new PDO( 'sqlite::memory:' );
	}

	/** Creates the tables of WpdbEventStore for this prefix (mirrors Install::create_tables). */
	public function create_tables(): void {
		$p = $this->prefix;
		$this->pdo->exec(
			"CREATE TABLE {$p}workflow_captain_events (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				event_id TEXT NOT NULL, blog_id INTEGER NOT NULL, idempotency_key TEXT NOT NULL,
				type TEXT NOT NULL, product TEXT NOT NULL, subject_type TEXT NOT NULL, subject_id TEXT NOT NULL,
				actor_user_id INTEGER NULL, envelope TEXT NOT NULL, state TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
				next_attempt_at TEXT NOT NULL, lease_owner TEXT NULL, lease_until TEXT NULL, last_error TEXT NULL,
				created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
				UNIQUE (blog_id, idempotency_key)
			)"
		);
		$this->pdo->exec(
			"CREATE TABLE {$p}workflow_captain_causes (
				id INTEGER PRIMARY KEY AUTOINCREMENT, subject_type TEXT NOT NULL, subject_id TEXT NOT NULL,
				command_id TEXT NOT NULL, workflow_id INTEGER NOT NULL, correlation_id TEXT NOT NULL,
				depth INTEGER NOT NULL, expires_at TEXT NOT NULL
			)"
		);
	}

	/** Creates the engine tables for this prefix (mirrors Install::create_tables). */
	public function create_engine_tables(): void {
		$p = $this->prefix . 'workflow_captain_';
		$this->pdo->exec( "CREATE TABLE {$p}workflows ( id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, status TEXT NOT NULL, draft TEXT NOT NULL, published_version_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL )" );
		$this->pdo->exec( "CREATE TABLE {$p}versions ( id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_id INTEGER NOT NULL, version INTEGER NOT NULL, graph TEXT NOT NULL, trigger_type TEXT NOT NULL, authorized_by_user_id INTEGER NOT NULL, created_at TEXT NOT NULL, UNIQUE (workflow_id, version) )" );
		$this->pdo->exec( "CREATE TABLE {$p}runs ( id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_id INTEGER NOT NULL, version_id INTEGER NOT NULL, event_row_id INTEGER NOT NULL, event_key TEXT NOT NULL, context TEXT NOT NULL, actor_user_id INTEGER NULL, serial_key TEXT NULL, network TEXT NULL, state TEXT NOT NULL, last_error TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (version_id, event_key) )" );
		$this->pdo->exec( "CREATE TABLE {$p}steps ( id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER NOT NULL, node_id TEXT NOT NULL, step_key TEXT NOT NULL UNIQUE, state TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, effect TEXT NOT NULL DEFAULT 'none', due_at TEXT NOT NULL, lease_owner TEXT NULL, lease_until TEXT NULL, result TEXT NULL, last_error TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL )" );
		$this->pdo->exec( "CREATE TABLE {$p}tags ( id INTEGER PRIMARY KEY AUTOINCREMENT, product TEXT NOT NULL, subject_type TEXT NOT NULL, subject_id TEXT NOT NULL, tag TEXT NOT NULL, workflow_id INTEGER NOT NULL, created_at TEXT NOT NULL, UNIQUE (product, subject_type, subject_id, tag) )" );
		$this->pdo->exec( "CREATE TABLE {$p}notes ( id INTEGER PRIMARY KEY AUTOINCREMENT, note_key TEXT NOT NULL UNIQUE, product TEXT NOT NULL, subject_type TEXT NOT NULL, subject_id TEXT NOT NULL, note TEXT NOT NULL, workflow_id INTEGER NOT NULL, run_id INTEGER NOT NULL, created_at TEXT NOT NULL )" );
	}

	public function suppress_errors( $suppress = true ) {
		return true;
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%%|%d|%s|%f/',
			function ( $m ) use ( &$i, $args ) {
				if ( '%%' === $m[0] ) {
					return '%';
				}
				$value = $args[ $i++ ] ?? null;
				if ( '%d' === $m[0] ) {
					return (string) (int) $value;
				}
				if ( '%f' === $m[0] ) {
					return (string) (float) $value;
				}
				return $this->pdo->quote( (string) $value );
			},
			$query
		);
	}

	public function query( $sql ) {
		try {
			$this->last_error = '';
			if ( preg_match( '/^\s*(UPDATE|DELETE|INSERT)/i', $sql ) ) {
				return $this->pdo->exec( $sql );
			}
			$this->pdo->query( $sql );
			return true;
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	public function get_results( $sql, $output = 'OBJECT' ) {
		$rows = $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC );
		return 'ARRAY_A' === $output ? $rows : array_map( static fn( $r ) => (object) $r, $rows );
	}

	public function get_row( $sql, $output = 'OBJECT' ) {
		$rows = $this->get_results( $sql, $output );
		return $rows[0] ?? null;
	}

	public function get_var( $sql ) {
		$row = $this->pdo->query( $sql )->fetch( PDO::FETCH_NUM );
		return false === $row ? null : $row[0];
	}

	public function get_col( $sql ) {
		return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_COLUMN, 0 );
	}

	public function insert( $table, array $data ) {
		$cols = array_keys( $data );
		$sql  = 'INSERT INTO ' . $table . ' (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_map( fn( $v ) => null === $v ? 'NULL' : $this->pdo->quote( (string) $v ), $data ) ) . ')';
		$res  = $this->query( $sql );
		if ( false === $res ) {
			return false;
		}
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	public function update( $table, array $data, array $where ) {
		$set = array();
		foreach ( $data as $k => $v ) {
			$set[] = $k . ' = ' . ( null === $v ? 'NULL' : $this->pdo->quote( (string) $v ) );
		}
		$cond = array();
		foreach ( $where as $k => $v ) {
			$cond[] = $k . ' = ' . $this->pdo->quote( (string) $v );
		}
		return $this->query( 'UPDATE ' . $table . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $cond ) );
	}
}
