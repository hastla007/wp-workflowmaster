<?php
/**
 * Adapter of the core for recurring schedules (WF-CORE-04): product "workflow-captain", one event type
 * "workflow.schedule.due". It only exists inside the core and registers through AdapterRegistry::add_core().
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Providers;

use WorkflowCaptain\Adapter\Adapter;
use WorkflowCaptain\Engine\Schedule;

defined( 'ABSPATH' ) || exit;

final class ScheduleAdapter implements Adapter {

	public function product(): string {
		return 'workflow-captain';
	}

	public function core_version(): string {
		return defined( 'WORKFLOW_CAPTAIN_VERSION' ) ? (string) WORKFLOW_CAPTAIN_VERSION : '0.0.0';
	}

	public function contract_version(): ?string {
		return defined( 'WORKFLOW_CAPTAIN_CONTRACT_VERSION' ) ? (string) WORKFLOW_CAPTAIN_CONTRACT_VERSION : null;
	}

	public function types(): array {
		// Own events of the core: schedules (WF-CORE-04) and inbound triggers declared by add-ons (4.10).
		return array_merge( array( Schedule::EVENT ), \WorkflowCaptain\Engine\InboundTriggers::types() );
	}

	public function needs_reread( string $type ): bool {
		return false;
	}

	public function reread( array $envelope ): bool {
		return true;
	}
}
