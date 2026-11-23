<?php
/**
 * Tests of SET-WC-02 (included by tests/run.php; uses its check() and stand-ins): the grey Pro hint lines at the end
 * of a tab, the switch `show_pro_hints` and the filter `workflow_captain_settings_fields_v1` for add-on fields.
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Admin\SettingsExtras;
use WorkflowCaptain\Settings;
use WorkflowCaptain\SettingsRegistry;

echo "\n# SET-WC-02 Pro hint lines and add-on fields\n";

if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( $group ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '">'; }
}
if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other = array() ) { echo '<input type="submit" name="' . esc_attr( $name ) . '" id="' . esc_attr( $other['id'] ?? '' ) . '">'; }
}

$wfc_h_saved = $GLOBALS['wfc_test_options'][ Settings::OPTION ] ?? null;
unset( $GLOBALS['wfc_test_options'][ Settings::OPTION ] );

// The switch is in the register: on by default, exported, on the General tab, with label and help text.
$wfc_h_field = SettingsRegistry::field( 'show_pro_hints' );
check( 'SET-WC-02 show_pro_hints: switch in the register, default on, exported, tab General', null !== $wfc_h_field && Settings::OPTION === $wfc_h_field['option'] && SettingsRegistry::TYPE_BOOL === $wfc_h_field['type'] && 1 === $wfc_h_field['default'] && true === $wfc_h_field['export'] && 'general' === $wfc_h_field['tab'] && in_array( 'show_pro_hints', SettingsRegistry::tab( 'general', Settings::OPTION ), true ) );
check( 'SET-WC-02 show_pro_hints: label and help text', '' !== SettingsRegistry::texts()['show_pro_hints'][0] && '' !== SettingsRegistry::texts()['show_pro_hints'][1] );

// S5: the shipped list is empty, so nothing is shown on any tab.
check( 'SET-WC-02 S5: the shipped list of hint lines is empty, no tab shows a hint', array() === SettingsExtras::hint_lines() && array() === array_filter( array_map( static fn( $t ) => SettingsExtras::hints_html( $t ), SettingsExtras::TABS ) ) );

// Display with a list given (as once a feature is sold).
$wfc_h_lines = array( 'execution' => array( 'More in Pro: <b>x</b>', '  ', 7 ), 'privacy' => array() );
$wfc_h_html  = SettingsExtras::hints_html( 'execution', $wfc_h_lines );
check( 'SET-WC-02 hint block: grey text lines at the end of the tab, escaped, blank lines dropped', str_contains( $wfc_h_html, 'class="wfc-pro-hints"' ) && str_contains( $wfc_h_html, 'color:#646970' ) && str_contains( $wfc_h_html, 'More in Pro: &lt;b&gt;x&lt;/b&gt;' ) && 1 === substr_count( $wfc_h_html, '<p ' ) );
check( 'SET-WC-02 hint block: text only, no field, link or button', ! preg_match( '/<(input|select|textarea|button|form|a)\b/', $wfc_h_html ) );
check( 'SET-WC-02 hint block: nothing for a tab with an empty or missing list', '' === SettingsExtras::hints_html( 'privacy', $wfc_h_lines ) && '' === SettingsExtras::hints_html( 'notify', $wfc_h_lines ) );
$GLOBALS['wfc_test_options'][ Settings::OPTION ] = array( 'show_pro_hints' => 0 );
check( 'SET-WC-02 show_pro_hints off: no hint line at all', '' === SettingsExtras::hints_html( 'execution', $wfc_h_lines ) );
add_filter( 'workflow_captain_setting_show_pro_hints', static fn( $v ) => true );
check( 'SET-WC-02 show_pro_hints: the filter of the setting wins', '' !== SettingsExtras::hints_html( 'execution', $wfc_h_lines ) );
unset( $GLOBALS['wfc_test_filters']['workflow_captain_setting_show_pro_hints'], $GLOBALS['wfc_test_options'][ Settings::OPTION ] );

// The core holds no names of rights; the license guard covers the shipped files, this checks the new class itself.
$wfc_h_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/SettingsExtras.php' );
check( 'SET-WC-02 hint lines: no right, license or check in the class', ! preg_match( '/entitlement|licen[sc]e|workflow\.[a-z_]+|current_user_can/i', $wfc_h_src ) );

// Add-on fields through the filter.
check( 'SET-WC-02 add-on fields: none without the filter, no section', array() === SettingsExtras::fields( 'execution' ) && '' === SettingsExtras::fields_html( 'execution' ) );
add_filter(
	'workflow_captain_settings_fields_v1',
	static function ( $fields, $tab ) {
		if ( 'messages' !== $tab ) {
			return $fields;
		}
		$fields[] = array( 'key' => 'brand', 'label' => 'Brand <name>', 'help' => 'Shown in mails.', 'option' => 'addon_opts', 'type' => 'text' );
		$fields[] = array( 'key' => 'on', 'label' => 'Switch', 'option' => 'addon_opts', 'type' => 'checkbox' );
		$fields[] = array( 'key' => 'own', 'label' => 'Own control', 'option' => 'addon_other', 'group' => 'addon_group', 'render' => static function ( $f, $value, $name, $id ) { echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option>' . esc_html( (string) $value ) . '</option></select>'; } );
		$fields[] = array( 'key' => 'batch', 'label' => 'Hijack', 'option' => Settings::OPTION, 'type' => 'number' );
		$fields[] = array( 'key' => 'x', 'label' => 'Core group', 'option' => 'addon_x', 'group' => 'workflow_captain', 'type' => 'text' );
		$fields[] = array( 'key' => 'Bad Key', 'label' => 'Bad', 'option' => 'addon_opts' );
		$fields[] = array( 'key' => 'nolabel', 'label' => '', 'option' => 'addon_opts' );
		$fields[] = array( 'key' => 'kind', 'label' => 'Unknown type', 'option' => 'addon_opts', 'type' => 'color' );
		$fields[] = array( 'key' => 'brand', 'label' => 'Twice', 'option' => 'addon_opts' );
		$fields[] = 'not an array';
		return $fields;
	}
);
$GLOBALS['wfc_test_options']['addon_opts']  = array( 'brand' => 'Acme "Co"', 'on' => 1 );
$GLOBALS['wfc_test_options']['addon_other'] = array( 'own' => 'picked' );
$wfc_h_fields = SettingsExtras::fields( 'messages' );
check( 'SET-WC-02 add-on fields: well-formed fields are taken, core options and groups, bad keys, empty labels, unknown types and doubles are refused', array( 'brand', 'on', 'own' ) === array_column( $wfc_h_fields, 'key' ) && 'addon_opts' === $wfc_h_fields[0]['group'] && 'addon_group' === $wfc_h_fields[2]['group'] );
check( 'SET-WC-02 add-on fields: only for the tab asked and only for tabs with settings', array() === SettingsExtras::fields( 'execution' ) && array() === SettingsExtras::fields( 'status' ) && array() === SettingsExtras::fields( 'roles' ) );
$wfc_h_section = SettingsExtras::fields_html( 'messages' );
check( 'SET-WC-02 add-on section: heading and one form per settings group of the add-on, posted to options.php', str_contains( $wfc_h_section, 'Settings of add-ons' ) && 2 === substr_count( $wfc_h_section, '<form method="post" action="options.php"' ) && str_contains( $wfc_h_section, 'name="option_page" value="addon_opts"' ) && str_contains( $wfc_h_section, 'name="option_page" value="addon_group"' ) && ! str_contains( $wfc_h_section, 'value="workflow_captain"' ) );
check( 'SET-WC-02 add-on section: controls named after the add-on option, value from it, label and help escaped', str_contains( $wfc_h_section, 'name="addon_opts[brand]" value="Acme &quot;Co&quot;"' ) && str_contains( $wfc_h_section, 'Brand &lt;name&gt;' ) && str_contains( $wfc_h_section, 'aria-describedby="wfc-addon-addon_opts-brand-help"' ) && str_contains( $wfc_h_section, 'name="addon_opts[on]" value="1" checked' ) );
check( 'SET-WC-02 add-on section: a render callback draws its own control', str_contains( $wfc_h_section, '<select id="wfc-addon-addon_other-own" name="addon_other[own]"><option>picked</option></select>' ) );
check( 'SET-WC-02 add-on section: the core stores nothing (no register_setting, no update_option in the class)', ! preg_match( '/\b(register_setting|update_option|add_option)\s*\(\s*[\'"$]/', $wfc_h_src ) && array( 'brand' => 'Acme "Co"', 'on' => 1 ) === $GLOBALS['wfc_test_options']['addon_opts'] );
unset( $GLOBALS['wfc_test_filters']['workflow_captain_settings_fields_v1'], $GLOBALS['wfc_test_options']['addon_opts'], $GLOBALS['wfc_test_options']['addon_other'] );
add_filter( 'workflow_captain_settings_fields_v1', static fn( $f, $t ) => 'broken' );
check( 'SET-WC-02 add-on fields: a filter result that is no list gives no fields', array() === SettingsExtras::fields( 'general' ) );
unset( $GLOBALS['wfc_test_filters']['workflow_captain_settings_fields_v1'] );

// The settings page closes every tab with the add-on section and the hints; the General tab now has a form.
$wfc_h_page = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/SettingsPage.php' );
check( 'SET-WC-02 settings page: add-on fields and hints at the end of the tab, General tab gets the form', str_contains( $wfc_h_page, 'SettingsExtras::render( $tab );' ) && ! preg_match( "/'general' === \\\$tab \\) \\{\\s*\\\$this->general\\(\\);\\s*echo '<\\/div>';\\s*return;/", $wfc_h_page ) );

if ( null !== $wfc_h_saved ) {
	$GLOBALS['wfc_test_options'][ Settings::OPTION ] = $wfc_h_saved;
}
