<?php
/**
 * Directory on WordPress functions.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class WpDirectory implements MessageFooter, Directory, SiteMembers, Marketing {

	public function user_can( int $user_id, string $capability ): bool {
		return $user_id > 0 && false !== get_userdata( $user_id ) && user_can( $user_id, $capability );
	}

	public function email_of( int $user_id ): ?string {
		$user = get_userdata( $user_id );
		return $user && is_email( $user->user_email ) ? (string) $user->user_email : null;
	}

	public function emails_with_capability( string $capability ): array {
		$emails = array();
		foreach ( get_users( array( 'capability' => $capability, 'fields' => array( 'user_email' ), 'number' => 50 ) ) as $user ) {
			if ( is_email( $user->user_email ) ) {
				$emails[] = (string) $user->user_email;
			}
		}
		return array_values( array_unique( $emails ) );
	}

	public function emails_with_role( string $role ): array {
		$emails = array();
		foreach ( get_users( array( 'role' => $role, 'fields' => array( 'user_email' ), 'number' => 50 ) ) as $user ) {
			if ( is_email( $user->user_email ) ) {
				$emails[] = (string) $user->user_email;
			}
		}
		return array_values( array_unique( $emails ) );
	}

	public function is_member( int $user_id ): bool {
		return $user_id > 0 && ( ! is_multisite() || is_user_member_of_blog( $user_id, get_current_blog_id() ) );
	}

	public function site_admin_email(): string {
		return (string) get_option( 'admin_email' );
	}

	public function mail( string $to, string $subject, string $message ): bool {
		if ( \WorkflowCaptain\TestMode::REDIRECT === \WorkflowCaptain\TestMode::mode() ) {
			// SET-WC-14: the message goes to the catch address; the original recipient is not named anywhere.
			$to      = \WorkflowCaptain\TestMode::catch_address();
			$subject = '[Test] ' . $subject;
		}
		return (bool) wp_mail( $to, $subject, $message, \WorkflowCaptain\Messages::headers( \WorkflowCaptain\Messages::config() ) );
	}

	/** Footer of messages to customers (SET-WC-10); empty when none is set. */
	public function footer(): string {
		return \WorkflowCaptain\Messages::site_footer();
	}

	/**
	 * Consent comes from another plugin (shop, newsletter, form) through the filter; Workflow Captain stores none.
	 * Only a strict true counts (E-S2: no consent, no promotional message).
	 */
	public function has_consent( int $user_id, array $context ): bool {
		/**
		 * Whether a user consented to promotional messages from this site.
		 *
		 * @since 0.3.0
		 * @param bool  $consent Default false.
		 * @param int   $user_id WordPress user ID.
		 * @param array $context workflow_id and event (type of the triggering event).
		 */
		return true === apply_filters( 'workflow_captain_has_consent_v1', false, $user_id, $context );
	}

	public function unsubscribed( int $user_id ): bool {
		return \WorkflowCaptain\Unsubscribe::is_unsubscribed( $user_id );
	}

	public function unsubscribe_url( int $user_id ): string {
		return \WorkflowCaptain\Unsubscribe::url( $user_id );
	}

	public function mail_promotional( string $to, string $subject, string $message, string $unsubscribe_url ): bool {
		if ( \WorkflowCaptain\TestMode::REDIRECT === \WorkflowCaptain\TestMode::mode() ) {
			$to      = \WorkflowCaptain\TestMode::catch_address();
			$subject = '[Test] ' . $subject;
		}
		$headers = array_merge( \WorkflowCaptain\Messages::headers( \WorkflowCaptain\Messages::config() ), array( 'List-Unsubscribe: <' . esc_url_raw( $unsubscribe_url ) . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ) );
		return (bool) wp_mail( $to, $subject, $message, $headers );
	}
}
