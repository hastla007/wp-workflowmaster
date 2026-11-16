<?php
/**
 * notify.admin (8.1): an additional message to the site's admin address or to the users with a fixed capability.
 * Never replaces or suppresses a mail of a core (R-9.2).
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

final class NotifyAdmin implements Action {

	/** Capabilities whose holders may be addressed. */
	const RECIPIENT_CAPS = array( 'manage_options', 'manage_woocommerce', 'manage_workflow_captain' );

	public function __construct( private Directory $directory ) {}

	public function key(): string {
		return 'notify.admin';
	}

	public function label(): string {
		return __( 'Nachricht an Betreiber', 'workflow-captain' );
	}

	public function capability(): ?string {
		return null;
	}

	public function idempotent(): bool {
		return false;
	}

	public function validate_config( array $config ): array {
		$v = array_merge( Template::validate( $config['subject'] ?? null, 'subject', 200 ), Template::validate( $config['message'] ?? null, 'message', 5000 ) );
		$to = $config['to'] ?? 'site_admin';
		if ( 'site_admin' !== $to && ! in_array( $to, self::RECIPIENT_CAPS, true ) ) {
			$v[] = 'to:invalid';
		}
		return $v;
	}

	public function execute( ActionContext $context ): array {
		$to         = $context->config['to'] ?? 'site_admin';
		$recipients = 'site_admin' === $to ? array( $this->directory->site_admin_email() ) : $this->directory->emails_with_capability( (string) $to );
		$recipients = array_values( array_filter( $recipients, 'is_string' ) );
		if ( array() === $recipients ) {
			return array( 'sent' => 0, 'skipped' => 'no_recipient' );
		}
		$subject = str_replace( array( "\r", "\n" ), ' ', Template::render( (string) $context->config['subject'], $context->envelope ) );
		$message = Template::render( (string) $context->config['message'], $context->envelope );
		$sent    = 0;
		foreach ( $recipients as $address ) {
			if ( $this->directory->mail( $address, $subject, $message ) ) {
				++$sent;
			}
		}
		if ( 0 === $sent ) {
			throw new TransientFailure( 'mail_failed' );
		}
		// The log names the recipient group, never an address.
		return array( 'sent' => $sent, 'to' => $to );
	}
}
