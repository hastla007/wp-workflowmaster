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
 *     wp workflow-captain pause <on|off>
 *     wp workflow-captain export <id> | import <file>
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
	 * Processes due schedule triggers, events and steps now (for system cron on weakly visited sites).
	 *
	 * [--limit=<n>]
	 * : Events and steps per pass. Default 100.
	 */
	public function process( array $args, array $assoc ): void {
		$limit = max( 1, min( 1000, (int) ( $assoc['limit'] ?? 100 ) ) );
		// The same pass as the scheduled worker, schedule triggers included (it replaces WP-Cron, docs/RUNBOOK.md).
		$done = Plugin::instance()->work( $limit );
		if ( null === $done ) {
			\WP_CLI::error( 'The stored schema does not match this plugin version; run "wp workflow-captain migrate" (or install the newer version after a downgrade). Nothing was processed.' );
		}
		\WP_CLI::success( sprintf( '%d events, %d steps processed.', $done[0], $done[1] ) );
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
	 * Checks events that are not done against their provider (WF-CORE-10). Read only unless --requeue is given.
	 *
	 * ## OPTIONS
	 *
	 * [--requeue]
	 * : Put failed or paused events that still hold back into the queue.
	 */
	public function reconcile( array $args, array $assoc ): void {
		$p = Plugin::instance();
		$r = ( new \WorkflowCaptain\Consumer\Reconcile( $p->store(), $p->adapters(), $p->consumer() ) )->run( isset( $assoc['requeue'] ) );
		\WP_CLI::line( sprintf( 'checked %d: %d still hold, %d changed at the provider, %d without adapter, %d errors, %d requeued', $r['checked'], $r['holds'], $r['changed'], $r['no_adapter'], $r['error'], $r['requeued'] ) );
		if ( array() !== $r['changed_ids'] ) {
			\WP_CLI::line( 'Changed at the provider (decide by hand): ' . implode( ', ', array_slice( $r['changed_ids'], 0, 50 ) ) );
		}
		\WP_CLI::success( 'Done.' );
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
	 * Exports a workflow as JSON.
	 *
	 * <workflow-id>
	 * : Workflow to export.
	 */
	public function export( array $args ): void {
		$wf = Plugin::instance()->engine()->store()->get_workflow( (int) ( $args[0] ?? 0 ) );
		if ( null === $wf ) {
			\WP_CLI::error( 'Workflow not found.' );
		}
		\WP_CLI::line( (string) wp_json_encode( \WorkflowCaptain\Engine\Transfer::export( $wf, WORKFLOW_CAPTAIN_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Imports a workflow file as a new draft (never published).
	 *
	 * <file>
	 * : JSON file from export.
	 */
	public function import( array $args ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( 'File not readable.' );
		}
		$result = \WorkflowCaptain\Engine\Transfer::import( (string) file_get_contents( $file ), Plugin::instance()->workflows() );
		if ( ! $result['ok'] ) {
			\WP_CLI::error( implode( ', ', $result['violations'] ) );
		}
		foreach ( $result['violations'] as $v ) {
			\WP_CLI::warning( 'Before publishing: ' . $v );
		}
		\WP_CLI::success( 'Imported as draft ' . $result['id'] . '.' );
	}

	/**
	 * Sets or lifts the global emergency pause (no step runs while it is set).
	 *
	 * <on|off>
	 * : on = pause, off = lift.
	 */
	public function pause( array $args ): void {
		$on = 'on' === ( $args[0] ?? '' );
		\WorkflowCaptain\EmergencyPause::set( $on, 0 );
		\WP_CLI::success( $on ? 'Emergency pause on.' : 'Emergency pause off.' );
	}

	/**
	 * Re-encrypts stored secrets after a key change (WF-SEC-02). Counts only, never values.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only count what would be re-encrypted.
	 */
	public function secrets( array $args, array $assoc ): void {
		$vault = new \WorkflowCaptain\Security\Vault();
		if ( ! $vault->available() ) {
			\WP_CLI::error( 'No key available (WORKFLOW_CAPTAIN_SECRET_KEY or salts) or sodium missing.' );
		}
		$result = \WorkflowCaptain\Security\SecretStores::rekey( $vault, \WorkflowCaptain\Security\SecretStores::registered(), isset( $assoc['dry-run'] ) );
		if ( array() === $result ) {
			\WP_CLI::success( 'No secret stores registered.' );
			return;
		}
		foreach ( $result as $name => $c ) {
			\WP_CLI::line( sprintf( '%s: %d current, %d %s, %d unreadable', $name, $c['current'], $c['rekeyed'], isset( $assoc['dry-run'] ) ? 'to re-encrypt' : 're-encrypted', $c['unreadable'] ) );
		}
		\WP_CLI::success( 'Done. Unreadable values need the old key (WORKFLOW_CAPTAIN_SECRET_KEY_PREVIOUS) or must be entered again.' );
	}

	/**
	 * Prints the support diagnostics as JSON (no personal data, no secrets).
	 */
	public function diagnostics(): void {
		\WP_CLI::line( (string) wp_json_encode( Plugin::instance()->diagnostics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
