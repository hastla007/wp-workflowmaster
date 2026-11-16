<?php
/**
 * Admin page "Einstellungen" with the tabs Ausführung, Datenschutz and Status (konzept 8, 19.14).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Audit;
use WorkflowCaptain\Install;
use WorkflowCaptain\Notifications;
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
		add_action( 'admin_post_workflow_captain_test_mail', array( $this, 'handle_test_mail' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'update_option_' . Settings::OPTION, array( Audit::class, 'settings_updated' ), 10, 2 );
	}

	/** Warning on unsaved changes and the confirmed reset of a tab (WF-SET-13). */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_script( 'workflow-captain-settings', plugins_url( 'assets/js/settings.js', WORKFLOW_CAPTAIN_FILE ), array(), WORKFLOW_CAPTAIN_VERSION, true );
		wp_add_inline_script(
			'workflow-captain-settings',
			'window.workflowCaptainSettings = ' . wp_json_encode(
				array(
					'unsaved' => __( 'Es gibt ungespeicherte Änderungen. Seite trotzdem verlassen?', 'workflow-captain' ),
					'reset'   => __( 'Alle Felder dieses Tabs auf die Standardwerte setzen? Gespeichert wird erst mit „Änderungen speichern“.', 'workflow-captain' ),
				)
			) . ';',
			'before'
		);
	}

	public function register_setting(): void {
		register_setting( 'workflow_captain', Settings::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( Settings::class, 'sanitize_and_report' ), 'default' => Settings::defaults() ) );
		// Own group: options.php resets every option of a group that is missing in the request.
		register_setting( 'workflow_captain_notify', Notifications::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( Notifications::class, 'sanitize' ), 'default' => Notifications::sanitize( array() ) ) );
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
		settings_errors( Settings::OPTION );
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
				'notify_daily' => array( __( 'Tägliche Hinweis-Mail an die Betreiber-Adresse', 'workflow-captain' ), __( 'Kommt nur, wenn Entscheidungen warten, Ereignisse fehlgeschlagen sind, Arbeit hängt oder die Notfallpause aktiv ist. Ohne Inhalte von Ereignissen.', 'workflow-captain' ) ),
			)
			: ( 'execution' === $tab
			? array(
				'batch'          => array( __( 'Ereignisse und Schritte je Worker-Durchlauf', 'workflow-captain' ), '' ),
				'max_site'       => array( __( 'Höchstens gleichzeitig laufende Schritte auf dieser Website', 'workflow-captain' ), '' ),
				'max_workflow'   => array( __( 'Höchstens gleichzeitig laufende Schritte je Workflow', 'workflow-captain' ), '' ),
				'catch_up_hours' => array( __( 'Nachholgrenze in Stunden', 'workflow-captain' ), __( 'Ein Schritt, der länger überfällig ist (zum Beispiel weil WP-Cron nicht lief), wird nicht von selbst ausgeführt, sondern zur Entscheidung angezeigt.', 'workflow-captain' ) ),
				'max_seconds'    => array( __( 'Laufzeit je Worker-Durchlauf in Sekunden', 'workflow-captain' ), __( 'Danach beginnt der Worker keinen neuen Schritt mehr; ein begonnener Schritt läuft zu Ende. Hält Seitenaufrufe kurz, wenn WP-Cron über Besucher läuft.', 'workflow-captain' ) ),
			)
			: array(
				'retention_finished' => array( __( 'Erledigte Ereignisse löschen nach (Tagen)', 'workflow-captain' ), __( 'Höchstens 30 Tage; nur kürzer möglich.', 'workflow-captain' ) ),
				'retention_runs'     => array( __( 'Erledigte Läufe mit Schrittprotokoll löschen nach (Tagen)', 'workflow-captain' ), __( 'Höchstens 30 Tage; offene Läufe bleiben. Protokolle enthalten keine Inhalte von Nachrichten oder Ereignisdaten außer der Kopie im Lauf.', 'workflow-captain' ) ),
				'retention_stuck'    => array( __( 'Fehlgeschlagene und pausierte Ereignisse löschen nach (Tagen)', 'workflow-captain' ), __( 'Höchstens 90 Tage; nur kürzer möglich. Die Fristen sind ein Vorschlag bis zur Rechtsprüfung.', 'workflow-captain' ) ),
			) );
		echo '<form method="post" action="options.php" class="wfc-settings-form">';
		settings_fields( 'workflow_captain' );
		// Keep the values of the other tab: the option holds all fields.
		foreach ( $s as $key => $value ) {
			if ( ! isset( $fields[ $key ] ) ) {
				echo '<input type="hidden" name="' . esc_attr( Settings::OPTION . '[' . $key . ']' ) . '" value="' . (int) $value . '">';
			}
		}
		echo '<table class="form-table" role="presentation">';
		$errors = array();
		foreach ( function_exists( 'get_settings_errors' ) ? get_settings_errors( Settings::OPTION ) : array() as $error ) {
			$errors[ $error['code'] ] = $error['message'];
		}
		foreach ( $fields as $key => list( $label, $help ) ) {
			list( $default, $min, $max ) = Settings::FIELDS[ $key ];
			$id                          = 'wfc-' . $key;
			$name                        = Settings::OPTION . '[' . $key . ']';
			$range                       = 0 === $min && 1 === $max ? '' : sprintf( /* translators: 1: lowest, 2: highest, 3: default value */ __( 'Erlaubt: %1$d bis %2$d. Standard: %3$d.', 'workflow-captain' ), $min, $max, $default );
			$help                        = trim( $help . ' ' . $range );
			$described                   = trim( ( '' !== $help ? $id . '-help' : '' ) . ( isset( $errors[ $id ] ) ? ' ' . $id . '-error' : '' ) );
			$aria                        = ( '' !== $described ? ' aria-describedby="' . esc_attr( $described ) . '"' : '' ) . ( isset( $errors[ $id ] ) ? ' aria-invalid="true"' : '' );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 0 === $min && 1 === $max ) {
				// A switch is a checkbox; the hidden field sends 0 when it is unchecked.
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1" data-default="' . (int) $default . '"' . ( 1 === $s[ $key ] ? ' checked' : '' ) . $aria . '>';
			} else {
				echo '<input id="' . esc_attr( $id ) . '" type="number" required min="' . (int) $min . '" max="' . (int) $max . '" name="' . esc_attr( $name ) . '" value="' . (int) $s[ $key ] . '" data-default="' . (int) $default . '" class="small-text"' . $aria . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $aria is escaped above.
			}
			if ( isset( $errors[ $id ] ) ) {
				echo '<p id="' . esc_attr( $id . '-error' ) . '" class="wfc-field-error" style="color:#b32d2e">' . esc_html( $errors[ $id ] ) . '</p>';
			}
			if ( '' !== $help ) {
				echo '<p id="' . esc_attr( $id . '-help' ) . '" class="description">' . esc_html( $help ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table><p class="submit">';
		submit_button( null, 'primary', 'submit', false );
		echo ' <button type="button" class="button wfc-reset-tab">' . esc_html__( 'Standardwerte dieses Tabs', 'workflow-captain' ) . '</button></p>';
		echo '</form>';
		if ( 'notify' === $tab ) {
			$this->notify();
		}
		if ( 'execution' === $tab ) {
			$this->execution_help();
		}
	}

	/** How the worker is scheduled and how failures are repeated (WF-SET-05). */
	private function execution_help(): void {
		$plan = array();
		foreach ( \WorkflowCaptain\Consumer\RetryPlan::DELAYS as $seconds ) {
			$plan[] = $seconds < HOUR_IN_SECONDS ? sprintf( /* translators: %d: minutes */ __( '%d Min.', 'workflow-captain' ), $seconds / MINUTE_IN_SECONDS ) : sprintf( /* translators: %d: hours */ __( '%d Std.', 'workflow-captain' ), $seconds / HOUR_IN_SECONDS );
		}
		echo '<h2>' . esc_html__( 'So läuft die Ausführung', 'workflow-captain' ) . '</h2>';
		echo '<p>' . esc_html( \WorkflowCaptain\Scheduler::ACTION_SCHEDULER === \WorkflowCaptain\Scheduler::backend() ? __( 'Der Worker läuft über Action Scheduler (von WooCommerce), jede Minute.', 'workflow-captain' ) : __( 'Der Worker läuft über WP-Cron, jede Minute. WP-Cron startet nur bei Seitenaufrufen; auf Websites mit wenig Besuch oder mit DISABLE_WP_CRON einen System-Cron einrichten: wp workflow-captain process (jede Minute).', 'workflow-captain' ) ) . '</p>';
		echo '<p>' . esc_html( sprintf( /* translators: %s: list of waiting times */ __( 'Vorübergehende Fehler werden nach festem Plan wiederholt: %s. Danach wird ein Ereignis als fehlgeschlagen angezeigt; ein Schritt braucht eine Entscheidung. Nachrichten werden nach einem unklaren Ausgang nie von selbst wiederholt.', 'workflow-captain' ), implode( ', ', $plan ) ) ) . '</p>';
		echo '<p>' . esc_html__( 'Je Workflow lässt sich im Editor nur die Serialisierung (ein Lauf je Objekt nacheinander) einstellen; die Grenzen oben gelten für alle Workflows.', 'workflow-captain' ) . '</p>';
	}

	/** Recipients, kinds and a test mail (WF-SET-06). */
	private function notify(): void {
		$c     = Notifications::config();
		$kinds = array(
			'decisions' => __( 'Läufe, die eine Entscheidung brauchen', 'workflow-captain' ),
			'paused'    => __( 'Pausierte Läufe', 'workflow-captain' ),
			'failed'    => __( 'Fehlgeschlagene Ereignisse (Queue-Ausfall)', 'workflow-captain' ),
			'stuck'     => __( 'Hängende Schritte und Läufe', 'workflow-captain' ),
			'pause'     => __( 'Aktive Notfallpause', 'workflow-captain' ),
			'providers' => __( 'Abgelehnte oder inkompatible Provider', 'workflow-captain' ),
		);
		echo '<h2>' . esc_html__( 'Empfänger und Arten', 'workflow-captain' ) . '</h2><form method="post" action="options.php" class="wfc-settings-form">';
		settings_fields( 'workflow_captain_notify' );
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="wfc-recipients">' . esc_html__( 'Empfänger', 'workflow-captain' ) . '</label></th><td>';
		echo '<input id="wfc-recipients" type="text" class="regular-text" name="' . esc_attr( Notifications::OPTION ) . '[recipients]" value="' . esc_attr( $c['recipients'] ) . '" aria-describedby="wfc-recipients-help">';
		echo '<p id="wfc-recipients-help" class="description">' . esc_html( sprintf( /* translators: %s: e-mail address of the site */ __( 'Bis zu zehn Adressen, durch Komma getrennt. Leer: die Adresse der Website (%s). Absender ist der von WooCommerce, wenn WooCommerce aktiv ist, sonst der von WordPress.', 'workflow-captain' ), (string) get_option( 'admin_email' ) ) ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Arten', 'workflow-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Arten', 'workflow-captain' ) . '</legend>';
		// An empty list must still reach the server, otherwise all kinds would come back.
		echo '<input type="hidden" name="' . esc_attr( Notifications::OPTION ) . '[kinds][]" value="">';
		foreach ( $kinds as $key => $label ) {
			echo '<label><input type="checkbox" name="' . esc_attr( Notifications::OPTION ) . '[kinds][]" value="' . esc_attr( $key ) . '"' . ( in_array( $key, $c['kinds'], true ) ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '</fieldset></td></tr></table>';
		submit_button();
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="workflow_captain_test_mail">';
		wp_nonce_field( 'workflow_captain_test_mail' );
		echo '<p><button class="button">' . esc_html__( 'Testnachricht senden', 'workflow-captain' ) . '</button> <span class="description">' . esc_html__( 'Ein Testereignis für einen Workflow gibt es im Editor als „Testlauf“.', 'workflow-captain' ) . '</span></p></form>';
	}

	public function handle_test_mail(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) ) {
			wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
		}
		check_admin_referer( 'workflow_captain_test_mail' );
		$mail = Notifications::test_mail( wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) );
		$sent = wp_mail( Notifications::recipients( Notifications::config(), (string) get_option( 'admin_email' ) ), $mail['subject'], $mail['message'], Notifications::headers() );
		add_settings_error( Settings::OPTION, 'wfc-test-mail', $sent ? __( 'Testnachricht übergeben. Kommt sie nicht an, prüfen Sie den Mailversand der Website.', 'workflow-captain' ) : __( 'Die Testnachricht konnte nicht übergeben werden. Prüfen Sie den Mailversand der Website.', 'workflow-captain' ), $sent ? 'success' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'notify', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
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
			'manage_workflow_integrations' => __( 'Eigene Connectoren freigeben', 'workflow-captain' ),
		);
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wfc-settings-form"><input type="hidden" name="action" value="workflow_captain_roles">';
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
		$changes = array();
		$wanted = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? wp_unslash( $_POST['caps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys and values checked against fixed lists below.
		foreach ( wp_roles()->role_objects as $key => $role ) {
			if ( 'administrator' === $key ) {
				continue;
			}
			$given = array_intersect( (array) ( $wanted[ $key ] ?? array() ), Install::CAPS );
			foreach ( Install::CAPS as $cap ) {
				$want = in_array( $cap, $given, true );
				if ( $want !== $role->has_cap( $cap ) ) {
					$changes[ $key ][] = ( $want ? '+' : '-' ) . $cap;
				}
				$want ? $role->add_cap( $cap ) : $role->remove_cap( $cap );
			}
		}
		if ( $changes ) {
			Audit::record( 'roles', $changes );
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
		$this->audit();
	}

	/** The last security-relevant changes (WF-SET-13). */
	private function audit(): void {
		$labels = array(
			'settings'  => __( 'Einstellungen geändert', 'workflow-captain' ),
			'roles'     => __( 'Rechte der Rollen geändert', 'workflow-captain' ),
			'pause_on'  => __( 'Notfallpause eingeschaltet', 'workflow-captain' ),
			'pause_off' => __( 'Notfallpause aufgehoben', 'workflow-captain' ),
			'connector' => __( 'Freigabe eines eigenen Connectors geändert', 'workflow-captain' ),
		);
		echo '<h2>' . esc_html__( 'Letzte sicherheitsrelevante Änderungen', 'workflow-captain' ) . '</h2>';
		$entries = array_slice( Audit::entries(), 0, 20 );
		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'Noch keine Einträge.', 'workflow-captain' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Zeit (UTC)', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Benutzer', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Änderung', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Einzelheiten', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $entries as $e ) {
			$user   = get_userdata( (int) $e['user'] );
			$detail = array();
			foreach ( (array) $e['detail'] as $key => $value ) {
				$detail[] = $key . ': ' . ( is_array( $value ) ? implode( ' → ', array_map( 'strval', $value ) ) : (string) $value );
			}
			echo '<tr><td>' . esc_html( (string) $e['at'] ) . '</td><td>' . esc_html( $user ? $user->display_name : '#' . (int) $e['user'] ) . '</td><td>' . esc_html( $labels[ $e['what'] ] ?? (string) $e['what'] ) . '</td><td>' . esc_html( implode( '; ', $detail ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: 1: number, 2: option name */ __( 'Es bleiben die letzten %1$d Einträge in der Option %2$s.', 'workflow-captain' ), Audit::KEEP, Audit::OPTION ) ) . '</p>';
	}
}
