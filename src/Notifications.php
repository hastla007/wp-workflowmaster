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

	const OPTION = 'workflow_captain_notify';

	/** Kinds of notices the operator can choose (WF-SET-06). */
	const KINDS = array( 'decisions', 'paused', 'failed', 'stuck', 'pause', 'providers' );

	/** @return array{recipients:string,kinds:string[]} */
	public static function config(): array {
		$c      = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$config = self::sanitize( is_array( $c ) ? $c : array() );
		foreach ( $config as $key => $value ) {
			$config[ $key ] = SettingsRegistry::filtered( $key, $value );
		}
		return $config;
	}

	/** Keeps valid addresses (at most 10) and known kinds; all kinds when none is stored yet (rules in the register). */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		return array(
			'recipients' => SettingsRegistry::clean( 'recipients', $input['recipients'] ?? '' ),
			'kinds'      => SettingsRegistry::clean( 'kinds', $input['kinds'] ?? null ),
		);
	}

	/** @return string[] The chosen addresses, else the address of the site administrator. */
	public static function recipients( array $config, string $fallback ): array {
		$list = array_filter( array_map( 'trim', explode( ',', $config['recipients'] ) ) );
		return $list ? array_values( $list ) : array( $fallback );
	}

	/**
	 * Sender: WooCommerce's sender when WooCommerce is active, else WordPress' default (no header).
	 *
	 * @return string[]
	 */
	public static function headers(): array {
		if ( function_exists( 'get_option' ) && class_exists( 'WooCommerce' ) ) {
			$address = sanitize_email( (string) get_option( 'woocommerce_email_from_address' ) );
			$name    = wp_specialchars_decode( (string) get_option( 'woocommerce_email_from_name' ), ENT_QUOTES );
			if ( '' !== $address ) {
				return array( 'From: ' . str_replace( array( "\r", "\n", '<', '>' ), '', $name ) . ' <' . $address . '>' );
			}
		}
		return array();
	}

	/**
	 * @param string[]|null $kinds Kinds to report; null for all.
	 * @return array{subject:string,message:string}|null Null when nothing needs attention.
	 */
	public static function digest( array $d, string $admin_url, ?array $kinds = null ): ?array {
		$kinds = $kinds ?? self::KINDS;
		$lines = array();
		$add   = static function ( string $kind, int $n, string $text ) use ( &$lines, $kinds ): void {
			if ( $n > 0 && in_array( $kind, $kinds, true ) ) {
				$lines[] = sprintf( '- %d %s', $n, $text );
			}
		};
		$add( 'decisions', (int) ( $d['runs']['action_required'] ?? 0 ), __( 'Läufe brauchen eine Entscheidung', 'workflow-captain' ) );
		$add( 'paused', (int) ( $d['runs']['paused'] ?? 0 ), __( 'Läufe sind pausiert', 'workflow-captain' ) );
		$add( 'failed', (int) ( $d['events']['dead_letter'] ?? 0 ), __( 'Ereignisse sind fehlgeschlagen', 'workflow-captain' ) );
		$add( 'stuck', (int) ( $d['stuck']['expired_leases'] ?? 0 ) + (int) ( $d['stuck']['runs_without_steps'] ?? 0 ), __( 'Schritte oder Läufe hängen', 'workflow-captain' ) );
		$add( 'providers', count( (array) ( $d['adapters']['refused'] ?? array() ) ), __( 'Provider wurden abgelehnt (zum Beispiel unpassende Vertragsversion)', 'workflow-captain' ) );
		if ( ! empty( $d['environment']['emergency_pause'] ) && in_array( 'pause', $kinds, true ) ) {
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

	/** Test mail: no content of events, no secrets (WF-SET-06). */
	public static function test_mail( string $site_name ): array {
		return array(
			/* translators: %s: site name */
			'subject' => sprintf( __( 'Workflow Captain: Testnachricht von %s', 'workflow-captain' ), $site_name ),
			'message' => __( 'Diese Testnachricht zeigt, dass Hinweise von Workflow Captain ankommen. Sie enthält keine Daten von Ereignissen oder Läufen.', 'workflow-captain' ),
		);
	}
}
