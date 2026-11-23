<?php
/**
 * Short audit trail of security-relevant changes (WF-SET-13): rights per role, the emergency pause and the settings.
 * Holds the last entries only, with the user ID and the changed keys, never values of events or secrets.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class Audit {

	const OPTION = 'workflow_captain_audit';
	const KEEP   = 50;

	/**
	 * Adds an entry in front and keeps the newest KEEP entries.
	 *
	 * @param array<int,array<string,mixed>> $log
	 * @return array<int,array<string,mixed>>
	 */
	public static function append( array $log, string $what, int $user_id, array $detail, string $at, ?int $keep = null ): array {
		array_unshift( $log, array( 'at' => $at, 'user' => $user_id, 'what' => $what, 'detail' => $detail ) );
		return array_slice( $log, 0, $keep ?? self::keep() );
	}

	/** Number of entries kept: setting `audit_keep` (10 to 500, default KEEP; SET-WC-13). */
	public static function keep(): int {
		return (int) Settings::get()['audit_keep'];
	}

	/**
	 * Keys whose value changed between two settings arrays, with old and new value (settings are numbers only).
	 *
	 * @return array<string,array{0:mixed,1:mixed}>
	 */
	public static function changed( array $old, array $new ): array {
		$out = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
			if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
				$out[ $key ] = array( $old[ $key ] ?? null, $new[ $key ] ?? null );
			}
		}
		return $out;
	}

	public static function record( string $what, array $detail = array() ): void {
		$log = get_option( self::OPTION, array() );
		update_option( self::OPTION, self::append( is_array( $log ) ? $log : array(), $what, (int) get_current_user_id(), $detail, gmdate( 'Y-m-d\TH:i:s\Z' ) ), false );
	}

	/** @return array<int,array<string,mixed>> */
	public static function entries(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/** Hook for update_option_ of both settings options (form save through options.php). */
	public static function settings_updated( $old, $new ): void {
		$changed = self::changed( is_array( $old ) ? $old : array(), is_array( $new ) ? $new : array() );
		if ( $changed ) {
			self::record( 'settings', self::masked( $changed ) );
		}
	}

	/**
	 * Hook for add_option_ of both settings options: the first save of a site creates the option instead of updating
	 * it, so update_option_ does not fire (P1 finding). The change is recorded against the defaults.
	 */
	public static function settings_added( $option, $value ): void {
		$defaults = Settings::OPTION === $option ? Settings::defaults() : Notifications::sanitize( array() );
		// Compare like for like: fields the value does not name count as their default, not as a change.
		$value = Settings::OPTION === $option ? Settings::sanitize( $value ) : Notifications::sanitize( $value );
		self::settings_updated( $defaults, $value );
	}

	/** Addresses are personal data: the log names the field, not the value (as SettingsTransfer::apply()). */
	private static function masked( array $changed ): array {
		foreach ( $changed as $key => $pair ) {
			$field = SettingsRegistry::field( (string) $key );
			if ( null !== $field && SettingsRegistry::TYPE_EMAILS === $field['type'] ) {
				$changed[ $key ] = array( '…', '…' );
			}
		}
		return $changed;
	}

	/**
	 * Hooks or unhooks the form-save audit of both settings options. SettingsTransfer::apply() unhooks it while it
	 * writes, because it records the same change with its source.
	 */
	public static function watch_settings( bool $on ): void {
		foreach ( array( Settings::OPTION, Notifications::OPTION, Messages::OPTION ) as $option ) {
			if ( $on ) {
				add_action( 'update_option_' . $option, array( self::class, 'settings_updated' ), 10, 2 );
				add_action( 'add_option_' . $option, array( self::class, 'settings_added' ), 10, 2 );
			} else {
				remove_action( 'update_option_' . $option, array( self::class, 'settings_updated' ), 10 );
				remove_action( 'add_option_' . $option, array( self::class, 'settings_added' ), 10 );
			}
		}
	}

	/** Whether the form-save audit is hooked (see watch_settings()). */
	public static function watching_settings(): bool {
		return function_exists( 'has_action' ) && false !== has_action( 'update_option_' . Settings::OPTION, array( self::class, 'settings_updated' ) );
	}
}
