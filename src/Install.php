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
	const CAPS = array( 'manage_workflow_captain', 'edit_workflows', 'activate_workflows', 'view_workflow_logs', 'retry_workflow_runs' );

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

	public static function activate_site(): void {
		self::create_tables();
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( self::CAPS as $cap ) {
				$role->add_cap( $cap );
			}
		}
		Plugin::schedule_events();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_PROCESS );
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

	/** Creates or updates the tables when the stored schema is older or missing (also after an update). */
	public static function maybe_upgrade(): string {
		$state = self::schema_state( get_option( self::DB_OPTION ), WORKFLOW_CAPTAIN_DB_VERSION );
		if ( self::SCHEMA_UPGRADE === $state ) {
			self::create_tables();
			return self::SCHEMA_CURRENT;
		}
		return $state;
	}

	public static function create_tables(): void {
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
		update_option( self::DB_OPTION, WORKFLOW_CAPTAIN_DB_VERSION, false );
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

	/** Removes tables, options and capabilities of the current site (uninstall.php). */
	public static function uninstall_site(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . WpdbEventStore::events_table( $wpdb ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . WpdbEventStore::causes_table( $wpdb ) );
		foreach ( \WorkflowCaptain\Engine\WpdbEngineStore::TABLES as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . \WorkflowCaptain\Engine\WpdbEngineStore::table( $wpdb, $name ) );
		}
		foreach ( array( self::DB_OPTION, 'workflow_captain_adapters', 'workflow_captain_rejected', 'workflow_captain_settings' ) as $option ) {
			delete_option( $option );
		}
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		self::deactivate();
	}
}
