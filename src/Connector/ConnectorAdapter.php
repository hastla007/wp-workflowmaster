<?php
/**
 * The adapter the consumer actually uses for an own connector (F-22, WF-CONNECTOR-03, -04, -11).
 *
 * It wraps the connector's own adapter and adds what the core enforces for every connector, whatever the connector
 * hands over:
 *
 * - nothing is captured until the operator enabled the connector (WF-CONNECTOR-11);
 * - only event types declared in the manifest are captured;
 * - data is rebuilt from the fields declared in the manifest (positive list, R-5.2), not copied and stripped; a value
 *   of the wrong data type refuses the event;
 * - fields of the class "personal" are left out unless the operator enabled that field (R-4.12).
 *
 * Consumer::capture() routes every event whose adapter names a connector key through filter() of the instance that
 * the ConnectorRegistry registered, so a connector cannot bypass it by passing its own adapter object.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Connector;

use WorkflowCaptain\Adapter\Adapter;

defined( 'ABSPATH' ) || exit;

final class ConnectorAdapter implements Adapter {

	public function __construct( private Adapter $inner, private array $manifest, private ConnectorRegistry $registry ) {}

	public function product(): string {
		return (string) $this->manifest['key'];
	}

	public function core_version(): string {
		return (string) $this->manifest['version'];
	}

	/** The connector's own contract version (source.contract_version). */
	public function contract_version(): ?string {
		return (string) $this->manifest['contract_version'];
	}

	public function enabled(): bool {
		return $this->registry->is_enabled( $this->product() );
	}

	/** Event types a workflow can start from: the declared ones, and only while the connector is enabled. */
	public function types(): array {
		return $this->enabled() ? $this->declared_types() : array();
	}

	/** @return string[] */
	public function declared_types(): array {
		return array_map( 'strval', array_keys( (array) $this->manifest['events'] ) );
	}

	/** Re-read when the connector says so, and always for a type it does not declare "after commit" (R-7.4). */
	public function needs_reread( string $type ): bool {
		return true !== ( $this->manifest['events'][ $type ]['after_commit'] ?? false ) || $this->inner->needs_reread( $type );
	}

	public function reread( array $envelope ): bool {
		return $this->inner->reread( $envelope );
	}

	public function manifest(): array {
		return $this->manifest;
	}

	public function inner(): Adapter {
		return $this->inner;
	}

	/**
	 * The event as the core captures it, or the reason code why it is refused. Never contains a value in a code.
	 *
	 * @param array $event Event as the connector handed it to Consumer::capture().
	 * @return array|string
	 */
	public function filter( array $event ) {
		if ( ! $this->enabled() ) {
			return 'connector:not_enabled';
		}
		$type = is_string( $event['type'] ?? null ) ? $event['type'] : '';
		$spec = $this->manifest['events'][ $type ] ?? null;
		if ( ! is_array( $spec ) ) {
			return 'connector:undeclared_type';
		}
		$given = is_array( $event['data'] ?? null ) ? $event['data'] : array();
		$data  = array();
		foreach ( (array) $spec['fields'] as $field => $def ) {
			$field = (string) $field;
			if ( ! array_key_exists( $field, $given ) ) {
				continue;
			}
			if ( 'personal' === $def['class'] && ! $this->registry->personal_allowed( $this->product(), $type, $field ) ) {
				continue;
			}
			$value = $given[ $field ];
			if ( null !== $value && ! self::type_matches( (string) $def['type'], $value ) ) {
				return 'connector:field_type';
			}
			$data[ $field ] = $value;
		}
		$event['data'] = $data;
		return $event;
	}

	/** Whether a value has the data type the manifest declares (catalog data_types). */
	public static function type_matches( string $type, $value ): bool {
		switch ( $type ) {
			case 'integer':
				return is_int( $value );
			case 'number':
				return is_int( $value ) || ( is_float( $value ) && is_finite( $value ) );
			case 'boolean':
				return is_bool( $value );
			case 'string':
				return is_string( $value );
			case 'currency':
				return is_string( $value ) && 1 === preg_match( '/^[A-Z]{3}$/', $value );
			case 'datetime':
				return is_string( $value ) && 1 === preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?Z$/', $value );
		}
		return false;
	}
}
