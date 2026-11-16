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

	const SLUG     = 'workflow-captain';
	const PER_PAGE = 50;

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_workflow_captain_requeue', array( $this, 'handle_requeue' ) );
	}

	public function menu(): void {
		add_menu_page(
			__( 'Workflow Captain', 'workflow-captain' ),
			__( 'Workflow Captain', 'workflow-captain' ),
			'view_workflow_logs',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-randomize',
			58
		);
		add_submenu_page( self::SLUG, __( 'Ereignisse', 'workflow-captain' ), __( 'Ereignisse', 'workflow-captain' ), 'view_workflow_logs', self::SLUG, array( $this, 'render' ) );
	}

	public function handle_requeue(): void {
		if ( ! current_user_can( 'retry_workflow_runs' ) ) {
			wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'workflow-captain' ), 403 );
		}
		$id = isset( $_POST['event'] ) ? absint( wp_unslash( $_POST['event'] ) ) : 0;
		check_admin_referer( 'workflow_captain_requeue_' . $id );
		$ok = $id > 0 && $this->plugin->consumer()->requeue( $id );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'requeued' => $ok ? '1' : '0' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'view_workflow_logs' ) ) {
			return;
		}
		$store  = $this->plugin->consumer()->store();
		$state  = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		$state  = in_array( $state, EventStore::STATES, true ) ? $state : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$counts = $store->count_by_state();
		$rows   = $store->find( '' === $state ? array() : array( 'state' => $state ), self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		echo '<div class="wrap"><h1>' . esc_html__( 'Ereignisse', 'workflow-captain' ) . '</h1>';
		if ( isset( $_GET['requeued'] ) ) {
			$ok = '1' === sanitize_key( wp_unslash( $_GET['requeued'] ) );
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p>' . esc_html( $ok ? __( 'Das Ereignis ist wieder eingereiht.', 'workflow-captain' ) : __( 'Das Ereignis ließ sich nicht wieder einreihen.', 'workflow-captain' ) ) . '</p></div>';
		}
		$adapters = $this->plugin->adapters()->products();
		echo '<p>' . esc_html(
			array() === $adapters
				? __( 'Kein Adapter angemeldet. Ereignisse der Captain-Produkte kommen erst an, wenn ein Add-on einen Adapter anmeldet.', 'workflow-captain' )
				/* translators: %s: comma separated product keys */
				: sprintf( __( 'Angemeldete Adapter: %s', 'workflow-captain' ), implode( ', ', $adapters ) )
		) . '</p>';
		$rejected = (array) get_option( Plugin::REJECTED_OPTION, array() );
		if ( ! empty( $rejected['count'] ) ) {
			/* translators: 1: number of rejected events, 2: rule codes */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( '%1$d Ereignisse wurden abgelehnt, weil sie das Schema verletzten. Zuletzt: %2$s', 'workflow-captain' ), (int) $rejected['count'], implode( ', ', (array) ( $rejected['last'] ?? array() ) ) ) ) . '</p></div>';
		}

		echo '<ul class="subsubsub">';
		$links = array( '<a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) ) . '"' . ( '' === $state ? ' class="current"' : '' ) . '>' . esc_html__( 'Alle', 'workflow-captain' ) . ' (' . (int) array_sum( $counts ) . ')</a>' );
		foreach ( $counts as $key => $count ) {
			$links[] = '<a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'state' => $key ), admin_url( 'admin.php' ) ) ) . '"' . ( $state === $key ? ' class="current"' : '' ) . '>' . esc_html( self::state_label( $key ) ) . ' (' . (int) $count . ')</a>';
		}
		echo '<li>' . implode( ' | </li><li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Nr.', 'workflow-captain' ), __( 'Ereignis', 'workflow-captain' ), __( 'Objekt', 'workflow-captain' ), __( 'Zeitpunkt (UTC)', 'workflow-captain' ), __( 'Zustand', 'workflow-captain' ), __( 'Versuche', 'workflow-captain' ), __( 'Letzter Fehler', 'workflow-captain' ), '' ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( array() === $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Keine Ereignisse.', 'workflow-captain' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr><td>' . (int) $row['id'] . '</td><td><code>' . esc_html( $row['type'] ) . '</code></td><td>' . esc_html( $row['subject_type'] . ' ' . $row['subject_id'] ) . '</td><td>' . esc_html( (string) ( $row['envelope']['occurred_at'] ?? $row['created_at'] ) ) . '</td><td>' . esc_html( self::state_label( $row['state'] ) ) . '</td><td>' . (int) $row['attempts'] . '</td><td>' . esc_html( (string) $row['last_error'] ) . '</td><td>';
			if ( in_array( $row['state'], array( EventStore::DEAD_LETTER, EventStore::PAUSED ), true ) && current_user_can( 'retry_workflow_runs' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="workflow_captain_requeue"><input type="hidden" name="event" value="' . (int) $row['id'] . '">';
				wp_nonce_field( 'workflow_captain_requeue_' . (int) $row['id'] );
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Erneut einreihen', 'workflow-captain' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $rows ) === self::PER_PAGE ) {
			echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'state' => $state, 'paged' => $paged + 1 ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Ältere', 'workflow-captain' ) . '</a></p>';
		}
		echo '</div>';
	}

	public static function state_label( string $state ): string {
		$labels = array(
			EventStore::RECEIVED     => __( 'Empfangen', 'workflow-captain' ),
			EventStore::PROCESSING   => __( 'In Arbeit', 'workflow-captain' ),
			EventStore::DONE         => __( 'Erledigt', 'workflow-captain' ),
			EventStore::RETRY_WAIT   => __( 'Wartet auf Wiederholung', 'workflow-captain' ),
			EventStore::DEAD_LETTER  => __( 'Fehlgeschlagen (dead_letter)', 'workflow-captain' ),
			EventStore::DISCARDED    => __( 'Verworfen', 'workflow-captain' ),
			EventStore::LOOP_BLOCKED => __( 'Schleife gestoppt', 'workflow-captain' ),
			EventStore::PAUSED       => __( 'Pausiert', 'workflow-captain' ),
		);
		return $labels[ $state ] ?? $state;
	}
}
