<?php
/**
 * Registration and release of own connectors (F-22, R-4.12, WF-CONNECTOR-02, -03, -11).
 *
 * A connector registers on the action "workflow_captain_register_connectors_v1" (init, priority 20, after the
 * adapters) with its manifest and its adapter:
 *
 *     add_action( 'workflow_captain_register_connectors_v1', function ( $registry ) {
 *         $registry->add( My_Connector::manifest(), new My_Connector_Adapter() );
 *     } );
 *
 * The registry checks the manifest and the versions. A connector that passes is registered with the consumer
 * (AdapterRegistry::add_connector()), but stays off until the operator enables it on the admin page "Eigene
 * Connectoren" (option OPTION). A connector that fails keeps a reason; other connectors and adapters keep running.
 * The core checks no license (R-2.4, R-4.13).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Connector;

use WorkflowCaptain\Adapter\Adapter;
use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\Envelope\Validator;

defined( 'ABSPATH' ) || exit;

final class ConnectorRegistry {

	/** Operator release: key => array( 'enabled' => bool, 'personal_fields' => array( type => field[] ) ). */
	const OPTION = 'workflow_captain_connectors';

	/** Connector keys registered on the last request, to pause open events of a connector that is gone (R-10.7). */
	const SEEN_OPTION = 'workflow_captain_connectors_registered';

	const INVALID_MANIFEST    = 'invalid_manifest';
	const REQUIRES_NEWER_CORE = 'requires_newer_core';
	const CONTRACT_MAJOR      = 'contract_major';
	const DUPLICATE           = 'duplicate';
	const NOT_ENABLED         = 'not_enabled';

	/** @var array<string,ConnectorAdapter> */
	private array $connectors = array();

	/** @var array<string,array{reason:string,violations:string[],name:string,version:string,contract_version:string,types:int,actions:int}> */
	private array $refused = array();

	/** @var callable():array */
	private $settings;

	/**
	 * @param AdapterRegistry $adapters      Registry of the consumer; accepted connectors are added there.
	 * @param callable        $settings      Returns the operator release (the value of OPTION), read on every check.
	 * @param string          $core_contract WORKFLOW_CAPTAIN_CONTRACT_VERSION.
	 */
	public function __construct( private AdapterRegistry $adapters, callable $settings, private string $core_contract ) {
		$this->settings = $settings;
	}

	/**
	 * Registers a connector. True when it is accepted (it may still be off until the operator enables it).
	 */
	public function add( array $manifest, Adapter $adapter ): bool {
		$key        = is_string( $manifest['key'] ?? null ) && '' !== $manifest['key'] ? substr( $manifest['key'], 0, 40 ) : '?';
		if ( isset( $this->connectors[ $key ] ) ) {
			return $this->refuse( $key, self::DUPLICATE, $manifest );
		}
		$violations = Manifest::violations( $manifest );
		try {
			if ( array() === $violations && $adapter->product() !== $key ) {
				$violations[] = 'adapter:product_mismatch';
			}
		} catch ( \Throwable $e ) {
			$violations[] = 'adapter:exception';
		}
		if ( array() !== $violations ) {
			return $this->refuse( $key, self::INVALID_MANIFEST, $manifest, $violations );
		}
		$required = (string) $manifest['requires']['workflow_captain'];
		if ( ! Manifest::at_least( $this->core_contract, $required ) ) {
			return $this->refuse( $key, self::REQUIRES_NEWER_CORE, $manifest );
		}
		if ( Manifest::major( $this->core_contract ) !== Manifest::major( $required ) ) {
			return $this->refuse( $key, self::CONTRACT_MAJOR, $manifest );
		}
		$wrapped = new ConnectorAdapter( $adapter, $manifest, $this );
		if ( ! $this->adapters->add_connector( $wrapped ) ) {
			return $this->refuse( $key, self::DUPLICATE, $manifest );
		}
		$this->connectors[ $key ] = $wrapped;
		unset( $this->refused[ $key ] );
		return true;
	}

	public function get( string $key ): ?ConnectorAdapter {
		return $this->connectors[ $key ] ?? null;
	}

	/** @return array<string,ConnectorAdapter> Accepted connectors, enabled or not. */
	public function all(): array {
		return $this->connectors;
	}

	/** @return array<string,array> Refused connectors with reason and violation codes. */
	public function refused(): array {
		return $this->refused;
	}

	/** Reason why a connector does not run: one of the constants, or null when it is accepted and enabled. */
	public function reason( string $key ): ?string {
		if ( isset( $this->connectors[ $key ] ) ) {
			return $this->is_enabled( $key ) ? null : self::NOT_ENABLED;
		}
		return $this->refused[ $key ]['reason'] ?? null;
	}

	public function is_enabled( string $key ): bool {
		$entry = $this->setting( $key );
		return isset( $this->connectors[ $key ] ) && true === ( $entry['enabled'] ?? false );
	}

	/** Whether the operator enabled a personal field of an event type of this connector (WF-CONNECTOR-11). */
	public function personal_allowed( string $key, string $type, string $field ): bool {
		$entry = $this->setting( $key );
		return in_array( $field, (array) ( $entry['personal_fields'][ $type ] ?? array() ), true );
	}

	/**
	 * Whether a workflow may use an action: always for actions without the connector prefix "x"; for an "x" action
	 * only while a registered connector with that prefix declares it in its manifest and is enabled.
	 */
	public function action_available( string $action_key ): bool {
		if ( ! str_starts_with( $action_key, 'x' ) ) {
			return true;
		}
		$prefix = strstr( $action_key, '.', true );
		foreach ( $this->connectors as $key => $connector ) {
			if ( Validator::connector_prefix( $key ) === $prefix ) {
				return $this->is_enabled( $key ) && in_array( $action_key, (array) ( $connector->manifest()['actions'] ?? array() ), true );
			}
		}
		return false;
	}

	/** @return string[] Event types of the enabled connectors. */
	public function event_types(): array {
		$types = array();
		foreach ( $this->connectors as $connector ) {
			$types = array_merge( $types, $connector->types() );
		}
		return $types;
	}

	/**
	 * Data fields with their data type per event type of the enabled connectors, in the format of
	 * assets/data/catalog-fields.json (type => field => data type). Personal fields only when enabled.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function builder_fields(): array {
		$out = array();
		foreach ( $this->connectors as $key => $connector ) {
			if ( ! $this->is_enabled( $key ) ) {
				continue;
			}
			foreach ( (array) $connector->manifest()['events'] as $type => $spec ) {
				$fields = array();
				foreach ( (array) $spec['fields'] as $field => $def ) {
					if ( 'personal' !== $def['class'] || $this->personal_allowed( $key, (string) $type, (string) $field ) ) {
						$fields[ (string) $field ] = (string) $def['type'];
					}
				}
				$out[ (string) $type ] = $fields;
			}
		}
		return $out;
	}

	/**
	 * The release entry to store for a connector, built from the operator's form: only personal fields the manifest
	 * declares survive.
	 *
	 * @param array<string,string[]> $personal Checked fields per event type.
	 */
	public function release_entry( string $key, bool $enabled, array $personal ): ?array {
		$connector = $this->connectors[ $key ] ?? null;
		if ( null === $connector ) {
			return null;
		}
		$allowed = array();
		foreach ( (array) $connector->manifest()['events'] as $type => $spec ) {
			foreach ( (array) $spec['fields'] as $field => $def ) {
				if ( 'personal' === $def['class'] && in_array( (string) $field, (array) ( $personal[ $type ] ?? array() ), true ) ) {
					$allowed[ (string) $type ][] = (string) $field;
				}
			}
		}
		return array(
			'enabled'         => $enabled,
			'personal_fields' => $allowed,
		);
	}

	/**
	 * One row per registered or refused connector, for the admin page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function rows(): array {
		$rows = array();
		foreach ( $this->connectors as $key => $connector ) {
			$m      = $connector->manifest();
			$rows[] = array(
				'key'              => $key,
				'name'             => (string) $m['name'],
				'version'          => (string) $m['version'],
				'contract_version' => (string) $m['contract_version'],
				'status'           => $this->is_enabled( $key ) ? 'enabled' : 'disabled',
				'reason'           => $this->reason( $key ),
				'violations'       => array(),
				'types'            => count( (array) $m['events'] ),
				'actions'          => count( (array) ( $m['actions'] ?? array() ) ),
			);
		}
		foreach ( $this->refused as $key => $r ) {
			$rows[] = array(
				'key'    => (string) $key,
				'status' => 'refused',
			) + $r;
		}
		usort( $rows, static fn( $a, $b ) => strcmp( $a['key'], $b['key'] ) );
		return $rows;
	}

	private function setting( string $key ): array {
		$all = ( $this->settings )();
		return is_array( $all ) && is_array( $all[ $key ] ?? null ) ? $all[ $key ] : array();
	}

	private function refuse( string $key, string $reason, array $manifest, array $violations = array() ): bool {
		$text = static fn( $value, int $max ) => is_string( $value ) ? mb_substr( $value, 0, $max ) : '';
		// A second registration of an accepted key is shown beside it, never instead of it.
		$key  = isset( $this->connectors[ $key ] ) ? $key . ' (2)' : $key;
		$this->refused[ $key ] = array(
			'reason'           => $reason,
			'violations'       => array_slice( $violations, 0, 20 ),
			'name'             => $text( $manifest['name'] ?? null, 80 ),
			'version'          => $text( $manifest['version'] ?? null, 40 ),
			'contract_version' => $text( $manifest['contract_version'] ?? null, 40 ),
			'types'            => is_array( $manifest['events'] ?? null ) ? count( $manifest['events'] ) : 0,
			'actions'          => is_array( $manifest['actions'] ?? null ) ? count( $manifest['actions'] ) : 0,
		);
		return false;
	}
}
