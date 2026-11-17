<?php
/**
 * Registration of adapters (WF-SPEC-06). Add-ons register on the action "workflow_captain_register_adapters_v1":
 *
 *     add_action( 'workflow_captain_register_adapters_v1', function ( $registry ) {
 *         $registry->add( new My_Adapter() );
 *     } );
 *
 * One adapter per provider. The registry checks only the form of what an adapter says about itself; it checks no
 * license and knows no product key (C-1, R-2.4).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Adapter;

use WorkflowCaptain\Connector\ConnectorAdapter;
use WorkflowCaptain\Envelope\Validator;

defined( 'ABSPATH' ) || exit;

final class AdapterRegistry {

	/** @var array<string,Adapter> */
	private array $adapters = array();

	/** @var array<string,string> Reason per product an adapter was refused. */
	private array $refused = array();

	public function add( Adapter $adapter ): bool {
		$product = $adapter->product();
		// Own connectors register through ConnectorRegistry with a manifest (F-22, WF-CONNECTOR-03), never here.
		if ( ! in_array( $product, Validator::PRODUCTS, true ) || 'workflow-captain' === $product ) {
			$this->refused[ $product ] = 'unknown_product';
			return false;
		}
		if ( isset( $this->adapters[ $product ] ) ) {
			$this->refused[ $product ] = 'already_registered';
			return false;
		}
		if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$/', $adapter->core_version() ) ) {
			$this->refused[ $product ] = 'invalid_core_version';
			return false;
		}
		$contract = $adapter->contract_version();
		if ( null !== $contract && ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/', $contract ) ) {
			$this->refused[ $product ] = 'invalid_contract_version';
			return false;
		}
		$this->adapters[ $product ] = $adapter;
		unset( $this->refused[ $product ] );
		return true;
	}

	/**
	 * Records why a product has no adapter although its provider is present, e.g. "provider_too_old" for a built-in
	 * adapter whose WordPress or WooCommerce is older than the minimum (MINVER-1). Never overwrites a registration.
	 *
	 * @internal
	 */
	public function note_refused( string $product, string $reason ): void {
		if ( ! isset( $this->adapters[ $product ] ) ) {
			$this->refused[ $product ] = $reason;
		}
	}

	/**
	 * Internal path for own connectors: only ConnectorRegistry::add() calls this, after it checked the manifest
	 * (F-22, WF-CONNECTOR-03). It takes only the core's wrapper, so the consumer always filters a connector's events
	 * through it (ConnectorAdapter::filter()). add() keeps refusing connector keys.
	 *
	 * @internal
	 */
	public function add_connector( ConnectorAdapter $adapter ): bool {
		$product = $adapter->product();
		if ( ! Validator::is_connector( $product ) || isset( $this->adapters[ $product ] ) ) {
			return false;
		}
		if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$/', $adapter->core_version() ) ) {
			return false;
		}
		$this->adapters[ $product ] = $adapter;
		unset( $this->refused[ $product ] );
		return true;
	}

	/**
	 * Internal path for the core's own schedule adapter (product "workflow-captain", WF-CORE-04).
	 *
	 * @internal
	 */
	public function add_core( \WorkflowCaptain\Providers\ScheduleAdapter $adapter ): bool {
		if ( isset( $this->adapters[ $adapter->product() ] ) ) {
			return false;
		}
		$this->adapters[ $adapter->product() ] = $adapter;
		return true;
	}

	public function remove( string $product ): void {
		unset( $this->adapters[ $product ] );
	}

	public function get( string $product ): ?Adapter {
		return $this->adapters[ $product ] ?? null;
	}

	/** @return string[] Products with a registered adapter. */
	public function products(): array {
		return array_keys( $this->adapters );
	}

	/** @return array<string,string> */
	public function refused(): array {
		return $this->refused;
	}
}
