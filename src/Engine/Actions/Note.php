<?php
/**
 * workflow.note.add (8.1): a note on the event's object, stored in the consumer, once per step key (R-8.4).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine\Actions;

use WorkflowCaptain\Clock;
use WorkflowCaptain\Engine\Action;
use WorkflowCaptain\Engine\ActionContext;
use WorkflowCaptain\Engine\NetworkSafe;
use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Engine\Template;

defined( 'ABSPATH' ) || exit;

final class Note implements Action, NetworkSafe {

	public function __construct( private EngineStore $store, private Clock $clock ) {}

	public function key(): string {
		return 'workflow.note.add';
	}

	public function label(): string {
		return __( 'Add note', 'workflow-captain' );
	}

	public function capability(): ?string {
		return null;
	}

	public function idempotent(): bool {
		return true;
	}

	public function validate_config( array $config ): array {
		return Template::validate( $config['text'] ?? null, 'text', 1000 );
	}

	/** Writes only on the site it runs on and needs no user of the source site (R-17.4). */
	public function network_check( ActionContext $context ): ?string {
		return null;
	}

	public function execute( ActionContext $context ): array {
		$s = $context->subject();
		$this->store->add_note( $context->idempotency_key, $s['product'], $s['type'], $s['id'], Template::render( (string) $context->config['text'], $context->envelope ), $context->workflow_id, $context->run_id, $this->clock->mysql() );
		return array( 'note' => 1 );
	}
}
