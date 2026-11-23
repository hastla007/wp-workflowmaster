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
		// Form saves of both options, the first save of a site included (it creates the option).
		Audit::watch_settings( true );
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
					'unsaved' => __( 'There are unsaved changes. Leave the page anyway?', 'workflow-captain' ),
					'reset'   => __( 'Set all fields in this tab to their default values? Nothing is saved until you click “Save changes”.', 'workflow-captain' ),
				)
			) . ';',
			'before'
		);
	}

	public function register_setting(): void {
		register_setting( 'workflow_captain', Settings::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( Settings::class, 'sanitize_and_report' ), 'default' => Settings::defaults() ) );
		// Own group: options.php resets every option of a group that is missing in the request.
		register_setting( 'workflow_captain_messages', \WorkflowCaptain\Messages::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( \WorkflowCaptain\Messages::class, 'sanitize_and_report' ), 'default' => \WorkflowCaptain\Messages::sanitize( array() ) ) );
		register_setting( 'workflow_captain_notify', Notifications::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( Notifications::class, 'sanitize_and_report' ), 'default' => Notifications::sanitize( array() ) ) );
	}

	public function menu(): void {
		add_submenu_page( OverviewPage::SLUG, __( 'Settings', 'workflow-captain' ), __( 'Settings', 'workflow-captain' ), 'manage_workflow_captain', self::SLUG, array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch only.
		$tabs = array(
			'general'   => __( 'General', 'workflow-captain' ),
			'execution' => __( 'Execution', 'workflow-captain' ),
			'messages'  => __( 'Messages', 'workflow-captain' ),
			'notify'    => __( 'Notifications', 'workflow-captain' ),
			'privacy'   => __( 'Privacy & retention', 'workflow-captain' ),
			'roles'     => __( 'Roles & capabilities', 'workflow-captain' ),
			'status'    => __( 'Status', 'workflow-captain' ),
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'general';
		// After an action (settings-updated, which WordPress removes from the address bar at once) settings.js moves
		// focus to its message.
		$result = isset( $_GET['settings-updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		echo '<div class="wrap"' . ( $result ? ' data-wfc-result="1"' : '' ) . '><h1>' . esc_html__( 'Settings', 'workflow-captain' ) . '</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		settings_errors( Settings::OPTION );
		// options.php reports a successful save as setting "general"; without it a save gave no feedback at all.
		settings_errors( 'general' );
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
		// The form shows and saves what is stored; a value a filter overrides is only noted at the field, otherwise
		// saving any tab would store the filtered value for good (P1 finding).
		$s      = Settings::stored();
		$active = Settings::get();
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
			$range                       = ( 0 === $min && 1 === $max ) || array() !== \WorkflowCaptain\SettingsRegistry::choices( $key ) || \WorkflowCaptain\SettingsRegistry::TYPE_DAYS === \WorkflowCaptain\SettingsRegistry::field( $key )['type'] ? '' : sprintf( /* translators: 1: lowest, 2: highest, 3: default value */ __( 'Allowed: %1$d to %2$d. Default: %3$d.', 'workflow-captain' ), $min, $max, $default );
			$help                        = trim( $help . ' ' . $range );
			// The error first: a screen reader reads it before the help text.
			$described                   = trim( ( isset( $errors[ $id ] ) ? $id . '-error ' : '' ) . ( '' !== $help ? $id . '-help' : '' ) );
			$invalid                     = isset( $errors[ $id ] );
			$is_days                     = \WorkflowCaptain\SettingsRegistry::TYPE_DAYS === \WorkflowCaptain\SettingsRegistry::field( $key )['type'];
			echo '<tr><th scope="row">' . ( $is_days ? esc_html( $label ) : '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>' ) . '</th><td>';
			if ( array() !== \WorkflowCaptain\SettingsRegistry::choices( $key ) ) {
				$locked = 'test_mode' === $key && \WorkflowCaptain\TestMode::forced();
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" data-default="' . (int) $default . '"' . ( $locked ? ' disabled' : '' ) . ( '' !== $described ? ' aria-describedby="' . esc_attr( $described ) . '"' : '' ) . '>';
				foreach ( \WorkflowCaptain\SettingsRegistry::choices( $key ) as $value => $choice ) {
					echo '<option value="' . (int) $value . '"' . ( (int) $value === ( $locked ? \WorkflowCaptain\TestMode::mode() : $s[ $key ] ) ? ' selected' : '' ) . '>' . esc_html( $choice ) . '</option>';
				}
				echo '</select>';
				if ( $locked ) {
					// A disabled select is not sent; keep the stored value.
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . (int) $s[ $key ] . '"><p class="description">' . esc_html__( 'Set by WORKFLOW_CAPTAIN_TEST_MODE in wp-config.php.', 'workflow-captain' ) . '</p>';
				}
			} elseif ( \WorkflowCaptain\SettingsRegistry::TYPE_DAYS === \WorkflowCaptain\SettingsRegistry::field( $key )['type'] ) {
				// Weekdays as checkboxes in a group; the hidden field marks the list as sent (no day = error, SET-WC-07).
				echo '<fieldset id="' . esc_attr( $id ) . '"' . ( '' !== $described ? ' aria-describedby="' . esc_attr( $described ) . '"' : '' ) . ( $invalid ? ' aria-invalid="true"' : '' ) . '><legend class="screen-reader-text">' . esc_html( $label ) . '</legend><input type="hidden" name="' . esc_attr( $name . '[]' ) . '" value="">';
				foreach ( \WorkflowCaptain\SettingsRegistry::weekdays() as $day => $day_name ) {
					echo '<label class="wfc-confirm" style="margin-right:1em"><input type="checkbox" name="' . esc_attr( $name . '[]' ) . '" value="' . (int) $day . '" data-default="' . ( 0 !== ( (int) $default & ( 1 << ( $day - 1 ) ) ) ? '1' : '0' ) . '"' . ( 0 !== ( $s[ $key ] & ( 1 << ( $day - 1 ) ) ) ? ' checked' : '' ) . '> ' . esc_html( $day_name ) . '</label> ';
				}
				echo '</fieldset>';
			} elseif ( 0 === $min && 1 === $max ) {
				// A switch is a checkbox; the hidden field sends 0 when it is unchecked.
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1" data-default="' . (int) $default . '"' . ( 1 === $s[ $key ] ? ' checked' : '' ) . ( '' !== $described ? ' aria-describedby="' . esc_attr( $described ) . '"' : '' ) . ( $invalid ? ' aria-invalid="true"' : '' ) . '>';
				if ( 'delete_data_on_uninstall' === $key && 1 !== $s[ $key ] ) {
					// Typed confirmation, the same in every Captain plugin (S10); checked on the server.
					echo '<p><label for="wfc-delete-confirm">' . esc_html__( 'Type DELETE to switch it on:', 'workflow-captain' ) . '</label> <input type="text" id="wfc-delete-confirm" name="' . esc_attr( Settings::OPTION . '[delete_data_on_uninstall_confirm]' ) . '" autocomplete="off" size="10" value=""></p>';
				}
			} else {
				echo '<input id="' . esc_attr( $id ) . '" type="number" required min="' . (int) $min . '" max="' . (int) $max . '" name="' . esc_attr( $name ) . '" value="' . (int) $s[ $key ] . '" data-default="' . (int) $default . '" class="small-text"' . ( '' !== $described ? ' aria-describedby="' . esc_attr( $described ) . '"' : '' ) . ( $invalid ? ' aria-invalid="true"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ( '' !== $described ? ' aria-describedby="' . esc_attr( $described ) . '"' : '' ) . ( $invalid ? ' aria-invalid="true"' : '' ) is escaped above.
			}
			if ( isset( $errors[ $id ] ) ) {
				echo '<p id="' . esc_attr( $id . '-error' ) . '" class="wfc-field-error" style="color:#b32d2e">' . esc_html( $errors[ $id ] ) . '</p>';
			}
			if ( '' !== $help ) {
				echo '<p id="' . esc_attr( $id . '-help' ) . '" class="description">' . esc_html( $help ) . '</p>';
			}
			if ( $active[ $key ] !== $s[ $key ] ) {
				echo '<p class="description">' . esc_html( self::filter_note( $key, (string) $active[ $key ] ) ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table><p class="submit">';
		submit_button( null, 'primary', 'submit', false, array( 'id' => 'wfc-settings-submit' ) );
		echo ' <button type="button" class="button wfc-reset-tab">' . esc_html__( 'Defaults of this tab', 'workflow-captain' ) . '</button></p>';
		echo '</form>';
		if ( 'notify' === $tab ) {
			$this->notify( $errors );
		}
		if ( 'messages' === $tab ) {
			$this->sender( $errors );
		}
		if ( 'execution' === $tab ) {
			$this->execution_help();
		}
	}

	/** How the worker is scheduled and how failures are repeated (WF-SET-05). */
	private function execution_help(): void {
		$plan = array();
		foreach ( \WorkflowCaptain\Consumer\RetryPlan::delays() as $seconds ) {
			$plan[] = $seconds < HOUR_IN_SECONDS ? sprintf( /* translators: %d: minutes */ __( '%d min', 'workflow-captain' ), $seconds / MINUTE_IN_SECONDS ) : sprintf( /* translators: %d: hours */ __( '%d h', 'workflow-captain' ), $seconds / HOUR_IN_SECONDS );
		}
		echo '<h2>' . esc_html__( 'How execution works', 'workflow-captain' ) . '</h2>';
		echo '<p>' . esc_html( \WorkflowCaptain\Scheduler::ACTION_SCHEDULER === \WorkflowCaptain\Scheduler::backend() ? __( 'The worker runs via Action Scheduler (from WooCommerce), every minute.', 'workflow-captain' ) : __( 'The worker runs via WP-Cron, every minute. WP-Cron only starts on page loads; on sites with little traffic or with DISABLE_WP_CRON, set up a system cron: wp workflow-captain process (every minute).', 'workflow-captain' ) ) . '</p>';
		echo '<p>' . esc_html( sprintf( /* translators: %s: list of waiting times */ __( 'Temporary errors are retried on this schedule (setting above): %s. After that, an event is shown as failed; a step needs a decision. Messages are never retried automatically after an unclear outcome.', 'workflow-captain' ), implode( ', ', $plan ) ) ) . '</p>';
		echo '<p>' . esc_html__( 'In the editor, only serialization (one run per object at a time) can be set per workflow; the limits above apply to all workflows.', 'workflow-captain' ) . '</p>';
	}

	/** Note at a field whose value a filter overrides (SET-WC-01: the filter wins, the stored value stays). */
	private static function filter_note( string $key, string $value ): string {
		/* translators: 1: filter name, 2: value in effect */
		return sprintf( __( 'The filter %1$s sets this value to “%2$s”. The value in the field stays saved; it applies again only without the filter.', 'workflow-captain' ), 'workflow_captain_setting_' . $key, '' === $value ? '–' : $value );
	}

	/**
	 * Readable names of the notice kinds (notification tab, import preview, log).
	 *
	 * @return array<string,string>
	 */
	public static function kind_labels(): array {
		return array(
			'decisions' => __( 'Runs that need a decision', 'workflow-captain' ),
			'paused'    => __( 'Paused runs', 'workflow-captain' ),
			'failed'    => __( 'Failed events (queue outage)', 'workflow-captain' ),
			'stuck'     => __( 'Stuck steps and runs', 'workflow-captain' ),
			'pause'     => __( 'Active emergency pause', 'workflow-captain' ),
			'providers' => __( 'Rejected or incompatible providers', 'workflow-captain' ),
		);
	}

	/** Label of a setting as on its tab; the key itself for anything outside the register. */
	public static function field_label( string $key ): string {
		$texts = \WorkflowCaptain\SettingsRegistry::texts();
		return isset( $texts[ $key ] ) ? $texts[ $key ][0] : $key;
	}

	/** A value of a setting as the operator reads it: switches as ein/aus, notice kinds by name, numbers as they are. */
	public static function display_value( string $key, $value ): string {
		$f = \WorkflowCaptain\SettingsRegistry::field( $key );
		if ( null !== $f && \WorkflowCaptain\SettingsRegistry::TYPE_BOOL === $f['type'] && is_scalar( $value ) ) {
			return 1 === (int) $value ? __( 'on', 'workflow-captain' ) : __( 'off', 'workflow-captain' );
		}
		if ( null !== $f && \WorkflowCaptain\SettingsRegistry::TYPE_DAYS === $f['type'] && is_scalar( $value ) ) {
			$names = array_intersect_key( \WorkflowCaptain\SettingsRegistry::weekdays(), array_flip( \WorkflowCaptain\SettingsRegistry::days_list( (int) $value ) ) );
			return implode( ', ', $names );
		}
		$choices = \WorkflowCaptain\SettingsRegistry::choices( $key );
		if ( array() !== $choices && is_scalar( $value ) && isset( $choices[ (int) $value ] ) ) {
			return $choices[ (int) $value ];
		}
		if ( null !== $f && \WorkflowCaptain\SettingsRegistry::TYPE_KINDS === $f['type'] && is_array( $value ) ) {
			$labels = self::kind_labels();
			$names  = array_map( static fn( $k ): string => is_scalar( $k ) ? ( $labels[ (string) $k ] ?? (string) $k ) : '', $value );
			return array() === $names ? __( 'none', 'workflow-captain' ) : implode( ', ', $names );
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( static fn( $p ): string => is_scalar( $p ) ? (string) $p : '', $value ) );
		}
		return is_scalar( $value ) || null === $value ? (string) $value : '';
	}

	/**
	 * Sender, reply address and footer of messages from workflows (SET-WC-10): own option and form.
	 *
	 * @param array<string,string> $errors Field errors of the last save (id => message).
	 */
	private function sender( array $errors ): void {
		$c      = \WorkflowCaptain\Messages::stored();
		$active = \WorkflowCaptain\Messages::config();
		$texts  = \WorkflowCaptain\SettingsRegistry::texts();
		echo '<h2>' . esc_html__( 'Sender, footer and time zone', 'workflow-captain' ) . '</h2><form method="post" action="options.php" class="wfc-settings-form">';
		settings_fields( 'workflow_captain_messages' );
		echo '<table class="form-table" role="presentation">';
		foreach ( \WorkflowCaptain\SettingsRegistry::keys( \WorkflowCaptain\Messages::OPTION ) as $key ) {
			$f         = \WorkflowCaptain\SettingsRegistry::field( $key );
			$id        = 'wfc-' . $key;
			$name      = \WorkflowCaptain\Messages::OPTION . '[' . $key . ']';
			$invalid   = isset( $errors[ $id ] );
			$described = trim( ( $invalid ? $id . '-error ' : '' ) . $id . '-help' );
			$attrs     = ' name="' . esc_attr( $name ) . '" data-default="" aria-describedby="' . esc_attr( $described ) . '"' . ( $invalid ? ' aria-invalid="true"' : '' );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $texts[ $key ][0] ) . '</label></th><td>';
			if ( \WorkflowCaptain\SettingsRegistry::TYPE_TIMEZONE === $f['type'] ) {
				echo '<select id="' . esc_attr( $id ) . '"' . $attrs . '><option value="">' . esc_html( sprintf( /* translators: %s: time zone of WordPress */ __( 'Like WordPress (%s)', 'workflow-captain' ), wp_timezone_string() ) ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is escaped above.
				foreach ( \DateTimeZone::listIdentifiers() as $zone ) {
					echo '<option value="' . esc_attr( $zone ) . '"' . ( $zone === $c[ $key ] ? ' selected' : '' ) . '>' . esc_html( $zone ) . '</option>';
				}
				echo '</select>';
			} elseif ( \WorkflowCaptain\SettingsRegistry::TYPE_TEXTAREA === $f['type'] ) {
				echo '<textarea id="' . esc_attr( $id ) . '"' . $attrs . ' rows="4" class="large-text" maxlength="' . (int) $f['max'] . '">' . esc_textarea( $c[ $key ] ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is escaped above.
			} else {
				echo '<input id="' . esc_attr( $id ) . '"' . $attrs . ' type="' . ( \WorkflowCaptain\SettingsRegistry::TYPE_EMAIL === $f['type'] ? 'email' : 'text' ) . '" class="regular-text"' . ( \WorkflowCaptain\SettingsRegistry::TYPE_TEXT === $f['type'] ? ' maxlength="' . (int) $f['max'] . '"' : '' ) . ' value="' . esc_attr( $c[ $key ] ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is escaped above.
			}
			if ( $invalid ) {
				echo '<p id="' . esc_attr( $id . '-error' ) . '" class="wfc-field-error" style="color:#b32d2e">' . esc_html( $errors[ $id ] ) . '</p>';
			}
			echo '<p id="' . esc_attr( $id . '-help' ) . '" class="description">' . esc_html( $texts[ $key ][1] ) . '</p>';
			if ( $active[ $key ] !== $c[ $key ] ) {
				echo '<p class="description">' . esc_html( self::filter_note( $key, (string) $active[ $key ] ) ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table><p class="submit">';
		submit_button( null, 'primary', 'submit', false, array( 'id' => 'wfc-messages-submit' ) );
		echo '</p></form>';
	}

	/**
	 * Recipients, kinds and a test mail (WF-SET-06).
	 *
	 * @param array<string,string> $errors Field errors of the last save (id => message).
	 */
	private function notify( array $errors ): void {
		$c       = Notifications::stored();
		$active  = Notifications::config();
		$texts   = \WorkflowCaptain\SettingsRegistry::texts();
		$invalid = isset( $errors['wfc-recipients'] );
		echo '<h2>' . esc_html__( 'Recipients and notice types', 'workflow-captain' ) . '</h2><form method="post" action="options.php" class="wfc-settings-form">';
		settings_fields( 'workflow_captain_notify' );
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="wfc-recipients">' . esc_html( $texts['recipients'][0] ) . '</label></th><td>';
		echo '<input id="wfc-recipients" type="text" class="regular-text" name="' . esc_attr( Notifications::OPTION ) . '[recipients]" value="' . esc_attr( $c['recipients'] ) . '" aria-describedby="' . ( $invalid ? 'wfc-recipients-error ' : '' ) . 'wfc-recipients-help"' . ( $invalid ? ' aria-invalid="true"' : '' ) . '>';
		if ( $invalid ) {
			echo '<p id="wfc-recipients-error" class="wfc-field-error" style="color:#b32d2e">' . esc_html( $errors['wfc-recipients'] ) . '</p>';
		}
		/* translators: %s: e-mail address of the site */
		echo '<p id="wfc-recipients-help" class="description">' . esc_html( $texts['recipients'][1] . ' ' . sprintf( __( 'Currently that is %s.', 'workflow-captain' ), (string) get_option( 'admin_email' ) ) . ' ' . __( 'The sender is WooCommerce\'s sender when WooCommerce is active, otherwise WordPress\'s.', 'workflow-captain' ) ) . '</p>';
		if ( $active['recipients'] !== $c['recipients'] ) {
			echo '<p class="description">' . esc_html( self::filter_note( 'recipients', $active['recipients'] ) ) . '</p>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html( $texts['kinds'][0] ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html( $texts['kinds'][0] ) . '</legend>';
		// An empty list must still reach the server, otherwise all kinds would come back.
		echo '<input type="hidden" name="' . esc_attr( Notifications::OPTION ) . '[kinds][]" value="">';
		foreach ( self::kind_labels() as $key => $label ) {
			echo '<label><input type="checkbox" name="' . esc_attr( Notifications::OPTION ) . '[kinds][]" value="' . esc_attr( $key ) . '"' . ( in_array( $key, $c['kinds'], true ) ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '</fieldset>';
		if ( $active['kinds'] !== $c['kinds'] ) {
			echo '<p class="description">' . esc_html( self::filter_note( 'kinds', implode( ', ', $active['kinds'] ) ) ) . '</p>';
		}
		echo '</td></tr>';
		// SET-WC-25: roles whose users can be chosen as recipients of "Nachricht an Betreiber".
		list( $roles_label, $roles_help ) = \WorkflowCaptain\SettingsRegistry::texts()['notify_admin_roles'];
		echo '<tr><th scope="row">' . esc_html( $roles_label ) . '</th><td><fieldset aria-describedby="wfc-roles-help"><legend class="screen-reader-text">' . esc_html( $roles_label ) . '</legend>';
		echo '<input type="hidden" name="' . esc_attr( Notifications::OPTION ) . '[notify_admin_roles][]" value="">';
		foreach ( wp_roles()->get_names() as $role => $name ) {
			echo '<label><input type="checkbox" name="' . esc_attr( Notifications::OPTION ) . '[notify_admin_roles][]" value="' . esc_attr( $role ) . '"' . ( in_array( $role, $c['notify_admin_roles'], true ) ? ' checked' : '' ) . '> ' . esc_html( translate_user_role( $name ) ) . '</label><br>';
		}
		echo '<p id="wfc-roles-help" class="description">' . esc_html( $roles_help ) . '</p></fieldset></td></tr></table>';
		submit_button( null, 'primary', 'submit', true, array( 'id' => 'wfc-notify-submit' ) );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="workflow_captain_test_mail">';
		wp_nonce_field( 'workflow_captain_test_mail' );
		echo '<p><button class="button">' . esc_html__( 'Send test message', 'workflow-captain' ) . '</button> <span class="description">' . esc_html__( 'A test event for a workflow is available in the editor as “Test run”.', 'workflow-captain' ) . '</span></p></form>';
	}

	public function handle_test_mail(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'workflow-captain' ), 403 );
		}
		check_admin_referer( 'workflow_captain_test_mail' );
		$mail = Notifications::test_mail( wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) );
		$sent = wp_mail( Notifications::recipients( Notifications::config(), (string) get_option( 'admin_email' ) ), $mail['subject'], $mail['message'], Notifications::headers() );
		add_settings_error( Settings::OPTION, 'wfc-test-mail', $sent ? __( 'Test message handed over. If it does not arrive, check the site\'s mail delivery.', 'workflow-captain' ) : __( 'The test message could not be handed off. Check the site\'s mail delivery.', 'workflow-captain' ), $sent ? 'success' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'notify', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Time zone and date format come from WordPress; the source is shown (WF-SET-04). */
	private function general(): void {
		$rows = array(
			__( 'Time zone', 'workflow-captain' )     => \WorkflowCaptain\Settings::timezone() . ' – ' . ( '' !== \WorkflowCaptain\Messages::config()['timezone'] ? __( 'own setting in the Messages tab', 'workflow-captain' ) : __( 'from WordPress Settings → General', 'workflow-captain' ) ),
			__( 'Date format', 'workflow-captain' ) => (string) get_option( 'date_format' ) . ' – ' . __( 'from WordPress Settings → General', 'workflow-captain' ),
			__( 'Storage', 'workflow-captain' )  => __( 'All times are stored in UTC; delays in days follow the site\'s time zone.', 'workflow-captain' ),
		);
		echo '<table class="form-table" role="presentation">';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</table><p><a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Change time zone and date format in WordPress', 'workflow-captain' ) . '</a></p>';
	}

	/** Capabilities per role (WF-SET-08). Administrators always keep all of them. */
	private function roles(): void {
		$labels = array(
			'view_workflow_logs'      => __( 'View runs and events', 'workflow-captain' ),
			'edit_workflows'          => __( 'Design workflows', 'workflow-captain' ),
			'activate_workflows'      => __( 'Publish and pause workflows', 'workflow-captain' ),
			'retry_workflow_runs'     => __( 'Retry, resume, cancel', 'workflow-captain' ),
			'manage_workflow_captain' => __( 'Settings, emergency pause, diagnostics', 'workflow-captain' ),
			'manage_workflow_integrations' => __( 'Approve custom connectors', 'workflow-captain' ),
		);
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wfc-settings-form"><input type="hidden" name="action" value="workflow_captain_roles">';
		wp_nonce_field( 'workflow_captain_roles' );
		echo '<table class="widefat striped" tabindex="0" style="max-width:900px"><thead><tr><th scope="col">' . esc_html__( 'Role', 'workflow-captain' ) . '</th>';
		foreach ( $labels as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( wp_roles()->role_objects as $key => $role ) {
			$admin = 'administrator' === $key;
			$name  = translate_user_role( wp_roles()->role_names[ $key ] ?? $key );
			echo '<tr><th scope="row">' . esc_html( $name ) . '</th>';
			foreach ( $labels as $cap => $label ) {
				// The name says right and role: a screen reader in a table cell does not always read both headers.
				/* translators: 1: right, 2: role */
				echo '<td><input type="checkbox" name="caps[' . esc_attr( $key ) . '][]" value="' . esc_attr( $cap ) . '"' . ( $role->has_cap( $cap ) ? ' checked' : '' ) . ( $admin ? ' disabled' : '' ) . ' aria-label="' . esc_attr( sprintf( __( '%1$s – %2$s', 'workflow-captain' ), $label, $name ) ) . '"></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Administrators always have all capabilities. Shop managers do not get any capabilities automatically.', 'workflow-captain' ) . '</p>';
		submit_button( null, 'primary', 'submit', true, array( 'id' => 'wfc-roles-submit' ) );
		echo '</form>';
	}

	public function handle_roles(): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) || ! current_user_can( 'promote_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'workflow-captain' ), 403 );
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
		add_settings_error( Settings::OPTION, 'wfc-roles', $changes ? __( 'Capabilities saved.', 'workflow-captain' ) : __( 'No capabilities changed.', 'workflow-captain' ), $changes ? 'success' : 'info' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'roles', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function status(): void {
		$d    = $this->plugin->diagnostics();
		$env  = $d['environment'];
		$rows = array(
			__( 'Site time zone', 'workflow-captain' )          => \WorkflowCaptain\Settings::timezone(),
			__( 'Database schema', 'workflow-captain' )                => $env['schema'] . ' / ' . $env['schema_expected'],
			__( 'Database update', 'workflow-captain' )    => array( 'held' => __( 'in progress', 'workflow-captain' ), 'stale' => __( 'aborted – will be retried on the next admin page load, via cron or WP-CLI', 'workflow-captain' ) )[ $env['upgrade_lock'] ?? '' ] ?? __( 'none pending', 'workflow-captain' ),
			__( 'Next worker run', 'workflow-captain' )     => isset( $env['next_worker_run'] ) ? Time::show( (string) $env['next_worker_run'] ) . ' (' . Time::label() . ')' : __( 'not scheduled', 'workflow-captain' ),
			__( 'Worker scheduling', 'workflow-captain' ) => 'action_scheduler' === ( $env['scheduler'] ?? '' ) ? __( 'Action Scheduler (from WooCommerce)', 'workflow-captain' ) : __( 'WP-Cron', 'workflow-captain' ),
			__( 'WP-Cron disabled', 'workflow-captain' )           => $env['wp_cron_disabled'] ? __( 'yes – system cron required: wp workflow-captain process', 'workflow-captain' ) : __( 'no', 'workflow-captain' ),
			__( 'Custom secret key', 'workflow-captain' ) => Vault::own_key_configured() ? __( 'set', 'workflow-captain' ) : __( 'not set – recommended: WORKFLOW_CAPTAIN_SECRET_KEY in wp-config.php', 'workflow-captain' ),
			__( 'Stuck steps (lock expired more than a day ago)', 'workflow-captain' ) => (string) $d['stuck']['expired_leases'],
			__( 'Runs without an open step', 'workflow-captain' )     => (string) $d['stuck']['runs_without_steps'],
		);
		echo '<table class="widefat striped" tabindex="0" style="max-width:900px"><caption class="screen-reader-text">' . esc_html__( 'Installation status', 'workflow-captain' ) . '</caption><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: option name */ __( 'Stored in the option %s.', 'workflow-captain' ), Install::DB_OPTION ) ) . '</p>';
		$this->transfer();
		$this->audit();
	}

	/** Export, import with preview, reset of all settings and undo (SET-WC-11, SET-WC-23). */
	private function transfer(): void {
		$post = esc_url( admin_url( 'admin-post.php' ) );
		echo '<h2>' . esc_html__( 'Transfer settings', 'workflow-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'The file contains all settings except the recipient addresses; secrets and credentials are never included. An import first shows what will change.', 'workflow-captain' ) . '</p>';
		echo '<form method="post" action="' . $post . '" style="display:inline-block;margin-right:1em"><input type="hidden" name="action" value="workflow_captain_settings_export">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post is esc_url() above.
		wp_nonce_field( 'workflow_captain_settings_export' );
		submit_button( __( 'Export settings', 'workflow-captain' ), 'secondary', 'submit', false, array( 'id' => 'wfc-export-submit' ) );
		echo '</form>';
		$preview = get_transient( 'workflow_captain_settings_import_' . get_current_user_id() );
		if ( is_array( $preview ) && isset( $preview['values'], $preview['diff'] ) ) {
			echo '<div id="wfc-import-preview" class="notice notice-info inline" tabindex="-1" data-wfc-focus role="region" aria-labelledby="wfc-import-preview-title"><p><strong id="wfc-import-preview-title">' . esc_html__( 'Import preview', 'workflow-captain' ) . '</strong></p>';
			if ( array() === $preview['diff'] ) {
				echo '<p>' . esc_html__( 'The file changes nothing.', 'workflow-captain' ) . '</p>';
			} else {
				echo '<table class="widefat striped" tabindex="0" style="max-width:700px"><caption class="screen-reader-text">' . esc_html__( 'Changes from the import', 'workflow-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Setting', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Previous', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'New', 'workflow-captain' ) . '</th></tr></thead><tbody>';
				foreach ( $preview['diff'] as $key => $pair ) {
					echo '<tr><th scope="row">' . esc_html( self::field_label( (string) $key ) ) . ' <code>' . esc_html( (string) $key ) . '</code></th><td>' . esc_html( self::display_value( (string) $key, $pair[0] ) ) . '</td><td>' . esc_html( self::display_value( (string) $key, $pair[1] ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			if ( ! empty( $preview['ignored'] ) && is_array( $preview['ignored'] ) ) {
				/* translators: %s: list of settings */
				echo '<p>' . esc_html( sprintf( __( 'Not applied because not transferable (like the recipient addresses) or unknown: %s.', 'workflow-captain' ), implode( ', ', array_map( static fn( $k ): string => self::field_label( (string) $k ), $preview['ignored'] ) ) ) ) . '</p>';
			}
			echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="workflow_captain_settings_import"><input type="hidden" name="confirm" value="1">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post is esc_url() above.
			wp_nonce_field( 'workflow_captain_settings_import' );
			echo '<p>';
			submit_button( __( 'Apply', 'workflow-captain' ), 'primary', 'submit', false, array_merge( array( 'id' => 'wfc-import-apply' ), array() === $preview['diff'] ? array( 'disabled' => 'disabled' ) : array() ) );
			echo ' ';
			submit_button( __( 'Discard', 'workflow-captain' ), 'secondary', 'discard', false, array( 'id' => 'wfc-import-discard' ) );
			echo '</p></form></div>';
		} else {
			echo '<form method="post" enctype="multipart/form-data" action="' . $post . '" style="display:inline-block"><input type="hidden" name="action" value="workflow_captain_settings_import">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post is esc_url() above.
			wp_nonce_field( 'workflow_captain_settings_import' );
			echo '<label for="wfc-settings-file">' . esc_html__( 'Settings file', 'workflow-captain' ) . '</label> <input id="wfc-settings-file" type="file" name="settings_file" accept="application/json,.json" required aria-describedby="wfc-settings-file-help"> ';
			submit_button( __( 'Show preview', 'workflow-captain' ), 'secondary', 'submit', false, array( 'id' => 'wfc-import-preview-submit' ) );
			echo '<p id="wfc-settings-file-help" class="description">' . esc_html__( 'A JSON file from “Export settings”, at most 64 KB.', 'workflow-captain' ) . '</p>';
			echo '</form>';
		}
		echo '<h3>' . esc_html__( 'Reset', 'workflow-captain' ) . '</h3>';
		echo '<form method="post" action="' . $post . '" style="display:inline-block;margin-right:1em"><input type="hidden" name="action" value="workflow_captain_settings_reset">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post is esc_url() above.
		wp_nonce_field( 'workflow_captain_settings_reset' );
		echo '<label for="wfc-reset-scope">' . esc_html__( 'Section', 'workflow-captain' ) . '</label> <select id="wfc-reset-scope" name="tab"><option value="">' . esc_html__( 'All settings', 'workflow-captain' ) . '</option><option value="execution">' . esc_html__( 'Execution', 'workflow-captain' ) . '</option><option value="notify">' . esc_html__( 'Notifications', 'workflow-captain' ) . '</option><option value="privacy">' . esc_html__( 'Privacy & retention', 'workflow-captain' ) . '</option></select> ';
		submit_button( __( 'Set to defaults', 'workflow-captain' ), 'secondary', 'submit', false, array( 'id' => 'wfc-reset-submit', 'onclick' => 'return confirm(' . wp_json_encode( __( 'Set the settings of the selected section to their default values? You can undo this for 30 days, until the next import or reset.', 'workflow-captain' ) ) . ')' ) );
		echo '</form>';
		$backup = \WorkflowCaptain\SettingsTransfer::backup();
		if ( null !== $backup ) {
			echo '<form method="post" action="' . $post . '" style="display:inline-block"><input type="hidden" name="action" value="workflow_captain_settings_undo">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post is esc_url() above.
			wp_nonce_field( 'workflow_captain_settings_undo' );
			$when = wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) $backup['at'] );
			// Long on a phone: the button wraps instead of widening the page (found at 375 px).
			submit_button(
				/* translators: %s: date and time of the backup */
				sprintf( __( 'Undo: restore the state from %s', 'workflow-captain' ), $when ),
				'secondary',
				'submit',
				false,
				array(
					'id'      => 'wfc-undo-submit',
					'style'   => 'white-space:normal;height:auto;max-width:100%;text-align:left',
					/* translators: %s: date and time of the backup */
					'onclick' => 'return confirm(' . wp_json_encode( sprintf( __( 'Reset all settings to the state of %s? Later changes will be lost. After that, there is no further undo.', 'workflow-captain' ), $when ) ) . ')',
				)
			);
			echo '</form>';
		}
	}

	/**
	 * Capability, POST and nonce for the transfer actions (export, import, reset, undo). admin-post.php also runs
	 * its hooks for GET; a changing action is only taken from the form, never from a link.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_workflow_captain' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'workflow-captain' ), 403 );
		}
		if ( ! self::is_post( $_SERVER ) ) {
			wp_die( esc_html__( 'This action is only possible via the form.', 'workflow-captain' ), 405 );
		}
		check_admin_referer( $action );
	}

	/** @param array<string,mixed> $server */
	public static function is_post( array $server ): bool {
		return 'POST' === strtoupper( is_string( $server['REQUEST_METHOD'] ?? null ) ? $server['REQUEST_METHOD'] : '' );
	}

	/**
	 * Text of the uploaded settings file, or '' when there is none, it failed, is too large or not an upload. Reads
	 * only PHP's temp file of this request; nothing is written.
	 *
	 * @param mixed $file Entry of $_FILES.
	 */
	public static function uploaded_text( $file, int $max = 65536 ): string {
		if ( ! is_array( $file ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_int( $file['error'] ?? null ) || UPLOAD_ERR_OK !== $file['error'] ) {
			return '';
		}
		$tmp = $file['tmp_name'];
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return '';
		}
		$size = filesize( $tmp );
		if ( false === $size || $size < 1 || $size > $max ) {
			return '';
		}
		return (string) file_get_contents( $tmp, false, null, 0, $max ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded temp file.
	}

	/** Sends the export file (SET-WC-11). */
	public function handle_export(): void {
		self::guard( 'workflow_captain_settings_export' );
		$json = (string) wp_json_encode( \WorkflowCaptain\SettingsTransfer::export( \WorkflowCaptain\SettingsTransfer::current(), WORKFLOW_CAPTAIN_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="workflow-captain-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
		exit;
	}

	/** Step 1: upload and preview; step 2: confirm or discard (SET-WC-11). */
	public function handle_import(): void {
		self::guard( 'workflow_captain_settings_import' );
		$key = 'workflow_captain_settings_import_' . get_current_user_id();
		if ( isset( $_POST['confirm'] ) ) {
			$preview = get_transient( $key );
			delete_transient( $key );
			if ( isset( $_POST['discard'] ) ) {
				add_settings_error( Settings::OPTION, 'wfc-import', __( 'Import discarded. Nothing was changed.', 'workflow-captain' ), 'info' );
			} elseif ( ! is_array( $preview ) || ! isset( $preview['values'] ) ) {
				// The preview lives 15 minutes; "Übernehmen" after that must not read as a discard by the operator.
				add_settings_error( Settings::OPTION, 'wfc-import', __( 'The preview has expired (after 15 minutes). Nothing was changed; choose the file again.', 'workflow-captain' ), 'error' );
			} else {
				$changed = \WorkflowCaptain\SettingsTransfer::apply( $preview['values'], 'import' );
				/* translators: %d: number of changed settings */
				add_settings_error( Settings::OPTION, 'wfc-import', sprintf( __( '%d settings applied. You can undo this for 30 days, until the next import or reset.', 'workflow-captain' ), count( $changed ) ), 'success' );
			}
		} else {
			$text = self::uploaded_text( $_FILES['settings_file'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only PHP's temp file of this upload is read (is_uploaded_file), its content is checked by parse().
			$parsed = \WorkflowCaptain\SettingsTransfer::parse( $text );
			if ( array() !== $parsed['errors'] ) {
				$messages = array();
				foreach ( $parsed['errors'] as $field => $code ) {
					$messages[] = '_file' === $field ? self::import_error( $code ) : self::field_label( (string) $field ) . ' (' . $field . '): ' . self::import_error( $code );
				}
				add_settings_error( Settings::OPTION, 'wfc-import', __( 'The file was not applied:', 'workflow-captain' ) . ' ' . implode( '; ', $messages ), 'error' );
			} else {
				set_transient( $key, array( 'values' => $parsed['values'], 'ignored' => $parsed['ignored'], 'diff' => \WorkflowCaptain\SettingsTransfer::diff( \WorkflowCaptain\SettingsTransfer::current(), $parsed['values'] ) ), 15 * MINUTE_IN_SECONDS );
			}
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'status', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function import_error( string $code ): string {
		$texts = array(
			'not_a_settings_file' => __( 'not a Workflow Captain settings file', 'workflow-captain' ),
			'other_plugin'        => __( 'the file belongs to a different plugin', 'workflow-captain' ),
			'no_format'           => __( 'Format number missing', 'workflow-captain' ),
			'newer_format'        => __( 'the file comes from a newer version; update Workflow Captain', 'workflow-captain' ),
			'not_a_number'        => __( 'not a whole number', 'workflow-captain' ),
			'out_of_range'        => __( 'outside the allowed range', 'workflow-captain' ),
			'not_a_list'          => __( 'not a list', 'workflow-captain' ),
			'unknown_kind'        => __( 'unknown type', 'workflow-captain' ),
			'invalid_address'     => __( 'invalid address', 'workflow-captain' ),
		);
		return $texts[ $code ] ?? $code;
	}

	/** Resets one tab or all settings on the server, with a backup for undo (SET-WC-23). */
	public function handle_reset(): void {
		self::guard( 'workflow_captain_settings_reset' );
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$tab = in_array( $tab, array( 'execution', 'messages', 'notify', 'privacy' ), true ) ? $tab : '';
		$changed = \WorkflowCaptain\SettingsTransfer::apply( \WorkflowCaptain\SettingsTransfer::reset_values( '' === $tab ? null : $tab ), 'reset' );
		/* translators: %d: number of changed settings */
		add_settings_error( Settings::OPTION, 'wfc-reset', sprintf( __( '%d settings reset to defaults. You can undo this for 30 days, until the next import or reset.', 'workflow-captain' ), count( $changed ) ), 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'status', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Restores the backup of the last reset or import once (SET-WC-23). */
	public function handle_undo(): void {
		self::guard( 'workflow_captain_settings_undo' );
		$changed = \WorkflowCaptain\SettingsTransfer::undo();
		/* translators: %d: number of restored settings */
		add_settings_error( Settings::OPTION, 'wfc-undo', null === $changed ? __( 'There is nothing to undo.', 'workflow-captain' ) : sprintf( __( '%d settings restored.', 'workflow-captain' ), count( $changed ) ), null === $changed ? 'error' : 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'status', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
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
			'settings'  => __( 'Settings changed', 'workflow-captain' ),
			'settings_import' => __( 'Settings imported', 'workflow-captain' ),
			'settings_reset'  => __( 'Settings reset to defaults', 'workflow-captain' ),
			'settings_undo'   => __( 'Settings restored', 'workflow-captain' ),
			'settings_cli'    => __( 'Setting changed via WP-CLI', 'workflow-captain' ),
			'roles'     => __( 'Role capabilities changed', 'workflow-captain' ),
			'pause_on'  => __( 'Emergency pause turned on', 'workflow-captain' ),
			'pause_off' => __( 'Emergency pause lifted', 'workflow-captain' ),
			'connector' => __( 'Approval of a custom connector changed', 'workflow-captain' ),
			'requeue'          => __( 'Events re-queued', 'workflow-captain' ),
			'run_cancel'       => __( 'Run canceled', 'workflow-captain' ),
			'run_continue'     => __( 'Step resumed', 'workflow-captain' ),
			'workflow_publish' => __( 'Workflow published', 'workflow-captain' ),
			'workflow_archive' => __( 'Workflow archived', 'workflow-captain' ),
			'workflow_restore' => __( 'Workflow restored', 'workflow-captain' ),
			'workflow_pause'   => __( 'Workflow paused', 'workflow-captain' ),
			'workflow_resume'  => __( 'Workflow resumed', 'workflow-captain' ),
			'upgrade_resumed'  => __( 'Retried interrupted database update', 'workflow-captain' ),
			'run_export'       => __( 'Run exported', 'workflow-captain' ),
			'log_level_reverted' => __( 'Detailed step log switched back to normal after 7 days', 'workflow-captain' ),
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
		echo '<h2>' . esc_html__( 'Recent security-relevant changes', 'workflow-captain' ) . '</h2>';
		$entries = array_slice( Audit::entries(), 0, 20 );
		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'No entries yet.', 'workflow-captain' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" tabindex="0"><caption class="screen-reader-text">' . esc_html__( 'Recent security-relevant changes', 'workflow-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html( sprintf( /* translators: %s: time zone */ __( 'Time (%s)', 'workflow-captain' ), Time::label() ) ) . '</th><th scope="col">' . esc_html__( 'User', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Change', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Details', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $entries as $e ) {
			$user   = get_userdata( (int) $e['user'] );
			$detail = array();
			$named  = 'settings' === $e['what'] || str_starts_with( (string) $e['what'], 'settings_' );
			foreach ( (array) $e['detail'] as $key => $value ) {
				$detail[] = ( $named ? self::field_label( (string) $key ) : $key ) . ': ' . ( $named && is_array( $value ) && 2 === count( $value ) && \WorkflowCaptain\SettingsRegistry::field( (string) $key ) ? self::display_value( (string) $key, array_values( $value )[0] ) . ' → ' . self::display_value( (string) $key, array_values( $value )[1] ) : self::audit_value( $value ) );
			}
			echo '<tr><td>' . esc_html( Time::show( (string) $e['at'] ) ) . '</td><td>' . esc_html( $user ? $user->display_name : ( 0 === (int) $e['user'] ? __( 'System/WP-CLI', 'workflow-captain' ) : '#' . (int) $e['user'] ) ) . '</td><td>' . esc_html( $labels[ $e['what'] ] ?? (string) $e['what'] ) . '</td><td>' . esc_html( implode( '; ', $detail ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: 1: number, 2: option name */ __( 'The last %1$d entries are kept in the option %2$s (adjustable in the Privacy & retention tab).', 'workflow-captain' ), Audit::keep(), Audit::OPTION ) ) . '</p>';
	}
}
