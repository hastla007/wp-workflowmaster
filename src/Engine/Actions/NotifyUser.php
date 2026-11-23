<?php
/**
 * notify.user (8.1): an additional message to one WordPress user, addressed by user ID (R-5.6). The address is resolved
 * at execution and not stored.
 *
 * Every message is a transaction (default, also for versions published before this field existed) or promotional
 * (SET-WC-03, decisions E-S2 and S11). A promotional message goes out only with the user's consent (filter
 * workflow_captain_has_consent_v1), never to a user who unsubscribed, and always with an unsubscribe link and the
 * List-Unsubscribe headers (SET-WC-04). Otherwise the step runs and sends nothing, with the reason in the log.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine\Actions;

use WorkflowCaptain\Consumer\TransientFailure;
use WorkflowCaptain\Engine\Action;
use WorkflowCaptain\Engine\ActionContext;
use WorkflowCaptain\Engine\Directory;
use WorkflowCaptain\Engine\Marketing;
use WorkflowCaptain\Engine\NetworkSafe;
use WorkflowCaptain\Engine\SiteMembers;
use WorkflowCaptain\Engine\Template;

defined( 'ABSPATH' ) || exit;

final class NotifyUser implements Action, NetworkSafe {

	const PURPOSE_TRANSACTIONAL = 'transactional';
	const PURPOSE_PROMOTIONAL   = 'promotional';

	public function __construct( private Directory $directory ) {}

	public function key(): string {
		return 'notify.user';
	}

	public function label(): string {
		return __( 'Message to user', 'workflow-captain' );
	}

	public function capability(): ?string {
		return null;
	}

	public function idempotent(): bool {
		return false;
	}

	public function validate_config( array $config ): array {
		$v     = array_merge( Template::validate( $config['subject'] ?? null, 'subject', 200 ), Template::validate( $config['message'] ?? null, 'message', 5000 ) );
		$field = $config['user_field'] ?? 'actor_user_id';
		if ( 'actor_user_id' !== $field && ! preg_match( '/^data\.[a-z0-9_]*user_id$/', (string) $field ) ) {
			$v[] = 'user_field:invalid';
		}
		if ( ! in_array( $config['purpose'] ?? self::PURPOSE_TRANSACTIONAL, array( self::PURPOSE_TRANSACTIONAL, self::PURPOSE_PROMOTIONAL ), true ) ) {
			$v[] = 'purpose:invalid';
		}
		return $v;
	}

	/** Editor fields; the kind of message is a required choice with "transaction" first (SET-WC-03). */
	public function builder_fields(): array {
		return array(
			array( 'key' => 'subject', 'label' => __( 'Subject', 'workflow-captain' ) ),
			array( 'key' => 'message', 'label' => __( 'Message', 'workflow-captain' ), 'type' => 'textarea' ),
			array( 'key' => 'user_field', 'label' => __( 'User from (actor_user_id or data.…_user_id)', 'workflow-captain' ) ),
			array(
				'key'     => 'purpose',
				'label'   => __( 'Kind of message', 'workflow-captain' ),
				'type'    => 'select',
				'options' => array(
					array( 'value' => self::PURPOSE_TRANSACTIONAL, 'label' => __( 'Transaction (about an order, booking or account)', 'workflow-captain' ) ),
					array( 'value' => self::PURPOSE_PROMOTIONAL, 'label' => __( 'Promotional (only with consent, with unsubscribe link)', 'workflow-captain' ) ),
				),
			),
		);
	}

	/**
	 * In a run from another site only a user ID from a released data field can be addressed (the actor never crosses,
	 * R-17.3), and only a member of this site (R-17.4). Without a user ID the step runs and sends nothing.
	 */
	public function network_check( ActionContext $context ): ?string {
		$user_id = $this->recipient( $context );
		if ( null === $user_id ) {
			return null;
		}
		return $this->directory instanceof SiteMembers && $this->directory->is_member( $user_id ) ? null : 'user_not_member';
	}

	private function recipient( ActionContext $context ): ?int {
		$field   = (string) ( $context->config['user_field'] ?? 'actor_user_id' );
		$user_id = 'actor_user_id' === $field
			? ( $context->envelope['actor_user_id'] ?? null )
			: ( $context->envelope['data'][ substr( $field, 5 ) ] ?? null );
		return is_int( $user_id ) && $user_id > 0 ? $user_id : null;
	}

	public function execute( ActionContext $context ): array {
		$user_id = $this->recipient( $context );
		$address = null === $user_id ? null : $this->directory->email_of( $user_id );
		if ( null === $address ) {
			return array( 'sent' => 0, 'skipped' => 'no_recipient' );
		}
		$subject = str_replace( array( "\r", "\n" ), ' ', Template::render( (string) $context->config['subject'], $context->envelope ) );
		$message = Template::render( (string) $context->config['message'], $context->envelope );
		if ( self::PURPOSE_PROMOTIONAL === ( $context->config['purpose'] ?? self::PURPOSE_TRANSACTIONAL ) ) {
			return $this->promotional( (int) $user_id, $address, $subject, $message, $context );
		}
		if ( ! $this->directory->mail( $address, $subject, $message ) ) {
			throw new TransientFailure( 'mail_failed' );
		}
		return array( 'sent' => 1 );
	}

	/** Consent first, then the unsubscribe list; the link line is added to the text (wording: legal review). */
	private function promotional( int $user_id, string $address, string $subject, string $message, ActionContext $context ): array {
		$m = $this->directory instanceof Marketing ? $this->directory : null;
		if ( null === $m || ! $m->has_consent( $user_id, array( 'workflow_id' => $context->workflow_id, 'event' => (string) ( $context->envelope['type'] ?? '' ) ) ) ) {
			return array( 'sent' => 0, 'skipped' => 'no_consent', 'purpose' => self::PURPOSE_PROMOTIONAL );
		}
		if ( $m->unsubscribed( $user_id ) ) {
			return array( 'sent' => 0, 'skipped' => 'unsubscribed', 'purpose' => self::PURPOSE_PROMOTIONAL );
		}
		$url = $m->unsubscribe_url( $user_id );
		/* translators: %s: unsubscribe link */
		$message .= "\n\n-- \n" . sprintf( __( 'To stop receiving these messages: %s', 'workflow-captain' ), $url );
		if ( ! $m->mail_promotional( $address, $subject, $message, $url ) ) {
			throw new TransientFailure( 'mail_failed' );
		}
		return array( 'sent' => 1, 'purpose' => self::PURPOSE_PROMOTIONAL );
	}
}
