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
	public static function append( array $log, string $what, int $user_id, array $detail, string $at ): array {
		array_unshift( $log, array( 'at' => $at, 'user' => $user_id, 'what' => $what, 'detail' => $detail ) );
		return array_slice( $log, 0, self::KEEP );
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

	/** Hook for update_option_workflow_captain_settings. */
	public static function settings_updated( $old, $new ): void {
		$changed = self::changed( is_array( $old ) ? $old : array(), is_array( $new ) ? $new : array() );
		if ( $changed ) {
			self::record( 'settings', $changed );
		}
	}
}
