<?php
/**
 * Action of the starter connector: notes on a ticket that a workflow handled it.
 *
 * Idempotent: the step's idempotency key is stored with the note; a repetition with the same key writes nothing
 * again (R-8.4). The demo keeps the notes in an option; a real connector writes into its project.
 *
 * @package WfcStarterConnector
 */

namespace WfcStarterConnector;

use WorkflowCaptain\Consumer\PermanentFailure;
use WorkflowCaptain\Engine\Action;
use WorkflowCaptain\Engine\ActionContext;

defined( 'ABSPATH' ) || exit;

final class TicketNoteAction implements Action {

	const OPTION = 'wfc_starter_connector_notes';
	const KEEP   = 200;

	public function key(): string {
		return 'xstarterconnector.ticket.note';
	}

	public function label(): string {
		return __( 'Ticket: Vermerk setzen (Starter Connector)', 'wfc-starter-connector' );
	}

	/** The right a human needs to put the same note on a ticket. */
	public function capability(): ?string {
		return 'manage_options';
	}

	public function idempotent(): bool {
		return true;
	}

	public function validate_config( array $config ): array {
		$label = $config['label'] ?? 'workflow';
		return is_string( $label ) && 1 === preg_match( '/^[a-z0-9_-]{1,40}$/', $label ) ? array() : array( 'label:invalid' );
	}

	public function execute( ActionContext $context ): array {
		$ticket_id = (int) ( $context->envelope['data']['ticket_id'] ?? 0 );
		if ( $ticket_id < 1 ) {
			throw new PermanentFailure( 'ticket_missing' );
		}
		$notes = get_option( self::OPTION, array() );
		$notes = is_array( $notes ) ? $notes : array();
		if ( isset( $notes[ $context->idempotency_key ] ) ) {
			return array( 'ticket_id' => $ticket_id, 'repeated' => true );
		}
		$notes[ $context->idempotency_key ] = array(
			'ticket_id' => $ticket_id,
			'label'     => (string) ( $context->config['label'] ?? 'workflow' ),
			'workflow'  => $context->workflow_id,
		);
		update_option( self::OPTION, array_slice( $notes, -self::KEEP, null, true ), false );
		return array( 'ticket_id' => $ticket_id, 'repeated' => false );
	}
}
