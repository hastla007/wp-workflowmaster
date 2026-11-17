<?php
/**
 * A command a workflow step can run (docs/SPEC-suite-events.md, section 8). Only commands of 8.1 exist in version 1;
 * nothing of the forbidden list 8.2.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

interface Action {

	/** Stable key, for example "notify.admin". */
	public function key(): string;

	/** Label for the admin. */
	public function label(): string;

	/**
	 * Capability a human needs for the same act (R-8.6). The publisher must have it (R-8.7); for a command that writes
	 * in a provider it is checked again before every execution (R-8.8). Null for commands that only write in the
	 * consumer: they need the consumer's capability at publishing only (R-8.9).
	 */
	public function capability(): ?string;

	/** Whether the provider re-checks the idempotency key, so an unclear outcome may be repeated (konzept 15.3). */
	public function idempotent(): bool;

	/**
	 * @return string[] Violation codes of the step configuration; empty when valid. Checked on save and publish.
	 */
	public function validate_config( array $config ): array;

	/**
	 * Runs the command. Throws TransientFailure for a temporary failure, PermanentFailure otherwise.
	 *
	 * @return array Redacted result for the run log (no personal data, no codes).
	 */
	public function execute( ActionContext $context ): array;
}
