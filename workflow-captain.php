<?php
/**
 * Plugin Name:       Workflow Captain
 * Plugin URI:        https://github.com/hastla007/wp-workflowmaster
 * Description:       Automatisierungsbaukasten der Captain Suite. Diese Fassung enthält den Ereignis-Abnehmer (Consumer): Speicher, Wiederholung, Schleifenschutz und die Anmeldeschnittstelle für Adapter.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Captain Suite
 * Text Domain:       workflow-captain
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'WORKFLOW_CAPTAIN_VERSION', '0.2.0' );
define( 'WORKFLOW_CAPTAIN_DB_VERSION', '4' );
/*
 * Version of the interface at which adapters register (docs/SPEC-suite-events.md, R-13.7; name as proposed in F-03).
 * Independent of the plugin version. MAJOR = generation of the adapter interface, MINOR rises when methods or hooks are
 * added. 0.x: not stable before the specification reaches 1.0.0 (R-13.2).
 */
define( 'WORKFLOW_CAPTAIN_CONTRACT_VERSION', '0.1.0' );
define( 'WORKFLOW_CAPTAIN_FILE', __FILE__ );
define( 'WORKFLOW_CAPTAIN_PATH', plugin_dir_path( __FILE__ ) );

// Own PSR-4 autoloader: the plugin needs no Composer at runtime.
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'WorkflowCaptain\\' ) ) {
			return;
		}
		$file = WORKFLOW_CAPTAIN_PATH . 'src/' . str_replace( '\\', '/', substr( $class, 16 ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Status of Workflow Captain for one product, for the admin of a core plugin (counts and links only).
 *
 * @since 0.2.0
 * @return array<string,mixed>|null Null before Workflow Captain has booted.
 */
function workflow_captain_status_for( string $product ): ?array {
	if ( ! did_action( 'workflow_captain_loaded' ) ) {
		return null;
	}
	$plugin = \WorkflowCaptain\Plugin::instance();
	return \WorkflowCaptain\SuiteStatus::for_product( $product, $plugin->adapters(), $plugin->store(), $plugin->engine()->store() );
}

register_activation_hook( __FILE__, array( '\WorkflowCaptain\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\WorkflowCaptain\Install', 'deactivate' ) );
add_filter( 'wpmu_drop_tables', array( '\WorkflowCaptain\Install', 'drop_site_tables' ), 10, 1 );

add_action(
	'plugins_loaded',
	static function (): void {
		\WorkflowCaptain\Plugin::instance()->boot();
	}
);
