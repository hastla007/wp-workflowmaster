<?php
/**
 * Support diagnostics without personal data or secrets (TODO 18.7 P2, WF-CORE-10): versions, schema, adapters,
 * counts per state, rejection codes, cron and stuck work. No event content, no user IDs, no addresses, no keys.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Store\EventStore;

defined( 'ABSPATH' ) || exit;

final class Diagnostics {

	/**
	 * @param array<string,mixed> $environment Versions and cron state from WordPress (see Plugin::diagnostics()).
	 */
	public static function report( EventStore $events, EngineStore $engine, AdapterRegistry $adapters, array $environment ): array {
		$runs = array();
		foreach ( array( EngineStore::RUN_RUNNING, EngineStore::RUN_ACTION_REQUIRED, EngineStore::RUN_PAUSED, EngineStore::RUN_SUCCEEDED, EngineStore::RUN_STOPPED, EngineStore::RUN_FAILED, EngineStore::RUN_CANCELLED ) as $state ) {
			$runs[ $state ] = count( $engine->find_runs( array( 'state' => $state ), 1000, 0 ) );
		}
		$reasons = array();
		foreach ( $engine->find_runs( array( 'state' => EngineStore::RUN_ACTION_REQUIRED ), 200, 0 ) as $run ) {
			$reason             = (string) $run['last_error'];
			$reasons[ $reason ] = ( $reasons[ $reason ] ?? 0 ) + 1;
		}
		$workflows = $engine->list_workflows( 1000, 0 );
		return array(
			'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'environment'  => $environment,
			'adapters'     => array(
				'registered' => $adapters->products(),
				'refused'    => $adapters->refused(),
			),
			'events'       => $events->count_by_state(),
			'runs'         => $runs,
			'action_required_reasons' => $reasons,
			'workflows'    => array(
				'total'  => count( $workflows ),
				'active' => count( array_filter( $workflows, static fn( $w ) => EngineStore::WF_ACTIVE === $w['status'] ) ),
				'paused' => count( array_filter( $workflows, static fn( $w ) => EngineStore::WF_PAUSED === $w['status'] ) ),
			),
		);
	}
}
