<?php
/**
 * Admin page "Workflow Captain → Ereignisse": stored events per state, dead_letter visible with type, object, time and
 * last error (7.4, C-5), requeue of dead_letter and paused events by a human.
 *
 * Shows no data fields and no actor: the list is for operations, not for looking at people (R-12.2).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Plugin;
use WorkflowCaptain\Store\EventStore;

defined( 'ABSPATH' ) || exit;

final class EventsPage {

	const SLUG     = 'workflow-captain-events';
	const PER_PAGE = 50;

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_workflow_captain_requeue', array( $this, 'handle_requeue' ) );
		add_action( 'admin_post_workflow_captain_requeue_bulk', array( $this, 'handle_requeue_bulk' ) );
	}

	public function menu(): void {
		add_submenu_page( OverviewPage::SLUG, __( 'Events', 'workflow-captain' ), __( 'Events', 'workflow-captain' ), 'view_workflow_logs', self::SLUG, array( $this, 'render' ) );
	}

	public function handle_requeue(): void {
		if ( ! current_user_can( 'retry_workflow_runs' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'workflow-captain' ), 403 );
		}
		$id = isset( $_POST['event'] ) ? absint( wp_unslash( $_POST['event'] ) ) : 0;
		check_admin_referer( 'workflow_captain_requeue_' . $id );
		$ok = $id > 0 && $this->plugin->consumer()->requeue( $id );
		if ( $ok ) {
			\WorkflowCaptain\Audit::record( 'requeue', array( 'event' => $id ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'requeued' => $ok ? '1' : '0' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_requeue_bulk(): void {
		if ( ! current_user_can( 'retry_workflow_runs' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'workflow-captain' ), 403 );
		}
		check_admin_referer( 'workflow_captain_requeue_bulk' );
		$ids   = isset( $_POST['events'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['events'] ) ) : array();
		$count = $this->plugin->consumer()->requeue_many( $ids );
		if ( $count > 0 ) {
			\WorkflowCaptain\Audit::record( 'requeue', array( 'events' => $count ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'requeued_many' => $count ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'view_workflow_logs' ) ) {
			return;
		}
		$store  = $this->plugin->consumer()->store();
		$state  = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display filter or notice after a redirect, read only.
		$state  = in_array( $state, EventStore::STATES, true ) ? $state : '';
		$corr    = self::correlation_param();
		$product = isset( $_GET['product'] ) ? sanitize_key( wp_unslash( $_GET['product'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display filter only.
		$product = preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $product ) ? $product : '';
		$filter  = array_filter( array( 'state' => $state, 'correlation_id' => $corr, 'product' => $product ) );
		$counts = $store->count_by_state();
		if ( '' !== $corr || '' !== $product ) {
			// Counts of the filtered list, so the numbers match what a click shows.
			foreach ( array_keys( $counts ) as $key ) {
				$counts[ $key ] = $store->count( array_filter( array( 'state' => $key, 'correlation_id' => $corr, 'product' => $product ) ) );
			}
		}
		$pages  = Pagination::state( $store->count( $filter ), self::PER_PAGE, Pagination::requested() );
		$rows   = $store->find( $filter, self::PER_PAGE, $pages['offset'] );

		echo '<div class="wrap"><h1>' . esc_html__( 'Events', 'workflow-captain' ) . '</h1>';
		if ( isset( $_GET['requeued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display filter or notice after a redirect, read only.
			$ok = '1' === sanitize_key( wp_unslash( $_GET['requeued'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display filter or notice after a redirect, read only.
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p>' . esc_html( $ok ? __( 'The event is queued again.', 'workflow-captain' ) : __( 'The event could not be queued again.', 'workflow-captain' ) ) . '</p></div>';
		}
		if ( isset( $_GET['requeued_many'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display filter or notice after a redirect, read only.
			/* translators: %d: number of events */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%d events queued again.', 'workflow-captain' ), absint( wp_unslash( $_GET['requeued_many'] ) ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display filter or notice after a redirect, read only.
		}
		$adapters = $this->plugin->adapters()->products();
		echo '<p>' . esc_html(
			array() === $adapters
				? __( 'No adapter registered. Events from the Captain products only arrive once an add-on registers an adapter.', 'workflow-captain' )
				/* translators: %s: comma separated product keys */
				: sprintf( __( 'Registered adapters: %s', 'workflow-captain' ), implode( ', ', $adapters ) )
		) . '</p>';
		$rejected = (array) get_option( Plugin::REJECTED_OPTION, array() );
		if ( ! empty( $rejected['count'] ) ) {
			/* translators: 1: number of rejected events, 2: rule codes */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( '%1$d events were rejected because they violated the schema. Most recent: %2$s', 'workflow-captain' ), (int) $rejected['count'], implode( ', ', (array) ( $rejected['last'] ?? array() ) ) ) ) . '</p></div>';
		}

		if ( current_user_can( 'manage_workflow_captain' ) ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=workflow_captain_diagnostics' ), 'workflow_captain_diagnostics' ) ) . '">' . esc_html__( 'Download diagnostics (without personal data)', 'workflow-captain' ) . '</a></p>';
		}
		if ( '' !== $product ) {
			/* translators: %s: product key of a provider */
			echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'Only events from %s.', 'workflow-captain' ), $product ) ) . ' <a href="' . esc_url( add_query_arg( array_filter( array( 'page' => self::SLUG, 'state' => $state, 'correlation' => $corr ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'All providers', 'workflow-captain' ) . '</a></p></div>';
		}
		if ( '' !== $corr ) {
			echo '<div class="notice notice-info inline"><p>';
			/* translators: %s: correlation ID */
			echo esc_html( sprintf( __( 'Only events with the correlation %s.', 'workflow-captain' ), $corr ) ) . ' ';
			echo '<a href="' . esc_url( WorkflowsPage::url( WorkflowsPage::SLUG_RUNS, array( 'correlation' => $corr ) ) ) . '">' . esc_html__( 'Runs with this correlation', 'workflow-captain' ) . '</a> | ';
			echo '<a href="' . esc_url( add_query_arg( array_filter( array( 'page' => self::SLUG, 'state' => $state, 'product' => $product ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Remove filter', 'workflow-captain' ) . '</a></p></div>';
		}
		echo '<ul class="subsubsub">';
		$links = array( '<a href="' . esc_url( add_query_arg( array_filter( array( 'page' => self::SLUG, 'correlation' => $corr, 'product' => $product ) ), admin_url( 'admin.php' ) ) ) . '"' . ( '' === $state ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'All', 'workflow-captain' ) . ' (' . (int) array_sum( $counts ) . ')</a>' );
		foreach ( $counts as $key => $count ) {
			$links[] = '<a href="' . esc_url( add_query_arg( array_filter( array( 'page' => self::SLUG, 'state' => $key, 'correlation' => $corr, 'product' => $product ) ), admin_url( 'admin.php' ) ) ) . '"' . ( $state === $key ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( self::state_label( $key ) ) . ' (' . (int) $count . ')</a>';
		}
		echo '<li>' . implode( ' | </li><li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.

		$bulk = in_array( $state, array( EventStore::DEAD_LETTER, EventStore::PAUSED ), true ) && current_user_can( 'retry_workflow_runs' ) && array() !== $rows;
		if ( $bulk ) {
			echo '<form id="wfc-bulk" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="workflow_captain_requeue_bulk">';
			wp_nonce_field( 'workflow_captain_requeue_bulk' );
			echo '<p><button class="button" type="submit">' . esc_html__( 'Queue selected again', 'workflow-captain' ) . '</button> <span class="description">' . esc_html__( 'First check whether the cause (adapter, license, provider) has been fixed.', 'workflow-captain' ) . '</span></p></form>';
		}
		echo '<table class="widefat striped" tabindex="0"><caption class="screen-reader-text">' . esc_html__( 'Stored events, newest first', 'workflow-captain' ) . '</caption><thead><tr>';
		foreach ( array( __( 'No.', 'workflow-captain' ), __( 'Event', 'workflow-captain' ), __( 'Object', 'workflow-captain' ), sprintf( /* translators: %s: time zone */ __( 'Timestamp (%s)', 'workflow-captain' ), Time::label() ), __( 'State', 'workflow-captain' ), __( 'Attempts', 'workflow-captain' ), __( 'Last error', 'workflow-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'workflow-captain' ) . '</span></th>';
		echo '</tr></thead><tbody>';
		if ( array() === $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No events.', 'workflow-captain' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr><td>';
			if ( $bulk ) {
				echo '<input type="checkbox" form="wfc-bulk" name="events[]" value="' . (int) $row['id'] . '" aria-label="' . esc_attr( sprintf( /* translators: %d: event number */ __( 'Select event %d', 'workflow-captain' ), (int) $row['id'] ) ) . '"> ';
			}
			echo (int) $row['id'] . '</td><td><code>' . esc_html( $row['type'] ) . '</code></td><td>' . esc_html( $row['subject_type'] . ' ' . $row['subject_id'] ) . '</td><td>' . Time::html( (string) ( $row['envelope']['occurred_at'] ?? $row['created_at'] ) ) . '</td><td>' . esc_html( self::state_label( $row['state'] ) ) . '</td><td>' . (int) $row['attempts'] . '</td><td>' . esc_html( (string) $row['last_error'] ) . '</td><td>';
			if ( in_array( $row['state'], array( EventStore::DEAD_LETTER, EventStore::PAUSED ), true ) && current_user_can( 'retry_workflow_runs' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="workflow_captain_requeue"><input type="hidden" name="event" value="' . (int) $row['id'] . '">';
				wp_nonce_field( 'workflow_captain_requeue_' . (int) $row['id'] );
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Re-queue', 'workflow-captain' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo Pagination::render( $pages, array( 'page' => self::SLUG, 'state' => $state, 'correlation' => $corr, 'product' => $product ), __( 'Event list pages', 'workflow-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Pagination::render().
		echo '</div>';
	}

	/** The correlation filter from the query string: a UUID or ''. */
	public static function correlation_param(): string {
		$value = isset( $_GET['correlation'] ) ? sanitize_key( wp_unslash( $_GET['correlation'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display filter only.
		return 1 === preg_match( \WorkflowCaptain\Envelope\Validator::UUID4, $value ) ? $value : '';
	}

	public static function state_label( string $state ): string {
		$labels = array(
			EventStore::RECEIVED     => __( 'Received', 'workflow-captain' ),
			EventStore::PROCESSING   => __( 'Processing', 'workflow-captain' ),
			EventStore::DONE         => __( 'Done', 'workflow-captain' ),
			EventStore::RETRY_WAIT   => __( 'Waiting for retry', 'workflow-captain' ),
			EventStore::DEAD_LETTER  => __( 'Failed (dead_letter)', 'workflow-captain' ),
			EventStore::DISCARDED    => __( 'Discarded', 'workflow-captain' ),
			EventStore::LOOP_BLOCKED => __( 'Loop stopped', 'workflow-captain' ),
			EventStore::PAUSED       => __( 'Paused', 'workflow-captain' ),
		);
		return $labels[ $state ] ?? $state;
	}
}
