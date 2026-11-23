<?php
/**
 * Access to WordPress users and mail, behind an interface so the engine can be tested without WordPress.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

interface Directory {

	/** Whether the user exists and has the capability (R-8.8). */
	public function user_can( int $user_id, string $capability ): bool;

	/** E-mail address of a user, resolved at execution and never stored (R-5.6). */
	public function email_of( int $user_id ): ?string;

	/** @return string[] Addresses of users with this capability. */
	public function emails_with_capability( string $capability ): array;

	/** @return string[] Addresses of users with this role (SET-WC-25). */
	public function emails_with_role( string $role ): array;

	public function site_admin_email(): string;

	/** Sends one plain text mail. */
	public function mail( string $to, string $subject, string $message ): bool;
}
