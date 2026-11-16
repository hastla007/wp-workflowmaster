<?php
/**
 * Reconciliation with the providers (WF-CORE-10): checks stored events that are not done (retry_wait, dead_letter,
 * paused) against the current state of their provider through the adapter's reread(). Read only by default; with
 * $requeue it puts events that still hold and can be requeued (dead_letter, paused) back into the queue. Nothing is
 * executed directly, no action is repeated, and the report contains counts and event numbers only, never data.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Consumer;

use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\Store\EventStore;

defined( 'ABSPATH' ) || exit;

final class Reconcile {

	const STATES = array( EventStore::RETRY_WAIT, EventStore::DEAD_LETTER, EventStore::PAUSED );

	public function __construct( private EventStore $store, private AdapterRegistry $adapters, private Consumer $consumer ) {}

	/**
	 * @return array{checked:int,holds:int,changed:int,no_adapter:int,error:int,requeued:int,changed_ids:int[]}
	 */
	public function run( bool $requeue = false, int $limit = 500 ): array {
		$r = array( 'checked' => 0, 'holds' => 0, 'changed' => 0, 'no_adapter' => 0, 'error' => 0, 'requeued' => 0, 'changed_ids' => array() );
		foreach ( self::STATES as $state ) {
			foreach ( $this->store->find( array( 'state' => $state ), $limit, 0 ) as $row ) {
				++$r['checked'];
				$envelope = (array) ( $row['envelope'] ?? array() );
				$adapter  = $this->adapters->get( (string) ( $envelope['source']['product'] ?? '' ) );
				if ( null === $adapter ) {
					++$r['no_adapter'];
					continue;
				}
				try {
					$holds = ! $adapter->needs_reread( (string) ( $envelope['type'] ?? '' ) ) || $adapter->reread( $envelope );
				} catch ( \Throwable $e ) {
					++$r['error'];
					continue;
				}
				if ( ! $holds ) {
					++$r['changed'];
					$r['changed_ids'][] = (int) $row['id'];
					continue;
				}
				++$r['holds'];
				if ( $requeue && EventStore::RETRY_WAIT !== $state && $this->consumer->requeue( (int) $row['id'] ) ) {
					++$r['requeued'];
				}
			}
		}
		return $r;
	}
}
