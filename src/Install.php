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
	const CAPS = array( 'manage_workflow_captain', 'view_workflow_logs', 'retry_workflow_runs' );

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

	/** Creates or updates the tables when the stored schema version differs (also after an update). */
	public static function maybe_upgrade(): void {
		if ( WORKFLOW_CAPTAIN_DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::create_tables();
		}
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
		update_option( self::DB_OPTION, WORKFLOW_CAPTAIN_DB_VERSION, false );
	}

	/** Multisite: deleting a site drops its tables too (they can hold user IDs). */
	public static function drop_site_tables( array $tables ): array {
		global $wpdb;
		$tables[] = WpdbEventStore::events_table( $wpdb );
		$tables[] = WpdbEventStore::causes_table( $wpdb );
		return $tables;
	}

	/** Removes tables, options and capabilities of the current site (uninstall.php). */
	public static function uninstall_site(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . WpdbEventStore::events_table( $wpdb ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . WpdbEventStore::causes_table( $wpdb ) );
		foreach ( array( self::DB_OPTION, 'workflow_captain_adapters', 'workflow_captain_rejected' ) as $option ) {
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
