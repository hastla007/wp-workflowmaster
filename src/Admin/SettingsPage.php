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
		add_action( 'admin_post_workflow_captain_settings_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_workflow_captain_settings_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_workflow_captain_settings_reset', array( $this, 'handle_reset' ) );
		add_action( 'admin_post_workflow_captain_settings_undo', array( $this, 'handle_undo' ) );
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
		// The form shows and sends the stored values; a value a filter forces is named at the field, never saved.
		$s      = Settings::sanitize( get_option( Settings::OPTION, array() ) );
		$forced = \WorkflowCaptain\SettingsRegistry::forced( $s );
		$texts  = \WorkflowCaptain\SettingsRegistry::texts();
		$fields = array();
		foreach ( \WorkflowCaptain\SettingsRegistry::tab( $tab, Settings::OPTION ) as $key ) {
			$fields[ $key ] = $texts[ $key ];
		}
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
			list( $default, $min, $max ) = Settings::fields()[ $key ];
			$id                          = 'wfc-' . $key;
			$name                        = Settings::OPTION . '[' . $key . ']';
			$range                       = 0 === $min && 1 === $max ? '' : sprintf( /* translators: 1: lowest, 2: highest, 3: default value */ __( 'Erlaubt: %1$d bis %2$d. Standard: %3$d.', 'workflow-captain' ), $min, $max, $default );
			$help                        = trim( $help . ' ' . $range );
			$described                   = trim( ( '' !== $help ? $id . '-help' : '' ) . ( isset( $errors[ $id ] ) ? ' ' . $id . '-error' : '' ) );
			$invalid                     = isset( $errors[ $id ] );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 0 === $min && 1 === $max ) {
				// A switch is a checkbox; the hidden field sends 0 when it is unchecked.
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1" data-default="' . (int) $default . '"' . ( 1 === $s[ $key ] ? ' checked' : '' ) . ( '' !== $described ? ' aria-describedby="' . esc_attr( $described ) . '"' : '' ) . ( $invalid ? ' aria-invalid="true"' : '' ) . '>';
			} else {
				echo '<input id="' . esc_attr( $id ) . '" type="number" required min="' . (int) $min . '" max="' . (int) $max . '" name="' . esc_attr( $name ) . '" value="' . (int) $s[ $key ] . '" data-default="' . (int) $default . '" class="small-text"' . ( '' !== $described ? ' aria-describedby="' . esc_attr( $described ) . '"' : '' ) . ( $invalid ? ' aria-invalid="true"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ( '' !== $described ? ' aria-describedby="' . esc_attr( $described ) . '"' : '' ) . ( $invalid ? ' aria-invalid="true"' : '' ) is escaped above.
			}
			if ( isset( $errors[ $id ] ) ) {
				echo '<p id="' . esc_attr( $id . '-error' ) . '" class="wfc-field-error" style="color:#b32d2e">' . esc_html( $errors[ $id ] ) . '</p>';
			}
			if ( array_key_exists( $key, $forced ) ) {
				echo wp_kses_post( self::forced_note( $key, $forced[ $key ] ) );
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
		$c      = Notifications::sanitize( get_option( Notifications::OPTION, array() ) );
		$forced = \WorkflowCaptain\SettingsRegistry::forced( $c );
		$kinds  = array(
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
		if ( array_key_exists( 'recipients', $forced ) ) {
			echo wp_kses_post( self::forced_note( 'recipients', $forced['recipients'] ) );
		}
		echo '<p id="wfc-recipients-help" class="description">' . esc_html( sprintf( /* translators: %s: e-mail address of the site */ __( 'Bis zu zehn Adressen, durch Komma getrennt. Leer: die Adresse der Website (%s). Absender ist der von WooCommerce, wenn WooCommerce aktiv ist, sonst der von WordPress.', 'workflow-captain' ), (string) get_option( 'admin_email' ) ) ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Arten', 'workflow-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Arten', 'workflow-captain' ) . '</legend>';
		// An empty list must still reach the server, otherwise all kinds would come back.
		echo '<input type="hidden" name="' . esc_attr( Notifications::OPTION ) . '[kinds][]" value="">';
		foreach ( $kinds as $key => $label ) {
			echo '<label><input type="checkbox" name="' . esc_attr( Notifications::OPTION ) . '[kinds][]" value="' . esc_attr( $key ) . '"' . ( in_array( $key, $c['kinds'], true ) ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '</fieldset>';
		if ( array_key_exists( 'kinds', $forced ) ) {
			echo wp_kses_post( self::forced_note( 'kinds', $forced['kinds'] ) );
		}
		echo '</td></tr></table>';
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
		echo '<table class="widefat striped" tabindex="0" style="max-width:900px"><thead><tr><th scope="col">' . esc_html__( 'Rolle', 'workflow-captain' ) . '</th>';
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
			__( 'Aktualisierung der Datenbank', 'workflow-captain' )    => array( 'held' => __( 'läuft gerade', 'workflow-captain' ), 'stale' => __( 'abgebrochen – wird beim nächsten Aufruf im Admin, per Cron oder WP-CLI wiederholt', 'workflow-captain' ) )[ $env['upgrade_lock'] ?? '' ] ?? __( 'keine offen', 'workflow-captain' ),
			__( 'Nächster Worker-Lauf (UTC)', 'workflow-captain' )     => (string) ( $env['next_worker_run'] ?? __( 'nicht geplant', 'workflow-captain' ) ),
			__( 'Planung des Workers', 'workflow-captain' ) => 'action_scheduler' === ( $env['scheduler'] ?? '' ) ? __( 'Action Scheduler (von WooCommerce)', 'workflow-captain' ) : __( 'WP-Cron', 'workflow-captain' ),
			__( 'WP-Cron abgeschaltet', 'workflow-captain' )           => $env['wp_cron_disabled'] ? __( 'ja – System-Cron nötig: wp workflow-captain process', 'workflow-captain' ) : __( 'nein', 'workflow-captain' ),
			__( 'Eigener Schlüssel für Geheimnisse', 'workflow-captain' ) => Vault::own_key_configured() ? __( 'gesetzt', 'workflow-captain' ) : __( 'nicht gesetzt – empfohlen: WORKFLOW_CAPTAIN_SECRET_KEY in wp-config.php', 'workflow-captain' ),
			__( 'Hängende Schritte (Sperrfrist über einen Tag abgelaufen)', 'workflow-captain' ) => (string) $d['stuck']['expired_leases'],
			__( 'Läufe ohne offenen Schritt', 'workflow-captain' )     => (string) $d['stuck']['runs_without_steps'],
		);
		echo '<table class="widefat striped" tabindex="0" style="max-width:900px"><caption class="screen-reader-text">' . esc_html__( 'Zustand der Installation', 'workflow-captain' ) . '</caption><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: option name */ __( 'Gespeichert in der Option %s.', 'workflow-captain' ), Install::DB_OPTION ) ) . '</p>';
		$this->transfer();
		$this->audit();
	}

	/** Export, import with preview, reset of all settings and undo (SET-WC-11, SET-WC-23). */
	private function transfer(): void {
		$post = esc_url( admin_url( 'admin-post.php' ) );
		echo '<h2>' . esc_html__( 'Einstellungen übertragen', 'workflow-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'Die Datei enthält alle Einstellungen außer den Empfänger-Adressen; Geheimnisse und Zugangsdaten sind nie darin. Ein Import zeigt zuerst, was sich ändert.', 'workflow-captain' ) . '</p>';
		echo '<form method="post" action="' . $post . '" style="display:inline-block;margin-right:1em"><input type="hidden" name="action" value="workflow_captain_settings_export">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post is esc_url() above.
		wp_nonce_field( 'workflow_captain_settings_export' );
		submit_button( __( 'Einstellungen exportieren', 'workflow-captain' ), 'secondary', 'submit', false );
		echo '</form>';
		$preview = get_transient( 'workflow_captain_settings_import_' . get_current_user_id() );
		if ( is_array( $preview ) && isset( $preview['values'], $preview['diff'] ) ) {
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Vorschau des Imports', 'workflow-captain' ) . '</strong></p>';
			if ( array() === $preview['diff'] ) {
				echo '<p>' . esc_html__( 'Die Datei ändert nichts.', 'workflow-captain' ) . '</p>';
			} else {
				echo '<table class="widefat striped" tabindex="0" style="max-width:700px"><caption class="screen-reader-text">' . esc_html__( 'Änderungen durch den Import', 'workflow-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Einstellung', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Bisher', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Neu', 'workflow-captain' ) . '</th></tr></thead><tbody>';
				foreach ( $preview['diff'] as $key => $pair ) {
					echo '<tr><td><code>' . esc_html( (string) $key ) . '</code></td><td>' . esc_html( is_array( $pair[0] ) ? implode( ', ', $pair[0] ) : (string) $pair[0] ) . '</td><td>' . esc_html( is_array( $pair[1] ) ? implode( ', ', $pair[1] ) : (string) $pair[1] ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="workflow_captain_settings_import"><input type="hidden" name="confirm" value="1">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post is esc_url() above.
			wp_nonce_field( 'workflow_captain_settings_import' );
			echo '<p>';
			submit_button( __( 'Übernehmen', 'workflow-captain' ), 'primary', 'submit', false, array_merge( array() === $preview['diff'] ? array( 'disabled' => 'disabled' ) : array() ) );
			echo ' ';
			submit_button( __( 'Verwerfen', 'workflow-captain' ), 'secondary', 'discard', false );
			echo '</p></form></div>';
		} else {
			echo '<form method="post" enctype="multipart/form-data" action="' . $post . '" style="display:inline-block"><input type="hidden" name="action" value="workflow_captain_settings_import">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post is esc_url() above.
			wp_nonce_field( 'workflow_captain_settings_import' );
			echo '<label for="wfc-settings-file">' . esc_html__( 'Datei mit Einstellungen', 'workflow-captain' ) . '</label> <input id="wfc-settings-file" type="file" name="settings_file" accept="application/json,.json" required> ';
			submit_button( __( 'Vorschau anzeigen', 'workflow-captain' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '<h3>' . esc_html__( 'Zurücksetzen', 'workflow-captain' ) . '</h3>';
		echo '<form method="post" action="' . $post . '" style="display:inline-block;margin-right:1em"><input type="hidden" name="action" value="workflow_captain_settings_reset">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post is esc_url() above.
		wp_nonce_field( 'workflow_captain_settings_reset' );
		echo '<label for="wfc-reset-scope" class="screen-reader-text">' . esc_html__( 'Bereich', 'workflow-captain' ) . '</label><select id="wfc-reset-scope" name="tab"><option value="">' . esc_html__( 'Alle Einstellungen', 'workflow-captain' ) . '</option><option value="execution">' . esc_html__( 'Ausführung', 'workflow-captain' ) . '</option><option value="notify">' . esc_html__( 'Benachrichtigungen', 'workflow-captain' ) . '</option><option value="privacy">' . esc_html__( 'Datenschutz & Aufbewahrung', 'workflow-captain' ) . '</option></select> ';
		submit_button( __( 'Auf Standard setzen', 'workflow-captain' ), 'secondary', 'submit', false, array( 'onclick' => 'return confirm(' . wp_json_encode( __( 'Die gewählten Einstellungen auf die Standardwerte setzen? Rückgängig ist 30 Tage lang möglich.', 'workflow-captain' ) ) . ')' ) );
		echo '</form>';
		$backup = \WorkflowCaptain\SettingsTransfer::backup();
		if ( null !== $backup ) {
			echo '<form method="post" action="' . $post . '" style="display:inline-block"><input type="hidden" name="action" value="workflow_captain_settings_undo">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post is esc_url() above.
			wp_nonce_field( 'workflow_captain_settings_undo' );
			/* translators: %s: date and time of the backup */
			submit_button( sprintf( __( 'Rückgängig: Stand vom %s wiederherstellen', 'workflow-captain' ), wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) $backup['at'] ) ), 'secondary', 'submit', false );
			echo '</form>';
		}
	}

	/** Sends the export file (SET-WC-11). */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'workflow-captain' ), 403 );
		}
		check_admin_referer( 'workflow_captain_settings_export' );
		$json = (string) wp_json_encode( \WorkflowCaptain\SettingsTransfer::export( \WorkflowCaptain\SettingsTransfer::current(), WORKFLOW_CAPTAIN_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="workflow-captain-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
		exit;
	}

	/** Step 1: upload and preview; step 2: confirm or discard (SET-WC-11). */
	public function handle_import(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'workflow-captain' ), 403 );
		}
		check_admin_referer( 'workflow_captain_settings_import' );
		$key = 'workflow_captain_settings_import_' . get_current_user_id();
		if ( isset( $_POST['confirm'] ) ) {
			$preview = get_transient( $key );
			delete_transient( $key );
			if ( isset( $_POST['discard'] ) || ! is_array( $preview ) || ! isset( $preview['values'] ) ) {
				add_settings_error( Settings::OPTION, 'wfc-import', __( 'Import verworfen. Nichts wurde geändert.', 'workflow-captain' ), 'info' );
			} else {
				$changed = \WorkflowCaptain\SettingsTransfer::apply( $preview['values'], 'import' );
				/* translators: %d: number of changed settings */
				add_settings_error( Settings::OPTION, 'wfc-import', sprintf( __( '%d Einstellungen übernommen. Rückgängig ist 30 Tage lang möglich.', 'workflow-captain' ), count( $changed ) ), 'success' );
			}
		} else {
			$tmp  = isset( $_FILES['settings_file']['tmp_name'] ) ? (string) $_FILES['settings_file']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path of the uploaded temp file, only read.
			$size = isset( $_FILES['settings_file']['size'] ) ? (int) $_FILES['settings_file']['size'] : 0;
			$text = '' !== $tmp && is_uploaded_file( $tmp ) && $size > 0 && $size <= 65536 ? (string) file_get_contents( $tmp ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded temp file.
			$parsed = \WorkflowCaptain\SettingsTransfer::parse( $text );
			if ( array() !== $parsed['errors'] ) {
				$messages = array();
				foreach ( $parsed['errors'] as $field => $code ) {
					$messages[] = '_file' === $field ? self::import_error( $code ) : $field . ': ' . self::import_error( $code );
				}
				add_settings_error( Settings::OPTION, 'wfc-import', __( 'Die Datei wurde nicht übernommen:', 'workflow-captain' ) . ' ' . implode( '; ', $messages ), 'error' );
			} else {
				set_transient( $key, array( 'values' => $parsed['values'], 'diff' => \WorkflowCaptain\SettingsTransfer::diff( \WorkflowCaptain\SettingsTransfer::current(), $parsed['values'] ) ), 15 * MINUTE_IN_SECONDS );
			}
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'status', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function import_error( string $code ): string {
		$texts = array(
			'not_a_settings_file' => __( 'keine Einstellungsdatei von Workflow Captain', 'workflow-captain' ),
			'other_plugin'        => __( 'die Datei gehört zu einem anderen Plugin', 'workflow-captain' ),
			'no_format'           => __( 'Formatnummer fehlt', 'workflow-captain' ),
			'newer_format'        => __( 'die Datei stammt von einer neueren Version; bitte Workflow Captain aktualisieren', 'workflow-captain' ),
			'not_a_number'        => __( 'keine ganze Zahl', 'workflow-captain' ),
			'out_of_range'        => __( 'außerhalb des erlaubten Bereichs', 'workflow-captain' ),
			'not_a_list'          => __( 'keine Liste', 'workflow-captain' ),
			'unknown_kind'        => __( 'unbekannte Art', 'workflow-captain' ),
			'invalid_address'     => __( 'ungültige Adresse', 'workflow-captain' ),
		);
		return $texts[ $code ] ?? $code;
	}

	/** Resets one tab or all settings on the server, with a backup for undo (SET-WC-23). */
	public function handle_reset(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'workflow-captain' ), 403 );
		}
		check_admin_referer( 'workflow_captain_settings_reset' );
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$tab = in_array( $tab, array( 'execution', 'notify', 'privacy' ), true ) ? $tab : '';
		$changed = \WorkflowCaptain\SettingsTransfer::apply( \WorkflowCaptain\SettingsTransfer::reset_values( '' === $tab ? null : $tab ), 'reset' );
		/* translators: %d: number of changed settings */
		add_settings_error( Settings::OPTION, 'wfc-reset', sprintf( __( '%d Einstellungen auf Standard gesetzt. Rückgängig ist 30 Tage lang möglich.', 'workflow-captain' ), count( $changed ) ), 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'status', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Restores the backup of the last reset or import once (SET-WC-23). */
	public function handle_undo(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'workflow-captain' ), 403 );
		}
		check_admin_referer( 'workflow_captain_settings_undo' );
		$changed = \WorkflowCaptain\SettingsTransfer::undo();
		/* translators: %d: number of restored settings */
		add_settings_error( Settings::OPTION, 'wfc-undo', null === $changed ? __( 'Es gibt nichts rückgängig zu machen.', 'workflow-captain' ) : sprintf( __( '%d Einstellungen wiederhergestellt.', 'workflow-captain' ), count( $changed ) ), null === $changed ? 'error' : 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'status', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Note at a field whose value a filter sets (workflow_captain_setting_<key>): the form keeps the stored value.
	 *
	 * @param mixed $effective
	 */
	private static function forced_note( string $key, $effective ): string {
		$shown = is_array( $effective ) ? implode( ', ', array_map( 'strval', $effective ) ) : (string) $effective;
		/* translators: 1: value in effect, 2: filter name */
		return '<p class="description wfc-forced"><strong>' . esc_html( sprintf( __( 'Wirksam ist „%1$s“: ein Plugin oder Code der Website setzt diesen Wert mit dem Filter %2$s. Gespeichert bleibt der Wert im Feld; er gilt wieder, wenn der Filter entfällt.', 'workflow-captain' ), $shown, 'workflow_captain_setting_' . $key ) ) . '</strong></p>';
	}

	/** One audit value as text: old → new pairs, lists (such as the notice kinds) comma separated, nothing nested. */
	private static function audit_value( $value ): string {
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) || null === $value ? (string) $value : '';
		}
		$parts = array();
		foreach ( $value as $part ) {
			$parts[] = is_array( $part ) ? implode( ', ', array_map( static fn( $p ): string => is_scalar( $p ) ? (string) $p : '', $part ) ) : ( is_scalar( $part ) || null === $part ? (string) $part : '' );
		}
		return implode( ' → ', $parts );
	}

	/** The last security-relevant changes (WF-SET-13). */
	private function audit(): void {
		$labels = array(
			'settings'  => __( 'Einstellungen geändert', 'workflow-captain' ),
			'settings_import' => __( 'Einstellungen importiert', 'workflow-captain' ),
			'settings_reset'  => __( 'Einstellungen auf Standard gesetzt', 'workflow-captain' ),
			'settings_undo'   => __( 'Einstellungen wiederhergestellt', 'workflow-captain' ),
			'settings_cli'    => __( 'Einstellung per WP-CLI geändert', 'workflow-captain' ),
			'roles'     => __( 'Rechte der Rollen geändert', 'workflow-captain' ),
			'pause_on'  => __( 'Notfallpause eingeschaltet', 'workflow-captain' ),
			'pause_off' => __( 'Notfallpause aufgehoben', 'workflow-captain' ),
			'connector' => __( 'Freigabe eines eigenen Connectors geändert', 'workflow-captain' ),
			'requeue'          => __( 'Ereignisse wieder eingereiht', 'workflow-captain' ),
			'run_cancel'       => __( 'Lauf abgebrochen', 'workflow-captain' ),
			'run_continue'     => __( 'Schritt fortgesetzt', 'workflow-captain' ),
			'workflow_publish' => __( 'Workflow veröffentlicht', 'workflow-captain' ),
			'workflow_archive' => __( 'Workflow archiviert', 'workflow-captain' ),
			'workflow_restore' => __( 'Workflow wiederhergestellt', 'workflow-captain' ),
			'workflow_pause'   => __( 'Workflow pausiert', 'workflow-captain' ),
			'workflow_resume'  => __( 'Workflow fortgesetzt', 'workflow-captain' ),
			'upgrade_resumed'  => __( 'Abgebrochene Datenbank-Aktualisierung wiederholt', 'workflow-captain' ),
			'run_export'       => __( 'Lauf exportiert', 'workflow-captain' ),
		);
		/**
		 * Labels of audit entries written by add-ons (key => label); core labels cannot be replaced.
		 *
		 * @since 0.2.0
		 * @param array<string,string> $labels
		 */
		$extra = apply_filters( 'workflow_captain_audit_labels_v1', array() );
		foreach ( is_array( $extra ) ? $extra : array() as $key => $label ) {
			if ( is_string( $key ) && is_string( $label ) && ! isset( $labels[ $key ] ) ) {
				$labels[ $key ] = $label;
			}
		}
		echo '<h2>' . esc_html__( 'Letzte sicherheitsrelevante Änderungen', 'workflow-captain' ) . '</h2>';
		$entries = array_slice( Audit::entries(), 0, 20 );
		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'Noch keine Einträge.', 'workflow-captain' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" tabindex="0"><caption class="screen-reader-text">' . esc_html__( 'Letzte sicherheitsrelevante Änderungen', 'workflow-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Zeit (UTC)', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Benutzer', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Änderung', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Einzelheiten', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $entries as $e ) {
			$user   = get_userdata( (int) $e['user'] );
			$detail = array();
			foreach ( (array) $e['detail'] as $key => $value ) {
				$detail[] = $key . ': ' . self::audit_value( $value );
			}
			echo '<tr><td>' . esc_html( (string) $e['at'] ) . '</td><td>' . esc_html( $user ? $user->display_name : ( 0 === (int) $e['user'] ? __( 'System/WP-CLI', 'workflow-captain' ) : '#' . (int) $e['user'] ) ) . '</td><td>' . esc_html( $labels[ $e['what'] ] ?? (string) $e['what'] ) . '</td><td>' . esc_html( implode( '; ', $detail ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: 1: number, 2: option name */ __( 'Es bleiben die letzten %1$d Einträge in der Option %2$s.', 'workflow-captain' ), Audit::KEEP, Audit::OPTION ) ) . '</p>';
	}
}
