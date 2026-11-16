<?php
/**
 * Checks the manifest an own connector registers with (F-22, R-4.12, WF-CONNECTOR-01; docs/CONNECTOR-CONTRACT.md).
 *
 *     array(
 *         'key'              => 'starter-connector',
 *         'name'             => 'Starter Connector',
 *         'version'          => '1.0.0',
 *         'contract_version' => '1.0.0',
 *         'requires'         => array( 'workflow_captain' => '0.1.0' ),
 *         'events'           => array(
 *             'xstarterconnector.ticket.closed' => array(
 *                 'fields'       => array( 'ticket_id' => array( 'type' => 'integer', 'class' => 'normal' ) ),
 *                 'after_commit' => true,
 *                 'actor'        => 'logged-in user who closed the ticket',
 *             ),
 *         ),
 *         'actions'          => array( 'xstarterconnector.ticket.note' ),
 *     )
 *
 * The result is a list of violation codes. A code names the rule and the place, never a value (as Validator does).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Connector;

use WorkflowCaptain\Envelope\Validator;

defined( 'ABSPATH' ) || exit;

final class Manifest {

	const MAX_TYPES   = 50;
	const MAX_FIELDS  = 24;
	const MAX_ACTIONS = 50;

	const FIELD_TYPES   = array( 'integer', 'number', 'boolean', 'string', 'currency', 'datetime' );
	const FIELD_CLASSES = array( 'normal', 'personal' );

	const TOP_LEVEL = array( 'key', 'name', 'version', 'contract_version', 'requires', 'events', 'actions', 'description' );

	const SEMVER      = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/';
	const SEMVER_PRE  = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.]+)?$/';
	const FIELD_NAME  = '/^[a-z][a-z0-9_]*$/';
	const ACTION_TAIL = '/^(\.[a-z][a-z0-9_]*){2}$/';

	/**
	 * @param mixed $manifest
	 * @return string[] Violation codes; empty when the manifest is valid.
	 */
	public static function violations( $manifest ): array {
		if ( ! is_array( $manifest ) || array() === $manifest || array_is_list( $manifest ) ) {
			return array( 'manifest:not_object' );
		}
		$v = array();
		foreach ( array_keys( $manifest ) as $key ) {
			if ( ! in_array( $key, self::TOP_LEVEL, true ) ) {
				$v[] = 'manifest:unknown_field';
			}
		}
		$key = $manifest['key'] ?? null;
		if ( ! is_string( $key ) || ! Validator::is_connector( $key ) ) {
			$v[] = 'key:invalid';
			$key = null;
		}
		$name = $manifest['name'] ?? null;
		if ( ! is_string( $name ) || '' === trim( $name ) || mb_strlen( $name ) > 80 ) {
			$v[] = 'name:invalid';
		}
		if ( isset( $manifest['description'] ) && ( ! is_string( $manifest['description'] ) || mb_strlen( $manifest['description'] ) > 500 ) ) {
			$v[] = 'description:invalid';
		}
		if ( ! self::matches( $manifest['version'] ?? null, self::SEMVER_PRE ) ) {
			$v[] = 'version:invalid';
		}
		if ( ! self::matches( $manifest['contract_version'] ?? null, self::SEMVER ) ) {
			$v[] = 'contract_version:invalid';
		}
		$requires = $manifest['requires'] ?? null;
		if ( ! is_array( $requires ) || ! self::matches( $requires['workflow_captain'] ?? null, self::SEMVER ) ) {
			$v[] = 'requires.workflow_captain:invalid';
		} else {
			foreach ( $requires as $what => $version ) {
				if ( ! is_string( $what ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,39}$/', $what ) || ! self::matches( $version, self::SEMVER_PRE ) ) {
					$v[] = 'requires:invalid';
				}
			}
		}
		self::events( $manifest['events'] ?? null, $key, $v );
		self::actions( $manifest['actions'] ?? array(), $key, $v );
		return array_values( array_unique( $v ) );
	}

	public static function is_valid( $manifest ): bool {
		return array() === self::violations( $manifest );
	}

	/** Whether a semantic version is at least another one (both MAJOR.MINOR.PATCH). */
	public static function at_least( string $version, string $minimum ): bool {
		return version_compare( $version, $minimum, '>=' );
	}

	public static function major( string $version ): int {
		return (int) strtok( $version, '.' );
	}

	private static function events( $events, ?string $key, array &$v ): void {
		if ( ! is_array( $events ) || ( array() !== $events && array_is_list( $events ) ) ) {
			$v[] = 'events:not_object';
			return;
		}
		if ( count( $events ) > self::MAX_TYPES ) {
			$v[] = 'events:too_many_types';
		}
		$prefix = null === $key ? null : Validator::connector_prefix( $key ) . '.';
		foreach ( $events as $type => $spec ) {
			$type = (string) $type;
			if ( ! preg_match( Validator::TYPE, $type ) || strlen( $type ) > 96 || ( null !== $prefix && ! str_starts_with( $type, $prefix ) ) ) {
				$v[] = 'events:type_invalid';
				continue;
			}
			if ( ! is_array( $spec ) || ( array() !== $spec && array_is_list( $spec ) ) || array_diff( array_keys( $spec ), array( 'fields', 'after_commit', 'actor' ) ) ) {
				$v[] = 'events:spec_invalid';
				continue;
			}
			if ( ! is_bool( $spec['after_commit'] ?? null ) ) {
				$v[] = 'events:after_commit_invalid';
			}
			if ( ! is_string( $spec['actor'] ?? null ) || mb_strlen( $spec['actor'] ) > 191 ) {
				$v[] = 'events:actor_invalid';
			}
			$fields = $spec['fields'] ?? null;
			if ( ! is_array( $fields ) || ( array() !== $fields && array_is_list( $fields ) ) ) {
				$v[] = 'events:fields_invalid';
				continue;
			}
			if ( count( $fields ) > self::MAX_FIELDS ) {
				$v[] = 'events:too_many_fields';
			}
			foreach ( $fields as $field => $def ) {
				$field = (string) $field;
				if ( ! preg_match( self::FIELD_NAME, $field ) || strlen( $field ) > 48 ) {
					$v[] = 'events:field_name_invalid';
				} elseif ( preg_match( Validator::FORBIDDEN_DATA_NAME, $field ) ) {
					$v[] = 'events:field_name_forbidden';
				}
				if ( ! is_array( $def ) || array_diff( array_keys( $def ), array( 'type', 'class' ) ) || ! in_array( $def['type'] ?? null, self::FIELD_TYPES, true ) || ! in_array( $def['class'] ?? null, self::FIELD_CLASSES, true ) ) {
					$v[] = 'events:field_spec_invalid';
				}
			}
		}
	}

	private static function actions( $actions, ?string $key, array &$v ): void {
		if ( ! is_array( $actions ) || ! array_is_list( $actions ) ) {
			$v[] = 'actions:not_list';
			return;
		}
		if ( count( $actions ) > self::MAX_ACTIONS ) {
			$v[] = 'actions:too_many';
		}
		if ( count( $actions ) !== count( array_unique( array_map( 'strval', array_filter( $actions, 'is_string' ) ) ) ) ) {
			$v[] = 'actions:duplicate';
		}
		$prefix = null === $key ? null : Validator::connector_prefix( $key );
		foreach ( $actions as $action ) {
			if ( ! is_string( $action ) || strlen( $action ) > 96 || null === $prefix || ! str_starts_with( $action, $prefix . '.' ) || ! preg_match( self::ACTION_TAIL, substr( $action, strlen( $prefix ) ) ) ) {
				$v[] = 'actions:key_invalid';
			}
		}
	}

	private static function matches( $value, string $pattern ): bool {
		return is_string( $value ) && 1 === preg_match( $pattern, $value );
	}
}
