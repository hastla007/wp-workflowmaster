<?php
/**
 * Start page "Workflow Captain → Übersicht" (konzept 19.x dashboard): what runs, what needs a decision, whether the
 * worker runs. Counts and links only, no event content.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

final class OverviewPage {

	const SLUG = 'workflow-captain';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 5 );
	}

	public function menu(): void {
		add_menu_page( __( 'Workflow Captain', 'workflow-captain' ), __( 'Workflow Captain', 'workflow-captain' ), 'view_workflow_logs', self::SLUG, array( $this, 'render' ), 'dashicons-randomize', 58 );
		add_submenu_page( self::SLUG, __( 'Übersicht', 'workflow-captain' ), __( 'Übersicht', 'workflow-captain' ), 'view_workflow_logs', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Cards of the overview from the diagnostics: label, number, tone (ok, warn, bad) and target page.
	 *
	 * @return array<int,array{label:string,value:int|string,tone:string,page:string,args:array}>
	 */
	public static function cards( array $d, ?int $runs_30 = null ): array {
		$health = SiteHealth::evaluate( $d, time() );
		$wait   = (int) ( $d['runs']['action_required'] ?? 0 ) + (int) ( $d['runs']['paused'] ?? 0 );
		$dead   = (int) ( $d['events']['dead_letter'] ?? 0 );
		$stuck  = (int) ( $d['stuck']['expired_leases'] ?? 0 ) + (int) ( $d['stuck']['runs_without_steps'] ?? 0 );
		$cards = array(
			array( 'label' => __( 'Aktive Workflows', 'workflow-captain' ), 'value' => (int) ( $d['workflows']['active'] ?? 0 ), 'tone' => 'ok', 'page' => WorkflowsPage::SLUG_WORKFLOWS, 'args' => array() ),
			array( 'label' => __( 'Laufende Läufe', 'workflow-captain' ), 'value' => (int) ( $d['runs']['running'] ?? 0 ), 'tone' => 'ok', 'page' => WorkflowsPage::SLUG_RUNS, 'args' => array( 'state' => EngineStore::RUN_RUNNING ) ),
			array( 'label' => __( 'Brauchen eine Entscheidung', 'workflow-captain' ), 'value' => $wait, 'tone' => $wait > 0 ? 'warn' : 'ok', 'page' => WorkflowsPage::SLUG_RUNS, 'args' => array( 'state' => EngineStore::RUN_ACTION_REQUIRED ) ),
			array( 'label' => __( 'Fehlgeschlagene Ereignisse', 'workflow-captain' ), 'value' => $dead, 'tone' => $dead > 0 ? 'warn' : 'ok', 'page' => EventsPage::SLUG, 'args' => array( 'state' => 'dead_letter' ) ),
			array( 'label' => __( 'Hängende Schritte und Läufe', 'workflow-captain' ), 'value' => $stuck, 'tone' => $stuck > 0 ? 'bad' : 'ok', 'page' => SettingsPage::SLUG, 'args' => array( 'tab' => 'status' ) ),
			array( 'label' => __( 'Worker', 'workflow-captain' ), 'value' => 'good' === $health['cron']['status'] ? __( 'läuft', 'workflow-captain' ) : __( 'prüfen', 'workflow-captain' ), 'tone' => 'good' === $health['cron']['status'] ? 'ok' : ( 'critical' === $health['cron']['status'] ? 'bad' : 'warn' ), 'page' => SettingsPage::SLUG, 'args' => array( 'tab' => 'status' ) ),
		);
		if ( null !== $runs_30 ) {
			// Runs started in the last 30 days (dashboard.svg "Läufe · 30 Tage"), linked to the run list for that range.
			array_splice( $cards, 1, 0, array( array( 'label' => __( 'Läufe · 30 Tage', 'workflow-captain' ), 'value' => $runs_30, 'tone' => 'ok', 'page' => WorkflowsPage::SLUG_RUNS, 'args' => array( 'period' => '30' ) ) ) );
		}
		return $cards;
	}

	/**
	 * Provider status for the overview (dashboard.svg "Providerstatus"): one entry per product of the provider page
	 * with label, state text, tone and how many event types and actions it brings.
	 *
	 * @param array<int,array<string,mixed>> $rows    ProvidersPage::rows().
	 * @param array<string,mixed>            $sources Provenance::sources().
	 * @return array<int,array{product:string,label:string,state:string,tone:string,events:int,actions:int}>
	 */
	public static function provider_status( array $rows, array $sources ): array {
		$actions = array_count_values( array_map( 'strval', (array) ( $sources['actions'] ?? array() ) ) );
		$states  = array(
			'registered' => array( __( 'angemeldet', 'workflow-captain' ), 'ok' ),
			'refused'    => array( __( 'abgelehnt', 'workflow-captain' ), 'bad' ),
			'missing'    => array( __( 'kein Adapter', 'workflow-captain' ), 'warn' ),
		);
		$out = array();
		foreach ( $rows as $r ) {
			$state = $states[ $r['status'] ] ?? array( (string) $r['status'], 'warn' );
			$out[] = array(
				'product' => (string) $r['product'],
				'label'   => (string) ( $sources['products'][ $r['product'] ]['label'] ?? Provenance::label( (string) $r['product'] ) ),
				'state'   => $state[0],
				'tone'    => $state[1],
				'events'  => count( (array) $r['types'] ),
				'actions' => (int) ( $actions[ (string) $r['product'] ] ?? 0 ),
			);
		}
		return $out;
	}

	/** Provider status as a table with caption; the state is text, the colour only repeats it. */
	public static function provider_table( array $status ): string {
		$colors = array( 'ok' => '#00a32a', 'warn' => '#996800', 'bad' => '#b32d2e' );
		$html   = '<table class="widefat striped" tabindex="0" style="max-width:720px"><caption class="screen-reader-text">' . esc_html__( 'Providerstatus', 'workflow-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Provider', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Adapter', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Auslöser und Aktionen', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $status as $p ) {
			// Singular and plural per part ("1 Ereignistyp", E2E third run: "1 Ereignistypen").
			/* translators: %d: number of event types */
			$counts = sprintf( _n( '%d Ereignistyp', '%d Ereignistypen', $p['events'], 'workflow-captain' ), $p['events'] ) . ' · ';
			/* translators: %d: number of actions */
			$counts .= sprintf( _n( '%d Aktion', '%d Aktionen', $p['actions'], 'workflow-captain' ), $p['actions'] );
			$html  .= '<tr><th scope="row">' . esc_html( $p['label'] ) . '</th><td><span aria-hidden="true" style="display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;background:' . esc_attr( $colors[ $p['tone'] ] ?? '#996800' ) . '"></span>' . esc_html( $p['state'] ) . '</td><td>' . esc_html( $counts ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	public function render(): void {
		if ( ! current_user_can( 'view_workflow_logs' ) ) {
			return;
		}
		$d      = $this->plugin->diagnostics();
		$store  = $this->plugin->engine()->store();
		$runs30 = $store->count_runs( array( 'since' => WorkflowsPage::error_since( time() ) ) );
		$colors = array( 'ok' => '#00a32a', 'warn' => '#996800', 'bad' => '#b32d2e' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Workflow Captain', 'workflow-captain' ) . '</h1>';
		if ( array() === $d['adapters']['registered'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Noch kein Adapter angemeldet. Workflows für die Captain-Produkte lassen sich veröffentlichen, sobald ein Pro-Add-on seinen Adapter anmeldet.', 'workflow-captain' ) . '</p></div>';
		}
		echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0">';
		foreach ( self::cards( $d, $runs30 ) as $card ) {
			$url = add_query_arg( array( 'page' => $card['page'] ) + $card['args'], admin_url( 'admin.php' ) );
			echo '<a href="' . esc_url( $url ) . '" style="flex:1 1 200px;max-width:260px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid ' . esc_attr( $colors[ $card['tone'] ] ) . ';padding:12px 16px;text-decoration:none;color:#1d2327">';
			echo '<span style="display:block;font-size:13px;color:#50575e">' . esc_html( $card['label'] ) . '</span><strong style="font-size:24px">' . esc_html( (string) $card['value'] ) . '</strong></a>';
		}
		echo '</div>';
		if ( current_user_can( 'manage_workflow_captain' ) ) {
			$pause = \WorkflowCaptain\EmergencyPause::state();
			$open  = (int) ( $d['runs']['running'] ?? 0 );
			echo '<h2>' . esc_html__( 'Notfallpause', 'workflow-captain' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="workflow_captain_pause">';
			wp_nonce_field( 'workflow_captain_pause' );
			if ( $pause['active'] ) {
				/* translators: %s: time in UTC */
				echo '<p>' . esc_html( sprintf( __( 'Aktiv seit %s (UTC). Kein Schritt wird ausgeführt; Ereignisse werden weiter gespeichert.', 'workflow-captain' ), (string) $pause['since'] ) ) . '</p>';
				echo '<button class="button button-primary" name="pause" value="0">' . esc_html__( 'Notfallpause aufheben', 'workflow-captain' ) . '</button>';
			} else {
				/* translators: %d: number of running runs */
				echo '<p>' . esc_html( sprintf( __( 'Hält sofort alle Schritte an. Betroffen: %d laufende Läufe. Überfällige Schritte brauchen danach eine Entscheidung.', 'workflow-captain' ), $open ) ) . '</p>';
				$ask = isset( $_GET['pause_confirm'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
				echo '<label class="wfc-confirm"><input type="checkbox" name="confirm" value="1"' . ( $ask ? ' autofocus' : '' ) . '> ' . esc_html__( 'Ja, alle Abläufe anhalten', 'workflow-captain' ) . '</label><br>';
				echo '<button class="button" name="pause" value="1">' . esc_html__( 'Notfallpause aktivieren', 'workflow-captain' ) . '</button>';
			}
			echo '</form>';
		}
		$status = self::provider_status( ProvidersPage::rows( $this->plugin->adapters(), $this->plugin->store(), $store ), Provenance::sources( $this->plugin->adapters(), array_keys( $this->plugin->engine()->actions()->all() ), array_keys( $this->plugin->connectors()->all() ) ) );
		echo '<h2>' . esc_html__( 'Providerstatus', 'workflow-captain' ) . '</h2>';
		if ( array() === $status ) {
			echo '<p>' . esc_html__( 'Noch kein Provider bekannt.', 'workflow-captain' ) . '</p>';
		} else {
			echo self::provider_table( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- provider_table() escapes.
		}
		echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => ProvidersPage::SLUG ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Alle Angaben auf der Seite „Provider“', 'workflow-captain' ) . '</a></p>';
		$runs = $store->find_runs( array( 'state' => EngineStore::RUN_ACTION_REQUIRED ), 10, 0 );
		if ( array() !== $runs ) {
			echo '<h2>' . esc_html__( 'Läufe, die eine Entscheidung brauchen', 'workflow-captain' ) . '</h2><ul style="list-style:disc;margin-left:2em">';
			foreach ( $runs as $run ) {
				echo '<li><a href="' . esc_url( add_query_arg( array( 'page' => WorkflowsPage::SLUG_RUNS, 'run' => $run['id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( sprintf( /* translators: 1: run number, 2: reason code */ __( 'Lauf %1$d – %2$s', 'workflow-captain' ), $run['id'], (string) $run['last_error'] ) ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}
}
