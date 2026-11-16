<?php
/**
 * notify.user (8.1): an additional message to one WordPress user, addressed by user ID (R-5.6). The address is resolved
 * at execution and not stored.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine\Actions;

use WorkflowCaptain\Consumer\TransientFailure;
use WorkflowCaptain\Engine\Action;
use WorkflowCaptain\Engine\ActionContext;
use WorkflowCaptain\Engine\Directory;
use WorkflowCaptain\Engine\Template;

defined( 'ABSPATH' ) || exit;

final class NotifyUser implements Action {

	public function __construct( private Directory $directory ) {}

	public function key(): string {
		return 'notify.user';
	}

	public function label(): string {
		return __( 'Nachricht an Benutzer', 'workflow-captain' );
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
		return $v;
	}

	public function execute( ActionContext $context ): array {
		$field   = (string) ( $context->config['user_field'] ?? 'actor_user_id' );
		$user_id = 'actor_user_id' === $field
			? ( $context->envelope['actor_user_id'] ?? null )
			: ( $context->envelope['data'][ substr( $field, 5 ) ] ?? null );
		$address = is_int( $user_id ) && $user_id > 0 ? $this->directory->email_of( $user_id ) : null;
		if ( null === $address ) {
			return array( 'sent' => 0, 'skipped' => 'no_recipient' );
		}
		$subject = str_replace( array( "\r", "\n" ), ' ', Template::render( (string) $context->config['subject'], $context->envelope ) );
		if ( ! $this->directory->mail( $address, $subject, Template::render( (string) $context->config['message'], $context->envelope ) ) ) {
			throw new TransientFailure( 'mail_failed' );
		}
		return array( 'sent' => 1 );
	}
}
