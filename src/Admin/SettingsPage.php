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
		add_action( 'admin_post_workflow_captain_roles', array( $this, 'handle_roles' ) );
	}

	public function register_setting(): void {
		register_setting( 'workflow_captain', Settings::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( Settings::class, 'sanitize' ), 'default' => Settings::defaults() ) );
	}

	public function menu(): void {
		add_submenu_page( OverviewPage::SLUG, __( 'Einstellungen', 'workflow-captain' ), __( 'Einstellungen', 'workflow-captain' ), 'manage_workflow_captain', self::SLUG, array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch only.
		$tabs = array(
			'general'   => __( 'Allgemein', 'workflow-captain' ),
			'execution' => __( 'Ausführung', 'workflow-captain' ),
			'notify'    => __( 'Benachrichtigungen', 'workflow-captain' ),
			'privacy'   => __( 'Datenschutz & Aufbewahrung', 'workflow-captain' ),
			'roles'     => __( 'Rollen & Rechte', 'workflow-captain' ),
			'status'    => __( 'Status', 'workflow-captain' ),
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'general';
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
		if ( 'general' === $tab ) {
			$this->general();
			echo '</div>';
			return;
		}
		if ( 'roles' === $tab ) {
			$this->roles();
			echo '</div>';
			return;
		}
		$s      = Settings::get();
		$fields = 'notify' === $tab
			? array(
				'notify_daily' => array( __( 'Tägliche Hinweis-Mail an die Betreiber-Adresse (1 = an, 0 = aus)', 'workflow-captain' ), __( 'Kommt nur, wenn Entscheidungen warten, Ereignisse fehlgeschlagen sind, Arbeit hängt oder die Notfallpause aktiv ist. Ohne Inhalte von Ereignissen.', 'workflow-captain' ) ),
			)
			: ( 'execution' === $tab
			? array(
				'batch'          => array( __( 'Ereignisse und Schritte je Worker-Durchlauf', 'workflow-captain' ), '' ),
				'max_site'       => array( __( 'Höchstens gleichzeitig laufende Schritte auf dieser Website', 'workflow-captain' ), '' ),
				'max_workflow'   => array( __( 'Höchstens gleichzeitig laufende Schritte je Workflow', 'workflow-captain' ), '' ),
				'catch_up_hours' => array( __( 'Nachholgrenze in Stunden', 'workflow-captain' ), __( 'Ein Schritt, der länger überfällig ist (zum Beispiel weil WP-Cron nicht lief), wird nicht von selbst ausgeführt, sondern zur Entscheidung angezeigt.', 'workflow-captain' ) ),
			)
			: array(
				'retention_finished' => array( __( 'Erledigte Ereignisse löschen nach (Tagen)', 'workflow-captain' ), __( 'Höchstens 30 Tage; nur kürzer möglich.', 'workflow-captain' ) ),
				'retention_runs'     => array( __( 'Erledigte Läufe mit Schrittprotokoll löschen nach (Tagen)', 'workflow-captain' ), __( 'Höchstens 30 Tage; offene Läufe bleiben. Protokolle enthalten keine Inhalte von Nachrichten oder Ereignisdaten außer der Kopie im Lauf.', 'workflow-captain' ) ),
				'retention_stuck'    => array( __( 'Fehlgeschlagene und pausierte Ereignisse löschen nach (Tagen)', 'workflow-captain' ), __( 'Höchstens 90 Tage; nur kürzer möglich. Die Fristen sind ein Vorschlag bis zur Rechtsprüfung.', 'workflow-captain' ) ),
			) );
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

	/** Time zone and date format come from WordPress; the source is shown (WF-SET-04). */
	private function general(): void {
		$rows = array(
			__( 'Zeitzone', 'workflow-captain' )     => wp_timezone_string() . ' – ' . __( 'aus Einstellungen → Allgemein von WordPress', 'workflow-captain' ),
			__( 'Datumsformat', 'workflow-captain' ) => (string) get_option( 'date_format' ) . ' – ' . __( 'aus Einstellungen → Allgemein von WordPress', 'workflow-captain' ),
			__( 'Speicherung', 'workflow-captain' )  => __( 'Alle Zeitpunkte werden in UTC gespeichert; Wartezeiten in Tagen richten sich nach der Zeitzone der Website.', 'workflow-captain' ),
		);
		echo '<table class="form-table" role="presentation">';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</table><p><a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Zeitzone und Datumsformat in WordPress ändern', 'workflow-captain' ) . '</a></p>';
	}

	/** Capabilities per role (WF-SET-08). Administrators always keep all of them. */
	private function roles(): void {
		$labels = array(
			'view_workflow_logs'      => __( 'Läufe und Ereignisse ansehen', 'workflow-captain' ),
			'edit_workflows'          => __( 'Workflows entwerfen', 'workflow-captain' ),
			'activate_workflows'      => __( 'Workflows veröffentlichen und pausieren', 'workflow-captain' ),
			'retry_workflow_runs'     => __( 'Wiederholen, fortsetzen, abbrechen', 'workflow-captain' ),
			'manage_workflow_captain' => __( 'Einstellungen, Notfallpause, Diagnose', 'workflow-captain' ),
		);
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="workflow_captain_roles">';
		wp_nonce_field( 'workflow_captain_roles' );
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th scope="col">' . esc_html__( 'Rolle', 'workflow-captain' ) . '</th>';
		foreach ( $labels as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( wp_roles()->role_objects as $key => $role ) {
			$admin = 'administrator' === $key;
			echo '<tr><th scope="row">' . esc_html( translate_user_role( wp_roles()->role_names[ $key ] ?? $key ) ) . '</th>';
			foreach ( array_keys( $labels ) as $cap ) {
				echo '<td><input type="checkbox" name="caps[' . esc_attr( $key ) . '][]" value="' . esc_attr( $cap ) . '"' . ( $role->has_cap( $cap ) ? ' checked' : '' ) . ( $admin ? ' disabled' : '' ) . ' aria-label="' . esc_attr( $cap ) . '"></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Administratoren haben immer alle Rechte. Shop-Manager bekommen keine Rechte automatisch.', 'workflow-captain' ) . '</p>';
		submit_button();
		echo '</form>';
	}

	public function handle_roles(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) || ! current_user_can( 'promote_users' ) ) {
			wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
		}
		check_admin_referer( 'workflow_captain_roles' );
		$wanted = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? wp_unslash( $_POST['caps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys and values checked against fixed lists below.
		foreach ( wp_roles()->role_objects as $key => $role ) {
			if ( 'administrator' === $key ) {
				continue;
			}
			$given = array_intersect( (array) ( $wanted[ $key ] ?? array() ), Install::CAPS );
			foreach ( Install::CAPS as $cap ) {
				in_array( $cap, $given, true ) ? $role->add_cap( $cap ) : $role->remove_cap( $cap );
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'roles', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function status(): void {
		$d    = $this->plugin->diagnostics();
		$env  = $d['environment'];
		$rows = array(
			__( 'Zeitzone der Website', 'workflow-captain' )          => wp_timezone_string(),
			__( 'Datenbankschema', 'workflow-captain' )                => $env['schema'] . ' / ' . $env['schema_expected'],
			__( 'Nächster Worker-Lauf (UTC)', 'workflow-captain' )     => (string) ( $env['next_worker_run'] ?? __( 'nicht geplant', 'workflow-captain' ) ),
			__( 'Planung des Workers', 'workflow-captain' ) => 'action_scheduler' === ( $env['scheduler'] ?? '' ) ? __( 'Action Scheduler (von WooCommerce)', 'workflow-captain' ) : __( 'WP-Cron', 'workflow-captain' ),
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
