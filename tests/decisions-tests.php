<?php
/**
 * Owner decisions of 23.11.2026 on the settings findings: SET-WC-29 (privacy of notice recipients and the settings
 * backup), SET-WC-30 (one right for all settings pages), SET-WC-35 (autoload no for every option), SET-WC-36 (WP-CLI
 * process uses the batch setting).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Privacy\Privacy;

echo "\n# Decisions SET-WC-29/-30/-35/-36\n";

check( 'SET-WC-29 an address is found in a comma list regardless of case and spaces', Privacy::in_list( 'a@x.example, Shop@X.example', 'shop@x.example' ) && ! Privacy::in_list( 'a@x.example', 'b@x.example' ) && ! Privacy::in_list( 'a@x.example', '' ) );
check( 'SET-WC-29 erasing keeps the other addresses in the stored form', 'a@x.example, c@x.example' === Privacy::without( 'a@x.example, B@x.example, c@x.example', 'b@x.example' ) && '' === Privacy::without( 'b@x.example', 'B@X.example' ) );
$wfc_priv = (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Privacy/Privacy.php' );
check( 'SET-WC-29 exporter and eraser are registered for the notice recipients and cover the settings backup', str_contains( $wfc_priv, "\$exporters['workflow-captain-settings']" ) && str_contains( $wfc_priv, "\$erasers['workflow-captain-settings']" ) && 2 === substr_count( $wfc_priv, 'SettingsTransfer::BACKUP_OPTION, null' ) );
$wfc_sp = (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Admin/SettingsPage.php' );
check( 'SET-WC-30 saving any settings group needs manage_workflow_captain (filter option_page_capability_<group>)', str_contains( $wfc_sp, "add_filter( 'option_page_capability_' . \$group, array( self::class, 'settings_capability' ) )" ) && 'manage_workflow_captain' === \WorkflowCaptain\Admin\SettingsPage::settings_capability() && ! preg_match( "/current_user_can\\(\\s*'manage_options'/", $wfc_sp ) );
$wfc_all = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WORKFLOW_CAPTAIN_PATH . 'src' ) ) as $wfc_f ) {
	if ( str_ends_with( (string) $wfc_f, '.php' ) ) {
		$wfc_all .= (string) file_get_contents( (string) $wfc_f );
	}
}
preg_match_all( '/\bupdate_option\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/s', $wfc_all, $wfc_m );
$wfc_auto = array_filter( $wfc_m[0], static fn( $c ) => ! preg_match( '/,\s*false\s*\)$/', $c ) );
check( 'SET-WC-35 every update_option of the core passes autoload false' . ( $wfc_auto ? ': ' . implode( ' | ', array_map( static fn( $c ) => substr( preg_replace( '/\s+/', ' ', $c ), 0, 80 ), $wfc_auto ) ) : '' ), array() === $wfc_auto );
check( 'SET-WC-35 the settings options are created non-autoloaded before options.php can create them', str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Install.php' ), 'wp_set_options_autoload( $names, false )' ) );
check( 'SET-WC-36 wp workflow-captain process without --limit uses the batch setting', str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Cli/Command.php' ), "\$assoc['limit'] ?? \\WorkflowCaptain\\Settings::get()['batch']" ) );
