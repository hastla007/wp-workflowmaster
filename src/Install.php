<?php
/**
 * Activation, deactivation, schema and clean-up.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

use WorkflowCaptain\Store\WpdbEventStore;

defined( 'ABSPATH' ) || exit;

final class Install {

	const DB_OPTION = 'workflow_captain_db_version';

	/** Capabilities of the consumer (konzept.md, section 9), given to administrators on activation. */
	const CAPS = array( 'manage_workflow_captain', 'edit_workflows', 'activate_workflows', 'view_workflow_logs', 'retry_workflow_runs', 'manage_workflow_integrations' );

	const CRON_PROCESS = 'workflow_captain_process_events';
	const CRON_DAILY   = 'workflow_captain_daily_maintenance';

	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				try {
					self::activate_site();
				} finally {
					restore_current_blog();
				}
			}
			return;
		}
		self::activate_site();
	}

	/**
	 * Multisite: a site created while the plugin is network-active gets its tables, capabilities and worker plan right
	 * away (hook "wp_initialize_site"). Otherwise events captured on its front end before the first admin, cron or
	 * WP-CLI request would be lost, because only those requests run maybe_upgrade().
	 *
	 * @param \WP_Site|object $site
	 */
	public static function initialize_site( $site ): void {
		$blog_id = (int) ( $site->blog_id ?? 0 );
		if ( $blog_id <= 0 || ! self::network_active() ) {
			return;
		}
		switch_to_blog( $blog_id );
		try {
			self::activate_site();
		} finally {
			restore_current_blog();
		}
	}

	/** Whether this plugin is network-activated (without loading wp-admin/includes/plugin.php). */
	public static function network_active(): bool {
		if ( ! is_multisite() || ! defined( 'WORKFLOW_CAPTAIN_FILE' ) ) {
			return false;
		}
		return array_key_exists( plugin_basename( WORKFLOW_CAPTAIN_FILE ), (array) get_site_option( 'active_sitewide_plugins', array() ) );
	}

	public static function activate_site(): void {
		// Reactivation after an update runs the same versioned upgrade; on a current schema the tables are completed.
		if ( Migrator::CURRENT === self::upgrade()['state'] ) {
			self::ensure_tables();
		}
		self::grant_admin_caps();
		Plugin::schedule_events();
	}

	public static function deactivate(): void {
		Scheduler::clear();
		wp_clear_scheduled_hook( self::CRON_DAILY );
	}

	const SCHEMA_CURRENT = 'current';
	const SCHEMA_UPGRADE = 'upgrade';
	const SCHEMA_NEWER   = 'newer';

	/**
	 * State of the stored schema against the code (WF-CORE-07): "newer" after a plugin downgrade – then the schema is
	 * left alone and the worker pauses, so no older code writes into tables it does not know.
	 */
	public static function schema_state( $stored, string $expected ): string {
		if ( (string) $stored === $expected ) {
			return self::SCHEMA_CURRENT;
		}
		return ( is_numeric( $stored ) && (int) $stored > (int) $expected ) ? self::SCHEMA_NEWER : self::SCHEMA_UPGRADE;
	}

	/**
	 * Creates or updates the tables when the stored schema is older or missing (also after an update). While another
	 * request holds the upgrade lock the state stays "upgrade" and the worker waits (Migrator).
	 */
	public static function maybe_upgrade(): string {
		$result = self::upgrade();
		self::grant_admin_caps();
		$map = array(
			Migrator::DONE    => self::SCHEMA_CURRENT,
			Migrator::CURRENT => self::SCHEMA_CURRENT,
			Migrator::NEWER   => self::SCHEMA_NEWER,
			Migrator::BUSY    => self::SCHEMA_UPGRADE,
		);
		return $map[ $result['state'] ] ?? self::SCHEMA_UPGRADE;
	}

	/**
	 * Versioned, repeatable upgrade of this site (WF-CORE-07): tables first, then the data steps of migrations().
	 *
	 * @return array{state:string,resumed:bool,from:string,to:string,steps:array<int,int>}
	 */
	public static function upgrade(): array {
		$result = ( new Migrator(
			array(
				'get'    => static fn( $name, $default = false ) => get_option( $name, $default ),
				'add'    => static fn( $name, $value ) => add_option( $name, $value, '', false ),
				'update' => static fn( $name, $value ) => update_option( $name, $value, false ),
				'delete' => static fn( $name ) => delete_option( $name ),
			),
			array( self::class, 'ensure_tables' ),
			self::migrations(),
			time()
		) )->run( WORKFLOW_CAPTAIN_DB_VERSION );
		if ( $result['resumed'] && class_exists( Audit::class ) ) {
			Audit::record( 'upgrade_resumed', array( 'from' => $result['from'], 'to' => $result['to'] ) );
		}
		if ( Migrator::DONE === $result['state'] ) {
			self::$last_done = $result;
		}
		return $result;
	}

	/** @var array{state:string,resumed:bool,from:string,to:string,steps:array<int,int>}|null */
	private static ?array $last_done = null;

	/**
	 * The last upgrade of this request that changed something, or null. boot() upgrades before any WP-CLI command runs,
	 * so "wp workflow-captain migrate" reports from here what that upgrade did (E2E third run).
	 *
	 * @return array{state:string,resumed:bool,from:string,to:string,steps:array<int,int>}|null
	 */
	public static function last_done(): ?array {
		return self::$last_done;
	}

	/**
	 * Data steps by the schema version they lead to. Each must be idempotent and must not delete history. Versions 1 to
	 * 5 changed only tables (dbDelta), so there is no data step yet; a later version adds e.g. 6 => [ self::class, 'to_6' ].
	 *
	 * @return array<int,callable>
	 */
	public static function migrations(): array {
		return array();
	}

	/** Administrators get capabilities added by an update without reactivation (only missing ones are added). */
	public static function grant_admin_caps(): void {
		$role = function_exists( 'get_role' ) ? get_role( 'administrator' ) : null;
		if ( $role ) {
			foreach ( self::CAPS as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/** Creates the tables and records the current schema version. */
	public static function create_tables(): void {
		self::ensure_tables();
		update_option( self::DB_OPTION, WORKFLOW_CAPTAIN_DB_VERSION, false );
	}

	/** Creates or completes the tables (dbDelta adds only what is missing, never drops a column or a row). */
	public static function ensure_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$events  = WpdbEventStore::events_table( $wpdb );
		$causes  = WpdbEventStore::causes_table( $wpdb );
		dbDelta(
			"CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id char(36) NOT NULL,
  blog_id bigint(20) unsigned NOT NULL,
  idempotency_key char(64) NOT NULL,
  type varchar(96) NOT NULL,
  product varchar(40) NOT NULL,
  subject_type varchar(40) NOT NULL,
  subject_id varchar(191) NOT NULL,
  actor_user_id bigint(20) unsigned DEFAULT NULL,
  envelope longtext NOT NULL,
  state varchar(20) NOT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime NOT NULL,
  lease_owner varchar(64) DEFAULT NULL,
  lease_until datetime DEFAULT NULL,
  last_error varchar(191) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY site_key (blog_id,idempotency_key),
  KEY due (state,next_attempt_at),
  KEY actor (actor_user_id),
  KEY product_state (product,state),
  KEY subject (subject_type,subject_id(64))
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$causes} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subject_type varchar(40) NOT NULL,
  subject_id varchar(191) NOT NULL,
  command_id char(36) NOT NULL,
  workflow_id bigint(20) unsigned NOT NULL,
  correlation_id char(36) NOT NULL,
  depth tinyint(3) unsigned NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY subject (subject_type,subject_id(64)),
  KEY expires (expires_at)
) {$charset};"
		);
		$t = static fn( string $name ): string => \WorkflowCaptain\Engine\WpdbEngineStore::table( $wpdb, $name );
		dbDelta(
			"CREATE TABLE {$t( 'workflows' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  status varchar(20) NOT NULL,
  draft longtext NOT NULL,
  published_version_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$t( 'versions' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  workflow_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  graph longtext NOT NULL,
  trigger_type varchar(96) NOT NULL,
  authorized_by_user_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY workflow_version (workflow_id,version),
  KEY trigger_type (trigger_type)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$t( 'runs' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  workflow_id bigint(20) unsigned NOT NULL,
  version_id bigint(20) unsigned NOT NULL,
  event_row_id bigint(20) unsigned NOT NULL,
  event_key char(64) NOT NULL,
  context longtext NOT NULL,
  actor_user_id bigint(20) unsigned DEFAULT NULL,
  serial_key char(64) DEFAULT NULL,
  network text DEFAULT NULL,
  state varchar(20) NOT NULL,
  last_error varchar(191) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY version_event (version_id,event_key),
  KEY serial (workflow_id,serial_key),
  KEY workflow_state (workflow_id,state),
  KEY actor (actor_user_id),
  KEY state_updated (state,updated_at)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$t( 'steps' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  node_id varchar(64) NOT NULL,
  step_key char(64) NOT NULL,
  state varchar(20) NOT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  effect varchar(10) NOT NULL DEFAULT 'none',
  due_at datetime NOT NULL,
  lease_owner varchar(64) DEFAULT NULL,
  lease_until datetime DEFAULT NULL,
  result text DEFAULT NULL,
  last_error varchar(191) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY step_key (step_key),
  KEY due (state,due_at),
  KEY run (run_id)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$t( 'tags' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product varchar(40) NOT NULL,
  subject_type varchar(40) NOT NULL,
  subject_id varchar(191) NOT NULL,
  tag varchar(64) NOT NULL,
  workflow_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY subject_tag (product,subject_type,subject_id(64),tag)
) {$charset};"
		);
		// Daily limit per recipient (SET-WC-08): one row per calendar day of the site and user, kept a few days.
		dbDelta(
			"CREATE TABLE {$t( 'sends' )} (
  day date NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  sent smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,user_id),
  KEY user_id (user_id)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$t( 'notes' )} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  note_key char(64) NOT NULL,
  product varchar(40) NOT NULL,
  subject_type varchar(40) NOT NULL,
  subject_id varchar(191) NOT NULL,
  note text NOT NULL,
  workflow_id bigint(20) unsigned NOT NULL,
  run_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY note_key (note_key),
  KEY subject (product,subject_type,subject_id(64))
) {$charset};"
		);
	}

	/** Multisite: deleting a site drops its tables too (they can hold user IDs). */
	public static function drop_site_tables( array $tables ): array {
		global $wpdb;
		$tables[] = WpdbEventStore::events_table( $wpdb );
		$tables[] = WpdbEventStore::causes_table( $wpdb );
		foreach ( \WorkflowCaptain\Engine\WpdbEngineStore::TABLES as $name ) {
			$tables[] = \WorkflowCaptain\Engine\WpdbEngineStore::table( $wpdb, $name );
		}
		return $tables;
	}

	/** Removes tables, options and capabilities of the current site when the operator chose so (uninstall.php). */
	/**
	 * Whether the operator chose to remove all data when the plugin is deleted (setting `delete_data_on_uninstall`,
	 * SET-WC-05, E-S6). Default: keep. Reads the option directly: uninstall.php runs without the plugin's autoloader.
	 */
	public static function remove_data_wanted(): bool {
		$settings = get_option( 'workflow_captain_settings', array() );
		return is_array( $settings ) && 1 === (int) ( $settings['delete_data_on_uninstall'] ?? 0 );
	}

	public static function uninstall_site(): void {
		if ( ! self::remove_data_wanted() ) {
			// Keep tables, options and capabilities (E-S6); only the worker plan goes with the plugin.
			self::deactivate();
			return;
		}
		global $wpdb;
		$tables = array( WpdbEventStore::events_table( $wpdb ), WpdbEventStore::causes_table( $wpdb ) );
		foreach ( \WorkflowCaptain\Engine\WpdbEngineStore::TABLES as $name ) {
			$tables[] = \WorkflowCaptain\Engine\WpdbEngineStore::table( $wpdb, $name );
		}
		foreach ( $tables as $table ) {
			// Table names are the plugin's own (prefix + fixed name), never input; uninstall removes its tables.
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}
		foreach ( array( self::DB_OPTION, Migrator::LOCK_OPTION, 'workflow_captain_adapters', 'workflow_captain_rejected', 'workflow_captain_settings', 'workflow_captain_emergency_pause', 'workflow_captain_audit', 'workflow_captain_notify', 'workflow_captain_connectors', 'workflow_captain_connectors_registered', 'workflow_captain_schedule_fired', 'workflow_captain_maintenance_at', 'workflow_captain_settings_backup' ) as $option ) {
			delete_option( $option );
		}
		// Short-lived transients per user (import preview, notices, dry run); fixed names, no input.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_workflow\\_captain\\_%' OR option_name LIKE '\\_transient\\_timeout\\_workflow\\_captain\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed query on the plugin's own transients.
		// Unsubscribes of this site (user option with the site prefix, S11); the literal name, uninstall.php has no autoloader.
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $wpdb->get_blog_prefix() . 'workflow_captain_unsubscribed' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		self::deactivate();
	}
}
