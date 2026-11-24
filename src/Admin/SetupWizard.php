<?php
/**
 * Setup assistant (SET-WC-27): three steps and a summary, built on what exists – country profile (CountryProfiles),
 * sender, reply address and footer (Messages, register fields) and a first recipe (TemplatesPage, assets/recipes,
 * copies of docs/recipes).
 *
 * - Every step can be skipped; moving between the steps writes nothing. The choices travel in the form itself (no
 *   transient, nothing stored on the way), so cancelling at any point leaves everything as it was.
 * - The summary shows every change as before → after, values of a profile with legal weight marked "unchecked".
 *   Only the button there writes: settings through SettingsTransfer::apply() (strict check per field, backup for
 *   undo, audit entry "settings_setup"), the recipe through Transfer::import() as a draft, never published.
 * - A notice on the overview and on the plugin screen offers the assistant until it is finished or hidden; the
 *   state is kept per site in the option workflow_captain_setup (autoload no), without user or address.
 * - Everything needs manage_workflow_captain (SET-WC-30); every changing action is a POST with its own nonce.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Audit;
use WorkflowCaptain\CountryProfiles;
use WorkflowCaptain\Engine\Transfer;
use WorkflowCaptain\Engine\Workflows;
use WorkflowCaptain\Messages;
use WorkflowCaptain\Plugin;
use WorkflowCaptain\SettingsRegistry;
use WorkflowCaptain\SettingsTransfer;

defined( 'ABSPATH' ) || exit;

final class SetupWizard {

	const SLUG = 'workflow-captain-setup';

	/** State of the assistant on this site: array( state => done|dismissed, at => time ). */
	const OPTION = 'workflow_captain_setup';

	/** Source of the settings change in the backup and the audit log (audit entry "settings_setup"). */
	const SOURCE = 'setup';

	const STEPS = array( 'profile', 'messages', 'recipe', 'summary' );

	/** Fields of step 2; the time zone is part of the country profile. */
	const MESSAGE_KEYS = array( 'sender_name', 'sender_email', 'reply_to', 'message_footer' );

	const DONE      = 'done';
	const DISMISSED = 'dismissed';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 31 );
		add_action( 'admin_post_workflow_captain_setup_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_workflow_captain_setup_dismiss', array( $this, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'plugins_notice' ) );
	}

	public function menu(): void {
		add_submenu_page( OverviewPage::SLUG, __( 'Setup', 'workflow-captain' ), __( 'Setup', 'workflow-captain' ), SettingsRegistry::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/** State of this site: '' (open), done or dismissed. */
	public static function state(): string {
		$s = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$s = is_array( $s ) ? (string) ( $s['state'] ?? '' ) : '';
		return in_array( $s, array( self::DONE, self::DISMISSED ), true ) ? $s : '';
	}

	/** Whether the notice "Start setup" is shown: until the assistant is finished or the notice hidden. */
	public static function notice_wanted(): bool {
		return '' === self::state();
	}

	/** Marks the assistant finished or hidden on this site (autoload no; no user, no address). */
	public static function close( string $state ): bool {
		if ( ! in_array( $state, array( self::DONE, self::DISMISSED ), true ) ) {
			return false;
		}
		update_option( self::OPTION, array( 'state' => $state, 'at' => time() ), false );
		return true;
	}

	/**
	 * The choices from the form of the assistant (unslashed request). Never writes.
	 *
	 * - profile: key of a country profile or '' (skipped), keys: the values of the profile selected for takeover;
	 * - messages: the four fields of step 2, or null when the step was skipped or not reached;
	 * - recipe: key of a template or '' (none).
	 *
	 * @param array<string,mixed>                $in
	 * @param array<string,array<string,string>> $templates TemplatesPage::templates().
	 * @return array{profile:string,keys:string[],messages:?array<string,string>,recipe:string}
	 */
	public static function read( array $in, array $templates ): array {
		$profile = is_scalar( $in['profile'] ?? null ) ? strtoupper( preg_replace( '/[^a-z]/i', '', (string) $in['profile'] ) ?? '' ) : '';
		$profile = CountryProfiles::exists( $profile ) ? $profile : '';
		$keys    = array();
		if ( '' !== $profile && is_array( $in['keys'] ?? null ) ) {
			foreach ( $in['keys'] as $key ) {
				$key = is_scalar( $key ) ? (string) $key : '';
				if ( isset( CountryProfiles::values( $profile )[ $key ] ) && ! in_array( $key, $keys, true ) ) {
					$keys[] = $key;
				}
			}
		}
		$messages = null;
		if ( '1' === ( is_scalar( $in['msg_set'] ?? null ) ? (string) $in['msg_set'] : '' ) ) {
			$messages = array();
			foreach ( self::MESSAGE_KEYS as $key ) {
				$messages[ $key ] = is_scalar( $in[ $key ] ?? null ) ? (string) $in[ $key ] : '';
			}
		}
		$recipe = is_scalar( $in['recipe'] ?? null ) ? strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $in['recipe'] ) ?? '' ) : '';
		return array(
			'profile'  => $profile,
			'keys'     => $keys,
			'messages' => $messages,
			'recipe'   => isset( $templates[ $recipe ] ) ? $recipe : '',
		);
	}

	/**
	 * Errors of step 2: field => error code of SettingsTransfer::check() (invalid_address, too_long, not_text).
	 *
	 * @param array<string,string>|null $messages
	 * @return array<string,string>
	 */
	public static function message_errors( ?array $messages ): array {
		$errors = array();
		foreach ( null === $messages ? array() : $messages as $key => $value ) {
			list( $ok, , $code ) = SettingsTransfer::check( (string) $key, $value );
			if ( ! $ok ) {
				$errors[ $key ] = $code;
			}
		}
		return $errors;
	}

	/**
	 * Values the plan would write, strictly checked: the selected values of the profile that differ from now and the
	 * fields of step 2 that differ from now. Returns array( values, errors ). Never writes.
	 *
	 * @param array<string,mixed> $plan    read().
	 * @param array<string,mixed> $current SettingsTransfer::current().
	 * @return array{0:array<string,mixed>,1:array<string,string>}
	 */
	public static function values( array $plan, array $current ): array {
		$values = array();
		$errors = array();
		if ( '' !== $plan['profile'] && array() !== $plan['keys'] ) {
			list( $values, $errors ) = CountryProfiles::selection( $plan['profile'], $plan['keys'], $current );
		}
		foreach ( null === $plan['messages'] ? array() : $plan['messages'] as $key => $value ) {
			list( $ok, $clean, $code ) = SettingsTransfer::check( (string) $key, $value );
			if ( ! $ok ) {
				$errors[ $key ] = $code;
			} elseif ( ( $current[ $key ] ?? '' ) !== $clean ) {
				$values[ $key ] = $clean;
			}
		}
		return array( $values, $errors );
	}

	/**
	 * Rows of the summary: key => array( current, new, checked ). `checked` false marks a value of the profile with
	 * legal weight that has not been reviewed; fields of step 2 are the operator's own text (true).
	 *
	 * @return array<string,array{0:mixed,1:mixed,2:bool}>
	 */
	public static function summary( array $plan, array $current ): array {
		list( $values ) = self::values( $plan, $current );
		$profile        = '' !== $plan['profile'] ? CountryProfiles::values( $plan['profile'] ) : array();
		$rows           = array();
		foreach ( SettingsTransfer::diff( $current, $values ) as $key => $pair ) {
			$rows[ $key ] = array( $pair[0], $pair[1], isset( $profile[ $key ] ) && ! in_array( $key, self::MESSAGE_KEYS, true ) ? (bool) $profile[ $key ][1] : true );
		}
		return $rows;
	}

	/** The step after $step (summary stays summary). */
	public static function next_step( string $step ): string {
		$i = array_search( $step, self::STEPS, true );
		return false === $i ? self::STEPS[0] : self::STEPS[ min( $i + 1, count( self::STEPS ) - 1 ) ];
	}

	/** The step before $step (profile stays profile). */
	public static function previous_step( string $step ): string {
		$i = array_search( $step, self::STEPS, true );
		return false === $i ? self::STEPS[0] : self::STEPS[ max( $i - 1, 0 ) ];
	}

	/**
	 * Where a click in the assistant leads, and with which choices. Never writes.
	 *
	 * The form names the step it was shown on (`wfc_from`) and the button pressed: `wfc_skip` (skip this step: its
	 * choice is dropped), `wfc_back`, `wfc_preview` (show the proposal of the chosen profile; values without legal
	 * weight are preselected, values with legal weight never) or `wfc_next`. `wfc_next` with a profile whose proposal
	 * was not shown (`shown_profile`) shows that proposal instead of taking a selection over. Going forward from a step with an error
	 * stays on that step.
	 *
	 * @param array<string,mixed>                $in        Unslashed request; empty: the first step.
	 * @param array<string,mixed>                $current   SettingsTransfer::current().
	 * @param array<string,array<string,string>> $templates TemplatesPage::templates().
	 * @return array{step:string,plan:array,errors:array<string,string>}
	 */
	public static function navigate( array $in, array $current, array $templates ): array {
		$plan = self::read( $in, $templates );
		$from = is_scalar( $in['wfc_from'] ?? null ) && in_array( (string) $in['wfc_from'], self::STEPS, true ) ? (string) $in['wfc_from'] : '';
		if ( '' === $from ) {
			return array( 'step' => self::STEPS[0], 'plan' => $plan, 'errors' => array() );
		}
		if ( isset( $in['wfc_skip'] ) ) {
			if ( 'profile' === $from ) {
				$plan['profile'] = '';
				$plan['keys']    = array();
			} elseif ( 'messages' === $from ) {
				$plan['messages'] = null;
			} elseif ( 'recipe' === $from ) {
				$plan['recipe'] = '';
			}
			return array( 'step' => self::next_step( $from ), 'plan' => $plan, 'errors' => array() );
		}
		if ( isset( $in['wfc_back'] ) ) {
			return array( 'step' => self::previous_step( $from ), 'plan' => $plan, 'errors' => array() );
		}
		// A profile changed in the list without its proposal shown first: show that proposal, take no selection over.
		$shown = is_scalar( $in['shown_profile'] ?? null ) ? (string) $in['shown_profile'] : '';
		if ( 'profile' === $from && ( isset( $in['wfc_preview'] ) || ( '' !== $plan['profile'] && $shown !== $plan['profile'] ) ) ) {
			$plan['keys'] = array();
			foreach ( '' === $plan['profile'] ? array() : CountryProfiles::diff( $plan['profile'], $current ) as $key => $row ) {
				if ( $row[2] ) {
					$plan['keys'][] = (string) $key;
				}
			}
			return array( 'step' => 'profile', 'plan' => $plan, 'errors' => array() );
		}
		$errors = array();
		if ( 'profile' === $from && '' !== $plan['profile'] ) {
			$errors = CountryProfiles::selection( $plan['profile'], $plan['keys'], $current )[1];
		} elseif ( 'messages' === $from ) {
			$errors = self::message_errors( $plan['messages'] );
		}
		return array( 'step' => array() === $errors ? self::next_step( $from ) : $from, 'plan' => $plan, 'errors' => $errors );
	}

	/**
	 * Carries out the plan after the confirmation in the summary: the settings in one change (backup for undo, audit
	 * entry "settings_setup"), then the recipe as a draft (audit entry "setup_recipe"), then marks the assistant done.
	 * On an error in the settings nothing is written.
	 *
	 * @param array<string,array<string,string>> $templates TemplatesPage::templates().
	 * @return array{ok:bool,errors:array<string,string>,changed:array,workflow:int,violations:string[]}
	 */
	public static function finish( array $plan, Workflows $workflows, array $templates ): array {
		list( $values, $errors ) = self::values( $plan, SettingsTransfer::current() );
		if ( array() !== $errors ) {
			return array( 'ok' => false, 'errors' => $errors, 'changed' => array(), 'workflow' => 0, 'violations' => array() );
		}
		$changed    = array() === $values ? array() : SettingsTransfer::apply( $values, self::SOURCE );
		$workflow   = 0;
		$violations = array();
		if ( '' !== $plan['recipe'] && isset( $templates[ $plan['recipe'] ] ) ) {
			// Transfer::import() always saves a draft; publishing stays a separate step after review and a test run.
			$result     = Transfer::import( TemplatesPage::localized( $templates[ $plan['recipe'] ]['file'] ), $workflows );
			$violations = $result['violations'];
			if ( $result['ok'] ) {
				$workflow = (int) $result['id'];
				Audit::record( 'setup_recipe', array( 'recipe' => $plan['recipe'], 'workflow' => $workflow ) );
			}
		}
		self::close( self::DONE );
		return array( 'ok' => true, 'errors' => array(), 'changed' => $changed, 'workflow' => $workflow, 'violations' => $violations );
	}

	/**
	 * The notice offering the assistant (overview and plugin screen); empty when it is finished or hidden.
	 */
	public static function notice_html(): string {
		if ( ! self::notice_wanted() ) {
			return '';
		}
		$html  = '<div class="notice notice-info wfc-setup-notice" role="region" aria-labelledby="wfc-setup-notice-title"><p><strong id="wfc-setup-notice-title">' . esc_html__( 'Workflow Captain: set up the basics', 'workflow-captain' ) . '</strong></p>';
		$html .= '<p>' . esc_html__( 'Three short steps: country profile, sender and footer, first recipe. Every step can be skipped, and nothing is changed until you confirm the summary.', 'workflow-captain' ) . '</p>';
		$html .= '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 8px"><a class="button button-primary" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Start setup', 'workflow-captain' ) . '</a>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0"><input type="hidden" name="action" value="workflow_captain_setup_dismiss">' . wp_nonce_field( 'workflow_captain_setup_dismiss', '_wpnonce', true, false );
		$html .= '<button type="submit" class="button button-link">' . esc_html__( 'Hide this notice', 'workflow-captain' ) . '</button></form></div>';
		return $html . '<p class="description">' . esc_html__( 'You can start the setup later at any time under Workflow Captain → Setup.', 'workflow-captain' ) . '</p></div>';
	}

	/** The notice on the plugin screen of this site, right after activation and until finished or hidden. */
	public function plugins_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'plugins' !== $screen->id || ! current_user_can( SettingsRegistry::CAPABILITY ) ) {
			return;
		}
		echo self::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- notice_html() escapes.
	}

	/** Hides the notice on this site (POST with nonce). */
	public function handle_dismiss(): void {
		self::guard( 'workflow_captain_setup_dismiss' );
		self::close( self::DISMISSED );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : add_query_arg( array( 'page' => OverviewPage::SLUG ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Carries out the confirmed summary (POST with nonce). */
	public function handle_apply(): void {
		self::guard( 'workflow_captain_setup_apply' );
		$templates = TemplatesPage::templates();
		$result    = self::finish( self::read( wp_unslash( $_POST ), $templates ), $this->plugin->workflows(), $templates ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read() keeps known keys only; every value is checked strictly.
		set_transient(
			'workflow_captain_setup_' . get_current_user_id(),
			array(
				'ok'       => $result['ok'],
				'errors'   => $result['errors'],
				'changed'  => count( $result['changed'] ),
				'workflow' => $result['workflow'],
				'findings' => count( $result['violations'] ),
			),
			300
		);
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'finished' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( SettingsRegistry::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'workflow-captain' ), 403 );
		}
		if ( ! SettingsPage::is_post( $_SERVER ) ) {
			wp_die( esc_html__( 'This action is only possible via the form.', 'workflow-captain' ), 405 );
		}
		check_admin_referer( $action );
	}

	public function render(): void {
		if ( ! current_user_can( SettingsRegistry::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Setup', 'workflow-captain' ) . '</h1>';
		if ( isset( $_GET['finished'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$this->render_result();
			echo '</div>';
			return;
		}
		$templates = TemplatesPage::templates();
		$current   = SettingsTransfer::current();
		$in        = array();
		// Moving between the steps writes nothing; the nonce only makes sure the choices come from this form.
		if ( SettingsPage::is_post( $_SERVER ) && isset( $_POST['_wfc_setup_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wfc_setup_nonce'] ) ), 'workflow_captain_setup_step' ) ) {
			$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read() keeps known keys only.
		}
		$nav = self::navigate( $in, $current, $templates );
		self::progress( $nav['step'] );
		if ( 'summary' === $nav['step'] ) {
			$this->step_summary( $nav['plan'], $current, $templates );
		}
		echo '<form method="post" action="' . esc_url( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) ) . '">';
		wp_nonce_field( 'workflow_captain_setup_step', '_wfc_setup_nonce' );
		echo '<input type="hidden" name="wfc_from" value="' . esc_attr( $nav['step'] ) . '">';
		switch ( $nav['step'] ) {
			case 'profile':
				$this->step_profile( $nav['plan'], $current, $nav['errors'] );
				break;
			case 'messages':
				$this->step_messages( $nav['plan'], $current, $nav['errors'] );
				break;
			case 'recipe':
				$this->step_recipe( $nav['plan'], $templates );
				break;
			default:
				self::hidden( $nav['plan'], '' );
		}
		echo '<p class="submit" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
		// "Next" comes first in the markup, so Enter in a field goes forward; "Back" is still shown on the left.
		if ( 'summary' !== $nav['step'] ) {
			submit_button( __( 'Next', 'workflow-captain' ), 'primary', 'wfc_next', false, array( 'id' => 'wfc-setup-next' ) );
			submit_button( __( 'Skip this step', 'workflow-captain' ), 'secondary', 'wfc_skip', false, array( 'id' => 'wfc-setup-skip' ) );
		}
		if ( 'profile' !== $nav['step'] ) {
			submit_button( __( 'Back', 'workflow-captain' ), 'secondary', 'wfc_back', false, array( 'id' => 'wfc-setup-back', 'style' => 'order:-1' ) );
		}
		echo '</p></form>';
		echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => OverviewPage::SLUG ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Cancel setup – nothing is changed', 'workflow-captain' ) . '</a></p></div>';
	}

	/** Steps as an ordered list; the current one is named for screen readers. */
	private static function progress( string $step ): void {
		$labels = self::step_labels();
		echo '<ol class="wfc-setup-steps" style="display:flex;flex-wrap:wrap;gap:4px 24px;margin:1em 0 1.5em 1.5em">';
		foreach ( self::STEPS as $s ) {
			echo '<li' . ( $s === $step ? ' aria-current="step" style="font-weight:600"' : '' ) . '>' . esc_html( $labels[ $s ] ) . '</li>';
		}
		echo '</ol>';
	}

	/** @return array<string,string> */
	public static function step_labels(): array {
		return array(
			'profile'  => __( 'Country profile', 'workflow-captain' ),
			'messages' => __( 'Sender and footer', 'workflow-captain' ),
			'recipe'   => __( 'First recipe', 'workflow-captain' ),
			'summary'  => __( 'Summary', 'workflow-captain' ),
		);
	}

	/** Hidden fields for the choices of every step except the one shown. */
	private static function hidden( array $plan, string $except ): void {
		if ( 'profile' !== $except ) {
			echo '<input type="hidden" name="profile" value="' . esc_attr( $plan['profile'] ) . '">';
			foreach ( $plan['keys'] as $key ) {
				echo '<input type="hidden" name="keys[]" value="' . esc_attr( $key ) . '">';
			}
		}
		if ( 'messages' !== $except && null !== $plan['messages'] ) {
			echo '<input type="hidden" name="msg_set" value="1">';
			foreach ( $plan['messages'] as $key => $value ) {
				echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
			}
		}
		if ( 'recipe' !== $except ) {
			echo '<input type="hidden" name="recipe" value="' . esc_attr( $plan['recipe'] ) . '">';
		}
	}

	private static function badge( bool $checked ): string {
		return $checked ? esc_html__( 'no legal weight', 'workflow-captain' ) : '<span class="wfc-badge wfc-badge-unchecked" style="display:inline-block;padding:0 .5em;border-radius:3px;background:#fcf0f1;color:#8a2424;border:1px solid #d63638">' . esc_html__( 'unchecked – legal review', 'workflow-captain' ) . '</span>';
	}

	/** Step 1: choose a profile, see current and proposed values, select the ones to take over (CountryProfiles). */
	private function step_profile( array $plan, array $current, array $errors ): void {
		self::hidden( $plan, 'profile' );
		$labels = CountryProfiles::labels();
		echo '<h2>' . esc_html__( 'Country profile', 'workflow-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'A country profile only proposes values. You see the current and the proposed value and choose which to take over; nothing is changed without your selection. Values marked “unchecked” have not been legally reviewed yet: check them with your legal advisor before you use them.', 'workflow-captain' ) . '</p>';
		echo '<p><label for="wfc-setup-profile">' . esc_html__( 'Profile', 'workflow-captain' ) . '</label> <select id="wfc-setup-profile" name="profile"><option value="">' . esc_html__( '– no profile –', 'workflow-captain' ) . '</option>';
		foreach ( $labels as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $plan['profile'], $key, false ) . '>' . esc_html( $label . ' (' . $key . ')' ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Show proposal', 'workflow-captain' ), 'secondary', 'wfc_preview', false, array( 'id' => 'wfc-setup-preview' ) );
		echo '</p>';
		if ( '' === $plan['profile'] ) {
			return;
		}
		foreach ( $errors as $field => $code ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( SettingsPage::field_label( (string) $field ) . ' (' . $field . '): ' . SettingsPage::import_error( (string) $code ) ) . '</p></div>';
		}
		$diff = CountryProfiles::diff( $plan['profile'], $current );
		echo '<div id="wfc-setup-proposal" class="notice notice-info inline" role="region" aria-labelledby="wfc-setup-proposal-title"><p><strong id="wfc-setup-proposal-title">' . esc_html( sprintf( /* translators: %s: name of the country profile */ __( 'Proposal of the profile %s', 'workflow-captain' ), $labels[ $plan['profile'] ] ) ) . '</strong></p>';
		echo '<input type="hidden" name="shown_profile" value="' . esc_attr( $plan['profile'] ) . '">';
		if ( array() === $diff ) {
			echo '<p>' . esc_html__( 'Your settings already match this profile. Nothing to change.', 'workflow-captain' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped" tabindex="0" style="max-width:900px"><caption class="screen-reader-text">' . esc_html__( 'Proposed changes of the country profile', 'workflow-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Apply', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Setting', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Current', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Proposed', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Review', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $diff as $key => $row ) {
			$id = 'wfc-setup-key-' . $key;
			echo '<tr><td><input type="checkbox" id="' . esc_attr( $id ) . '" name="keys[]" value="' . esc_attr( (string) $key ) . '"' . checked( in_array( (string) $key, $plan['keys'], true ), true, false ) . '></td>';
			echo '<th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( SettingsPage::field_label( (string) $key ) ) . '</label> <code>' . esc_html( (string) $key ) . '</code></th>';
			echo '<td>' . esc_html( SettingsPage::display_value( (string) $key, $row[0] ) ) . '</td><td>' . esc_html( SettingsPage::display_value( (string) $key, $row[1] ) ) . '</td>';
			echo '<td>' . self::badge( $row[2] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Selected values are only taken over after you confirm the summary at the end.', 'workflow-captain' ) . '</p></div>';
	}

	/** Step 2: sender, reply address and footer (Messages); empty keeps today's behaviour. */
	private function step_messages( array $plan, array $current, array $errors ): void {
		self::hidden( $plan, 'messages' );
		$texts  = SettingsRegistry::texts();
		$values = $plan['messages'] ?? array_intersect_key( $current, array_flip( self::MESSAGE_KEYS ) );
		echo '<h2>' . esc_html__( 'Sender and footer', 'workflow-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'Empty fields keep today’s behaviour: the sender of WordPress or your mail plugin, replies to the sender, no footer.', 'workflow-captain' ) . '</p>';
		echo '<input type="hidden" name="msg_set" value="1"><table class="form-table" role="presentation">';
		foreach ( self::MESSAGE_KEYS as $key ) {
			$f         = SettingsRegistry::field( $key );
			$id        = 'wfc-setup-' . $key;
			$invalid   = isset( $errors[ $key ] );
			$described = trim( ( $invalid ? $id . '-error ' : '' ) . $id . '-help' );
			$attrs     = ' name="' . esc_attr( $key ) . '" aria-describedby="' . esc_attr( $described ) . '"' . ( $invalid ? ' aria-invalid="true"' : '' );
			$value     = (string) ( $values[ $key ] ?? '' );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $texts[ $key ][0] ) . '</label></th><td>';
			if ( SettingsRegistry::TYPE_TEXTAREA === $f['type'] ) {
				echo '<textarea id="' . esc_attr( $id ) . '"' . $attrs . ' rows="4" class="large-text" maxlength="' . (int) $f['max'] . '">' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is escaped above.
			} else {
				echo '<input id="' . esc_attr( $id ) . '"' . $attrs . ' type="' . ( SettingsRegistry::TYPE_EMAIL === $f['type'] ? 'email' : 'text' ) . '" class="regular-text"' . ( SettingsRegistry::TYPE_TEXT === $f['type'] ? ' maxlength="' . (int) $f['max'] . '"' : '' ) . ' value="' . esc_attr( $value ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is escaped above.
			}
			if ( $invalid ) {
				echo '<p id="' . esc_attr( $id . '-error' ) . '" class="wfc-field-error" style="color:#b32d2e">' . esc_html( SettingsPage::import_error( $errors[ $key ] ) ) . '</p>';
			}
			echo '<p id="' . esc_attr( $id . '-help' ) . '" class="description">' . esc_html( $texts[ $key ][1] ) . '</p></td></tr>';
		}
		echo '</table>';
	}

	/** Step 3: a first recipe from the shipped templates, added as a draft. */
	private function step_recipe( array $plan, array $templates ): void {
		self::hidden( $plan, 'recipe' );
		$available = $this->plugin->available_event_types();
		echo '<h2>' . esc_html__( 'First recipe', 'workflow-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'A recipe is added as a draft and never runs on its own. Review it, start a test run, then publish.', 'workflow-captain' ) . '</p>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'First recipe', 'workflow-captain' ) . '</legend>';
		echo '<p><label><input type="radio" name="recipe" value=""' . checked( $plan['recipe'], '', false ) . '> ' . esc_html__( 'No recipe', 'workflow-captain' ) . '</label></p>';
		foreach ( $templates as $key => $t ) {
			$ready = in_array( $t['event'], $available, true );
			echo '<p><label><input type="radio" name="recipe" value="' . esc_attr( (string) $key ) . '"' . checked( $plan['recipe'], (string) $key, false ) . '> ' . esc_html( $t['name'] ) . '</label> <code>' . esc_html( $t['event'] ) . '</code><br><span class="description">' . esc_html( $ready ? __( 'available', 'workflow-captain' ) : __( 'not available – you can create a draft, but publishing requires the provider', 'workflow-captain' ) ) . '</span></p>';
		}
		echo '</fieldset>';
	}

	/** Summary: every change as before → after, and the one button that writes. */
	private function step_summary( array $plan, array $current, array $templates ): void {
		$rows   = self::summary( $plan, $current );
		$errors = self::values( $plan, $current )[1];
		echo '<h2>' . esc_html__( 'Summary', 'workflow-captain' ) . '</h2>';
		if ( array() !== $errors ) {
			$messages = array();
			foreach ( $errors as $field => $code ) {
				$messages[] = SettingsPage::field_label( (string) $field ) . ' (' . $field . '): ' . SettingsPage::import_error( (string) $code );
			}
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html( __( 'Please correct this first; nothing can be applied:', 'workflow-captain' ) . ' ' . implode( '; ', $messages ) ) . '</p></div>';
			return;
		}
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No setting will be changed.', 'workflow-captain' ) . '</p>';
		} else {
			echo '<table class="widefat striped" tabindex="0" style="max-width:900px"><caption class="screen-reader-text">' . esc_html__( 'Changes of the setup', 'workflow-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Setting', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Current', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'New', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Review', 'workflow-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $key => $row ) {
				$empty = __( '(empty)', 'workflow-captain' );
				$old   = SettingsPage::display_value( (string) $key, $row[0] );
				$new   = SettingsPage::display_value( (string) $key, $row[1] );
				echo '<tr><th scope="row">' . esc_html( SettingsPage::field_label( (string) $key ) ) . '</th><td>' . esc_html( '' === $old ? $empty : $old ) . '</td><td>' . esc_html( '' === $new ? $empty : $new ) . '</td>';
				echo '<td>' . ( in_array( $key, self::MESSAGE_KEYS, true ) ? esc_html__( 'your own entry', 'workflow-captain' ) : self::badge( $row[2] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			}
			echo '</tbody></table>';
		}
		echo '<p>' . esc_html(
			'' === $plan['recipe']
				? __( 'No recipe will be added.', 'workflow-captain' )
				/* translators: %s: name of the recipe */
				: sprintf( __( 'The recipe “%s” will be added as a draft. It does not run until you publish it.', 'workflow-captain' ), $templates[ $plan['recipe'] ]['name'] )
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="workflow_captain_setup_apply">';
		wp_nonce_field( 'workflow_captain_setup_apply' );
		self::hidden( $plan, '' );
		echo '<p>';
		submit_button( array() === $rows && '' === $plan['recipe'] ? __( 'Finish setup', 'workflow-captain' ) : __( 'Apply and finish setup', 'workflow-captain' ), 'primary', 'submit', false, array( 'id' => 'wfc-setup-apply' ) );
		echo '</p>';
		if ( array() !== $rows ) {
			echo '<p class="description">' . esc_html__( 'You can undo the changed settings for 30 days in Settings → Status, until the next import, reset or profile.', 'workflow-captain' ) . '</p>';
		}
		echo '</form>';
	}

	/** Result after the summary was confirmed. */
	private function render_result(): void {
		$key    = 'workflow_captain_setup_' . get_current_user_id();
		$result = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $result ) ) {
			echo '<p>' . esc_html( self::DONE === self::state() ? __( 'The setup is finished.', 'workflow-captain' ) : __( 'Nothing was changed.', 'workflow-captain' ) ) . '</p>';
			return;
		}
		if ( empty( $result['ok'] ) ) {
			$messages = array();
			foreach ( (array) $result['errors'] as $field => $code ) {
				$messages[] = SettingsPage::field_label( (string) $field ) . ' (' . $field . '): ' . SettingsPage::import_error( (string) $code );
			}
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html( __( 'Nothing was changed:', 'workflow-captain' ) . ' ' . implode( '; ', $messages ) ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Start setup', 'workflow-captain' ) . '</a></p>';
			return;
		}
		echo '<div class="notice notice-success inline" role="status"><p>' . esc_html__( 'The setup is finished.', 'workflow-captain' ) . '</p></div><ul style="list-style:disc;margin-left:2em">';
		/* translators: %d: number of changed settings */
		echo '<li>' . esc_html( sprintf( _n( '%d setting changed.', '%d settings changed.', (int) $result['changed'], 'workflow-captain' ), (int) $result['changed'] ) ) . '</li>';
		if ( (int) $result['workflow'] > 0 ) {
			echo '<li><a href="' . esc_url( WorkflowsPage::url( WorkflowsPage::SLUG_WORKFLOWS, array( 'edit' => (int) $result['workflow'] ) ) ) . '">' . esc_html__( 'Open the new draft', 'workflow-captain' ) . '</a>';
			if ( (int) $result['findings'] > 0 ) {
				echo ' – ' . esc_html__( 'the editor shows what is still needed before publishing.', 'workflow-captain' );
			}
			echo '</li>';
		}
		echo '</ul><p><a href="' . esc_url( add_query_arg( array( 'page' => SettingsPage::SLUG, 'tab' => 'status' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Settings → Status (undo, change log)', 'workflow-captain' ) . '</a></p>';
	}
}
