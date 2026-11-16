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

	private ?\WorkflowCaptain\Engine\Engine $engine = null;

	private AdapterRegistry $adapters;

	private string $schema = Install::SCHEMA_CURRENT;

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
		// Schema changes run in the admin, in cron or in WP-CLI, never on a front-end request (konzept 5, Datenmodell).
		$may_migrate  = is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
		$this->schema = $may_migrate ? Install::maybe_upgrade() : Install::schema_state( get_option( Install::DB_OPTION ), WORKFLOW_CAPTAIN_DB_VERSION );
		if ( Install::SCHEMA_NEWER === $this->schema ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( current_user_can( 'manage_workflow_captain' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Workflow Captain: Die Datenbank stammt von einer neueren Version des Plugins. Ereignisse und Schritte werden nicht verarbeitet, bis die neuere Version wieder installiert ist. Es wurde nichts gelöscht.', 'workflow-captain' ) . '</p></div>';
					}
				}
			);
		}
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
		add_action( 'init', array( self::class, 'schedule_events' ) );
		add_action( 'init', array( $this, 'register_adapters' ), 20 );
		add_action( Install::CRON_PROCESS, array( $this, 'run_worker' ) );
		add_action( Install::CRON_DAILY, array( $this, 'run_maintenance' ) );
		add_filter( 'workflow_captain_retention_days', array( Settings::class, 'retention' ), 5, 2 );
		( new Privacy( array( $this, 'store' ), null, array( $this, 'engine_store' ) ) )->register();
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'workflow-captain', \WorkflowCaptain\Cli\Command::class );
		}
		add_action( 'admin_post_workflow_captain_diagnostics', array( $this, 'download_diagnostics' ) );
		if ( is_admin() ) {
			( new \WorkflowCaptain\Admin\OverviewPage( $this ) )->register();
			( new EventsPage( $this ) )->register();
			( new \WorkflowCaptain\Admin\WorkflowsPage( $this ) )->register();
			( new \WorkflowCaptain\Admin\SettingsPage( $this ) )->register();
			( new \WorkflowCaptain\Admin\ProvidersPage( $this ) )->register();
			( new \WorkflowCaptain\Admin\SiteHealth( $this ) )->register();
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
			$dispatcher = apply_filters( 'workflow_captain_dispatcher', $this->engine() );
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
			if ( $dispatcher === $this->engine ) {
				$this->engine->set_consumer( $this->consumer );
			}
		}
		return $this->consumer;
	}

	/** The workflow engine of the free core with the actions of 8.1; add-ons add actions on "workflow_captain_actions_v1". */
	public function engine(): \WorkflowCaptain\Engine\Engine {
		if ( null === $this->engine ) {
			global $wpdb;
			$clock     = new Clock();
			$store     = new \WorkflowCaptain\Engine\WpdbEngineStore( $wpdb );
			$directory = new \WorkflowCaptain\Engine\WpDirectory();
			$actions   = new \WorkflowCaptain\Engine\ActionRegistry();
			$actions->add( new \WorkflowCaptain\Engine\Actions\NotifyAdmin( $directory ) );
			$actions->add( new \WorkflowCaptain\Engine\Actions\NotifyUser( $directory ) );
			$actions->add( new \WorkflowCaptain\Engine\Actions\Tag( $store, $clock ) );
			$actions->add( new \WorkflowCaptain\Engine\Actions\Tag( $store, $clock, true ) );
			$actions->add( new \WorkflowCaptain\Engine\Actions\Note( $store, $clock ) );
			/**
			 * Register further actions: $registry->add( new My_Action() ). Only commands of SPEC-suite-events 8.1.
			 *
			 * @since 0.2.0
			 * @param \WorkflowCaptain\Engine\ActionRegistry $registry
			 */
			do_action( 'workflow_captain_actions_v1', $actions );
			$this->engine = new \WorkflowCaptain\Engine\Engine( $store, $actions, $directory, $clock, wp_timezone_string(), Settings::engine_limits( Settings::get() ) );
		}
		return $this->engine;
	}

	public function engine_store(): \WorkflowCaptain\Engine\EngineStore {
		return $this->engine()->store();
	}

	public function workflows(): \WorkflowCaptain\Engine\Workflows {
		$engine = $this->engine();
		return new \WorkflowCaptain\Engine\Workflows( $engine->store(), $engine->actions(), new \WorkflowCaptain\Engine\WpDirectory(), new Clock(), array( $this, 'available_event_types' ) );
	}

	/** @return string[] Event types the registered adapters deliver. */
	public function available_event_types(): array {
		$types = array();
		foreach ( $this->adapters->products() as $product ) {
			$types = array_merge( $types, (array) $this->adapters->get( $product )?->types() );
		}
		return array_values( array_unique( $types ) );
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
		if ( Install::SCHEMA_CURRENT !== $this->schema ) {
			return; // Downgrade (newer schema) or upgrade not yet run: the worker waits (WF-CORE-07).
		}
		/**
		 * Events handled per worker run.
		 *
		 * @since 0.1.0
		 * @param int $limit
		 */
		$limit = max( 1, min( 200, (int) apply_filters( 'workflow_captain_worker_batch', Settings::get()['batch'] ) ) );
		$this->consumer()->process( $limit );
		$this->consumer(); // Makes sure the engine knows the consumer before steps run (C-9).
		$this->engine()->process( $limit );
	}

	public function run_maintenance(): void {
		( new Privacy( array( $this, 'store' ), null, array( $this, 'engine_store' ) ) )->apply_retention();
		$this->consumer()->store()->purge_causes( ( new Clock() )->mysql() );
	}

	/** Stores how many envelopes were rejected and the last violation codes; never a value (R-5.5). */
	/** Support diagnostics (Admin\Diagnostics) with versions and cron state of this site. */
	public function diagnostics(): array {
		global $wp_version;
		return \WorkflowCaptain\Admin\Diagnostics::report(
			$this->store(),
			$this->engine()->store(),
			$this->adapters,
			array(
				'plugin'            => WORKFLOW_CAPTAIN_VERSION,
				'schema'            => (string) get_option( Install::DB_OPTION ),
				'schema_expected'   => WORKFLOW_CAPTAIN_DB_VERSION,
				'schema_state'      => $this->schema,
				'adapter_interface' => WORKFLOW_CAPTAIN_CONTRACT_VERSION,
				'wordpress'         => (string) $wp_version,
				'php'               => PHP_VERSION,
				'multisite'         => is_multisite(),
				'wp_cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'next_worker_run'   => wp_next_scheduled( Install::CRON_PROCESS ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) wp_next_scheduled( Install::CRON_PROCESS ) ) : null,
				'secret_key_own'    => \WorkflowCaptain\Security\Vault::own_key_configured(),
				'rejected_envelopes' => (array) get_option( self::REJECTED_OPTION, array() ),
			)
		);
	}

	public function download_diagnostics(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) ) {
			wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
		}
		check_admin_referer( 'workflow_captain_diagnostics' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="workflow-captain-diagnose.json"' );
		echo wp_json_encode( $this->diagnostics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

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
