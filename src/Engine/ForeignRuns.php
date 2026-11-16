<?php
/**
 * Runs whose definition lives outside this site: network workflows of a multisite network (spec section 17, R-17.4 to
 * R-17.7). The core only offers the extension point; the control plane (definitions, consent, parent runs) belongs to
 * an add-on.
 *
 * - enqueue() puts a run with its first step into the queue of the CURRENT site. The caller switches to the target site
 *   (switch_to_blog() inside try/finally) only for this call; the step runs later in a worker of the target site, with
 *   the plugins, actions and rights of that site (R-17.4).
 * - The run carries the network IDs in its own column `network` next to the event snapshot, never inside it (R-17.5).
 *   actor_user_id never reaches the snapshot (R-17.3).
 * - Every run of one origin belongs to one anchor workflow of this site (EngineStore::ensure_anchor()). Events caused
 *   by its steps carry the event's correlation and chain depth from the source site, so the chain counts across sites
 *   (R-17.7); their caused_by_workflow_id is the anchor, which no local workflow has.
 * - The worker asks the resolver for the graph of the run, then for every action step: the action must implement
 *   NetworkSafe, the gate must agree, and NetworkSafe::network_check() must agree. Otherwise the step pauses with the
 *   reason code (R-17.2, R-17.8, R-17.9). Without a resolver (add-on inactive) every such step pauses.
 * - The observer hears every state of such a run, so the add-on can keep its child step up to date (R-17.6).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class ForeignRuns {

	/** Keys of the wrapper next to the event snapshot (R-17.5, plus the version number of the definition). */
	const WRAPPER_KEYS = array( 'network_id', 'source_blog_id', 'workflow_id', 'version', 'run_id' );

	/** Reason when nobody resolves the run (add-on inactive): the step pauses, nothing is lost. */
	const UNAVAILABLE = 'foreign_unavailable';

	/** @var (callable(array,array):?array)|null */
	private $resolver = null;

	/** @var (callable(array,array,array):?string)|null */
	private $gate = null;

	/** @var (callable(array,string,?string):void)|null */
	private $observer = null;

	/**
	 * @param callable(array $network, array $run):?array $resolver Returns the definition for the current site:
	 *        array{graph:array,version:int,authorized_by_user_id:int}, or null when there is none (the step pauses).
	 */
	public function set_resolver( callable $resolver ): void {
		$this->resolver = $resolver;
	}

	/**
	 * @param callable(array $network, array $run, array $node):?string $gate Null when the action step may run now;
	 *        otherwise a fixed reason code (for example a revoked consent), and the step pauses.
	 */
	public function set_gate( callable $gate ): void {
		$this->gate = $gate;
	}

	/** @param callable(array $run, string $state, ?string $reason):void $observer Hears every state of such a run. */
	public function set_observer( callable $observer ): void {
		$this->observer = $observer;
	}

	/** The version array the engine works with, or null. Never throws. */
	public function version( array $run ): ?array {
		if ( null === $this->resolver || ! is_array( $run['network'] ?? null ) ) {
			return null;
		}
		try {
			$found = ( $this->resolver )( $run['network'], $run );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_array( $found ) || ! is_array( $found['graph'] ?? null ) || null === Graph::trigger( $found['graph'] ) ) {
			return null;
		}
		return array(
			'id'                    => 0,
			'workflow_id'           => (int) $run['workflow_id'],
			'version'               => (int) ( $found['version'] ?? 0 ),
			'graph'                 => $found['graph'],
			'trigger_type'          => (string) ( Graph::trigger( $found['graph'] )['event'] ?? '' ),
			'authorized_by_user_id' => (int) ( $found['authorized_by_user_id'] ?? 0 ),
			'created_at'            => '',
		);
	}

	/** Reason code why the action step must not run now, or null. Fails closed. */
	public function gate( array $run, array $node ): ?string {
		if ( null === $this->gate ) {
			return null === $this->resolver ? self::UNAVAILABLE : null;
		}
		try {
			$reason = ( $this->gate )( (array) $run['network'], $run, $node );
		} catch ( \Throwable $e ) {
			return 'foreign_gate_error';
		}
		return null === $reason ? null : self::code( (string) $reason );
	}

	public function observe( array $run, string $state, ?string $reason ): void {
		if ( null === $this->observer ) {
			return;
		}
		try {
			( $this->observer )( $run, $state, $reason );
		} catch ( \Throwable $e ) {
			// The add-on reconciles later (it can read the run with find()); the step itself has finished.
			return;
		}
	}

	/** Whether a run came from outside this site. */
	public static function is_foreign( array $run ): bool {
		return is_array( $run['network'] ?? null ) && array() !== $run['network'];
	}

	/** The checked wrapper (positive integers under exactly WRAPPER_KEYS), or null. */
	public static function wrapper( array $network ): ?array {
		$out = array();
		foreach ( self::WRAPPER_KEYS as $key ) {
			if ( ! isset( $network[ $key ] ) || ! is_int( $network[ $key ] ) || $network[ $key ] < 1 ) {
				return null;
			}
			$out[ $key ] = $network[ $key ];
		}
		return count( $network ) === count( $out ) ? $out : null;
	}

	/** Key of a step of such a run: run key (network run + target site) and node (R-8.4). */
	public static function step_key( string $run_key, string $node_id ): string {
		return hash( 'sha256', 'foreign|' . $run_key . '|' . $node_id );
	}

	/**
	 * Puts a run with its first step into the queue of the current site, once per $key (a repeated call returns the same
	 * run and creates nothing).
	 *
	 * @param EngineStore $store    Store of the current (target) site.
	 * @param array       $graph    Definition for this site; only used to find the first step here.
	 * @param array       $envelope Snapshot of the event with the released fields only; actor_user_id is dropped.
	 * @param array       $network  Wrapper, see WRAPPER_KEYS.
	 * @param string      $key      Idempotency key of network run + target site, 64 hex characters.
	 * @param string      $origin   Stable name of the origin, for the anchor workflow (for example "network:12").
	 * @param string      $name     Name of the anchor workflow in the run list of this site.
	 * @return array{run_id:int,created:bool,anchor:int}
	 * @throws \InvalidArgumentException When wrapper, key or graph are not usable.
	 */
	public static function enqueue( EngineStore $store, array $graph, array $envelope, array $network, string $key, string $origin, string $name, string $now ): array {
		$wrapper = self::wrapper( $network );
		$trigger = Graph::trigger( $graph );
		$first   = null === $trigger ? null : Graph::next( $graph, (string) $trigger['id'] );
		if ( null === $wrapper || ! preg_match( '/^[a-f0-9]{64}$/', $key ) || null === $first || '' === $origin || ! isset( $envelope['id'], $envelope['type'] ) ) {
			throw new \InvalidArgumentException( 'foreign_run_invalid' );
		}
		// R-17.3: the user of the source site never crosses; the workflow ID of the source site means nothing here.
		unset( $envelope['actor_user_id'], $envelope['caused_by_workflow_id'] );
		$anchor = $store->ensure_anchor( $origin, $name, $now );
		list( $run_id, $created ) = $store->create_run(
			array(
				'workflow_id'   => $anchor,
				'version_id'    => 0,
				'event_row_id'  => 0,
				'event_key'     => $key,
				'context'       => $envelope,
				'actor_user_id' => null,
				'serial_key'    => null,
				'network'       => $wrapper,
			),
			$now
		);
		$store->ensure_step( $run_id, $first, self::step_key( $key, $first ), $now, $now );
		return array( 'run_id' => $run_id, 'created' => $created, 'anchor' => $anchor );
	}

	/**
	 * The run of $key on the current site with its steps, or null.
	 *
	 * @return array{run:array,steps:array}|null
	 */
	public static function find( EngineStore $store, string $key ): ?array {
		$run = $store->foreign_run( $key );
		return null === $run ? null : array( 'run' => $run, 'steps' => $store->steps_for_run( (int) $run['id'] ) );
	}

	/**
	 * A human resumes a paused run (after a new consent or a renewed license) or one that needs a decision: its paused
	 * and action_required steps go back into the queue. Steps with a started side effect stay where they are (R-8.12).
	 * Returns the number requeued.
	 */
	public static function resume( EngineStore $store, string $key, string $now ): int {
		$found = self::find( $store, $key );
		if ( null === $found || ! in_array( $found['run']['state'], array( EngineStore::RUN_PAUSED, EngineStore::RUN_ACTION_REQUIRED ), true ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $found['steps'] as $step ) {
			$waiting = in_array( $step['state'], array( EngineStore::STEP_PAUSED, EngineStore::STEP_ACTION_REQUIRED ), true );
			if ( $waiting && EngineStore::EFFECT_STARTED !== $step['effect'] && $store->requeue_step( (int) $step['id'], false, $now ) ) {
				++$count;
			}
		}
		if ( $count > 0 ) {
			$store->set_run_state( (int) $found['run']['id'], EngineStore::RUN_RUNNING, null, $now );
		}
		return $count;
	}

	/** Cancels the run of $key on the current site: waiting steps do not run, finished ones stay. */
	public static function cancel( EngineStore $store, string $key, string $now ): bool {
		$run = $store->foreign_run( $key );
		if ( null === $run || ! in_array( $run['state'], EngineStore::RUN_OPEN, true ) ) {
			return false;
		}
		$store->cancel_steps( (int) $run['id'], $now );
		return $store->set_run_state( (int) $run['id'], EngineStore::RUN_CANCELLED, 'cancelled', $now );
	}

	private static function code( string $reason ): string {
		$code = preg_replace( '/[^a-z0-9_:.\-]/i', '', $reason );
		return substr( '' === $code ? 'error' : $code, 0, 64 );
	}
}
