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
			'cron:ok'                    => __( 'Der Worker von Workflow Captain ist geplant.', 'workflow-captain' ),
			'cron:not_scheduled'         => __( 'Der Worker von Workflow Captain ist nicht geplant. Plugin deaktivieren und wieder aktivieren oder wp workflow-captain process per System-Cron aufrufen.', 'workflow-captain' ),
			'cron:overdue'               => __( 'Der Worker von Workflow Captain ist überfällig: WP-Cron läuft nicht zuverlässig. Einen System-Cron einrichten.', 'workflow-captain' ),
			'cron:system_cron_needed'    => __( 'WP-Cron ist abgeschaltet. Ein System-Cron muss wp-cron.php oder wp workflow-captain process jede Minute aufrufen.', 'workflow-captain' ),
			'schema:ok'                  => __( 'Die Tabellen von Workflow Captain sind aktuell.', 'workflow-captain' ),
			'schema:newer'               => __( 'Die Datenbank stammt von einer neueren Version von Workflow Captain; die Verarbeitung ist angehalten.', 'workflow-captain' ),
			'attention:ok'               => __( 'Keine fehlgeschlagenen Ereignisse oder Läufe, die eine Entscheidung brauchen.', 'workflow-captain' ),
			'attention:needs_attention'  => __( 'Es gibt fehlgeschlagene Ereignisse, hängende Schritte oder Läufe, die eine Entscheidung brauchen (Workflow Captain → Ereignisse und Läufe).', 'workflow-captain' ),
			'secret_key:ok'              => __( 'Ein eigener Schlüssel für Geheimnisse ist gesetzt.', 'workflow-captain' ),
			'pause:ok'                   => __( 'Die Notfallpause von Workflow Captain ist nicht aktiv.', 'workflow-captain' ),
			'pause:active'               => __( 'Die Notfallpause von Workflow Captain ist aktiv: Kein Schritt wird ausgeführt.', 'workflow-captain' ),
			'database:ok'                => __( 'Der Datenbankserver erfüllt die Mindestversion von Workflow Captain (MySQL 9.7 oder MariaDB 12.3).', 'workflow-captain' ),
			'database:unknown'           => __( 'Die Version des Datenbankservers ließ sich nicht lesen.', 'workflow-captain' ),
			'database:old_server'        => __( 'Der Datenbankserver ist älter als MySQL 9.7 bzw. MariaDB 12.3. Workflow Captain arbeitet weiter, ältere Server werden aber nicht mehr getestet. Empfohlen: beim Hoster ein Update anfragen.', 'workflow-captain' ),
			'secret_key:salts'           => __( 'Kein eigener Schlüssel gesetzt; Geheimnisse hängen an den WordPress-Salts. Empfohlen: WORKFLOW_CAPTAIN_SECRET_KEY in wp-config.php.', 'workflow-captain' ),
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
