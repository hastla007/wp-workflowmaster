<?php
/**
 * Checks in Tools → Site Health and debug information without personal data.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Install;
use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

final class SiteHealth {

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
		add_filter( 'debug_information', array( $this, 'debug' ) );
	}

	public function tests( array $tests ): array {
		foreach ( array( 'cron', 'schema', 'attention', 'secret_key', 'pause', 'database' ) as $key ) {
			$tests['direct'][ 'workflow_captain_' . $key ] = array(
				'label' => __( 'Workflow Captain', 'workflow-captain' ),
				'test'  => function () use ( $key ): array {
					return self::result( $key, self::evaluate( $this->plugin->diagnostics(), time() )[ $key ] );
				},
			);
		}
		return $tests;
	}

	/**
	 * Status per check from the diagnostics: "good", "recommended" or "critical" with a reason code.
	 *
	 * @return array<string,array{status:string,code:string}>
	 */
	public static function evaluate( array $d, int $now ): array {
		$env  = $d['environment'] ?? array();
		$next = isset( $env['next_worker_run'] ) ? strtotime( (string) $env['next_worker_run'] ) : false;
		$cron = false === $next ? array( 'critical', 'not_scheduled' ) : ( $next < $now - 10 * MINUTE_IN_SECONDS ? array( 'critical', 'overdue' ) : array( 'good', 'ok' ) );
		if ( 'good' === $cron[0] && ! empty( $env['wp_cron_disabled'] ) ) {
			$cron = array( 'recommended', 'system_cron_needed' );
		}
		$schema    = ( $env['schema_state'] ?? Install::SCHEMA_CURRENT );
		$attention = (int) ( $d['events']['dead_letter'] ?? 0 ) + (int) ( $d['runs']['action_required'] ?? 0 ) + (int) ( $d['stuck']['expired_leases'] ?? 0 ) + (int) ( $d['stuck']['runs_without_steps'] ?? 0 );
		return array(
			'cron'       => array( 'status' => $cron[0], 'code' => $cron[1] ),
			'schema'     => Install::SCHEMA_NEWER === $schema ? array( 'status' => 'critical', 'code' => 'newer' ) : array( 'status' => 'good', 'code' => 'ok' ),
			'attention'  => $attention > 0 ? array( 'status' => 'recommended', 'code' => 'needs_attention' ) : array( 'status' => 'good', 'code' => 'ok' ),
			'secret_key' => empty( $env['secret_key_own'] ) ? array( 'status' => 'recommended', 'code' => 'salts' ) : array( 'status' => 'good', 'code' => 'ok' ),
			'pause'      => ! empty( $env['emergency_pause'] ) ? array( 'status' => 'critical', 'code' => 'active' ) : array( 'status' => 'good', 'code' => 'ok' ),
			// MINVER-1: an older database server is only a recommendation, never critical and never a block.
			'database'   => \WorkflowCaptain\Requirements::database( (string) ( $env['database'] ?? '' ) ),
		);
	}

	private static function result( string $key, array $r ): array {
		$texts = array(
			'cron:ok'                    => __( 'The Workflow Captain worker is scheduled.', 'workflow-captain' ),
			'cron:not_scheduled'         => __( 'The Workflow Captain worker is not scheduled. Deactivate and reactivate the plugin, or call wp workflow-captain process via a system cron.', 'workflow-captain' ),
			'cron:overdue'               => __( 'The Workflow Captain worker is overdue: WP-Cron is not running reliably. Set up a system cron.', 'workflow-captain' ),
			'cron:system_cron_needed'    => __( 'WP-Cron is disabled. A system cron must call wp-cron.php or wp workflow-captain process every minute.', 'workflow-captain' ),
			'schema:ok'                  => __( 'The Workflow Captain tables are up to date.', 'workflow-captain' ),
			'schema:newer'               => __( 'The database comes from a newer version of Workflow Captain; processing is halted.', 'workflow-captain' ),
			'attention:ok'               => __( 'No failed events or runs that need a decision.', 'workflow-captain' ),
			'attention:needs_attention'  => __( 'There are failed events, stuck steps or runs that need a decision (Workflow Captain → Events and Runs).', 'workflow-captain' ),
			'secret_key:ok'              => __( 'A custom secret key is set.', 'workflow-captain' ),
			'pause:ok'                   => __( 'The Workflow Captain emergency pause is not active.', 'workflow-captain' ),
			'pause:active'               => __( 'The Workflow Captain emergency pause is active: no step is executed.', 'workflow-captain' ),
			'database:ok'                => __( 'Your database server meets the minimum version for Workflow Captain (MySQL 9.7 or MariaDB 12.3).', 'workflow-captain' ),
			'database:unknown'           => __( 'The database server version could not be read.', 'workflow-captain' ),
			'database:old_server'        => __( 'Your database server is older than MySQL 9.7 or MariaDB 12.3. Workflow Captain keeps working, but older servers are no longer tested. Recommended: ask your host for an update.', 'workflow-captain' ),
			'secret_key:salts'           => __( 'No key of your own set; secrets depend on the WordPress salts. Recommended: WORKFLOW_CAPTAIN_SECRET_KEY in wp-config.php.', 'workflow-captain' ),
		);
		return array(
			'label'       => $texts[ $key . ':' . $r['code'] ] ?? $key,
			'status'      => $r['status'],
			'badge'       => array( 'label' => __( 'Workflow Captain', 'workflow-captain' ), 'color' => 'good' === $r['status'] ? 'blue' : 'orange' ),
			'description' => '<p>' . esc_html( $texts[ $key . ':' . $r['code'] ] ?? '' ) . '</p>',
			'test'        => 'workflow_captain_' . $key,
		);
	}

	/** Debug information: the environment part of the diagnostics only (versions, schema, cron, counts). */
	public function debug( array $info ): array {
		$d      = $this->plugin->diagnostics();
		$fields = array();
		foreach ( $d['environment'] as $key => $value ) {
			if ( 'rejected_envelopes' === $key ) {
				continue;
			}
			$fields[ $key ] = array( 'label' => $key, 'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
		}
		$fields['adapters'] = array( 'label' => 'adapters', 'value' => implode( ', ', $d['adapters']['registered'] ) );
		$info['workflow-captain'] = array( 'label' => __( 'Workflow Captain', 'workflow-captain' ), 'fields' => $fields );
		return $info;
	}
}
