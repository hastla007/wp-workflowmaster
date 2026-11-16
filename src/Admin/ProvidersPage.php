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
		echo '<p class="description">' . esc_html__( 'Ereignisse der Captain-Produkte kommen über Adapter aus den Pro-Add-ons. Die Adapter für WordPress und WooCommerce (ab Version 8.2) sind eingebaut und brauchen keine Lizenz. Ohne Adapter werden offene Ereignisse pausiert, nicht gelöscht.', 'workflow-captain' ) . '</p>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Noch kein Adapter angemeldet und keine Ereignisse gespeichert.', 'workflow-captain' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Produkt', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Adapter', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Plugin / Vertrag', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Ereignistypen', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Gespeicherte Ereignisse', 'workflow-captain' ) . '</th><th>' . esc_html__( 'Aktive Workflows', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$events = array();
			foreach ( $r['events'] as $state => $n ) {
				$events[] = EventsPage::state_label( $state ) . ': ' . $n;
			}
			$active = array();
			foreach ( $r['active_workflows'] as $type => $n ) {
				$active[] = $type . ' (' . $n . ')';
			}
			echo '<tr><td><code>' . esc_html( $r['product'] ) . '</code></td><td>' . esc_html( $labels[ $r['status'] ] . ( $r['reason'] ? ' – ' . $r['reason'] : '' ) ) . '</td><td>' . esc_html( ( $r['core_version'] ?? '–' ) . ' / ' . ( $r['contract_version'] ?? '–' ) ) . '</td><td>' . esc_html( implode( ', ', $r['types'] ) ) . '</td><td>' . esc_html( implode( ', ', $events ) ) . '</td><td>' . esc_html( implode( ', ', $active ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
