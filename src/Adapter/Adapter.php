<?php
/**
 * Public interface an adapter implements to deliver events to the consumer (WF-SPEC-06; versioned with
 * WORKFLOW_CAPTAIN_CONTRACT_VERSION, R-13.7).
 *
 * An adapter for a Captain product lives in an add-on, never in the free core (A-1); the only built-in adapters are
 * those for WordPress and WooCommerce (src/Providers, F-20, SPEC-suite-events 4.7). An adapter hooks itself to the PHP actions of its provider and,
 * in each callback, builds the event from a fixed list of allowed fields (R-5.2) and hands it to
 * Consumer::capture(). The consumer knows no product key and no license right (C-1): whether an adapter may register
 * at all is decided in the add-on before it calls AdapterRegistry::add().
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Adapter;

defined( 'ABSPATH' ) || exit;

interface Adapter {

	/** Key of the provider (source.product), for example "pass-captain". */
	public function product(): string;

	/** Plugin version of the provider right now (source.core_version). */
	public function core_version(): string;

	/** Value of the provider's contract constant, or null while it has none (source.contract_version). */
	public function contract_version(): ?string;

	/**
	 * Event types this adapter delivers. Events of other types are stored but start nothing (R-13.3).
	 *
	 * @return string[]
	 */
	public function types(): array;

	/** Whether events of this type must be re-read before a workflow starts ("after commit" not proven, R-7.4). */
	public function needs_reread( string $type ): bool;

	/**
	 * Re-reads the provider's state through its public read functions (R-7.4).
	 *
	 * @return bool true if the state matches the event, false if it contradicts it (the event is discarded).
	 * @throws \WorkflowCaptain\Consumer\TransientFailure When the provider cannot be read right now.
	 */
	public function reread( array $envelope ): bool;
}
