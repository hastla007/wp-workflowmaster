<?php
/**
 * Admin page "Einstellungen" with the tabs Ausführung, Datenschutz and Status (konzept 8, 19.14).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Install;
use WorkflowCaptain\Plugin;
use WorkflowCaptain\Security\Vault;
use WorkflowCaptain\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	const SLUG = 'workflow-captain-settings';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	public function register_setting(): void {
		register_setting( 'workflow_captain', Settings::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( Settings::class, 'sanitize' ), 'default' => Settings::defaults() ) );
	}

	public function menu(): void {
		add_submenu_page( EventsPage::SLUG, __( 'Einstellungen', 'workflow-captain' ), __( 'Einstellungen', 'workflow-captain' ), 'manage_workflow_captain', self::SLUG, array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'execution'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch only.
		$tabs = array(
			'execution' => __( 'Ausführung', 'workflow-captain' ),
			'privacy'   => __( 'Datenschutz & Aufbewahrung', 'workflow-captain' ),
			'status'    => __( 'Status', 'workflow-captain' ),
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'execution';
		echo '<div class="wrap"><h1>' . esc_html__( 'Einstellungen', 'workflow-captain' ) . '</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'status' === $tab ) {
			$this->status();
			echo '</div>';
			return;
		}
		$s      = Settings::get();
		$fields = 'execution' === $tab
			? array(
				'batch'          => array( __( 'Ereignisse und Schritte je Worker-Durchlauf', 'workflow-captain' ), '' ),
				'max_site'       => array( __( 'Höchstens gleichzeitig laufende Schritte auf dieser Website', 'workflow-captain' ), '' ),
				'max_workflow'   => array( __( 'Höchstens gleichzeitig laufende Schritte je Workflow', 'workflow-captain' ), '' ),
				'catch_up_hours' => array( __( 'Nachholgrenze in Stunden', 'workflow-captain' ), __( 'Ein Schritt, der länger überfällig ist (zum Beispiel weil WP-Cron nicht lief), wird nicht von selbst ausgeführt, sondern zur Entscheidung angezeigt.', 'workflow-captain' ) ),
			)
			: array(
				'retention_finished' => array( __( 'Erledigte Ereignisse und Läufe löschen nach (Tagen)', 'workflow-captain' ), __( 'Höchstens 30 Tage; nur kürzer möglich.', 'workflow-captain' ) ),
				'retention_stuck'    => array( __( 'Fehlgeschlagene und pausierte Ereignisse löschen nach (Tagen)', 'workflow-captain' ), __( 'Höchstens 90 Tage; nur kürzer möglich. Die Fristen sind ein Vorschlag bis zur Rechtsprüfung.', 'workflow-captain' ) ),
			);
		echo '<form method="post" action="options.php">';
		settings_fields( 'workflow_captain' );
		// Keep the values of the other tab: the option holds all fields.
		foreach ( $s as $key => $value ) {
			if ( ! isset( $fields[ $key ] ) ) {
				echo '<input type="hidden" name="' . esc_attr( Settings::OPTION . '[' . $key . ']' ) . '" value="' . (int) $value . '">';
			}
		}
		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $key => list( $label, $help ) ) {
			list( , $min, $max ) = Settings::FIELDS[ $key ];
			$id                  = 'wfc-' . $key;
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input id="' . esc_attr( $id ) . '" type="number" min="' . (int) $min . '" max="' . (int) $max . '" name="' . esc_attr( Settings::OPTION . '[' . $key . ']' ) . '" value="' . (int) $s[ $key ] . '" class="small-text">';
			if ( '' !== $help ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	private function status(): void {
		$d    = $this->plugin->diagnostics();
		$env  = $d['environment'];
		$rows = array(
			__( 'Zeitzone der Website', 'workflow-captain' )          => wp_timezone_string(),
			__( 'Datenbankschema', 'workflow-captain' )                => $env['schema'] . ' / ' . $env['schema_expected'],
			__( 'Nächster Worker-Lauf (UTC)', 'workflow-captain' )     => (string) ( $env['next_worker_run'] ?? __( 'nicht geplant', 'workflow-captain' ) ),
			__( 'WP-Cron abgeschaltet', 'workflow-captain' )           => $env['wp_cron_disabled'] ? __( 'ja – System-Cron nötig: wp workflow-captain process', 'workflow-captain' ) : __( 'nein', 'workflow-captain' ),
			__( 'Eigener Schlüssel für Geheimnisse', 'workflow-captain' ) => Vault::own_key_configured() ? __( 'gesetzt', 'workflow-captain' ) : __( 'nicht gesetzt – empfohlen: WORKFLOW_CAPTAIN_SECRET_KEY in wp-config.php', 'workflow-captain' ),
			__( 'Hängende Schritte (Sperrfrist über einen Tag abgelaufen)', 'workflow-captain' ) => (string) $d['stuck']['expired_leases'],
			__( 'Läufe ohne offenen Schritt', 'workflow-captain' )     => (string) $d['stuck']['runs_without_steps'],
		);
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: option name */ __( 'Gespeichert in der Option %s.', 'workflow-captain' ), Install::DB_OPTION ) ) . '</p>';
	}
}
