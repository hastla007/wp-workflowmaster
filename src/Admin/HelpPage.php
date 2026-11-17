<?php
/**
 * Admin page "Hilfe" (WF-SET-02): first steps, terms and where to look when something is stuck.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

defined( 'ABSPATH' ) || exit;

final class HelpPage {

	const SLUG = 'workflow-captain-help';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 90 );
	}

	public function menu(): void {
		add_submenu_page( OverviewPage::SLUG, __( 'Hilfe', 'workflow-captain' ), __( 'Hilfe', 'workflow-captain' ), 'view_workflow_logs', self::SLUG, array( $this, 'render' ) );
	}

	/** @return array<string,string[]> Heading => paragraphs. */
	public static function sections(): array {
		return array(
			__( 'Erste Schritte', 'workflow-captain' ) => array(
				__( '1. Unter „Provider“ prüfen, welche Plugins Ereignisse liefern. WordPress und WooCommerce sind eingebaut; die Captain-Produkte kommen mit Workflow Captain Pro.', 'workflow-captain' ),
				__( '2. Unter „Vorlagen“ einen Ablauf als Entwurf übernehmen oder unter „Workflows“ einen neuen anlegen.', 'workflow-captain' ),
				__( '3. Im Editor einen Testlauf mit Beispieldaten starten. Er führt nichts aus.', 'workflow-captain' ),
				__( '4. Veröffentlichen. Ab dann startet jedes passende Ereignis einen Lauf; die Version bleibt für laufende Läufe unverändert.', 'workflow-captain' ),
			),
			__( 'Begriffe', 'workflow-captain' ) => array(
				__( 'Ereignis: etwas, das in einem Plugin passiert ist, zum Beispiel „Bestellung bezahlt“. Ereignisse enthalten keine Namen, E-Mail-Adressen oder Codes.', 'workflow-captain' ),
				__( 'Lauf: eine Ausführung eines Workflows für ein Ereignis, mit Schritten.', 'workflow-captain' ),
				__( 'Entscheidung: ein Lauf hält an, wenn unklar ist, ob eine Nachricht schon hinausging, oder wenn ein Schritt zu lange überfällig ist. Ein Mensch setzt fort oder bricht ab.', 'workflow-captain' ),
			),
			__( 'Wenn etwas hängt', 'workflow-captain' ) => array(
				__( 'Werkzeuge → Website-Zustand zeigt, ob der Worker läuft. Einstellungen → Status zeigt den nächsten Lauf und hängende Schritte.', 'workflow-captain' ),
				__( 'Fehlgeschlagene Ereignisse lassen sich unter „Ereignisse“ auswählen und erneut einreihen.', 'workflow-captain' ),
				__( 'Die Notfallpause auf der Übersicht hält sofort alle Schritte an; Ereignisse werden weiter gespeichert.', 'workflow-captain' ),
				__( 'Für den Support: „Ereignisse → Diagnose herunterladen“. Die Datei enthält keine persönlichen Daten und keine Geheimnisse.', 'workflow-captain' ),
			),
			__( 'Kommandozeile', 'workflow-captain' ) => array(
				'wp workflow-captain status | process | requeue | migrate | verify | diagnostics | pause | export | import',
			),
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'view_workflow_logs' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Hilfe', 'workflow-captain' ) . '</h1>';
		foreach ( self::sections() as $heading => $paragraphs ) {
			echo '<h2>' . esc_html( $heading ) . '</h2>';
			foreach ( $paragraphs as $p ) {
				echo '<p>' . esc_html( $p ) . '</p>';
			}
		}
		echo '</div>';
	}
}
