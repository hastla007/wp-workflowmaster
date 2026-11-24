<?php
/**
 * Times in the admin (SET-WC-31): stored in UTC, shown in the time zone of Workflow Captain (SET-WC-22: own setting,
 * else WordPress). The machine-readable UTC value stays in <time datetime> and in the title.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

use WorkflowCaptain\Settings;

defined( 'ABSPATH' ) || exit;

final class Time {

	/** Name of the time zone shown in column heads, e.g. "Europe/Berlin" or "+02:00". */
	public static function label(): string {
		return Settings::timezone();
	}

	/** The time zone of Workflow Captain as an object, for wp_date(); UTC when the name is not usable. */
	public static function zone( ?string $name = null ): \DateTimeZone {
		try {
			return new \DateTimeZone( $name ?? self::label() );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	/** A stored UTC time ("Y-m-d H:i:s") as local time of the same form; empty stays empty, garbage stays as it is. */
	public static function show( ?string $utc, ?string $zone = null ): string {
		if ( null === $utc || '' === $utc ) {
			return '';
		}
		try {
			$dt = new \DateTimeImmutable( str_replace( 'T', ' ', rtrim( $utc, 'Z' ) ), new \DateTimeZone( 'UTC' ) );
			return $dt->setTimezone( new \DateTimeZone( $zone ?? self::label() ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return $utc;
		}
	}

	/** A local time ("Y-m-d H:i:s" in $zone) as stored UTC. */
	public static function to_utc( string $local, string $zone ): string {
		try {
			return ( new \DateTimeImmutable( $local, new \DateTimeZone( $zone ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return $local;
		}
	}

	/** <time> element: local time as text, UTC in datetime and title. */
	public static function html( ?string $utc ): string {
		if ( null === $utc || '' === $utc ) {
			return '<span aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'not specified', 'workflow-captain' ) . '</span>';
		}
		$iso = str_replace( ' ', 'T', rtrim( $utc, 'Z' ) ) . 'Z';
		return '<time datetime="' . esc_attr( $iso ) . '" title="' . esc_attr( $utc . ' UTC' ) . '">' . esc_html( self::show( $utc ) ) . '</time>';
	}
}
