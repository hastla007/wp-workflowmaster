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
		add_submenu_page( OverviewPage::SLUG, __( 'Schedules', 'workflow-captain' ), __( 'Schedules', 'workflow-captain' ), 'view_workflow_logs', self::SLUG, array( $this, 'render' ) );
	}

	/** Readable rule of a schedule, e.g. "wöchentlich, Montag, 07:30". */
	public static function describe( array $schedule ): string {
		$freq = array(
			'daily'   => __( 'daily', 'workflow-captain' ),
			'weekly'  => __( 'weekly', 'workflow-captain' ),
			'monthly' => __( 'monthly', 'workflow-captain' ),
		);
		$days = array( 1 => __( 'Monday', 'workflow-captain' ), __( 'Tuesday', 'workflow-captain' ), __( 'Wednesday', 'workflow-captain' ), __( 'Thursday', 'workflow-captain' ), __( 'Friday', 'workflow-captain' ), __( 'Saturday', 'workflow-captain' ), __( 'Sunday', 'workflow-captain' ) );
		$parts = array( $freq[ $schedule['frequency'] ?? '' ] ?? '?' );
		if ( 'weekly' === ( $schedule['frequency'] ?? '' ) ) {
			$parts[] = $days[ (int) ( $schedule['weekday'] ?? 0 ) ] ?? '?';
		}
		if ( 'monthly' === ( $schedule['frequency'] ?? '' ) ) {
			/* translators: %d: day of the month */
			$parts[] = sprintf( __( 'on day %d', 'workflow-captain' ), (int) ( $schedule['day'] ?? 0 ) );
		}
		$parts[] = is_string( $schedule['time'] ?? null ) ? $schedule['time'] : '?';
		return implode( ', ', $parts );
	}

	/** Format without WordPress options (tests, empty options): weekday, day, month, year, 24-hour time. */
	const FALLBACK_FORMAT = 'D, d.m.Y H:i';

	/**
	 * Date and time format of the site: weekday plus "Datumsformat" and "Zeitformat" from Settings → General, so a
	 * site in en_US, de_CH or ja shows its own order and 12/24-hour clock (P3); the weekday helps with weekly rules.
	 */
	public static function site_format( string $date_format, string $time_format ): string {
		$date_format = trim( $date_format );
		$time_format = trim( $time_format );
		return '' === $date_format || '' === $time_format ? self::FALLBACK_FORMAT : 'D, ' . $date_format . ' ' . $time_format;
	}

	/**
	 * A UTC timestamp as a time element: machine-readable in UTC, shown in the time zone of Workflow Captain and the site's format, e.g.
	 * "Mo., 23.11.2026 07:30" or "Mon, November 23, 2026 7:30 am".
	 *
	 * @param callable(string,int):string $format  Formats in the time zone of Workflow Captain (wp_date() in WordPress).
	 * @param string                      $pattern PHP date format, see site_format().
	 */
	public static function time_cell( ?int $ts, callable $format, string $pattern = self::FALLBACK_FORMAT ): string {
		if ( null === $ts ) {
			return '<span aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'not specified', 'workflow-captain' ) . '</span>';
		}
		return '<time datetime="' . esc_attr( gmdate( 'Y-m-d\TH:i:s\Z', $ts ) ) . '">' . esc_html( $format( $pattern, $ts ) ) . '</time>';
	}

	/**
	 * Rows of the table, escaped.
	 *
	 * @param array<int,array<string,mixed>> $rows Engine::schedules().
	 * @param callable(string,int):string    $format
	 * @param bool                           $editor Whether the viewer may open the editor.
	 * @param string                         $pattern PHP date format, see site_format().
	 * @return string[]
	 */
	public static function table_rows( array $rows, callable $format, bool $editor, string $pattern = self::FALLBACK_FORMAT ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$name = esc_html( $r['name'] );
			if ( $editor ) {
				$name = '<a href="' . esc_url( WorkflowsPage::url( WorkflowsPage::SLUG_WORKFLOWS, array( 'edit' => $r['workflow_id'] ) ) ) . '">' . $name . '</a>';
			}
			$state = EngineStore::WF_ACTIVE === $r['status'] ? esc_html__( 'Active', 'workflow-captain' ) : esc_html__( 'Paused', 'workflow-captain' );
			/* translators: %d: version number */
			$state .= ' · ' . esc_html( sprintf( __( 'Version %d', 'workflow-captain' ), (int) $r['version'] ) );
			if ( $r['draft_differs'] ) {
				$state .= '<br><span class="description">' . esc_html__( 'The draft differs; the published version applies.', 'workflow-captain' ) . '</span>';
			}
			$next = null === $r['next'] && EngineStore::WF_ACTIVE !== $r['status'] ? esc_html__( 'none (paused)', 'workflow-captain' ) : self::time_cell( $r['next'], $format, $pattern );
			$last = self::time_cell( $r['last_fired'], $format, $pattern );
			if ( null !== $r['last_fired'] ) {
				$last .= ' (<a class="wfc-text-link" href="' . esc_url( WorkflowsPage::url( WorkflowsPage::SLUG_RUNS, array( 'workflow' => $r['workflow_id'] ) ) ) . '">' . esc_html__( 'Runs', 'workflow-captain' ) . '</a>)';
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
		echo '<div class="wrap"><h1>' . esc_html__( 'Schedules', 'workflow-captain' ) . '</h1>';
		/* translators: %s: time zone of Workflow Captain */
		echo '<p class="description">' . esc_html( sprintf( __( 'Workflows with the trigger “Schedule”, by name. All times in the time zone of Workflow Captain (%s). A missed date is made up for at most once.', 'workflow-captain' ), $tz ) ) . '</p>';
		echo '<table class="widefat striped" tabindex="0"><caption class="screen-reader-text">' . esc_html__( 'Workflow schedules', 'workflow-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Workflow', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'State', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Schedule', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Next date', 'workflow-captain' ) . '</th><th scope="col">' . esc_html__( 'Last triggered', 'workflow-captain' ) . '</th></tr></thead><tbody>';
		$lines = self::table_rows( $rows, static fn( string $f, int $ts ): string => (string) wp_date( $f, $ts, Time::zone( $tz ) ), current_user_can( 'edit_workflows' ), self::site_format( (string) get_option( 'date_format' ), (string) get_option( 'time_format' ) ) );
		if ( array() === $lines ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No active or paused workflow starts on a schedule. A draft only counts once it is published.', 'workflow-captain' ) . '</td></tr>';
		}
		echo implode( '', $lines ) . '</tbody></table></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- table_rows() escapes.
	}
}
