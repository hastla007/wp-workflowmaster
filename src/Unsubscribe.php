<?php
/**
 * Unsubscribe from promotional messages (SET-WC-04, decision S11): the one public address of Workflow Captain.
 *
 * The link goes to wp-admin/admin-post.php?action=workflow_captain_unsubscribe with the user ID and a signature
 * (HMAC-SHA256 with a key derived from the WordPress salts), so nobody can unsubscribe someone else. Opening the link
 * shows a button; only the POST unsubscribes, so link scanners in mail programs do not unsubscribe by opening it.
 * Mail programs that support one-click unsubscribe (RFC 8058) send that POST themselves.
 *
 * The result is the user option workflow_captain_unsubscribed (time in UTC, per site in a network). Messages of the kind "transaction" are
 * not affected. The wording of the pages and of the link line is a placeholder until the legal review (TODO SET-WC-04).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class Unsubscribe {

	const ACTION = 'workflow_captain_unsubscribe';
	const META   = 'workflow_captain_unsubscribed';

	public static function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/** Signature of the link for one user; changes when the salts of the site change. */
	public static function token( int $user_id ): string {
		return hash_hmac( 'sha256', 'workflow-captain-unsubscribe|' . $user_id, self::key() );
	}

	public static function verify( int $user_id, string $token ): bool {
		return $user_id > 0 && '' !== $token && hash_equals( self::token( $user_id ), $token );
	}

	public static function url( int $user_id ): string {
		return add_query_arg(
			array(
				'action' => self::ACTION,
				'u'      => $user_id,
				't'      => self::token( $user_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/** Per site: a user option (in a network the meta key carries the prefix of the site). */
	public static function is_unsubscribed( int $user_id ): bool {
		return '' !== (string) get_user_option( self::META, $user_id );
	}

	/** Records the unsubscribe; returns false when it was already recorded. */
	public static function unsubscribe( int $user_id ): bool {
		if ( self::is_unsubscribed( $user_id ) ) {
			return false;
		}
		update_user_option( $user_id, self::META, gmdate( 'Y-m-d\TH:i:s\Z' ) );
		return true;
	}

	/** GET shows a confirmation button, POST unsubscribes (also the one-click POST of mail programs). */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- the signed link is the check; anonymous visitors have no nonce.
		$user_id = isset( $_REQUEST['u'] ) ? absint( wp_unslash( $_REQUEST['u'] ) ) : 0;
		$token   = isset( $_REQUEST['t'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['t'] ) ) : '';
		// phpcs:enable
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
		}
		$title = __( 'Unsubscribe', 'workflow-captain' );
		if ( ! self::verify( $user_id, $token ) || false === get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'This unsubscribe link is not valid (anymore).', 'workflow-captain' ), esc_html( $title ), array( 'response' => 400 ) );
			return; // A wp_die handler of another plugin may not stop.
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' === $method ) {
			self::unsubscribe( $user_id );
			wp_die( esc_html__( 'You will no longer receive promotional messages from this site. Messages about your orders and bookings are not affected.', 'workflow-captain' ), esc_html( $title ), array( 'response' => 200 ) );
			return;
		}
		$form = '<p>' . esc_html__( 'Do you want to stop receiving promotional messages from this site?', 'workflow-captain' ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="u" value="' . (int) $user_id . '">'
			. '<input type="hidden" name="t" value="' . esc_attr( $token ) . '">'
			. '<button type="submit" class="button button-primary">' . esc_html__( 'Unsubscribe', 'workflow-captain' ) . '</button></form>';
		wp_die( $form, esc_html( $title ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
	}

	private static function key(): string {
		return function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) . '|workflow-captain' : 'workflow-captain-test-key';
	}
}
