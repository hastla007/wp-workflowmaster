<?php
/**
 * Plans the worker (WF-SET-05, konzept 5 point 8): with Action Scheduler (WooCommerce ships it) as a recurring action,
 * otherwise with WP-Cron. Never both: switching clears the other plan. The daily maintenance stays on WP-Cron.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	const GROUP = 'workflow-captain';

	const ACTION_SCHEDULER = 'action_scheduler';
	const WP_CRON          = 'wp_cron';

	/** @param array<string,callable> $api Functions used; default WordPress/Action Scheduler (replaceable in tests). */
	public static function ensure( ?array $api = null ): string {
		$api     = $api ?? self::wordpress_api();
		$backend = $api['as_available']() ? self::ACTION_SCHEDULER : self::WP_CRON;
		if ( self::ACTION_SCHEDULER === $backend ) {
			if ( $api['wp_next']() ) {
				$api['wp_clear']();
			}
			if ( ! $api['as_next']() ) {
				$api['as_schedule']();
			}
		} else {
			if ( ! $api['wp_next']() ) {
				$api['wp_schedule']();
			}
		}
		return $backend;
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( Install::CRON_PROCESS );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Install::CRON_PROCESS, array(), self::GROUP );
		}
	}

	public static function backend(): string {
		return function_exists( 'as_schedule_recurring_action' ) ? self::ACTION_SCHEDULER : self::WP_CRON;
	}

	/** Next run as a timestamp, from whichever plan is active. */
	public static function next_run(): ?int {
		if ( self::ACTION_SCHEDULER === self::backend() && function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( Install::CRON_PROCESS, array(), self::GROUP );
			return is_int( $next ) ? $next : ( true === $next ? time() : null );
		}
		$next = wp_next_scheduled( Install::CRON_PROCESS );
		return false === $next ? null : (int) $next;
	}

	private static function wordpress_api(): array {
		return array(
			'as_available' => static fn(): bool => function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ),
			'as_next'      => static fn() => as_next_scheduled_action( Install::CRON_PROCESS, array(), self::GROUP ),
			'as_schedule'  => static fn() => as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, MINUTE_IN_SECONDS, Install::CRON_PROCESS, array(), self::GROUP ),
			'wp_next'      => static fn() => wp_next_scheduled( Install::CRON_PROCESS ),
			'wp_schedule'  => static fn() => wp_schedule_event( time() + MINUTE_IN_SECONDS, 'workflow_captain_minute', Install::CRON_PROCESS ),
			'wp_clear'     => static fn() => wp_clear_scheduled_hook( Install::CRON_PROCESS ),
		);
	}
}
