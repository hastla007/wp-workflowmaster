<?php
/**
 * workflow.tag.set and workflow.tag.remove (8.1): a tag on the event's object, stored in the consumer.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine\Actions;

use WorkflowCaptain\Clock;
use WorkflowCaptain\Engine\Action;
use WorkflowCaptain\Engine\ActionContext;
use WorkflowCaptain\Engine\NetworkSafe;
use WorkflowCaptain\Engine\EngineStore;

defined( 'ABSPATH' ) || exit;

final class Tag implements Action, NetworkSafe {

	public function __construct( private EngineStore $store, private Clock $clock, private bool $remove = false ) {}

	public function key(): string {
		return $this->remove ? 'workflow.tag.remove' : 'workflow.tag.set';
	}

	public function label(): string {
		return $this->remove ? __( 'Markierung entfernen', 'workflow-captain' ) : __( 'Markierung setzen', 'workflow-captain' );
	}

	public function capability(): ?string {
		return null;
	}

	public function idempotent(): bool {
		return true;
	}

	public function validate_config( array $config ): array {
		return is_string( $config['tag'] ?? null ) && preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $config['tag'] ) ? array() : array( 'tag:invalid' );
	}

	/** Writes only on the site it runs on and needs no user of the source site (R-17.4). */
	public function network_check( ActionContext $context ): ?string {
		return null;
	}

	public function execute( ActionContext $context ): array {
		$s   = $context->subject();
		$tag = (string) $context->config['tag'];
		if ( $this->remove ) {
			$this->store->remove_tag( $s['product'], $s['type'], $s['id'], $tag );
		} else {
			$this->store->set_tag( $s['product'], $s['type'], $s['id'], $tag, $context->workflow_id, $this->clock->mysql() );
		}
		return array( 'tag' => $tag );
	}
}
