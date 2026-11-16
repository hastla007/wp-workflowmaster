<?php
/**
 * WP-CLI commands for operations (WF-CORE-07, WF-CORE-10).
 *
 *     wp workflow-captain status
 *     wp workflow-captain process [--limit=<n>]
 *     wp workflow-captain requeue <event-id>
 *     wp workflow-captain migrate
 *     wp workflow-captain diagnostics
 *     wp workflow-captain verify
 *
 * Read-only except process, requeue and migrate; requeue is the same human decision as in the admin (7.4).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Cli;

use WorkflowCaptain\Install;
use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

final class Command {

	/**
	 * Shows versions, schema state, adapters and counts per state.
	 */
	public function status(): void {
		$report = Plugin::instance()->diagnostics();
		\WP_CLI::line( 'Plugin ' . WORKFLOW_CAPTAIN_VERSION . ', schema ' . (string) get_option( Install::DB_OPTION ) . ' (expected ' . WORKFLOW_CAPTAIN_DB_VERSION . '), adapter interface ' . WORKFLOW_CAPTAIN_CONTRACT_VERSION );
		\WP_CLI::line( 'Adapters: ' . ( implode( ', ', $report['adapters']['registered'] ) ?: 'none' ) );
		foreach ( array( 'events', 'runs' ) as $group ) {
			$parts = array();
			foreach ( $report[ $group ] as $state => $count ) {
				$parts[] = $state . '=' . $count;
			}
			\WP_CLI::line( ucfirst( $group ) . ': ' . implode( ' ', $parts ) );
		}
	}

	/**
	 * Processes due events and steps now (for system cron on weakly visited sites).
	 *
	 * [--limit=<n>]
	 * : Events and steps per pass. Default 100.
	 */
	public function process( array $args, array $assoc ): void {
		$limit  = max( 1, min( 1000, (int) ( $assoc['limit'] ?? 100 ) ) );
		$plugin = Plugin::instance();
		$events = $plugin->consumer()->process( $limit );
		$steps  = $plugin->engine()->process( $limit );
		\WP_CLI::success( sprintf( '%d events, %d steps processed.', $events, $steps ) );
	}

	/**
	 * Puts a dead_letter or paused event back into the queue.
	 *
	 * <event-id>
	 * : Number of the stored event (admin list "Ereignisse").
	 */
	public function requeue( array $args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( $id > 0 && Plugin::instance()->consumer()->requeue( $id ) ) {
			\WP_CLI::success( 'Requeued.' );
			return;
		}
		\WP_CLI::error( 'Not requeued: only dead_letter or paused events can be requeued.' );
	}

	/**
	 * Creates or updates the tables of this site.
	 */
	public function migrate(): void {
		if ( Install::SCHEMA_NEWER === Install::schema_state( get_option( Install::DB_OPTION ), WORKFLOW_CAPTAIN_DB_VERSION ) ) {
			\WP_CLI::error( 'The stored schema is newer than this plugin (downgrade). Install the newer version; nothing was changed.' );
		}
		Install::create_tables();
		\WP_CLI::success( 'Schema ' . WORKFLOW_CAPTAIN_DB_VERSION . ' in place.' );
	}

	/**
	 * Checks references after a backup restore (runs, steps, versions, workflows). Reports only, changes nothing.
	 */
	public function verify(): void {
		$problems = Plugin::instance()->engine()->store()->consistency();
		$found    = false;
		foreach ( $problems as $kind => $ids ) {
			if ( array() !== $ids ) {
				$found = true;
				\WP_CLI::warning( $kind . ': ' . implode( ', ', $ids ) );
			}
		}
		if ( ! $found ) {
			\WP_CLI::success( 'No inconsistencies found.' );
		}
	}

	/**
	 * Prints the support diagnostics as JSON (no personal data, no secrets).
	 */
	public function diagnostics(): void {
		\WP_CLI::line( (string) wp_json_encode( Plugin::instance()->diagnostics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
