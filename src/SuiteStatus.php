<?php
/**
 * Status of Workflow Captain for one product (task "Providerstatus, aktive Workflowlinks und gemeinsame
 * Run-/Correlation-IDs in Suite-Admins anzeigen"). A core plugin (Pass, Voucher, Affiliate …) can show it in its own
 * admin without knowing Workflow Captain's classes:
 *
 *     if ( function_exists( 'workflow_captain_status_for' ) ) {
 *         $s = workflow_captain_status_for( 'pass-captain' );
 *     }
 *
 * Counts and links only; no event data, no personal data.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Store\EventStore;

defined( 'ABSPATH' ) || exit;

final class SuiteStatus {

	const VERSION = 1;

	/**
	 * @return array{version:int,product:string,adapter:string,reason:?string,contract_version:?string,active_workflows:int,events:array<string,int>,last_correlation_id:?string,links:array<string,?string>}
	 */
	public static function for_product( string $product, AdapterRegistry $adapters, EventStore $events, EngineStore $engine, ?callable $url = null ): array {
		$adapter = $adapters->get( $product );
		$refused = $adapters->refused();
		$active  = 0;
		foreach ( null !== $adapter ? $adapter->types() : array() as $type ) {
			$active += count( $engine->active_versions_for_trigger( (string) $type ) );
		}
		$counts = $events->count_by_product()[ $product ] ?? array();
		$last   = $events->find( array( 'product' => $product ), 1, 0 )[0]['envelope']['correlation_id'] ?? null;
		$link   = $url ?? static fn( string $page, array $args = array() ) => function_exists( 'admin_url' ) ? add_query_arg( array( 'page' => $page ) + $args, admin_url( 'admin.php' ) ) : $page;
		return array(
			'version'             => self::VERSION,
			'product'             => $product,
			'adapter'             => null !== $adapter ? 'registered' : ( isset( $refused[ $product ] ) ? 'refused' : 'missing' ),
			'reason'              => $refused[ $product ] ?? null,
			'contract_version'    => null !== $adapter ? $adapter->contract_version() : null,
			'active_workflows'    => $active,
			'events'              => array_map( 'intval', (array) $counts ),
			'last_correlation_id' => is_string( $last ) ? $last : null,
			'links'               => array(
				'workflows' => $link( 'workflow-captain-workflows' ),
				'runs'      => $link( 'workflow-captain-runs' ),
				'events'    => $link( 'workflow-captain-events', array( 'product' => $product ) ),
				'providers' => $link( 'workflow-captain-providers' ),
				'correlation' => is_string( $last ) ? $link( 'workflow-captain-events', array( 'correlation' => $last ) ) : null,
			),
		);
	}
}
