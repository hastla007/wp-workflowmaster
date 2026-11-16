<?php
/**
 * Daily notice to the operator when something needs attention (WF-SET-06): decisions waiting, failed events,
 * hanging work, emergency pause. Counts and links only, no event content.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class Notifications {

	/**
	 * @return array{subject:string,message:string}|null Null when nothing needs attention.
	 */
	public static function digest( array $d, string $admin_url ): ?array {
		$lines = array();
		$add   = static function ( int $n, string $text ) use ( &$lines ): void {
			if ( $n > 0 ) {
				$lines[] = sprintf( '- %d %s', $n, $text );
			}
		};
		$add( (int) ( $d['runs']['action_required'] ?? 0 ), __( 'Läufe brauchen eine Entscheidung', 'workflow-captain' ) );
		$add( (int) ( $d['runs']['paused'] ?? 0 ), __( 'Läufe sind pausiert', 'workflow-captain' ) );
		$add( (int) ( $d['events']['dead_letter'] ?? 0 ), __( 'Ereignisse sind fehlgeschlagen', 'workflow-captain' ) );
		$add( (int) ( $d['stuck']['expired_leases'] ?? 0 ) + (int) ( $d['stuck']['runs_without_steps'] ?? 0 ), __( 'Schritte oder Läufe hängen', 'workflow-captain' ) );
		if ( ! empty( $d['environment']['emergency_pause'] ) ) {
			$lines[] = '- ' . __( 'Die Notfallpause ist aktiv.', 'workflow-captain' );
		}
		if ( array() === $lines ) {
			return null;
		}
		return array(
			'subject' => __( 'Workflow Captain: Bitte prüfen', 'workflow-captain' ),
			'message' => implode( "\n", $lines ) . "\n\n" . $admin_url . "\n\n" . __( 'Diese Nachricht kommt einmal täglich, solange etwas offen ist. Abschalten unter Einstellungen → Benachrichtigungen.', 'workflow-captain' ),
		);
	}
}
