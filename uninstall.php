<?php
/**
 * Removes tables, options and capabilities of Workflow Captain on every site.
 *
 * @package WorkflowCaptain
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

define( 'WORKFLOW_CAPTAIN_PATH', __DIR__ . '/' );
if ( ! defined( 'WORKFLOW_CAPTAIN_DB_VERSION' ) ) {
	define( 'WORKFLOW_CAPTAIN_DB_VERSION', '1' );
}
require_once __DIR__ . '/src/Store/EventStore.php';
require_once __DIR__ . '/src/Store/WpdbEventStore.php';
require_once __DIR__ . '/src/Install.php';

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $workflow_captain_blog_id ) {
		switch_to_blog( (int) $workflow_captain_blog_id );
		try {
			\WorkflowCaptain\Install::uninstall_site();
		} finally {
			restore_current_blog();
		}
	}
} else {
	\WorkflowCaptain\Install::uninstall_site();
}
