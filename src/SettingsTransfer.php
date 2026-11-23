<?php
/**
 * Export, import, reset and undo of the settings (SET-WC-11, SET-WC-12, SET-WC-23). Works on the register
 * (SettingsRegistry): an export holds only fields marked as exportable (no addresses, no secrets), an import is
 * checked field by field and reports errors instead of correcting values silently, and every change that is not a
 * normal form save keeps a backup of the previous values for 30 days, so it can be undone once.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class SettingsTransfer {

	const PLUGIN        = 'workflow-captain';
	const BACKUP_OPTION = 'workflow_captain_settings_backup';
	const BACKUP_DAYS   = 30;

	/**
	 * Stored values of all fields (without filters): what the operator set.
	 *
	 * @return array<string,mixed>
	 */
	public static function current(): array {
		return Settings::stored() + Notifications::stored();
	}

	/** @return array<string,mixed> Default of every field. */
	public static function defaults(): array {
		$out = array();
		foreach ( array_keys( SettingsRegistry::SPEC ) as $key ) {
			$out[ $key ] = SettingsRegistry::field( $key )['default'];
		}
		return $out;
	}

	/**
	 * Export document: format number, plugin, version and the exportable values.
	 *
	 * @param array<string,mixed> $values
	 */
	public static function export( array $values, string $version ): array {
		$out = array();
		foreach ( array_keys( SettingsRegistry::SPEC ) as $key ) {
			if ( SettingsRegistry::field( $key )['export'] && array_key_exists( $key, $values ) ) {
				$out[ $key ] = $values[ $key ];
			}
		}
		return array(
			'format'   => SettingsRegistry::FORMAT,
			'plugin'   => self::PLUGIN,
			'version'  => $version,
			'settings' => $out,
		);
	}

	/**
	 * Checks one value strictly: returns array( ok, stored value, error code ).
	 *
	 * @return array{0:bool,1:mixed,2:string}
	 */
	public static function check( string $key, $value ): array {
		$f = SettingsRegistry::field( $key );
		if ( null === $f ) {
			return array( false, null, 'unknown' );
		}
		switch ( $f['type'] ) {
			case SettingsRegistry::TYPE_INT:
			case SettingsRegistry::TYPE_BOOL:
				if ( is_bool( $value ) && SettingsRegistry::TYPE_BOOL === $f['type'] ) {
					$value = (int) $value;
				}
				if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^-?\d+$/', trim( $value ) ) ) ) {
					return array( false, null, 'not_a_number' );
				}
				$value = (int) $value;
				if ( $value < $f['min'] || $value > $f['max'] ) {
					return array( false, null, 'out_of_range' );
				}
				return array( true, $value, '' );
			case SettingsRegistry::TYPE_EMAILS:
				if ( ! is_scalar( $value ) ) {
					return array( false, null, 'invalid_address' );
				}
				// The same address twice is one address (as the form stores it), not an error.
				$parts = preg_split( '/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
				$given = array_unique( array_map( 'strtolower', is_array( $parts ) ? $parts : array() ) );
				$valid = array_filter( $given, static fn( string $a ): bool => false !== filter_var( $a, FILTER_VALIDATE_EMAIL ) );
				if ( count( $valid ) !== count( $given ) ) {
					return array( false, null, 'invalid_address' );
				}
				if ( count( $given ) > $f['max'] ) {
					return array( false, null, 'too_many' );
				}
				return array( true, SettingsRegistry::clean( $key, $value ), '' );
			case SettingsRegistry::TYPE_KINDS:
				if ( ! is_array( $value ) || count( $value ) !== count( array_filter( $value, 'is_string' ) ) ) {
					return array( false, null, 'not_a_list' );
				}
				$clean = SettingsRegistry::clean( $key, $value );
				return count( $clean ) === count( array_unique( array_map( 'strval', $value ) ) ) ? array( true, $clean, '' ) : array( false, null, 'unknown_kind' );
		}
		return array( false, null, 'unknown' );
	}

	/**
	 * Reads an export document. Never writes.
	 *
	 * @param mixed $document Decoded JSON (array) or JSON text.
	 * @return array{values:array<string,mixed>,errors:array<string,string>,ignored:string[]}
	 */
	public static function parse( $document ): array {
		$result = array( 'values' => array(), 'errors' => array(), 'ignored' => array() );
		if ( is_string( $document ) ) {
			$document = json_decode( $document, true );
		}
		if ( ! is_array( $document ) || ! isset( $document['settings'] ) || ! is_array( $document['settings'] ) ) {
			$result['errors']['_file'] = 'not_a_settings_file';
			return $result;
		}
		if ( self::PLUGIN !== ( $document['plugin'] ?? '' ) ) {
			$result['errors']['_file'] = 'other_plugin';
			return $result;
		}
		if ( ! is_int( $document['format'] ?? null ) || $document['format'] < 1 ) {
			$result['errors']['_file'] = 'no_format';
			return $result;
		}
		if ( $document['format'] > SettingsRegistry::FORMAT ) {
			$result['errors']['_file'] = 'newer_format';
			return $result;
		}
		foreach ( $document['settings'] as $key => $value ) {
			$key = (string) $key;
			$f   = SettingsRegistry::field( $key );
			if ( null === $f || ! $f['export'] ) {
				$result['ignored'][] = $key;
				continue;
			}
			list( $ok, $clean, $code ) = self::check( $key, $value );
			if ( $ok ) {
				$result['values'][ $key ] = $clean;
			} else {
				$result['errors'][ $key ] = $code;
			}
		}
		return $result;
	}

	/**
	 * Keys whose value would change: key => array( old, new ).
	 *
	 * @return array<string,array{0:mixed,1:mixed}>
	 */
	public static function diff( array $current, array $incoming ): array {
		$out = array();
		foreach ( $incoming as $key => $value ) {
			if ( ( $current[ $key ] ?? null ) !== $value ) {
				$out[ $key ] = array( $current[ $key ] ?? null, $value );
			}
		}
		return $out;
	}

	/**
	 * Default values for one tab of the settings page, or for all fields.
	 *
	 * @return array<string,mixed>
	 */
	public static function reset_values( ?string $tab ): array {
		$defaults = self::defaults();
		if ( null === $tab || '' === $tab ) {
			return $defaults;
		}
		return array_intersect_key( $defaults, array_flip( SettingsRegistry::tab( $tab ) ) );
	}

	/**
	 * Writes values (already checked) to their options, keeps a backup of the previous values and records the change.
	 * Returns the changed keys.
	 *
	 * @param array<string,mixed> $values
	 * @return array<string,array{0:mixed,1:mixed}>
	 */
	public static function apply( array $values, string $source ): array {
		$current = self::current();
		$changed = self::diff( $current, $values );
		if ( array() === $changed ) {
			return array();
		}
		update_option(
			self::BACKUP_OPTION,
			array(
				'at'     => time(),
				'user'   => (int) get_current_user_id(),
				'source' => $source,
				'values' => $current,
			),
			false
		);
		$new = array_merge( $current, $values );
		// The audit hooks of the form would record the same change without its source.
		$hooked = Audit::watching_settings();
		if ( $hooked ) {
			Audit::watch_settings( false );
		}
		foreach ( SettingsRegistry::GROUPS as $option => $group ) {
			$own = array();
			foreach ( SettingsRegistry::keys( $option ) as $key ) {
				$own[ $key ] = $new[ $key ];
			}
			update_option( $option, $own, false );
		}
		if ( $hooked ) {
			Audit::watch_settings( true );
		}
		$logged = array();
		foreach ( $changed as $key => $pair ) {
			// Addresses are personal data: the log names the field, not the value.
			$logged[ $key ] = SettingsRegistry::TYPE_EMAILS === SettingsRegistry::field( $key )['type'] ? array( '…', '…' ) : $pair;
		}
		Audit::record( 'settings_' . $source, $logged );
		return $changed;
	}

	/** @return array{at:int,user:int,source:string,values:array}|null The backup that undo would restore. */
	public static function backup(): ?array {
		$b = function_exists( 'get_option' ) ? get_option( self::BACKUP_OPTION, null ) : null;
		if ( ! is_array( $b ) || ! isset( $b['at'], $b['values'] ) || ! is_array( $b['values'] ) ) {
			return null;
		}
		return (int) $b['at'] >= time() - self::BACKUP_DAYS * 86400 ? $b : null;
	}

	/**
	 * Deletes a backup older than 30 days (daily maintenance). It holds the addresses of the recipients, which must not
	 * stay longer than the undo period (P1 finding).
	 */
	public static function purge_backup(): bool {
		if ( ! function_exists( 'get_option' ) || false === get_option( self::BACKUP_OPTION, false ) || null !== self::backup() ) {
			return false;
		}
		delete_option( self::BACKUP_OPTION );
		return true;
	}

	/** Restores the backup once. Returns the changed keys, or null when there is nothing to undo. */
	public static function undo(): ?array {
		$b = self::backup();
		if ( null === $b ) {
			return null;
		}
		$values = array();
		foreach ( $b['values'] as $key => $value ) {
			list( $ok, $clean ) = self::check( (string) $key, $value );
			if ( $ok ) {
				$values[ $key ] = $clean;
			}
		}
		$changed = self::apply( $values, 'undo' );
		delete_option( self::BACKUP_OPTION );
		return $changed;
	}
}
