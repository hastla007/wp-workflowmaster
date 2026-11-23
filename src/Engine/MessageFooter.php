<?php
/**
 * Optional part of a Directory: the footer the operator set for messages to customers (SET-WC-10).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

interface MessageFooter {

	/** The footer with its placeholders filled; empty when none is set. */
	public function footer(): string;
}
