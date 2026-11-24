<?php
/**
 * Sending window and daily limit for messages to customers (SET-WC-07/-08, E-S8: for the whole site in the free core;
 * windows per workflow and holiday calendars belong to an add-on).
 *
 * - Quiet hours (`quiet_hours_start` to `quiet_hours_end`, full hours in the time zone of Workflow Captain, -1 = off) and sending
 *   days (`send_days`, bit 1 = Monday … bit 64 = Sunday): a message outside waits until the window opens; nothing
 *   is dropped.
 * - Daily limit (`daily_limit` messages per recipient and calendar day of the site, 0 = off), counted over all
 *   workflows. The slot is taken atomically in the database, so two runs at the same time cannot both take the last
 *   one. Over the limit a message waits for the next day (`daily_limit_action` 0) or is skipped (1).
 *
 * Only notify.user uses it in the core: messages to the operator (notify.admin) are not held back.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

use WorkflowCaptain\Clock;

defined( 'ABSPATH' ) || exit;

final class Pacing {

	const ALL_DAYS     = 127;
	const LIMIT_DEFER  = 0;
	const LIMIT_SKIP   = 1;

	/**
	 * @param \Closure():array<string,int> $settings Returns quiet_hours_start, quiet_hours_end, send_days, daily_limit, daily_limit_action.
	 */
	/** @var array<int,string> Day of the slot taken last per recipient, until it is given back. */
	private array $taken = array();

	public function __construct( private EngineStore $store, private Clock $clock, private string $timezone, private \Closure $settings ) {}

	/**
	 * Admits one message to $user_id now. Returns null when it may go out (a slot of the daily limit is then taken),
	 * or a skip reason. Throws Deferred when it has to wait.
	 *
	 * @throws Deferred Outside the window, or over the daily limit with "wait".
	 */
	public function admit( int $user_id ): ?string {
		unset( $this->taken[ $user_id ] );
		$s    = ( $this->settings )();
		$now  = $this->clock->now();
		$open = self::next_open( $now, $this->timezone, (int) ( $s['quiet_hours_start'] ?? -1 ), (int) ( $s['quiet_hours_end'] ?? -1 ), (int) ( $s['send_days'] ?? self::ALL_DAYS ) );
		if ( $open > $now ) {
			throw new Deferred( $open, 'quiet_hours' );
		}
		$limit = (int) ( $s['daily_limit'] ?? 0 );
		if ( $limit <= 0 ) {
			return null;
		}
		$day = self::day( $now, $this->timezone );
		if ( $this->store->take_send_slot( $day, $user_id, $limit ) ) {
			$this->taken[ $user_id ] = $day;
			return null;
		}
		if ( self::LIMIT_SKIP === (int) ( $s['daily_limit_action'] ?? self::LIMIT_DEFER ) ) {
			return 'daily_limit';
		}
		$tomorrow = self::next_midnight( $now, $this->timezone );
		throw new Deferred( self::next_open( $tomorrow, $this->timezone, (int) ( $s['quiet_hours_start'] ?? -1 ), (int) ( $s['quiet_hours_end'] ?? -1 ), (int) ( $s['send_days'] ?? self::ALL_DAYS ) ), 'daily_limit' );
	}

	/**
	 * Gives the slot back when the message could not be handed over (the mail failed; nothing went out). The slot goes
	 * back to the day it was taken on, even when the mail call ran past midnight (P1 round 2).
	 */
	public function release( int $user_id ): void {
		if ( isset( $this->taken[ $user_id ] ) ) {
			$this->store->release_send_slot( $this->taken[ $user_id ], $user_id );
			unset( $this->taken[ $user_id ] );
		}
	}

	/** Calendar day of the site (Y-m-d) at $timestamp. */
	public static function day( int $timestamp, string $timezone ): string {
		return self::local( $timestamp, $timezone )->format( 'Y-m-d' );
	}

	/** Whether a message may go out at $timestamp. */
	public static function is_open( int $timestamp, string $timezone, int $start, int $end, int $days ): bool {
		$local = self::local( $timestamp, $timezone );
		if ( 0 === ( $days & ( 1 << ( (int) $local->format( 'N' ) - 1 ) ) ) && 0 !== ( $days & self::ALL_DAYS ) ) {
			return false;
		}
		if ( $start < 0 || $end < 0 || $start === $end ) {
			return true;
		}
		$hour = (int) $local->format( 'G' );
		$quiet = $start < $end ? ( $hour >= $start && $hour < $end ) : ( $hour >= $start || $hour < $end );
		return ! $quiet;
	}

	/**
	 * The first moment at or after $now at which a message may go out: $now itself, or the start of a later full hour
	 * of the time zone of Workflow Captain (DST changes included). At most eight days ahead; with no open hour at all (cannot
	 * happen with at least one day and a quiet time shorter than a day) $now.
	 */
	public static function next_open( int $now, string $timezone, int $start, int $end, int $days ): int {
		if ( self::is_open( $now, $timezone, $start, $end, $days ) ) {
			return $now;
		}
		$local = self::local( $now, $timezone );
		$hour  = $local->setTime( (int) $local->format( 'G' ), 0, 0 )->getTimestamp();
		for ( $i = 1; $i <= 8 * 24 + 1; $i++ ) {
			$candidate = $hour + $i * HOUR_IN_SECONDS;
			if ( self::is_open( $candidate, $timezone, $start, $end, $days ) ) {
				return $candidate;
			}
		}
		return $now;
	}

	/** Start of the next calendar day of the site. */
	public static function next_midnight( int $now, string $timezone ): int {
		return self::local( $now, $timezone )->modify( 'tomorrow' )->getTimestamp();
	}

	private static function local( int $timestamp, string $timezone ): \DateTimeImmutable {
		try {
			$zone = new \DateTimeZone( '' === $timezone ? 'UTC' : $timezone );
		} catch ( \Exception $e ) {
			$zone = new \DateTimeZone( 'UTC' );
		}
		return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $zone );
	}
}
