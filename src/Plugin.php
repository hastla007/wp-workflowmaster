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

	private ?\WorkflowCaptain\Connector\ConnectorRegistry $connectors = null;

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
		if ( EmergencyPause::active() ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( current_user_can( 'view_workflow_logs' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'Workflow Captain: Die Notfallpause ist aktiv. Ereignisse werden gespeichert, aber kein Schritt wird ausgeführt.', 'workflow-captain' ) . '</p></div>';
					}
				}
			);
		}
		if ( TestMode::OFF !== TestMode::mode() ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( current_user_can( 'view_workflow_logs' ) ) {
						echo '<div class="notice notice-warning"><p><strong>' . esc_html( TestMode::DRY === TestMode::mode() ? __( 'Workflow Captain: Testmodus „Nichts ausführen“ ist aktiv. Workflows laufen, aber kein Schritt wirkt.', 'workflow-captain' ) : __( 'Workflow Captain: Testmodus „Nachrichten umleiten“ ist aktiv. Nachrichten aus Workflows gehen an die Testadresse.', 'workflow-captain' ) ) . '</strong></p></div>';
					}
				}
			);
		}
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
		add_action( 'init', array( $this, 'register_connectors' ), 20 );
		add_action( Install::CRON_PROCESS, array( $this, 'run_worker' ) );
		add_action( Install::CRON_DAILY, array( $this, 'run_maintenance' ) );
		add_filter( 'workflow_captain_retention_days', array( Settings::class, 'retention' ), 5, 2 );
		( new Privacy( array( $this, 'store' ), null, array( $this, 'engine_store' ) ) )->register();
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'workflow-captain', \WorkflowCaptain\Cli\Command::class );
		}
		add_action( 'admin_post_workflow_captain_diagnostics', array( $this, 'download_diagnostics' ) );
		add_action( 'admin_post_workflow_captain_pause', array( $this, 'handle_pause' ) );
		if ( is_admin() ) {
			( new \WorkflowCaptain\Admin\OverviewPage( $this ) )->register();
			( new EventsPage( $this ) )->register();
			( new \WorkflowCaptain\Admin\WorkflowsPage( $this ) )->register();
			( new \WorkflowCaptain\Admin\SchedulesPage( $this ) )->register();
			( new \WorkflowCaptain\Admin\SettingsPage( $this ) )->register();
			( new \WorkflowCaptain\Admin\ProvidersPage( $this ) )->register();
			( new \WorkflowCaptain\Admin\ConnectorsPage( $this ) )->register();
			( new \WorkflowCaptain\Admin\SiteHealth( $this ) )->register();
			( new \WorkflowCaptain\Admin\TemplatesPage( $this ) )->register();
			// Narrow screens: wide data tables scroll inside their box, the page itself does not (E2E finding, 390px).
			// Confirmation boxes: the label is 24 px high, WCAG 2.5.8 (E2E second run).
			// Header rows (run page, editor) wrap instead of widening the page.
			// Links inside running text of a table cell are underlined, not told apart by colour only (WCAG 1.4.1, E2E third run).
			// Text fields (name of the workflow) stay inside the page at 390 px: 100% wide plus the 1 px margin of wp-admin inputs (E2E).
			add_action(
				'admin_head',
				static function (): void {
					echo '<style>@media (max-width:782px){body[class*="workflow-captain"] .wrap table.widefat,body[class*="workflow-captain"] .wrap table.form-table{display:block;max-width:100%;overflow-x:auto;position:relative}}body[class*="workflow-captain"] .wfc-editor-head{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between}body[class*="workflow-captain"] .wfc-run-state-bad{color:#b32d2e;font-weight:600}body[class*="workflow-captain"] .wrap label.wfc-confirm{display:inline-block;min-height:24px;line-height:24px}body[class*="workflow-captain"] .wrap a.wfc-text-link{text-decoration:underline}body[class*="workflow-captain"] .wrap input.regular-text{max-width:calc(100% - 2px)}</style>';
				}
			);
			( new \WorkflowCaptain\Admin\HelpPage() )->register();
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
			// Also when an add-on wraps the engine (filter above): steps keep giving caused events their causation (C-9).
			$this->engine()->set_consumer( $this->consumer );
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
			$actions->set_availability( array( $this->connectors(), 'action_available' ) );
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
			$this->engine->set_paused( EmergencyPause::active() );
			$this->engine->set_provider_contracts( array( $this, 'provider_contracts' ) );
			/**
			 * Runs whose definition lives outside this site (network workflows of a multisite network, spec section 17):
			 * an add-on sets resolver, gate and observer. Without them such runs pause ("foreign_unavailable").
			 *
			 * @since 0.2.0
			 * @param \WorkflowCaptain\Engine\ForeignRuns $foreign
			 */
			do_action( 'workflow_captain_foreign_runs_v1', $this->engine->foreign() );
		}
		return $this->engine;
	}

	public function engine_store(): \WorkflowCaptain\Engine\EngineStore {
		return $this->engine()->store();
	}

	public function workflows(): \WorkflowCaptain\Engine\Workflows {
		$engine = $this->engine();
		return new \WorkflowCaptain\Engine\Workflows( $engine->store(), $engine->actions(), new \WorkflowCaptain\Engine\WpDirectory(), new Clock(), array( $this, 'available_event_types' ), array( $this, 'provider_contracts' ) );
	}

	/** @return array<string,?string> Contract version per registered provider (WF-CORE-02). */
	public function provider_contracts(): array {
		$out = array();
		foreach ( $this->adapters->products() as $product ) {
			$out[ $product ] = $this->adapters->get( $product )?->contract_version();
		}
		return $out;
	}

	/** @return string[] Event types the registered adapters deliver. */
	public function available_event_types(): array {
		$types = array();
		foreach ( $this->adapters->products() as $product ) {
			$types = array_merge( $types, (array) $this->adapters->get( $product )?->types() );
		}
		return array_values( array_unique( $types ) );
	}

	/** Registry of own connectors (F-22); its connectors are also in adapters() once accepted. */
	public function connectors(): \WorkflowCaptain\Connector\ConnectorRegistry {
		if ( null === $this->connectors ) {
			$this->connectors = new \WorkflowCaptain\Connector\ConnectorRegistry(
				$this->adapters,
				static fn() => (array) get_option( \WorkflowCaptain\Connector\ConnectorRegistry::OPTION, array() ),
				WORKFLOW_CAPTAIN_CONTRACT_VERSION
			);
		}
		return $this->connectors;
	}

	/**
	 * Lets own connectors register with their manifest (init, priority 20, after the adapters), then pauses the open
	 * events of every connector that was registered last time but not now (R-10.7). Nothing is deleted.
	 */
	public function register_connectors(): void {
		/**
		 * Register own connectors here: $registry->add( $manifest, new My_Connector_Adapter() ). Format of the manifest:
		 * docs/CONNECTOR-CONTRACT.md. A connector stays off until the operator enables it.
		 *
		 * @since 0.2.0
		 * @param \WorkflowCaptain\Connector\ConnectorRegistry $registry
		 */
		do_action( 'workflow_captain_register_connectors_v1', $this->connectors() );
		$now      = array_keys( $this->connectors()->all() );
		$previous = (array) get_option( \WorkflowCaptain\Connector\ConnectorRegistry::SEEN_OPTION, array() );
		foreach ( array_diff( $previous, $now ) as $gone ) {
			$this->consumer()->pause_product( (string) $gone );
		}
		sort( $now );
		sort( $previous );
		if ( $now !== $previous ) {
			update_option( \WorkflowCaptain\Connector\ConnectorRegistry::SEEN_OPTION, $now, false );
		}
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
		// WordPress and WooCommerce are built in, need no license and register first (F-20, SPEC-suite-events 4.7).
		$sink = function ( \WorkflowCaptain\Adapter\Adapter $adapter, array $event ): void {
			$this->consumer()->capture( $adapter, $event );
		};
		$this->adapters->add_core( new \WorkflowCaptain\Providers\ScheduleAdapter() );
		\WorkflowCaptain\Providers\CoreAdapter::register(
			$this->adapters,
			array(
				new \WorkflowCaptain\Providers\WordPressAdapter( $sink ),
				new \WorkflowCaptain\Providers\WooCommerceAdapter( $sink ),
			)
		);
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

	/** Turns due schedule triggers into events (WF-CORE-04); the last fired time per workflow is kept in an option. */
	public function tick_schedules(): void {
		$adapter = $this->adapters->get( 'workflow-captain' );
		if ( null === $adapter ) {
			return;
		}
		$last = (array) get_option( 'workflow_captain_schedule_fired', array() );
		$due  = $this->engine()->due_schedules( $last );
		foreach ( $due as $item ) {
			$this->consumer()->capture( $adapter, $item['event'] );
			$last[ $item['workflow_id'] ] = $item['due'];
		}
		if ( array() !== $due ) {
			update_option( 'workflow_captain_schedule_fired', $last, false );
		}
	}

	public function run_worker(): void {
		/**
		 * Events handled per worker run.
		 *
		 * @since 0.1.0
		 * @param int $limit
		 */
		$this->work( max( 1, min( 200, (int) apply_filters( 'workflow_captain_worker_batch', Settings::get()['batch'] ) ) ) );
	}

	/**
	 * One worker pass: due schedule triggers become events, then events and steps are processed. Shared by the
	 * scheduled worker and "wp workflow-captain process" (system cron), so both fire schedules.
	 *
	 * @return array{0:int,1:int}|null Events and steps processed; null while the schema is not current (WF-CORE-07).
	 */
	public function work( int $limit ): ?array {
		if ( Install::SCHEMA_CURRENT !== $this->schema ) {
			return null; // Downgrade (newer schema) or upgrade not yet run: the worker waits (WF-CORE-07).
		}
		$this->tick_schedules();
		$this->catch_up_maintenance();
		$events = $this->consumer()->process( $limit );
		$this->consumer(); // Makes sure the engine knows the consumer before steps run (C-9).
		return array( $events, $this->engine()->process( $limit ) );
	}

	/**
	 * Runs the daily maintenance from the worker when it is overdue (more than 25 hours), so retention and privacy
	 * purge also happen on sites where WP-Cron is off and only `wp workflow-captain process` runs (E2E finding).
	 */
	private function catch_up_maintenance(): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		$last = (int) get_option( 'workflow_captain_maintenance_at', 0 );
		if ( time() - $last > 25 * HOUR_IN_SECONDS ) {
			$this->run_maintenance();
		}
	}

	public function run_maintenance(): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( 'workflow_captain_maintenance_at', time(), false );
		}
		if ( 1 === Settings::get()['notify_daily'] ) {
			$config = Notifications::config();
			$mail   = Notifications::digest( $this->diagnostics(), admin_url( 'admin.php?page=workflow-captain' ), $config['kinds'] );
			if ( null !== $mail ) {
				wp_mail( Notifications::recipients( $config, (string) get_option( 'admin_email' ) ), $mail['subject'], $mail['message'], Notifications::headers() );
			}
		}
		( new Privacy( array( $this, 'store' ), null, array( $this, 'engine_store' ) ) )->apply_retention();
		SettingsTransfer::purge_expired_backup();
		$this->consumer()->store()->purge_causes( ( new Clock() )->mysql() );
	}

	/** Stores how many envelopes were rejected and the last violation codes; never a value (R-5.5). */
	/** Version string of the database server, e.g. "9.7.1" or "12.3.2-MariaDB"; "" when unknown. */
	private static function database_server(): string {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return '';
		}
		if ( method_exists( $wpdb, 'db_server_info' ) ) {
			return (string) $wpdb->db_server_info();
		}
		return method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '';
	}

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
				'upgrade_lock'      => Migrator::lock_state( get_option( Migrator::LOCK_OPTION, false ), time() ),
				'adapter_interface' => WORKFLOW_CAPTAIN_CONTRACT_VERSION,
				'wordpress'         => (string) $wp_version,
				'php'               => PHP_VERSION,
				'database'          => self::database_server(),
				'multisite'         => is_multisite(),
				'wp_cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'next_worker_run'   => null !== Scheduler::next_run() ? gmdate( 'Y-m-d\TH:i:s\Z', (int) Scheduler::next_run() ) : null,
				'scheduler'         => Scheduler::backend(),
				'secret_key_own'    => \WorkflowCaptain\Security\Vault::own_key_configured(),
				'emergency_pause'   => EmergencyPause::active(),
				'rejected_envelopes' => (array) get_option( self::REJECTED_OPTION, array() ),
			)
		);
	}

	public function handle_pause(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) ) {
			wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
		}
		check_admin_referer( 'workflow_captain_pause' );
		$on = ! empty( $_POST['pause'] );
		if ( $on && empty( $_POST['confirm'] ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'workflow-captain', 'pause_confirm' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}
		EmergencyPause::set( $on, get_current_user_id() );
		wp_safe_redirect( add_query_arg( array( 'page' => 'workflow-captain' ), admin_url( 'admin.php' ) ) );
		exit;
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
		if ( did_action( 'init' ) || doing_action( 'init' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			Scheduler::ensure();
		}
		if ( ! wp_next_scheduled( Install::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Install::CRON_DAILY );
		}
	}
}
