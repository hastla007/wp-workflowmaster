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
	public static function cards( array $d ): array {
		$health = SiteHealth::evaluate( $d, time() );
		$wait   = (int) ( $d['runs']['action_required'] ?? 0 ) + (int) ( $d['runs']['paused'] ?? 0 );
		$dead   = (int) ( $d['events']['dead_letter'] ?? 0 );
		$stuck  = (int) ( $d['stuck']['expired_leases'] ?? 0 ) + (int) ( $d['stuck']['runs_without_steps'] ?? 0 );
		return array(
			array( 'label' => __( 'Aktive Workflows', 'workflow-captain' ), 'value' => (int) ( $d['workflows']['active'] ?? 0 ), 'tone' => 'ok', 'page' => WorkflowsPage::SLUG_WORKFLOWS, 'args' => array() ),
			array( 'label' => __( 'Laufende Läufe', 'workflow-captain' ), 'value' => (int) ( $d['runs']['running'] ?? 0 ), 'tone' => 'ok', 'page' => WorkflowsPage::SLUG_RUNS, 'args' => array( 'state' => EngineStore::RUN_RUNNING ) ),
			array( 'label' => __( 'Brauchen eine Entscheidung', 'workflow-captain' ), 'value' => $wait, 'tone' => $wait > 0 ? 'warn' : 'ok', 'page' => WorkflowsPage::SLUG_RUNS, 'args' => array( 'state' => EngineStore::RUN_ACTION_REQUIRED ) ),
			array( 'label' => __( 'Fehlgeschlagene Ereignisse', 'workflow-captain' ), 'value' => $dead, 'tone' => $dead > 0 ? 'warn' : 'ok', 'page' => EventsPage::SLUG, 'args' => array( 'state' => 'dead_letter' ) ),
			array( 'label' => __( 'Hängende Schritte und Läufe', 'workflow-captain' ), 'value' => $stuck, 'tone' => $stuck > 0 ? 'bad' : 'ok', 'page' => SettingsPage::SLUG, 'args' => array( 'tab' => 'status' ) ),
			array( 'label' => __( 'Worker', 'workflow-captain' ), 'value' => 'good' === $health['cron']['status'] ? __( 'läuft', 'workflow-captain' ) : __( 'prüfen', 'workflow-captain' ), 'tone' => 'good' === $health['cron']['status'] ? 'ok' : ( 'critical' === $health['cron']['status'] ? 'bad' : 'warn' ), 'page' => SettingsPage::SLUG, 'args' => array( 'tab' => 'status' ) ),
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'view_workflow_logs' ) ) {
			return;
		}
		$d      = $this->plugin->diagnostics();
		$colors = array( 'ok' => '#00a32a', 'warn' => '#996800', 'bad' => '#b32d2e' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Workflow Captain', 'workflow-captain' ) . '</h1>';
		if ( array() === $d['adapters']['registered'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Noch kein Adapter angemeldet. Workflows für die Captain-Produkte lassen sich veröffentlichen, sobald ein Pro-Add-on seinen Adapter anmeldet.', 'workflow-captain' ) . '</p></div>';
		}
		echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0">';
		foreach ( self::cards( $d ) as $card ) {
			$url = add_query_arg( array( 'page' => $card['page'] ) + $card['args'], admin_url( 'admin.php' ) );
			echo '<a href="' . esc_url( $url ) . '" style="flex:1 1 200px;max-width:260px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid ' . esc_attr( $colors[ $card['tone'] ] ) . ';padding:12px 16px;text-decoration:none;color:#1d2327">';
			echo '<span style="display:block;font-size:13px;color:#50575e">' . esc_html( $card['label'] ) . '</span><strong style="font-size:24px">' . esc_html( (string) $card['value'] ) . '</strong></a>';
		}
		echo '</div>';
		$runs = $this->plugin->engine()->store()->find_runs( array( 'state' => EngineStore::RUN_ACTION_REQUIRED ), 10, 0 );
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
