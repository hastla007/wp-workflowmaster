<?php
/**
 * Directory on WordPress functions.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class WpDirectory implements Directory, SiteMembers {

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
		return (bool) wp_mail( $to, $subject, $message );
	}
}
