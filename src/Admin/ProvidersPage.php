<?php
/**
 * Admin page "Provider" (WF-INT-14, part): which adapters are registered or refused, their versions and event types,
 * stored events per state and active workflows per trigger.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Plugin;
use WorkflowCaptain\Store\EventStore;

defined( 'ABSPATH' ) || exit;

final class ProvidersPage {

	const SLUG = 'workflow-captain-providers';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 25 );
	}

	public function menu(): void {
		add_submenu_page( OverviewPage::SLUG, __( 'Provider', 'workflow-captain' ), __( 'Provider', 'workflow-captain' ), 'view_workflow_logs', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * One row per known product: registered adapters, refused ones and products with stored events but no adapter.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( AdapterRegistry $adapters, EventStore $events, EngineStore $engine ): array {
		$counts   = $events->count_by_product();
		$products = array_unique( array_merge( $adapters->products(), array_keys( $adapters->refused() ), array_keys( $counts ) ) );
		sort( $products );
		$rows = array();
		foreach ( $products as $product ) {
			$adapter = $adapters->get( $product );
			$types   = null === $adapter ? array() : $adapter->types();
			$active  = array();
			foreach ( $types as $type ) {
				$n = count( $engine->active_versions_for_trigger( $type ) );
				if ( $n > 0 ) {
					$active[ $type ] = $n;
				}
			}
			$rows[] = array(
				'product'          => $product,
				'status'           => null !== $adapter ? 'registered' : ( isset( $adapters->refused()[ $product ] ) ? 'refused' : 'missing' ),
				'reason'           => $adapters->refused()[ $product ] ?? null,
				'core_version'     => $adapter?->core_version(),
				'contract_version' => $adapter?->contract_version(),
				'types'            => $types,
				'events'           => $counts[ $product ] ?? array(),
				'active_workflows' => $active,
			);
		}
		return $rows;
	}

	/**
	 * Sample event for a type, filled from the data types of the catalog (WF-INT-14). No real values, no personal data.
	 *
	 * @param array<string,string> $types field => data type
	 */
	public static function sample_event( string $type, array $types ): array {
		$values = array( 'integer' => 1, 'number' => 1, 'boolean' => false, 'string' => 'beispiel', 'currency' => 'EUR', 'datetime' => gmdate( 'Y-m-d\TH:i:s\Z' ) );
		$data   = array();
		foreach ( $types as $field => $kind ) {
			$data[ $field ] = $values[ $kind ] ?? 'beispiel';
		}
		return array( 'type' => $type, 'subject' => array( 'type' => 'sample', 'id' => '1', 'id_kind' => 'record' ), 'data' => $data );
	}

	/**
	 * Test event (WF-INT-14): runs every active workflow of the type as a test run, without side effects.
	 *
	 * @return array<int,array{workflow_id:int,outcome:string,steps:int}>
	 */
	public static function test_event( string $type, array $types, \WorkflowCaptain\Engine\EngineStore $store, \WorkflowCaptain\Engine\ActionRegistry $actions ): array {
		$sample = self::sample_event( $type, $types );
		$out    = array();
		foreach ( $store->active_versions_for_trigger( $type ) as $version ) {
			$result = \WorkflowCaptain\Engine\DryRun::run( $version['graph'], $sample, $actions );
			$out[]  = array( 'workflow_id' => (int) $version['workflow_id'], 'outcome' => (string) $result['outcome'], 'steps' => count( $result['path'] ) );
		}
		return $out;
	}

	private function render_test( string $type ): void {
		$fields = json_decode( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'assets/data/catalog-fields.json' ), true );
		$types  = is_array( $fields[ $type ] ?? null ) ? $fields[ $type ] : array();
		$report = self::test_event( $type, $types, $this->plugin->engine()->store(), $this->plugin->engine()->actions() );
		/* translators: %s: event type */
		echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'Testereignis %s mit Beispielwerten, als Testlauf ohne Seiteneffekte:', 'workflow-captain' ), $type ) ) . '</p>';
		if ( array() === $report ) {
			echo '<p>' . esc_html__( 'Kein aktiver Workflow hört auf dieses Ereignis.', 'workflow-captain' ) . '</p></div>';
			return;
		}
		echo '<ul>';
		foreach ( $report as $r ) {
			/* translators: 1: workflow number, 2: outcome code, 3: number of steps */
			echo '<li>' . esc_html( sprintf( __( 'Workflow %1$d: %2$s nach %3$d Schritten', 'workflow-captain' ), $r['workflow_id'], $r['outcome'], $r['steps'] ) ) . '</li>';
		}
		echo '</ul></div>';
	}

	public function render(): void {
		if ( ! current_user_can( 'view_workflow_logs' ) ) {
			return;
		}
		$rows   = self::rows( $this->plugin->adapters(), $this->plugin->store(), $this->plugin->engine()->store() );
		$labels = array(
			'registered' => __( 'angemeldet', 'workflow-captain' ),
			'refused'    => __( 'abgelehnt', 'workflow-captain' ),
			'missing'    => __( 'kein Adapter (Add-on fehlt, inaktiv oder ohne Lizenz)', 'workflow-captain' ),
		);
		echo '<div class="wrap"><h1>' . esc_html__( 'Provider', 'workflow-captain' ) . '</h1>';
		$test = isset( $_GET['test'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'workflow_captain_test_event' ) ? sanitize_text_field( wp_unslash( $_GET['test'] ) ) : '';
		if ( '' !== $test && preg_match( \WorkflowCaptain\Envelope\Validator::TYPE, $test ) ) {
			$this->render_test( $test );
		}
		echo '<p class="description">' . esc_html__( 'Ereignisse der Captain-Produkte kommen über Adapter aus den Pro-Add-ons. Die Adapter für WordPress und WooCommerce (ab Version 8.2) sind eingebaut und brauchen keine Lizenz. Ohne Adapter werden offene Ereignisse pausiert, nicht gelöscht.', 'workflow-captain' ) . '</p>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Noch kein Adapter angemeldet und keine Ereignisse gespeichert.', 'workflow-captain' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped" tabindex="0"><thead><tr><th>' . esc_html__( 'Produkt', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Adapter', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Plugin / Vertrag', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Ereignistypen', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Gespeicherte Ereignisse', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Aktive Workflows', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$events = array();
			foreach ( $r['events'] as $state => $n ) {
				$events[] = EventsPage::state_label( $state ) . ': ' . $n;
			}
			$active = array();
			foreach ( $r['active_workflows'] as $type => $n ) {
				$active[] = $type . ' (' . $n . ')';
			}
			$links = array();
			foreach ( $r['types'] as $type ) {
				$links[] = '<a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'page' => self::SLUG, 'test' => $type ), admin_url( 'admin.php' ) ), 'workflow_captain_test_event' ) ) . '" title="' . esc_attr__( 'Testereignis', 'workflow-captain' ) . '">' . esc_html( $type ) . '</a>';
			}
			echo '<tr><td><code>' . esc_html( $r['product'] ) . '</code></td><td>' . esc_html( $labels[ $r['status'] ] . ( $r['reason'] ? ' – ' . $r['reason'] : '' ) ) . '</td><td>' . esc_html( ( $r['core_version'] ?? '–' ) . ' / ' . ( $r['contract_version'] ?? '–' ) ) . '</td><td>' . implode( ', ', $links ) . '</td><td>' . esc_html( implode( ', ', $events ) ) . '</td><td>' . esc_html( implode( ', ', $active ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
