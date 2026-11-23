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
 *     wp workflow-captain settings <list|get|set|export|import|reset|undo> (SET-WC-12)
 *     wp workflow-captain settings profile <DE|AT|CH|EU|OTHER> [--apply=<keys>] [--dry-run] (SET-WC-17)
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
	 * : Events and steps per pass. Default: the setting "Events and steps per worker pass" (batch, 25 out of the box;
	 * SET-WC-36, decided 23.11.2026). Before 0.4 the default was a fixed 100; set --limit=100 to keep that.
	 */
	public function process( array $args, array $assoc ): void {
		$limit = max( 1, min( 1000, (int) ( $assoc['limit'] ?? \WorkflowCaptain\Settings::get()['batch'] ) ) );
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
			\WorkflowCaptain\Audit::record( 'requeue', array( 'event' => $id, 'via' => 'wp-cli' ) );
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
		if ( $r['requeued'] > 0 ) {
			\WorkflowCaptain\Audit::record( 'requeue', array( 'events' => $r['requeued'], 'via' => 'wp-cli reconcile' ) );
		}
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
		$r = Install::upgrade();
		if ( \WorkflowCaptain\Migrator::NEWER === $r['state'] ) {
			\WP_CLI::error( 'The stored schema is newer than this plugin (downgrade). Install the newer version; nothing was changed.' );
		}
		if ( \WorkflowCaptain\Migrator::BUSY === $r['state'] ) {
			\WP_CLI::error( 'Another request is upgrading this site right now. Try again in a few minutes; an upgrade that stopped half-way is taken over after ' . \WorkflowCaptain\Migrator::STALE_SECONDS . ' seconds.' );
		}
		if ( \WorkflowCaptain\Migrator::CURRENT === $r['state'] ) {
			Install::ensure_tables(); // Completes missing tables, e.g. after a partial restore.
			// The plugin upgraded already while WP-CLI loaded it; report what that upgrade did.
			$r = Install::last_done() ?? $r;
		}
		if ( \WorkflowCaptain\Migrator::DONE === $r['state'] ) {
			\WP_CLI::line( sprintf( 'Upgraded from schema %s to %s.', '' === $r['from'] ? '-' : $r['from'], $r['to'] ) );
		}
		if ( $r['resumed'] ) {
			\WP_CLI::warning( 'An earlier upgrade had stopped half-way; it was repeated.' );
		}
		if ( array() !== $r['steps'] ) {
			\WP_CLI::line( 'Data steps run: ' . implode( ', ', $r['steps'] ) );
		}
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

	/**
	 * Reads and changes the settings with the same checks as the settings page (SET-WC-12). Every change is recorded
	 * in the audit log as System/WP-CLI and can be undone once with `undo`.
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : list | get <key> | set <key> <value> | export [<file>] | import <file> [--dry-run] | reset [--tab=<tab>] | undo | profile <DE|AT|CH|EU|OTHER> [--apply=<keys>] [--dry-run]
	 *
	 * [<key>]
	 * : Field of the register (see `list`); for export and import the file.
	 *
	 * [<value>]
	 * : New value. Lists (kinds) comma separated.
	 *
	 * [--tab=<tab>]
	 * : For reset: execution, notify or privacy. Without: all fields.
	 *
	 * [--dry-run]
	 * : For import and profile: show the changes, write nothing.
	 *
	 * [--apply=<keys>]
	 * : For profile: comma-separated keys of the proposal to take over. Without: only the proposal is shown.
	 *
	 * [--yes]
	 * : For reset: no confirmation.
	 *
	 * [--confirm=<word>]
	 * : For set delete_data_on_uninstall 1: the word LÖSCHEN (or DELETE), as in the settings form.
	 */
	public function settings( array $args, array $assoc ): void {
		$command = (string) ( $args[0] ?? 'list' );
		$current = \WorkflowCaptain\SettingsTransfer::current();
		$show    = static fn( $v ): string => is_array( $v ) ? implode( ',', $v ) : (string) $v;
		switch ( $command ) {
			case 'list':
				$rows = array();
				foreach ( array_keys( \WorkflowCaptain\SettingsRegistry::SPEC ) as $key ) {
					$f      = \WorkflowCaptain\SettingsRegistry::field( $key );
					$eff    = $show( \WorkflowCaptain\SettingsRegistry::filtered( $key, $current[ $key ] ) );
					$rows[] = array(
						'key'     => $key,
						'value'   => $show( $current[ $key ] ),
						'filter'  => $eff === $show( $current[ $key ] ) ? '' : $eff, // Value in effect when a filter overrides the stored one.
						'default' => $show( $f['default'] ),
						'range'   => in_array( $f['type'], array( 'int' ), true ) ? $f['min'] . '-' . $f['max'] : $f['type'],
						'tab'     => $f['tab'],
						'export'  => $f['export'] ? 'yes' : 'no',
					);
				}
				\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value', 'filter', 'default', 'range', 'tab', 'export' ) );
				return;
			case 'get':
				$key = (string) ( $args[1] ?? '' );
				if ( ! array_key_exists( $key, $current ) ) {
					\WP_CLI::error( 'Unknown setting. See: wp workflow-captain settings list' );
				}
				\WP_CLI::line( $show( $current[ $key ] ) );
				$effective = \WorkflowCaptain\SettingsRegistry::filtered( $key, $current[ $key ] );
				if ( $effective !== $current[ $key ] ) {
					// The stored value is shown; a filter overrides it (SET-WC-01), say so instead of hiding it.
					\WP_CLI::warning( sprintf( 'In effect: %s (set by the filter workflow_captain_setting_%s).', $show( $effective ), $key ) );
				}
				return;
			case 'set':
				$key   = (string) ( $args[1] ?? '' );
				$value = $args[2] ?? null;
				$f     = \WorkflowCaptain\SettingsRegistry::field( $key );
				if ( null === $f || null === $value ) {
					\WP_CLI::error( 'Usage: wp workflow-captain settings set <key> <value>' );
				}
				if ( in_array( $f['type'], array( \WorkflowCaptain\SettingsRegistry::TYPE_KINDS, \WorkflowCaptain\SettingsRegistry::TYPE_ROLES ), true ) ) {
					$value = '' === $value ? array() : array_map( 'trim', explode( ',', (string) $value ) );
				}
				if ( \WorkflowCaptain\SettingsRegistry::TYPE_DAYS === $f['type'] && preg_match( '/^\s*[1-7]\s*(,\s*[1-7]\s*)*$/', (string) $value ) ) {
					$value = array_map( 'trim', explode( ',', (string) $value ) ); // Weekdays 1 (Monday) to 7, e.g. 1,2,3,4,5; another number is the bit mask.
				}
				list( $ok, $clean, $code ) = \WorkflowCaptain\SettingsTransfer::check( $key, $value );
				if ( ! $ok ) {
					\WP_CLI::error( sprintf( '%s: %s (allowed: %s). Nothing was changed.', $key, $code, 'int' === $f['type'] ? $f['min'] . '-' . $f['max'] : $f['type'] ) );
				}
				if ( 'delete_data_on_uninstall' === $key && 1 === $clean && 1 !== \WorkflowCaptain\Settings::stored()['delete_data_on_uninstall'] && ! \WorkflowCaptain\Settings::deletion_confirmed( array( 'delete_data_on_uninstall_confirm' => $assoc['confirm'] ?? '' ) ) ) {
					\WP_CLI::error( 'Switching on delete_data_on_uninstall needs --confirm=LÖSCHEN (or DELETE). Nothing was changed.' );
				}
				$changed = \WorkflowCaptain\SettingsTransfer::apply( array( $key => $clean ), 'cli' );
				\WP_CLI::success( array() === $changed ? 'Unchanged.' : sprintf( '%s = %s', $key, $show( $clean ) ) );
				return;
			case 'export':
				$json = (string) wp_json_encode( \WorkflowCaptain\SettingsTransfer::export( $current, WORKFLOW_CAPTAIN_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				if ( isset( $args[1] ) ) {
					if ( false === file_put_contents( (string) $args[1], $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP-CLI writes the file the operator named.
						\WP_CLI::error( 'Could not write the file.' );
					}
					\WP_CLI::success( 'Settings exported (without addresses and secrets).' );
					return;
				}
				\WP_CLI::line( $json );
				return;
			case 'import':
				$file = (string) ( $args[1] ?? '' );
				if ( '' === $file || ! is_readable( $file ) ) {
					\WP_CLI::error( 'Usage: wp workflow-captain settings import <file> [--dry-run]' );
				}
				$parsed = \WorkflowCaptain\SettingsTransfer::parse( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file named by the operator.
				foreach ( $parsed['errors'] as $key => $code ) {
					\WP_CLI::warning( $key . ': ' . $code );
				}
				if ( array() !== $parsed['errors'] ) {
					\WP_CLI::error( 'The file has errors. Nothing was changed.' );
				}
				foreach ( $parsed['ignored'] as $key ) {
					\WP_CLI::line( 'Ignored: ' . $key );
				}
				$diff = \WorkflowCaptain\SettingsTransfer::diff( $current, $parsed['values'] );
				foreach ( $diff as $key => $pair ) {
					\WP_CLI::line( sprintf( '%s: %s -> %s', $key, $show( $pair[0] ), $show( $pair[1] ) ) );
				}
				if ( isset( $assoc['dry-run'] ) ) {
					\WP_CLI::success( sprintf( '%d changes (dry run, nothing written).', count( $diff ) ) );
					return;
				}
				\WorkflowCaptain\SettingsTransfer::apply( $parsed['values'], 'import' );
				\WP_CLI::success( sprintf( '%d changes imported. Undo: wp workflow-captain settings undo', count( $diff ) ) );
				return;
			case 'reset':
				$tab = isset( $assoc['tab'] ) ? (string) $assoc['tab'] : null;
				if ( null !== $tab && array() === \WorkflowCaptain\SettingsRegistry::tab( $tab ) ) {
					\WP_CLI::error( 'Unknown tab. Use execution, notify or privacy.' );
				}
				\WP_CLI::confirm( null === $tab ? 'Reset all settings to their defaults?' : sprintf( 'Reset the settings of tab %s?', $tab ), $assoc );
				$changed = \WorkflowCaptain\SettingsTransfer::apply( \WorkflowCaptain\SettingsTransfer::reset_values( $tab ), 'reset' );
				\WP_CLI::success( sprintf( '%d settings reset. Undo: wp workflow-captain settings undo', count( $changed ) ) );
				return;
			case 'undo':
				$changed = \WorkflowCaptain\SettingsTransfer::undo();
				if ( null === $changed ) {
					\WP_CLI::error( 'Nothing to undo (no backup of the last 30 days).' );
				}
				\WP_CLI::success( sprintf( '%d settings restored.', count( $changed ) ) );
				return;
			case 'profile':
				// A country profile only proposes (SET-WC-17, S8/S9): without --apply, or with --dry-run, nothing is written.
				$profile = strtoupper( (string) ( $args[1] ?? '' ) );
				if ( ! \WorkflowCaptain\CountryProfiles::exists( $profile ) ) {
					\WP_CLI::error( 'Usage: wp workflow-captain settings profile <' . implode( '|', \WorkflowCaptain\CountryProfiles::keys() ) . '> [--apply=<keys>] [--dry-run]' );
				}
				$diff = \WorkflowCaptain\CountryProfiles::diff( $profile, $current );
				foreach ( $diff as $key => $row ) {
					\WP_CLI::line( sprintf( '%s: %s -> %s%s', $key, $show( $row[0] ), $show( $row[1] ), $row[2] ? '' : '  [unchecked - legal review]' ) );
				}
				$selected = isset( $assoc['apply'] ) ? array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc['apply'] ) ) ) ) : array();
				if ( array() === $selected || isset( $assoc['dry-run'] ) ) {
					list( , $errors ) = \WorkflowCaptain\CountryProfiles::selection( $profile, $selected, $current );
					foreach ( $errors as $key => $code ) {
						\WP_CLI::warning( $key . ': ' . $code );
					}
					\WP_CLI::success( sprintf( '%d proposed changes (dry run, nothing written). Apply: --apply=<key1,key2>', count( $diff ) ) );
					return;
				}
				foreach ( $selected as $key ) {
					if ( isset( $diff[ $key ] ) && ! $diff[ $key ][2] ) {
						\WP_CLI::warning( sprintf( '%s has not been legally reviewed yet.', $key ) );
					}
				}
				list( $changed, $errors ) = \WorkflowCaptain\CountryProfiles::apply( $profile, $selected );
				foreach ( $errors as $key => $code ) {
					\WP_CLI::warning( $key . ': ' . $code );
				}
				if ( array() !== $errors ) {
					\WP_CLI::error( 'Nothing was changed.' );
				}
				\WP_CLI::success( sprintf( '%d settings of profile %s applied. Undo: wp workflow-captain settings undo', count( $changed ), $profile ) );
				return;
		}
		\WP_CLI::error( 'Unknown command. Use list, get, set, export, import, reset, undo or profile.' );
	}

}
