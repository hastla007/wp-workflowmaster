<?php
/**
 * Register of the settings (SET-WC-01): the one source for option, type, default, range, tab, label, help text,
 * capability and export rule of every value. Settings, Notifications, the settings page, export/import and WP-CLI
 * read from here. The two options and their settings groups stay as they are, so nothing stored is lost.
 *
 * Every value can be changed by the filter `workflow_captain_setting_<key>`; the filter wins, the result is still
 * checked against the type and range of the field.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class SettingsRegistry {

	/** Format number of exported settings files (SET-WC-11). */
	const FORMAT = 1;

	const TYPE_INT    = 'int';
	const TYPE_BOOL   = 'bool';
	const TYPE_EMAILS = 'emails';
	const TYPE_KINDS  = 'kinds';

	/**
	 * key => array( option, type, default, min, max, tab, export ). `export` false: the value is tied to this
	 * installation or holds personal data and is left out of an export.
	 */
	const SPEC = array(
		'batch'              => array( Settings::OPTION, self::TYPE_INT, 25, 1, 200, 'execution', true ),
		'max_site'           => array( Settings::OPTION, self::TYPE_INT, 5, 1, 20, 'execution', true ),
		'max_workflow'       => array( Settings::OPTION, self::TYPE_INT, 2, 1, 10, 'execution', true ),
		'catch_up_hours'     => array( Settings::OPTION, self::TYPE_INT, 24, 1, 168, 'execution', true ),
		'max_seconds'        => array( Settings::OPTION, self::TYPE_INT, 20, 5, 120, 'execution', true ),
		'retention_finished' => array( Settings::OPTION, self::TYPE_INT, 30, 1, 30, 'privacy', true ),
		'retention_runs'     => array( Settings::OPTION, self::TYPE_INT, 30, 1, 30, 'privacy', true ),
		'retention_stuck'    => array( Settings::OPTION, self::TYPE_INT, 90, 1, 90, 'privacy', true ),
		'notify_daily'       => array( Settings::OPTION, self::TYPE_BOOL, 1, 0, 1, 'notify', true ),
		'recipients'         => array( Notifications::OPTION, self::TYPE_EMAILS, '', 0, 10, 'notify', false ),
		'kinds'              => array( Notifications::OPTION, self::TYPE_KINDS, Notifications::KINDS, 0, 0, 'notify', true ),
	);

	/** Settings group of each option (register_setting; options.php resets the fields of a group it does not get). */
	const GROUPS = array(
		Settings::OPTION      => 'workflow_captain',
		Notifications::OPTION => 'workflow_captain_notify',
	);

	/** Capability needed to change a setting. */
	const CAPABILITY = 'manage_workflow_captain';

	/** @return string[] Keys of one option, in register order. */
	public static function keys( string $option ): array {
		return array_keys( array_filter( self::SPEC, static fn( array $f ): bool => $option === $f[0] ) );
	}

	/** @return string[] Keys shown on one tab of the settings page, in register order. */
	public static function tab( string $tab, ?string $option = null ): array {
		return array_keys(
			array_filter(
				self::SPEC,
				static fn( array $f ): bool => $tab === $f[5] && ( null === $option || $option === $f[0] )
			)
		);
	}

	/** Field definition with names: option, type, default, min, max, tab, export. */
	public static function field( string $key ): ?array {
		if ( ! isset( self::SPEC[ $key ] ) ) {
			return null;
		}
		list( $option, $type, $default, $min, $max, $tab, $export ) = self::SPEC[ $key ];
		return array(
			'key'     => $key,
			'option'  => $option,
			'type'    => $type,
			'default' => $default,
			'min'     => $min,
			'max'     => $max,
			'tab'     => $tab,
			'export'  => $export,
			'filter'  => 'workflow_captain_setting_' . $key,
		);
	}

	/** Checks one value against its field; returns the stored form (int, string or list). */
	public static function clean( string $key, $value ) {
		$f = self::field( $key );
		if ( null === $f ) {
			return null;
		}
		switch ( $f['type'] ) {
			case self::TYPE_INT:
			case self::TYPE_BOOL:
				return is_numeric( $value ) ? max( $f['min'], min( $f['max'], (int) $value ) ) : $f['default'];
			case self::TYPE_EMAILS:
				$list = array();
				foreach ( preg_split( '/[\s,;]+/', is_scalar( $value ) ? (string) $value : '' ) ?: array() as $address ) {
					$address = strtolower( trim( $address ) );
					if ( '' !== $address && false !== filter_var( $address, FILTER_VALIDATE_EMAIL ) && ! in_array( $address, $list, true ) ) {
						$list[] = $address;
					}
				}
				return implode( ', ', array_slice( $list, 0, $f['max'] ) );
			case self::TYPE_KINDS:
				return is_array( $value ) ? array_values( array_intersect( $f['default'], array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) ) : $f['default'];
		}
		return $f['default'];
	}

	/**
	 * Applies the filter of a field to a stored value; the filter wins within type and range. A switch takes true and
	 * false (`__return_false` turns it off), addresses also a list. A result of the wrong type keeps the stored value:
	 * falling back to the default would switch a mail back on or widen the chosen kinds (P1 finding).
	 */
	public static function filtered( string $key, $value ) {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $value;
		}
		$filtered = apply_filters( 'workflow_captain_setting_' . $key, $value );
		if ( $filtered === $value ) {
			return $value;
		}
		$f = self::field( $key );
		switch ( $f['type'] ?? '' ) {
			case self::TYPE_BOOL:
				$filtered = is_bool( $filtered ) ? (int) $filtered : $filtered;
				return is_numeric( $filtered ) ? self::clean( $key, $filtered ) : $value;
			case self::TYPE_INT:
				return is_numeric( $filtered ) ? self::clean( $key, $filtered ) : $value;
			case self::TYPE_EMAILS:
				if ( is_array( $filtered ) ) {
					$filtered = implode( ', ', array_filter( $filtered, 'is_scalar' ) );
				}
				return is_scalar( $filtered ) ? self::clean( $key, $filtered ) : $value;
			case self::TYPE_KINDS:
				return is_array( $filtered ) ? self::clean( $key, $filtered ) : $value;
		}
		return $value;
	}

	/**
	 * Label and help text of every field (translated at call time).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function texts(): array {
		return array(
			'batch'              => array( __( 'Ereignisse und Schritte je Worker-Durchlauf', 'workflow-captain' ), __( 'Mehr je Durchlauf arbeitet einen Rückstau schneller ab, macht aber jeden Durchlauf länger. Bei wenig Speicher oder kurzen Laufzeiten des Hosters kleiner wählen.', 'workflow-captain' ) ),
			'max_site'           => array( __( 'Höchstens gleichzeitig laufende Schritte auf dieser Website', 'workflow-captain' ), __( 'Schützt den Server, wenn viele Ereignisse auf einmal kommen. Weitere Schritte warten, bis ein Platz frei wird.', 'workflow-captain' ) ),
			'max_workflow'       => array( __( 'Höchstens gleichzeitig laufende Schritte je Workflow', 'workflow-captain' ), __( 'Verhindert, dass ein einzelner Workflow alle Plätze belegt; die anderen kommen weiter dran.', 'workflow-captain' ) ),
			'catch_up_hours'     => array( __( 'Nachholgrenze in Stunden', 'workflow-captain' ), __( 'Ein Schritt, der länger überfällig ist (zum Beispiel weil WP-Cron nicht lief), wird nicht von selbst ausgeführt, sondern zur Entscheidung angezeigt.', 'workflow-captain' ) ),
			'max_seconds'        => array( __( 'Laufzeit je Worker-Durchlauf in Sekunden', 'workflow-captain' ), __( 'Danach beginnt der Worker keinen neuen Schritt mehr; ein begonnener Schritt läuft zu Ende. Hält Seitenaufrufe kurz, wenn WP-Cron über Besucher läuft.', 'workflow-captain' ) ),
			'retention_finished' => array( __( 'Erledigte Ereignisse löschen nach (Tagen)', 'workflow-captain' ), __( 'Höchstens 30 Tage; nur kürzer möglich.', 'workflow-captain' ) ),
			'retention_runs'     => array( __( 'Erledigte Läufe mit Schrittprotokoll löschen nach (Tagen)', 'workflow-captain' ), __( 'Höchstens 30 Tage; offene Läufe bleiben. Protokolle enthalten keine Inhalte von Nachrichten oder Ereignisdaten außer der Kopie im Lauf.', 'workflow-captain' ) ),
			'retention_stuck'    => array( __( 'Fehlgeschlagene und pausierte Ereignisse löschen nach (Tagen)', 'workflow-captain' ), __( 'Höchstens 90 Tage; nur kürzer möglich. Die Fristen sind ein Vorschlag bis zur Rechtsprüfung.', 'workflow-captain' ) ),
			'notify_daily'       => array( __( 'Tägliche Hinweis-Mail an die Betreiber-Adresse', 'workflow-captain' ), __( 'Kommt nur, wenn Entscheidungen warten, Ereignisse fehlgeschlagen sind, Arbeit hängt oder die Notfallpause aktiv ist. Ohne Inhalte von Ereignissen.', 'workflow-captain' ) ),
			'recipients'         => array( __( 'Empfänger', 'workflow-captain' ), __( 'Bis zu zehn Adressen, durch Komma getrennt. Leer: die Adresse der Website.', 'workflow-captain' ) ),
			'kinds'              => array( __( 'Arten', 'workflow-captain' ), '' ),
		);
	}
}
