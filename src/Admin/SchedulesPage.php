<?php
/**
 * Admin page "Zeitpläne" (docs/UI-ENTWUERFE.md, section 4): every workflow that starts on a schedule, with its
 * rule, the next due time and the last fired time in the time zone of the site.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Plugin;

defined( 'ABSPATH' ) || exit;

final class SchedulesPage {

	const SLUG = 'workflow-captain-schedules';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 21 );
	}

	public function menu(): void {
		add_submenu_page( OverviewPage::SLUG, __( 'Zeitpläne', 'workflow-captain' ), __( 'Zeitpläne', 'workflow-captain' ), 'view_workflow_logs', self::SLUG, array( $this, 'render' ) );
	}

	/** Readable rule of a schedule, e.g. "wöchentlich, Montag, 07:30". */
	public static function describe( array $schedule ): string {
		$freq = array(
			'daily'   => __( 'täglich', 'workflow-captain' ),
			'weekly'  => __( 'wöchentlich', 'workflow-captain' ),
			'monthly' => __( 'monatlich', 'workflow-captain' ),
		);
		$days = array( 1 => __( 'Montag', 'workflow-captain' ), __( 'Dienstag', 'workflow-captain' ), __( 'Mittwoch', 'workflow-captain' ), __( 'Donnerstag', 'workflow-captain' ), __( 'Freitag', 'workflow-captain' ), __( 'Samstag', 'workflow-captain' ), __( 'Sonntag', 'workflow-captain' ) );
		$parts = array( $freq[ $schedule['frequency'] ?? '' ] ?? '?' );
		if ( 'weekly' === ( $schedule['frequency'] ?? '' ) ) {
			$parts[] = $days[ (int) ( $schedule['weekday'] ?? 0 ) ] ?? '?';
		}
		if ( 'monthly' === ( $schedule['frequency'] ?? '' ) ) {
			/* translators: %d: day of the month */
			$parts[] = sprintf( __( 'am %d.', 'workflow-captain' ), (int) ( $schedule['day'] ?? 0 ) );
		}
		$parts[] = is_string( $schedule['time'] ?? null ) ? $schedule['time'] : '?';
		return implode( ', ', $parts );
	}

	/**
	 * A UTC timestamp as a time element: machine-readable in UTC, shown in the site time zone, e.g. "Mo., 23.11.2026 07:30".
	 *
	 * @param callable(string,int):string $format Formats in the site time zone (wp_date() in WordPress).
	 */
	public static function time_cell( ?int $ts, callable $format ): string {
		if ( null === $ts ) {
			return '<span aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'keine Angabe', 'workflow-captain' ) . '</span>';
		}
		return '<time datetime="' . esc_attr( gmdate( 'Y-m-d\TH:i:s\Z', $ts ) ) . '">' . esc_html( $format( 'D, d.m.Y H:i', $ts ) ) . '</time>';
	}

	/**
	 * Rows of the table, escaped.
	 *
	 * @param array<int,array<string,mixed>> $rows Engine::schedules().
	 * @param callable(string,int):string    $format
	 * @param bool                           $editor Whether the viewer may open the editor.
	 * @return string[]
	 */
	public static function table_rows( array $rows, callable $format, bool $editor ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$name = esc_html( $r['name'] );
			if ( $editor ) {
				$name = '<a href="' . esc_url( WorkflowsPage::url( WorkflowsPage::SLUG_WORKFLOWS, array( 'edit' => $r['workflow_id'] ) ) ) . '">' . $name . '</a>';
			}
			$state = EngineStore::WF_ACTIVE === $r['status'] ? esc_html__( 'Aktiv', 'workflow-captain' ) : esc_html__( 'Pausiert', 'workflow-captain' );
			/* translators: %d: version number */
			$state .= ' · ' . esc_html( sprintf( __( 'Version %d', 'workflow-captain' ), (int) $r['version'] ) );
			if ( $r['draft_differs'] ) {
				$state .= '<br><span class="description">' . esc_html__( 'Der Entwurf weicht ab; es gilt die veröffentlichte Version.', 'workflow-captain' ) . '</span>';
			}
			$next = null === $r['next'] && EngineStore::WF_ACTIVE !== $r['status'] ? esc_html__( 'keiner (pausiert)', 'workflow-captain' ) : self::time_cell( $r['next'], $format );
			$last = self::time_cell( $r['last_fired'], $format );
			if ( null !== $r['last_fired'] ) {
				$last .= ' (<a class="wfc-text-link" href="' . esc_url( WorkflowsPage::url( WorkflowsPage::SLUG_RUNS, array( 'workflow' => $r['workflow_id'] ) ) ) . '">' . esc_html__( 'Läufe', 'workflow-captain' ) . '</a>)';
			}
			$out[] = '<tr><th scope="row">' . $name . '</th><td>' . $state . '</td><td>' . esc_html( self::describe( $r['schedule'] ) ) . '</td><td>' . $next . '</td><td>' . $last . '</td></tr>';
		}
		return $out;
	}

	public function render(): void {
		if ( ! current_user_can( 'view_workflow_logs' ) ) {
			return;
		}
		$engine = $this->plugin->engine();
		$tz     = $engine->timezone();
		$rows   = $engine->schedules( (array) get_option( 'workflow_captain_schedule_fired', array() ) );
		echo '<div class="wrap"><h1>' . esc_html__( 'Zeitpläne', 'workflow-captain' ) . '</h1>';
		/* translators: %s: time zone of the site */
		echo '<p class="description">' . esc_html( sprintf( __( 'Workflows mit dem Auslöser „Zeitplan“, nach Name. Alle Zeiten in der Zeitzone der Website (%s). Ein verpasster Termin wird höchstens einmal nachgeholt.', 'workflow-captain' ), $tz ) ) . '</p>';
		echo '<table class="widefat striped" tabindex="0"><caption class="screen-reader-text">' . esc_html__( 'Zeitpläne der Workflows', 'workflow-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Workflow', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Zustand', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Zeitplan', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Nächster Termin', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Zuletzt ausgelöst', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		$lines = self::table_rows( $rows, static fn( string $f, int $ts ): string => (string) wp_date( $f, $ts ), current_user_can( 'edit_workflows' ) );
		if ( array() === $lines ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Kein aktiver oder pausierter Workflow startet nach Zeitplan. Ein Entwurf zählt erst nach dem Veröffentlichen.', 'workflow-captain' ) . '</td></tr>';
		}
		echo implode( '', $lines ) . '</tbody></table></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- table_rows() escapes.
	}
}
