<?php
/**
 * Adapter of the starter connector: turns the demo hook "wfc_starter_ticket_closed" into an event.
 *
 * Rules of the contract that apply here: data is built from a fixed list of fields (R-5.2), the callback catches
 * every exception (R-7.6) and writes exactly once, via Consumer::capture().
 *
 * @package WfcStarterConnector
 */

namespace WfcStarterConnector;

use WorkflowCaptain\Adapter\Adapter;

defined( 'ABSPATH' ) || exit;

final class TicketAdapter implements Adapter {

	const TYPE = 'xstarterconnector.ticket.closed';

	/** @var callable():\WorkflowCaptain\Consumer\Consumer */
	private $consumer;

	/**
	 * @param callable|null $consumer Returns the consumer; by default the one of the running Workflow Captain.
	 */
	public function __construct( ?callable $consumer = null ) {
		$this->consumer = $consumer ?? static fn() => \WorkflowCaptain\Plugin::instance()->consumer();
	}

	public function listen(): void {
		add_action( 'wfc_starter_ticket_closed', array( $this, 'on_ticket_closed' ), 20, 2 );
	}

	public function product(): string {
		return Manifest::KEY;
	}

	public function core_version(): string {
		return '1.0.0';
	}

	public function contract_version(): ?string {
		return '1.0.0';
	}

	public function types(): array {
		return array( self::TYPE );
	}

	public function needs_reread( string $type ): bool {
		return false; // The demo hook fires after the ticket was saved (after_commit: true in the manifest).
	}

	public function reread( array $envelope ): bool {
		return true;
	}

	/**
	 * Callback of the demo hook.
	 *
	 * @param mixed $ticket_id
	 * @param mixed $priority
	 * @return string Result of Consumer::capture(), or "failed".
	 */
	public function on_ticket_closed( $ticket_id, $priority = 'normal' ): string {
		try {
			$ticket_id = (int) $ticket_id;
			if ( $ticket_id < 1 ) {
				return 'failed';
			}
			$event = array(
				'type'          => self::TYPE,
				'subject'       => array( 'type' => 'ticket', 'id' => (string) $ticket_id, 'id_kind' => 'record' ),
				'data'          => array(
					'ticket_id' => $ticket_id,
					'priority'  => in_array( $priority, array( 'low', 'normal', 'high' ), true ) ? $priority : 'normal',
				),
				'discriminator' => 'once',
			);
			$user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			if ( $user > 0 ) {
				$event['actor_user_id'] = $user;
			}
			return ( $this->consumer )()->capture( $this, $event );
		} catch ( \Throwable $e ) {
			return 'failed'; // Never break the project's own flow (R-7.6).
		}
	}
}
