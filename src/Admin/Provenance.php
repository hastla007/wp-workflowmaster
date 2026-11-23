<?php
/**
 * Origin of the event types and actions a workflow uses (docs/UI-ENTWUERFE.md, section 2): which product provides
 * them, with plugin and contract version, for the node heading ("WOOCOMMERCE · AUSLÖSER") and the editor header.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\Envelope\Validator;

defined( 'ABSPATH' ) || exit;

final class Provenance {

	/** Product of the core's own actions ("workflow.tag.set", "notify.admin"). */
	const CORE = 'workflow-captain';

	/** Readable name of a product key; unknown keys (own connectors) become words. */
	public static function label( string $product ): string {
		$known = array(
			'woocommerce'      => 'WooCommerce',
			'wordpress'        => 'WordPress',
			'workflow-captain' => 'Workflow Captain',
			'wp-license'       => 'WP License',
		);
		return $known[ $product ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $product ) );
	}

	/**
	 * @param string[] $action_keys    Available action keys.
	 * @param string[] $connector_keys Keys of known own connectors (their action prefix maps back to them).
	 * @return array{products:array<string,array{label:string,version:?string,contract:?string,registered:bool}>,events:array<string,string>,actions:array<string,string>}
	 */
	public static function sources( AdapterRegistry $adapters, array $action_keys, array $connector_keys = array() ): array {
		$products = array();
		$events   = array();
		foreach ( $adapters->products() as $product ) {
			$adapter = $adapters->get( $product );
			if ( null === $adapter ) {
				continue;
			}
			$products[ $product ] = array(
				'label'      => self::label( $product ),
				'version'    => '' !== $adapter->core_version() ? $adapter->core_version() : null,
				'contract'   => $adapter->contract_version(),
				'registered' => true,
			);
			foreach ( $adapter->types() as $type ) {
				$events[ (string) $type ] = $product;
			}
		}
		$actions = array();
		foreach ( $action_keys as $key ) {
			$prefix  = (string) strstr( (string) $key, '.', true );
			$product = in_array( $prefix, array( 'workflow', 'notify' ), true ) ? self::CORE : Validator::product_for_prefix( $prefix, $connector_keys );
			if ( null === $product ) {
				continue;
			}
			$actions[ (string) $key ] = $product;
			if ( ! isset( $products[ $product ] ) ) {
				$products[ $product ] = array( 'label' => self::label( $product ), 'version' => null, 'contract' => null, 'registered' => false );
			}
		}
		return array( 'products' => $products, 'events' => $events, 'actions' => $actions );
	}

	/**
	 * One line on the trigger's provider for the editor header, e.g. "WooCommerce 9.4.0, Vertrag 1.0", or null when
	 * no registered adapter provides the event type.
	 *
	 * @param array{products:array<string,array<string,mixed>>,events:array<string,string>} $sources From sources().
	 */
	public static function describe_event( array $sources, string $event ): ?string {
		$product = $sources['events'][ $event ] ?? null;
		if ( null === $product ) {
			return null;
		}
		$info = $sources['products'][ $product ];
		$text = $info['label'] . ( null !== $info['version'] ? ' ' . $info['version'] : '' );
		if ( null !== $info['contract'] ) {
			/* translators: 1: product and plugin version, 2: contract version */
			$text = sprintf( __( '%1$s, contract %2$s', 'workflow-captain' ), $text, $info['contract'] );
		}
		return $text;
	}
}
