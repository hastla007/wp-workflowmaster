<?php
/**
 * What an action gets when it runs.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class ActionContext {

	/**
	 * @param array  $envelope        The event that started the run (snapshot in the run).
	 * @param array  $config          Configuration of the step.
	 * @param string $idempotency_key Key of this step (R-8.4); the same on every repetition.
	 */
	public function __construct(
		public readonly array $envelope,
		public readonly array $config,
		public readonly string $idempotency_key,
		public readonly int $workflow_id,
		public readonly int $run_id,
		public readonly int $step_id,
		public readonly int $authorized_by_user_id,
		private readonly ?\Closure $heartbeat = null
	) {}

	/**
	 * Extends the worker's lease on this step (WF-CORE-06). An action that may take longer than a few minutes calls it
	 * regularly, so no second worker takes the step over. Returns false if the lease is lost: stop and throw.
	 */
	public function heartbeat(): bool {
		return null === $this->heartbeat ? true : (bool) ( $this->heartbeat )();
	}

	/** Subject of the event as product, type and ID. */
	public function subject(): array {
		return array(
			'product' => (string) ( $this->envelope['source']['product'] ?? '' ),
			'type'    => (string) ( $this->envelope['subject']['type'] ?? '' ),
			'id'      => (string) ( $this->envelope['subject']['id'] ?? '' ),
		);
	}
}
