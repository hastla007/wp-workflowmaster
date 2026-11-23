<?php
/**
 * Optional part of a Directory for promotional messages (SET-WC-03/-04, decisions E-S2 and S11): consent, the
 * unsubscribe list and the unsubscribe link. Without it, notify.user sends no promotional message at all.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

interface Marketing {

	/**
	 * Whether the user consented to promotional messages. Workflow Captain keeps no consent itself; another plugin
	 * answers through the filter workflow_captain_has_consent_v1. No answer means no consent.
	 *
	 * @param array<string,mixed> $context workflow_id, event type.
	 */
	public function has_consent( int $user_id, array $context ): bool;

	/** Whether the user unsubscribed from promotional messages of this site. */
	public function unsubscribed( int $user_id ): bool;

	/** Signed link that unsubscribes this user (one public address, admin-post.php). */
	public function unsubscribe_url( int $user_id ): string;

	/** Sends one promotional plain text mail with the List-Unsubscribe headers (RFC 2369, RFC 8058). */
	public function mail_promotional( string $to, string $subject, string $message, string $unsubscribe_url ): bool;
}
