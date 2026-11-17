<?php
/**
 * Versioned upgrade of the schema and the stored data (WF-CORE-07), safe to repeat and to resume.
 *
 * - One upgrade at a time per site: a lock option taken with add_option(), which fails while another request holds it.
 * - An upgrade that stopped half-way (fatal error, timeout, deployment) leaves the lock behind; after STALE_SECONDS the
 *   next admin, cron or WP-CLI request takes it over and repeats the run. Every part is safe to run twice: the tables
 *   come from dbDelta (only adds what is missing), and each data step must be idempotent.
 * - The stored version moves forward one data step at a time, so a resumed run starts after the last finished step.
 * - Nothing is ever deleted, and a stored version newer than the code (downgrade) is left alone.
 *
 * Without WordPress functions: the options and the schema routine are passed in (tests/engine-tests.php).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class Migrator {

	const LOCK_OPTION   = 'workflow_captain_upgrade_lock';
	const STALE_SECONDS = 600;

	const DONE    = 'done';
	const BUSY    = 'busy';
	const CURRENT = 'current';
	const NEWER   = 'newer';

	/**
	 * @param array{get:callable,add:callable,update:callable,delete:callable} $options Option accessors (add returns false when the option exists).
	 * @param callable                    $schema     Creates or completes the tables; must be idempotent.
	 * @param array<int,callable>         $migrations Data steps by the schema version they lead to; each must be idempotent.
	 */
	public function __construct( private array $options, private $schema, private array $migrations, private int $now ) {}

	/**
	 * Brings the site to $expected.
	 *
	 * @return array{state:string,resumed:bool,from:string,to:string,steps:array<int,int>}
	 */
	public function run( string $expected ): array {
		$stored = ( $this->options['get'] )( Install::DB_OPTION, false );
		$result = array( 'state' => self::CURRENT, 'resumed' => false, 'from' => (string) $stored, 'to' => (string) $stored, 'steps' => array() );
		$state  = Install::schema_state( $stored, $expected );
		if ( Install::SCHEMA_NEWER === $state ) {
			$result['state'] = self::NEWER;
			return $result;
		}
		if ( Install::SCHEMA_CURRENT === $state ) {
			return $result;
		}
		if ( ! ( $this->options['add'] )( self::LOCK_OPTION, (string) $this->now ) ) {
			$held = (int) ( $this->options['get'] )( self::LOCK_OPTION, 0 );
			if ( $this->now - $held < self::STALE_SECONDS ) {
				$result['state'] = self::BUSY; // Another request upgrades right now; the worker waits until it is done.
				return $result;
			}
			// The lock of an upgrade that stopped half-way: take it over and repeat the run.
			( $this->options['update'] )( self::LOCK_OPTION, (string) $this->now );
			$result['resumed'] = true;
		}
		try {
			( $this->schema )();
			// A fresh installation has no data to migrate; the data steps only run on stored data.
			if ( is_numeric( $stored ) ) {
				foreach ( self::pending_steps( (int) $stored, (int) $expected, array_keys( $this->migrations ) ) as $step ) {
					( $this->migrations[ $step ] )();
					( $this->options['update'] )( Install::DB_OPTION, (string) $step );
					$result['steps'][] = $step;
				}
			}
			( $this->options['update'] )( Install::DB_OPTION, $expected );
		} finally {
			// On an exception the next request starts again at once; after a fatal error the lock turns stale.
			( $this->options['delete'] )( self::LOCK_OPTION );
		}
		$result['state'] = self::DONE;
		$result['to']    = $expected;
		return $result;
	}

	/**
	 * Data steps still to run, in ascending order: those above the stored version up to the expected one.
	 *
	 * @param array<int,int> $available
	 * @return array<int,int>
	 */
	public static function pending_steps( int $stored, int $expected, array $available ): array {
		$steps = array_values( array_filter( array_map( 'intval', $available ), static fn( int $v ): bool => $v > $stored && $v <= $expected ) );
		sort( $steps );
		return $steps;
	}

	/**
	 * State of the lock for diagnostics: "free", "held" (an upgrade runs) or "stale" (an upgrade stopped half-way and
	 * is repeated on the next admin, cron or WP-CLI request).
	 */
	public static function lock_state( $lock, int $now ): string {
		if ( false === $lock || null === $lock || '' === $lock ) {
			return 'free';
		}
		return $now - (int) $lock < self::STALE_SECONDS ? 'held' : 'stale';
	}
}
