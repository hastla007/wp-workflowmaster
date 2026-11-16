<?php
/**
 * Wires the consumer into WordPress: adapters, worker cron, daily maintenance, privacy and admin.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\Admin\EventsPage;
use WorkflowCaptain\Consumer\Consumer;
use WorkflowCaptain\Consumer\Dispatcher;
use WorkflowCaptain\Consumer\NullDispatcher;
use WorkflowCaptain\Privacy\Privacy;
use WorkflowCaptain\Store\WpdbEventStore;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	const ADAPTERS_OPTION = 'workflow_captain_adapters';
	const REJECTED_OPTION = 'workflow_captain_rejected';

	private static ?Plugin $instance = null;

	private ?Consumer $consumer = null;

	private AdapterRegistry $adapters;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->adapters = new AdapterRegistry();
	}

	public function boot(): void {
		Install::maybe_upgrade();
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
		add_action( 'init', array( self::class, 'schedule_events' ) );
		add_action( 'init', array( $this, 'register_adapters' ), 20 );
		add_action( Install::CRON_PROCESS, array( $this, 'run_worker' ) );
		add_action( Install::CRON_DAILY, array( $this, 'run_maintenance' ) );
		( new Privacy( array( $this, 'store' ) ) )->register();
		if ( is_admin() ) {
			( new EventsPage( $this ) )->register();
		}
		/**
		 * Workflow Captain has booted. Adapters register on "workflow_captain_register_adapters_v1" (init, priority 20).
		 *
		 * @since 0.1.0
		 */
		do_action( 'workflow_captain_loaded', $this );
	}

	public function consumer(): Consumer {
		if ( null === $this->consumer ) {
			global $wpdb;
			/**
			 * The workflow engine that starts runs for stored events. Until it exists nothing starts.
			 *
			 * @since 0.1.0
			 * @param Dispatcher $dispatcher
			 */
			$dispatcher = apply_filters( 'workflow_captain_dispatcher', new NullDispatcher() );
			if ( ! $dispatcher instanceof Dispatcher ) {
				$dispatcher = new NullDispatcher();
			}
			$this->consumer = new Consumer(
				new WpdbEventStore( $wpdb ),
				$this->adapters,
				$dispatcher,
				new Clock(),
				get_current_blog_id(),
				array( self::class, 'record_rejection' )
			);
		}
		return $this->consumer;
	}

	public function store(): \WorkflowCaptain\Store\EventStore {
		return $this->consumer()->store();
	}

	public function adapters(): AdapterRegistry {
		return $this->adapters;
	}

	/**
	 * Lets add-ons register their adapters, then pauses the open events of every provider whose adapter was registered
	 * last time but not now (R-10.7, C-15). Nothing is deleted and nothing is caught up later (R-10.6, R-10.8).
	 */
	public function register_adapters(): void {
		/**
		 * Register adapters here: $registry->add( new My_Adapter() ). Interface version: WORKFLOW_CAPTAIN_CONTRACT_VERSION.
		 *
		 * @since 0.1.0
		 * @param AdapterRegistry $registry
		 */
		do_action( 'workflow_captain_register_adapters_v1', $this->adapters );
		$now      = $this->adapters->products();
		$previous = (array) get_option( self::ADAPTERS_OPTION, array() );
		foreach ( array_diff( $previous, $now ) as $gone ) {
			$this->consumer()->pause_product( (string) $gone );
		}
		sort( $now );
		sort( $previous );
		if ( $now !== $previous ) {
			update_option( self::ADAPTERS_OPTION, $now, false );
		}
	}

	public function run_worker(): void {
		/**
		 * Events handled per worker run.
		 *
		 * @since 0.1.0
		 * @param int $limit
		 */
		$limit = max( 1, min( 200, (int) apply_filters( 'workflow_captain_worker_batch', 25 ) ) );
		$this->consumer()->process( $limit );
	}

	public function run_maintenance(): void {
		( new Privacy( array( $this, 'store' ) ) )->apply_retention();
		$this->consumer()->store()->purge_causes( ( new Clock() )->mysql() );
	}

	/** Stores how many envelopes were rejected and the last violation codes; never a value (R-5.5). */
	public static function record_rejection( array $codes ): void {
		$state          = (array) get_option( self::REJECTED_OPTION, array() );
		$state['count'] = (int) ( $state['count'] ?? 0 ) + 1;
		$state['last']  = array_slice( array_map( 'strval', $codes ), 0, 10 );
		$state['at']    = time();
		update_option( self::REJECTED_OPTION, $state, false );
	}

	public static function cron_schedules( array $schedules ): array {
		$schedules['workflow_captain_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Jede Minute (Workflow Captain)', 'workflow-captain' ),
		);
		return $schedules;
	}

	public static function schedule_events(): void {
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
		if ( ! wp_next_scheduled( Install::CRON_PROCESS ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'workflow_captain_minute', Install::CRON_PROCESS );
		}
		if ( ! wp_next_scheduled( Install::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Install::CRON_DAILY );
		}
	}
}
