<?php
/**
 * Admin page "Hilfe" (WF-SET-02): first steps, terms and where to look when something is stuck.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

defined( 'ABSPATH' ) || exit;

final class HelpPage {

	const SLUG = 'workflow-captain-help';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 90 );
	}

	public function menu(): void {
		add_submenu_page( OverviewPage::SLUG, __( 'Help', 'workflow-captain' ), __( 'Help', 'workflow-captain' ), 'view_workflow_logs', self::SLUG, array( $this, 'render' ) );
	}

	/** @return array<string,string[]> Heading => paragraphs. */
	public static function sections(): array {
		return array(
			__( 'Getting started', 'workflow-captain' ) => array(
				__( '1. Under “Providers”, check which plugins supply events. WordPress and WooCommerce are built in; the Captain products come with Workflow Captain Pro.', 'workflow-captain' ),
				__( '2. Under “Templates”, add a flow as a draft, or create a new one under “Workflows”.', 'workflow-captain' ),
				__( '3. In the editor, start a test run with sample data. It does not execute anything.', 'workflow-captain' ),
				__( '4. Publish. From then on, every matching event starts a run; the version stays unchanged for runs in progress.', 'workflow-captain' ),
			),
			__( 'Terms', 'workflow-captain' ) => array(
				__( 'Event: something that happened in a plugin, for example “Order paid”. Events contain no names, email addresses or codes.', 'workflow-captain' ),
				__( 'Run: one execution of a workflow for an event, with steps.', 'workflow-captain' ),
				__( 'Decision: a run stops when it is unclear whether a message has already gone out, or when a step is overdue for too long. A person continues or cancels it.', 'workflow-captain' ),
			),
			__( 'When something is stuck', 'workflow-captain' ) => array(
				__( 'Tools → Site Health shows whether the worker is running. Settings → Status shows the next run and stuck steps.', 'workflow-captain' ),
				__( 'You can select failed events under “Events” and re-queue them.', 'workflow-captain' ),
				__( 'The emergency pause on the overview stops all steps immediately; events are still stored.', 'workflow-captain' ),
				__( 'For support: “Events → Download diagnostics”. The file contains no personal data and no secrets.', 'workflow-captain' ),
			),
			__( 'Command line', 'workflow-captain' ) => array(
				'wp workflow-captain status | process | requeue | migrate | verify | diagnostics | pause | export | import',
			),
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'view_workflow_logs' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Help', 'workflow-captain' ) . '</h1>';
		foreach ( self::sections() as $heading => $paragraphs ) {
			echo '<h2>' . esc_html( $heading ) . '</h2>';
			foreach ( $paragraphs as $p ) {
				echo '<p>' . esc_html( $p ) . '</p>';
			}
		}
		echo '</div>';
	}
}
