<?php
/**
 * Sender, reply address and footer of messages from workflows (SET-WC-10, decision S6: free in every core; white
 * label stays with an add-on). Empty fields keep today's behaviour: WordPress' sender (or the one an SMTP or shop
 * plugin sets), no Reply-To, no footer.
 *
 * - Sender and reply address apply to every message a workflow sends (notify.user and notify.admin).
 * - The footer is added to messages to customers (notify.user) only, before the unsubscribe line of a promotional
 *   message. Placeholders: {site_name}, {site_url}, {privacy_policy_url}. The text itself is the operator's; no
 *   default wording (legal review).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class Messages {

	const OPTION = 'workflow_captain_messages';

	/** @return array{sender_name:string,sender_email:string,reply_to:string,message_footer:string} Stored values, without filters. */
	public static function stored(): array {
		$c = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return self::sanitize( is_array( $c ) ? $c : array() );
	}

	/** @return array{sender_name:string,sender_email:string,reply_to:string,message_footer:string} Values in effect, with the filter of each field. */
	public static function config(): array {
		$config = self::stored();
		foreach ( $config as $key => $value ) {
			$config[ $key ] = SettingsRegistry::filtered( $key, $value );
		}
		return $config;
	}

	/** Cleans every field by the rules of the register; unknown keys are dropped. */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		foreach ( SettingsRegistry::keys( self::OPTION ) as $key ) {
			$out[ $key ] = SettingsRegistry::clean( $key, $input[ $key ] ?? '' );
		}
		return $out;
	}

	/** Sanitize callback of the form: like sanitize(), and says at the field which address was not taken. */
	public static function sanitize_and_report( $input ): array {
		$clean = self::sanitize( $input );
		if ( function_exists( 'add_settings_error' ) && is_array( $input ) ) {
			$codes = function_exists( 'get_settings_errors' ) ? array_column( get_settings_errors( Settings::OPTION ), 'code' ) : array();
			foreach ( array( 'sender_email', 'reply_to' ) as $key ) {
				$raw = is_scalar( $input[ $key ] ?? null ) ? trim( (string) $input[ $key ] ) : '';
				if ( '' !== $raw && '' === $clean[ $key ] && ! in_array( 'wfc-' . $key, $codes, true ) ) {
					/* translators: %s: entered address */
					add_settings_error( Settings::OPTION, 'wfc-' . $key, sprintf( __( '“%s” is not a valid e-mail address and was not saved.', 'workflow-captain' ), $raw ), 'error' );
				}
			}
		}
		return $clean;
	}

	/**
	 * Mail headers for From and Reply-To; empty when nothing is set.
	 *
	 * @return string[]
	 */
	public static function headers( array $config ): array {
		$headers = array();
		if ( '' !== $config['sender_email'] ) {
			$name      = str_replace( array( "\r", "\n", '<', '>', '"' ), '', $config['sender_name'] );
			$headers[] = 'From: ' . ( '' === $name ? '' : '"' . $name . '" ' ) . '<' . $config['sender_email'] . '>';
		}
		if ( '' !== $config['reply_to'] ) {
			$headers[] = 'Reply-To: <' . $config['reply_to'] . '>';
		}
		return $headers;
	}

	/**
	 * The footer with its placeholders filled; empty when none is set.
	 *
	 * @param array<string,string> $site site_name, site_url, privacy_policy_url.
	 */
	public static function footer( array $config, array $site ): string {
		if ( '' === $config['message_footer'] ) {
			return '';
		}
		return strtr(
			$config['message_footer'],
			array(
				'{site_name}'          => (string) ( $site['site_name'] ?? '' ),
				'{site_url}'           => (string) ( $site['site_url'] ?? '' ),
				'{privacy_policy_url}' => (string) ( $site['privacy_policy_url'] ?? '' ),
			)
		);
	}

	/** Footer of this site (WordPress functions). */
	public static function site_footer(): string {
		return self::footer(
			self::config(),
			array(
				'site_name'          => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
				'site_url'           => home_url( '/' ),
				'privacy_policy_url' => function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '',
			)
		);
	}
}
