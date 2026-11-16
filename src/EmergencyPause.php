<?php
/**
 * Global emergency pause (WF-SET-11): stops every step from running. Events are still stored and runs are still
 * created, so nothing is lost; steps wait until the pause is lifted. Nothing is caught up automatically beyond the
 * catch-up limit (konzept 15.3).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class EmergencyPause {

	const OPTION = 'workflow_captain_emergency_pause';

	/** @return array{active:bool,since:?string,by:int} */
	public static function state(): array {
		$s = (array) get_option( self::OPTION, array() );
		return array( 'active' => ! empty( $s['active'] ), 'since' => isset( $s['since'] ) ? (string) $s['since'] : null, 'by' => (int) ( $s['by'] ?? 0 ) );
	}

	public static function active(): bool {
		return self::state()['active'];
	}

	public static function set( bool $active, int $user_id ): void {
		update_option( self::OPTION, $active ? array( 'active' => true, 'since' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'by' => $user_id ) : array( 'active' => false ), false );
	}
}
