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
		$settings = Settings::sanitize( function_exists( 'get_option' ) ? get_option( Settings::OPTION, array() ) : array() );
		$notify   = function_exists( 'get_option' ) ? get_option( Notifications::OPTION, array() ) : array();
		return $settings + Notifications::sanitize( is_array( $notify ) ? $notify : array() );
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
				$clean = SettingsRegistry::clean( $key, $value );
				$given = array_filter( preg_split( '/[\s,;]+/', is_scalar( $value ) ? (string) $value : '' ) ?: array() );
				return count( $given ) === count( array_filter( explode( ', ', (string) $clean ) ) ) ? array( true, $clean, '' ) : array( false, null, 'invalid_address' );
			case SettingsRegistry::TYPE_ROLES:
				if ( ! is_array( $value ) ) {
					return array( false, null, 'not_a_list' );
				}
				$clean = SettingsRegistry::clean( $key, $value );
				return count( $clean ) === count( array_unique( array_map( 'strval', $value ) ) ) ? array( true, $clean, '' ) : array( false, null, 'unknown_role' );
			case SettingsRegistry::TYPE_KINDS:
				if ( ! is_array( $value ) ) {
					return array( false, null, 'not_a_list' );
				}
				if ( count( array_filter( $value, 'is_scalar' ) ) !== count( $value ) ) {
					// Nested lists or objects from a manipulated file are no kind (no "Array to string" warning).
					return array( false, null, 'unknown_kind' );
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
		// The audit hook of the settings option would record the same change without its source.
		$hooked = false !== has_action( 'update_option_' . Settings::OPTION, array( Audit::class, 'settings_updated' ) );
		remove_action( 'update_option_' . Settings::OPTION, array( Audit::class, 'settings_updated' ), 10 );
		foreach ( SettingsRegistry::GROUPS as $option => $group ) {
			$own = array();
			foreach ( SettingsRegistry::keys( $option ) as $key ) {
				$own[ $key ] = $new[ $key ];
			}
			update_option( $option, $own, false );
		}
		if ( $hooked ) {
			add_action( 'update_option_' . Settings::OPTION, array( Audit::class, 'settings_updated' ), 10, 2 );
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
		if ( (int) $b['at'] >= time() - self::BACKUP_DAYS * 86400 ) {
			return $b;
		}
		// The backup holds the recipient addresses: after the undo period it is removed, not only hidden.
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::BACKUP_OPTION );
		}
		return null;
	}

	/**
	 * Deletes a backup that can no longer be restored (daily maintenance). It may hold the notification addresses,
	 * so it should not outlive the 30 days the page promises (P3).
	 */
	public static function purge_expired_backup(): bool {
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
