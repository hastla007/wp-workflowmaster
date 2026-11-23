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
use WorkflowCaptain\Engine\NetworkSafe;
use WorkflowCaptain\Engine\Directory;
use WorkflowCaptain\Engine\Template;

defined( 'ABSPATH' ) || exit;

final class NotifyAdmin implements Action, NetworkSafe {

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
		if ( 'site_admin' !== $to && ! in_array( $to, self::RECIPIENT_CAPS, true ) && ! self::role_allowed( (string) $to ) ) {
			$v[] = 'to:invalid';
		}
		return $v;
	}

	/** `role:<slug>` for a role the operator released in the settings (SET-WC-25). */
	private static function role_allowed( string $to ): bool {
		return str_starts_with( $to, 'role:' ) && in_array( substr( $to, 5 ), \WorkflowCaptain\Notifications::config()['notify_admin_roles'], true );
	}

	/** Recipient as a choice list in the editor: site address, the fixed capabilities, the released roles. */
	public function builder_fields(): array {
		$options = array(
			array( 'value' => 'site_admin', 'label' => __( 'Adresse der Website', 'workflow-captain' ) ),
			array( 'value' => 'manage_options', 'label' => __( 'Alle Administratoren (Recht manage_options)', 'workflow-captain' ) ),
			array( 'value' => 'manage_woocommerce', 'label' => __( 'Alle Shop-Verwalter (Recht manage_woocommerce)', 'workflow-captain' ) ),
			array( 'value' => 'manage_workflow_captain', 'label' => __( 'Alle mit Recht manage_workflow_captain', 'workflow-captain' ) ),
		);
		$names = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();
		foreach ( \WorkflowCaptain\Notifications::config()['notify_admin_roles'] as $role ) {
			/* translators: %s: name of a user role */
			$options[] = array( 'value' => 'role:' . $role, 'label' => sprintf( __( 'Rolle: %s', 'workflow-captain' ), isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role ) );
		}
		return array(
			array( 'key' => 'subject', 'label' => __( 'Betreff', 'workflow-captain' ) ),
			array( 'key' => 'message', 'label' => __( 'Nachricht', 'workflow-captain' ), 'type' => 'textarea' ),
			array( 'key' => 'to', 'label' => __( 'Empfänger', 'workflow-captain' ), 'type' => 'select', 'options' => $options ),
		);
	}

	/** Writes only on the site it runs on and needs no user of the source site (R-17.4). */
	public function network_check( ActionContext $context ): ?string {
		return null;
	}

	public function execute( ActionContext $context ): array {
		$to = (string) ( $context->config['to'] ?? 'site_admin' );
		if ( str_starts_with( $to, 'role:' ) && ! self::role_allowed( $to ) ) {
			// The operator withdrew the role after publishing: send nothing, say why.
			return array( 'sent' => 0, 'skipped' => 'recipient_not_allowed' );
		}
		if ( 'site_admin' === $to ) {
			$recipients = array( $this->directory->site_admin_email() );
		} elseif ( str_starts_with( $to, 'role:' ) ) {
			$recipients = $this->directory->emails_with_role( substr( $to, 5 ) );
		} else {
			$recipients = $this->directory->emails_with_capability( $to );
		}
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
