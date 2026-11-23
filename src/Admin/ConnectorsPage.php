<?php
/**
 * Admin page "Eigene Connectoren" (F-22, WF-CONNECTOR-03, -11): registered and refused connectors with their reason,
 * and the operator's release – a connector and each of its personal fields are off until enabled here.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Audit;
use WorkflowCaptain\Connector\ConnectorRegistry;
use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

final class ConnectorsPage {

	const SLUG = 'workflow-captain-connectors';
	const CAP  = 'manage_workflow_integrations';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 26 );
		add_action( 'admin_post_workflow_captain_connector', array( $this, 'handle' ) );
	}

	public function menu(): void {
		add_submenu_page( OverviewPage::SLUG, __( 'Custom connectors', 'workflow-captain' ), __( 'Custom connectors', 'workflow-captain' ), self::CAP, self::SLUG, array( $this, 'render' ) );
	}

	public function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'workflow-captain' ), 403 );
		}
		$key = isset( $_POST['connector'] ) ? sanitize_key( wp_unslash( $_POST['connector'] ) ) : '';
		check_admin_referer( 'workflow_captain_connector_' . $key );
		$personal = array();
		$given    = isset( $_POST['personal'] ) && is_array( $_POST['personal'] ) ? wp_unslash( $_POST['personal'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared against the manifest in release_entry().
		foreach ( $given as $type => $fields ) {
			$personal[ (string) $type ] = array_map( 'strval', array_filter( (array) $fields, 'is_string' ) );
		}
		$entry = $this->plugin->connectors()->release_entry( $key, ! empty( $_POST['enabled'] ), $personal );
		$done  = '0';
		if ( null !== $entry ) {
			$all  = (array) get_option( ConnectorRegistry::OPTION, array() );
			$old  = is_array( $all[ $key ] ?? null ) ? $all[ $key ] : array();
			$all[ $key ] = $entry;
			update_option( ConnectorRegistry::OPTION, $all, false );
			$fields = array();
			foreach ( $entry['personal_fields'] as $type => $names ) {
				foreach ( $names as $name ) {
					$fields[] = $type . '.' . $name;
				}
			}
			Audit::record(
				'connector',
				array(
					'connector'       => $key,
					'enabled'         => array( empty( $old['enabled'] ) ? 'off' : 'on', $entry['enabled'] ? 'on' : 'off' ),
					'personal_fields' => array() === $fields ? '–' : implode( ', ', $fields ),
				)
			);
			$done = '1';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'done' => $done ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Label of a status or reason code. */
	public static function label( string $code ): string {
		$labels = array(
			'enabled'                                   => __( 'enabled', 'workflow-captain' ),
			'disabled'                                  => __( 'not enabled', 'workflow-captain' ),
			'refused'                                   => __( 'rejected', 'workflow-captain' ),
			ConnectorRegistry::INVALID_MANIFEST         => __( 'Invalid manifest', 'workflow-captain' ),
			ConnectorRegistry::REQUIRES_NEWER_CORE      => __( 'requires a newer version of Workflow Captain', 'workflow-captain' ),
			ConnectorRegistry::CONTRACT_MAJOR           => __( 'built for a different major version of Workflow Captain', 'workflow-captain' ),
			ConnectorRegistry::RUNTIME_NOT_ALLOWED      => __( 'the runtime for custom connectors is not unlocked on this site (Workflow Captain Pro)', 'workflow-captain' ),
			ConnectorRegistry::DUPLICATE                => __( 'Key registered twice', 'workflow-captain' ),
			ConnectorRegistry::NOT_ENABLED              => __( 'waiting to be enabled', 'workflow-captain' ),
		);
		return $labels[ $code ] ?? $code;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$registry = $this->plugin->connectors();
		$rows     = $registry->rows();
		echo '<div class="wrap"><h1>' . esc_html__( 'Custom connectors', 'workflow-captain' ) . '</h1>';
		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$ok = '1' === sanitize_key( wp_unslash( $_GET['done'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p>' . esc_html( $ok ? __( 'Approval saved.', 'workflow-captain' ) : __( 'This connector is not registered.', 'workflow-captain' ) ) . '</p></div>';
		}
		echo '<p class="description">' . esc_html__( 'A connector is a separate plugin that connects a project to Workflow Captain. It stays off until you approve it here. Until then, its events are not stored and its actions are not offered. Personal data fields stay off even after approval until you switch them on one by one.', 'workflow-captain' ) . '</p>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No connector registered yet.', 'workflow-captain' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped" tabindex="0"><thead><tr><th scope="col">' . esc_html__( 'Name', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Key', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Version', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Contract version', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Reason', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Event types', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Approval', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$reason = null === $r['reason'] ? '–' : self::label( (string) $r['reason'] );
			if ( array() !== $r['violations'] ) {
				$reason .= ' (' . implode( ', ', $r['violations'] ) . ')';
			}
			echo '<tr><td>' . esc_html( '' === $r['name'] ? '–' : $r['name'] ) . '</td><td><code>' . esc_html( $r['key'] ) . '</code></td><td>' . esc_html( '' === $r['version'] ? '–' : $r['version'] ) . '</td><td>' . esc_html( '' === $r['contract_version'] ? '–' : $r['contract_version'] ) . '</td><td>' . esc_html( self::label( (string) $r['status'] ) ) . '</td><td>' . esc_html( $reason ) . '</td><td>' . (int) $r['types'] . '</td><td>' . (int) $r['actions'] . '</td><td>';
			$connector = $registry->get( (string) $r['key'] );
			if ( null !== $connector ) {
				$this->render_form( $connector->product(), $connector->manifest(), $registry );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function render_form( string $key, array $manifest, ConnectorRegistry $registry ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="workflow_captain_connector"><input type="hidden" name="connector" value="' . esc_attr( $key ) . '">';
		wp_nonce_field( 'workflow_captain_connector_' . $key );
		echo '<label><input type="checkbox" name="enabled" value="1"' . ( $registry->is_enabled( $key ) ? ' checked' : '' ) . '> ' . esc_html__( 'Approve connector', 'workflow-captain' ) . '</label>';
		$personal = array();
		foreach ( (array) $manifest['events'] as $type => $spec ) {
			foreach ( (array) $spec['fields'] as $field => $def ) {
				if ( 'personal' === $def['class'] ) {
					$personal[] = array( (string) $type, (string) $field );
				}
			}
		}
		if ( array() !== $personal ) {
			echo '<fieldset style="margin-top:.5em"><legend>' . esc_html__( 'Pass on personal fields:', 'workflow-captain' ) . '</legend>';
			foreach ( $personal as list( $type, $field ) ) {
				echo '<label style="display:block"><input type="checkbox" name="personal[' . esc_attr( $type ) . '][]" value="' . esc_attr( $field ) . '"' . ( $registry->personal_allowed( $key, $type, $field ) ? ' checked' : '' ) . '> <code>' . esc_html( $type . ' → ' . $field ) . '</code></label>';
			}
			echo '</fieldset>';
		}
		echo '<p><button class="button button-small">' . esc_html__( 'Save', 'workflow-captain' ) . '</button></p></form>';
	}
}
