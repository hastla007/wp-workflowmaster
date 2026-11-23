<?php
/**
 * End of a settings tab (SET-WC-02, decisions S1, S5, E-S8): the fields an add-on registers through the filter
 * `workflow_captain_settings_fields_v1`, and the grey Pro hint lines.
 *
 * Hint lines are plain text from a static list in the core: no field, no switch, no check of a right. Per S5 a line
 * is only added once the feature it names is sold; until then the list stays empty and nothing is shown. The setting
 * `show_pro_hints` hides every line.
 *
 * Add-on fields are rendered, never stored by the core: each names the add-on's own option (and settings group), the
 * add-on registers that option with register_setting() and sanitizes it.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Settings;
use WorkflowCaptain\SettingsRegistry;

defined( 'ABSPATH' ) || exit;

final class SettingsExtras {

	/** Name of the filter through which an add-on registers fields for a tab. */
	const FILTER = 'workflow_captain_settings_fields_v1';

	/** Tabs that take add-on fields and hint lines (the tabs with settings). */
	const TABS = array( 'general', 'execution', 'messages', 'notify', 'privacy' );

	/** Control types the core can draw for an add-on field without a render callback. */
	const TYPES = array( 'text', 'number', 'checkbox', 'textarea' );

	/**
	 * The static hint lines per tab (translated at call time). Text only. Empty until a feature is sold (S5); a line is
	 * added here, as `'tab' => array( __( '…', 'workflow-captain' ) )`, with the release that sells the feature.
	 *
	 * @return array<string,string[]>
	 */
	public static function hint_lines(): array {
		return array();
	}

	/**
	 * The hint lines to show on one tab: none when `show_pro_hints` is off or the list of the tab is empty.
	 *
	 * @param array<string,string[]>|null $lines List to use instead of hint_lines() (tests).
	 * @return string[]
	 */
	public static function hints( string $tab, ?array $lines = null ): array {
		if ( 1 !== (int) ( Settings::get()['show_pro_hints'] ?? 1 ) ) {
			return array();
		}
		$lines = $lines ?? self::hint_lines();
		$list  = isset( $lines[ $tab ] ) && is_array( $lines[ $tab ] ) ? $lines[ $tab ] : array();
		return array_values( array_filter( array_map( static fn( $l ) => is_string( $l ) ? trim( $l ) : '', $list ), static fn( string $l ): bool => '' !== $l ) );
	}

	/**
	 * Grey, unobtrusive block of hint lines at the end of a tab; empty string when there is nothing to show.
	 *
	 * @param array<string,string[]>|null $lines List to use instead of hint_lines() (tests).
	 */
	public static function hints_html( string $tab, ?array $lines = null ): string {
		$hints = self::hints( $tab, $lines );
		if ( array() === $hints ) {
			return '';
		}
		$out = '<div class="wfc-pro-hints" style="margin-top:2em;color:#646970">';
		foreach ( $hints as $line ) {
			$out .= '<p class="description" style="color:#646970">' . esc_html( $line ) . '</p>';
		}
		return $out . '</div>';
	}

	/**
	 * Fields an add-on registers for one tab, checked. The filter gets an empty list and the tab and returns a list of
	 * arrays with `key`, `label`, `help` (optional), `option` (the add-on's own option), `group` (its settings group,
	 * default: the option) and either `render` (callable, gets field, value, input name and id, prints the control) or
	 * `type` (text, number, checkbox, textarea; default text). Malformed fields are left out; options of the core are
	 * refused, the core stores nothing here.
	 *
	 * @return array<int,array{key:string,label:string,help:string,option:string,group:string,type:string,render:?callable}>
	 */
	public static function fields( string $tab ): array {
		if ( ! in_array( $tab, self::TABS, true ) || ! function_exists( 'apply_filters' ) ) {
			return array();
		}
		$raw = apply_filters( 'workflow_captain_settings_fields_v1', array(), $tab );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out  = array();
		$seen = array();
		foreach ( $raw as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$key    = is_string( $f['key'] ?? null ) ? $f['key'] : '';
			$option = is_string( $f['option'] ?? null ) ? $f['option'] : '';
			$group  = is_string( $f['group'] ?? null ) && '' !== $f['group'] ? $f['group'] : $option;
			$label  = is_string( $f['label'] ?? null ) ? trim( $f['label'] ) : '';
			$render = isset( $f['render'] ) && is_callable( $f['render'] ) ? $f['render'] : null;
			$type   = is_string( $f['type'] ?? null ) ? $f['type'] : 'text';
			if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $key ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/', $option ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/', $group ) || '' === $label
				|| isset( SettingsRegistry::GROUPS[ $option ] ) || in_array( $group, SettingsRegistry::GROUPS, true )
				|| ( null === $render && ! in_array( $type, self::TYPES, true ) ) || isset( $seen[ $option . '/' . $key ] ) ) {
				continue;
			}
			$seen[ $option . '/' . $key ] = true;
			$out[]                        = array(
				'key'    => $key,
				'label'  => $label,
				'help'   => is_string( $f['help'] ?? null ) ? trim( $f['help'] ) : '',
				'option' => $option,
				'group'  => $group,
				'type'   => $type,
				'render' => $render,
			);
		}
		return $out;
	}

	/** Section of the add-on fields of one tab: one form per settings group of the add-on; empty when there are none. */
	public static function fields_html( string $tab ): string {
		$groups = array();
		foreach ( self::fields( $tab ) as $f ) {
			$groups[ $f['group'] ][] = $f;
		}
		if ( array() === $groups ) {
			return '';
		}
		$out = '<div class="wfc-addon-fields"><h2>' . esc_html__( 'Settings of add-ons', 'workflow-captain' ) . '</h2>';
		foreach ( $groups as $group => $fields ) {
			$out .= '<form method="post" action="options.php" class="wfc-settings-form">' . self::capture( 'settings_fields', $group ) . '<table class="form-table" role="presentation">';
			foreach ( $fields as $f ) {
				$out .= self::row( $f );
			}
			$out .= '</table><p class="submit">' . self::capture( 'submit_button', null, 'primary', 'submit', false, array( 'id' => 'wfc-addon-submit-' . $group ) ) . '</p></form>';
		}
		return $out . '</div>';
	}

	/** One table row of an add-on field; the value is read from the add-on's option, never written. */
	private static function row( array $f ): string {
		$stored = function_exists( 'get_option' ) ? get_option( $f['option'], array() ) : array();
		$value  = is_array( $stored ) ? ( $stored[ $f['key'] ] ?? '' ) : '';
		$id     = 'wfc-addon-' . $f['option'] . '-' . $f['key'];
		$name   = $f['option'] . '[' . $f['key'] . ']';
		$desc   = '' !== $f['help'] ? ' aria-describedby="' . esc_attr( $id . '-help' ) . '"' : '';
		$out    = '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . '</label></th><td>';
		if ( null !== $f['render'] ) {
			ob_start();
			call_user_func( $f['render'], $f, $value, $name, $id );
			$out .= (string) ob_get_clean();
		} else {
			$scalar = is_scalar( $value ) ? (string) $value : '';
			switch ( $f['type'] ) {
				case 'checkbox':
					$out .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . ( '1' === $scalar ? ' checked' : '' ) . $desc . '>';
					break;
				case 'textarea':
					$out .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" class="large-text"' . $desc . '>' . esc_html( $scalar ) . '</textarea>';
					break;
				default:
					$out .= '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $f['type'] ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $scalar ) . '" class="' . ( 'number' === $f['type'] ? 'small-text' : 'regular-text' ) . '"' . $desc . '>';
			}
		}
		if ( '' !== $f['help'] ) {
			$out .= '<p id="' . esc_attr( $id . '-help' ) . '" class="description">' . esc_html( $f['help'] ) . '</p>';
		}
		return $out . '</td></tr>';
	}

	/** Output of a WordPress function that prints (settings_fields, submit_button); empty where it does not exist. */
	private static function capture( string $fn, ...$args ): string {
		if ( ! function_exists( $fn ) ) {
			return '';
		}
		ob_start();
		$fn( ...$args );
		return (string) ob_get_clean();
	}

	/** Everything after the core fields of a tab: add-on section, then the hint lines. */
	public static function render( string $tab ): void {
		echo self::fields_html( $tab ) . self::hints_html( $tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts; add-on controls are printed by the add-on.
	}
}
