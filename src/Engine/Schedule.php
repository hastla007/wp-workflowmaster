<?php
/**
 * Recurring schedule triggers (WF-CORE-04, konzept 15.3 "Zeitplansemantik"): a workflow whose trigger is
 * "workflow.schedule.due" starts at a local time of the site, daily, weekly or monthly.
 *
 *     { "id": "t", "type": "trigger", "event": "workflow.schedule.due",
 *       "schedule": { "frequency": "weekly", "time": "08:00", "weekday": 1 } }
 *
 * Rules: the local time follows the site time zone; a time in the spring-forward gap moves forward by the gap,
 * a time in the repeated hour uses the first occurrence (PHP DateTime does both). A missed time is caught up at most
 * once: only the latest due time fires, and never one older than the catch-up limit.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class Schedule {

	const EVENT = 'workflow.schedule.due';

	const FREQUENCIES = array( 'daily', 'weekly', 'monthly' );

	/** @return string[] Violation codes of a schedule configuration (without node prefix). */
	public static function violations( $schedule ): array {
		if ( ! is_array( $schedule ) ) {
			return array( 'schedule:missing' );
		}
		$v = array();
		if ( ! in_array( $schedule['frequency'] ?? null, self::FREQUENCIES, true ) ) {
			$v[] = 'schedule:invalid_frequency';
		}
		if ( ! is_string( $schedule['time'] ?? null ) || ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $schedule['time'] ) ) {
			$v[] = 'schedule:invalid_time';
		}
		if ( 'weekly' === ( $schedule['frequency'] ?? null ) && ! in_array( $schedule['weekday'] ?? null, range( 1, 7 ), true ) ) {
			$v[] = 'schedule:invalid_weekday';
		}
		// Day 1 to 28 only: every month has it, so nothing shifts silently.
		if ( 'monthly' === ( $schedule['frequency'] ?? null ) && ! in_array( $schedule['day'] ?? null, range( 1, 28 ), true ) ) {
			$v[] = 'schedule:invalid_day';
		}
		return $v;
	}

	/**
	 * The latest due time (UTC timestamp) at or before $now and after $after, or null.
	 *
	 * @param int $after Only times later than this count (publication time or the last fired time).
	 */
	public static function last_due( array $schedule, int $now, int $after, string $timezone ): ?int {
		if ( array() !== self::violations( $schedule ) || $now <= $after ) {
			return null;
		}
		$tz          = new \DateTimeZone( $timezone );
		list( $h, $m ) = array_map( 'intval', explode( ':', $schedule['time'] ) );
		$local       = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		// Walk back from today's candidate until one is not in the future; at most 62 days for monthly.
		for ( $i = 0; $i <= 62; $i++ ) {
			$day = $local->modify( '-' . $i . ' days' );
			if ( 'weekly' === $schedule['frequency'] && (int) $day->format( 'N' ) !== $schedule['weekday'] ) {
				continue;
			}
			if ( 'monthly' === $schedule['frequency'] && (int) $day->format( 'j' ) !== $schedule['day'] ) {
				continue;
			}
			$candidate = ( new \DateTimeImmutable( $day->format( 'Y-m-d' ) . sprintf( ' %02d:%02d:00', $h, $m ), $tz ) )->getTimestamp();
			if ( $candidate <= $now ) {
				return $candidate > $after ? $candidate : null;
			}
		}
		return null;
	}

	/**
	 * The event a due schedule produces. Subject is the workflow, so only that workflow starts (Engine::dispatch);
	 * the discriminator is the due time, so a doubled timer or two workers start one run only.
	 */
	public static function event( int $workflow_id, int $due, int $now ): array {
		return array(
			'type'          => self::EVENT,
			'subject'       => array( 'type' => 'workflow', 'id' => (string) $workflow_id, 'id_kind' => 'record' ),
			'data'          => array( 'scheduled_for' => gmdate( 'Y-m-d\TH:i:s\Z', $due ), 'delay_seconds' => max( 0, $now - $due ) ),
			'discriminator' => (string) $due,
		);
	}
}
