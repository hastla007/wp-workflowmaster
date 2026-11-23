<?php
/**
 * Admin page "Vorlagen" (WF-SET-02): example workflows shipped with the plugin, imported as a draft in one click.
 * Add-ons can add their own templates through the filter workflow_captain_templates.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Engine\Transfer;
use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

final class TemplatesPage {

	const SLUG = 'workflow-captain-templates';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 25 );
		add_action( 'admin_post_workflow_captain_template', array( $this, 'handle_import' ) );
	}

	public function menu(): void {
		add_submenu_page( OverviewPage::SLUG, __( 'Templates', 'workflow-captain' ), __( 'Templates', 'workflow-captain' ), 'edit_workflows', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Templates by key: key => array( file, name, event ). Only readable JSON files in the export format are listed.
	 *
	 * @return array<string,array{file:string,name:string,event:string}>
	 */
	public static function templates( ?array $files = null ): array {
		if ( null === $files ) {
			$files = glob( WORKFLOW_CAPTAIN_PATH . 'assets/recipes/*.json' ) ?: array();
			/**
			 * Additional template files (absolute paths to JSON in the export format of Workflow Captain).
			 *
			 * @since 0.2.0
			 * @param string[] $files
			 */
			$files = function_exists( 'apply_filters' ) ? (array) apply_filters( 'workflow_captain_templates', $files ) : $files;
		}
		$out = array();
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || ! is_readable( $file ) || filesize( $file ) > Transfer::MAX_BYTES ) {
				continue;
			}
			$data = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $data ) || Transfer::FORMAT !== ( $data['format'] ?? null ) || ! is_array( $data['graph']['nodes'] ?? null ) ) {
				continue;
			}
			$event = '';
			foreach ( $data['graph']['nodes'] as $node ) {
				if ( 'trigger' === ( $node['type'] ?? '' ) ) {
					$event = (string) ( $node['event'] ?? '' );
				}
			}
			$key         = sanitize_key( basename( $file, '.json' ) );
			$out[ $key ] = array( 'file' => $file, 'name' => (string) ( $data['name'] ?? $key ), 'event' => $event );
		}
		ksort( $out );
		return $out;
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_workflows' ) ) {
			return;
		}
		$available = $this->plugin->available_event_types();
		echo '<div class="wrap"><h1>' . esc_html__( 'Templates', 'workflow-captain' ) . '</h1>';
		echo '<p>' . esc_html__( 'A template is added as a draft. Review it, start a test run, then publish. Templates only use version 1 actions and do not replace any email from a core plugin.', 'workflow-captain' ) . '</p>';
		echo '<table class="widefat striped" tabindex="0"><thead><tr><th scope="col">' . esc_html__( 'Template', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Trigger', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Provider', 'workflow-captain' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Action', 'workflow-captain' ) . '</span></th></tr></thead><tbody>';
		foreach ( self::templates() as $key => $t ) {
			$ready = in_array( $t['event'], $available, true );
			echo '<tr><td>' . esc_html( $t['name'] ) . '</td><td><code>' . esc_html( $t['event'] ) . '</code></td><td>' . esc_html( $ready ? __( 'available', 'workflow-captain' ) : __( 'not available – you can create a draft, but publishing requires the provider', 'workflow-captain' ) ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="workflow_captain_template"><input type="hidden" name="template" value="' . esc_attr( $key ) . '">';
			wp_nonce_field( 'workflow_captain_template' );
			echo '<button class="button">' . esc_html__( 'Add as draft', 'workflow-captain' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public function handle_import(): void {
		check_admin_referer( 'workflow_captain_template' );
		if ( ! current_user_can( 'edit_workflows' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'workflow-captain' ), 403 );
		}
		$key       = isset( $_POST['template'] ) ? sanitize_key( wp_unslash( $_POST['template'] ) ) : '';
		$templates = self::templates();
		$result    = isset( $templates[ $key ] )
			? Transfer::import( (string) file_get_contents( $templates[ $key ]['file'] ), $this->plugin->workflows() )
			: array( 'ok' => false, 'id' => 0, 'violations' => array( 'template:unknown' ) );
		set_transient( 'workflow_captain_notice_' . get_current_user_id(), array( 'do' => 'import', 'errors' => array_slice( $result['violations'], 0, 30 ) ), 60 );
		wp_safe_redirect( WorkflowsPage::url( WorkflowsPage::SLUG_WORKFLOWS, $result['ok'] ? array( 'edit' => $result['id'] ) : array() ) );
		exit;
	}
}
