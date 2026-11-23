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

	/** Longest retention the free core allows (E-S7 = F-04): 180 days; the defaults stay 30 and 90. */
	const RETENTION_MAX = 180;

	const TYPE_INT    = 'int';
	const TYPE_BOOL   = 'bool';
	const TYPE_EMAILS = 'emails';
	const TYPE_KINDS  = 'kinds';
	const TYPE_ROLES  = 'roles';

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
		'test_mode'          => array( Settings::OPTION, self::TYPE_INT, 0, 0, 2, 'execution', false ),
		'retry_attempts'     => array( Settings::OPTION, self::TYPE_INT, 8, 1, 8, 'execution', true ),
		'max_chain_depth'    => array( Settings::OPTION, self::TYPE_INT, 3, 1, 8, 'execution', true ),
		'retention_finished' => array( Settings::OPTION, self::TYPE_INT, 30, 1, self::RETENTION_MAX, 'privacy', true ),
		'retention_runs'     => array( Settings::OPTION, self::TYPE_INT, 30, 1, self::RETENTION_MAX, 'privacy', true ),
		'retention_stuck'    => array( Settings::OPTION, self::TYPE_INT, 90, 1, self::RETENTION_MAX, 'privacy', true ),
		'require_own_key'    => array( Settings::OPTION, self::TYPE_BOOL, 0, 0, 1, 'privacy', false ),
		'audit_keep'         => array( Settings::OPTION, self::TYPE_INT, 50, 10, 500, 'privacy', true ),
		'delete_data_on_uninstall' => array( Settings::OPTION, self::TYPE_BOOL, 0, 0, 1, 'privacy', false ),
		'notify_daily'       => array( Settings::OPTION, self::TYPE_BOOL, 1, 0, 1, 'notify', true ),
		'recipients'         => array( Notifications::OPTION, self::TYPE_EMAILS, '', 0, 10, 'notify', false ),
		'kinds'              => array( Notifications::OPTION, self::TYPE_KINDS, Notifications::KINDS, 0, 0, 'notify', true ),
		'notify_admin_roles' => array( Notifications::OPTION, self::TYPE_ROLES, array(), 0, 20, 'notify', true ),
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
			case self::TYPE_ROLES:
				return is_array( $value ) ? array_slice( array_values( array_filter( array_unique( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ), array( self::class, 'is_role' ) ) ), 0, $f['max'] ) : $f['default'];
		}
		return $f['default'];
	}

	/**
	 * Labels of the choices of a number field shown as a list (value => label), empty for plain numbers.
	 *
	 * @return array<int,string>
	 */
	public static function choices( string $key ): array {
		if ( 'test_mode' === $key ) {
			return array(
				TestMode::OFF      => __( 'Aus', 'workflow-captain' ),
				TestMode::REDIRECT => __( 'Nachrichten umleiten', 'workflow-captain' ),
				TestMode::DRY      => __( 'Nichts ausführen, nur protokollieren', 'workflow-captain' ),
			);
		}
		return array();
	}

	/** A role slug of this site (any well-formed slug where WordPress is not loaded, as in the tests). */
	public static function is_role( string $role ): bool {
		if ( ! preg_match( '/^[a-z0-9_-]{1,60}$/', $role ) ) {
			return false;
		}
		return function_exists( 'wp_roles' ) ? null !== wp_roles()->get_role( $role ) : true;
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
			case self::TYPE_ROLES:
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
			'test_mode'          => array( __( 'Testmodus', 'workflow-captain' ), __( 'Für Testumgebungen und zum Ausprobieren. „Nachrichten umleiten“: Jede Nachricht aus einem Workflow geht an den ersten Empfänger der Hinweis-Mail (sonst an die Adresse der Website), mit „[Test]“ im Betreff. „Nichts ausführen“: Workflows laufen, aber kein Schritt wirkt; jeder Schritt wird nur als übersprungen protokolliert. Die Konstante WORKFLOW_CAPTAIN_TEST_MODE in wp-config.php hat Vorrang.', 'workflow-captain' ) ),
			'retry_attempts'     => array( __( 'Wiederholungen nach einem vorübergehenden Fehler', 'workflow-captain' ), __( 'So oft wird ein Ereignis oder Schritt nach einem vorübergehenden Fehler erneut versucht, mit wachsenden Abständen (1 Min. bis 24 Std.). Danach wird ein Ereignis als fehlgeschlagen angezeigt, ein Schritt braucht eine Entscheidung. Nachrichten mit unklarem Ausgang werden nie von selbst wiederholt.', 'workflow-captain' ) ),
			'max_chain_depth'    => array( __( 'Höchste Tiefe einer Kette von Workflows', 'workflow-captain' ), __( 'Löst ein Workflow ein Ereignis aus, das wieder einen Workflow startet, entsteht eine Kette. Tiefer verschachtelte Ereignisse werden gespeichert, starten aber nichts (Schutz vor Schleifen).', 'workflow-captain' ) ),
			'retention_finished' => array( __( 'Erledigte Ereignisse löschen nach (Tagen)', 'workflow-captain' ), __( 'Vorgabe 30 Tage, höchstens 180. Welche Frist für Ihren Betrieb gilt, klären Sie mit Ihrer Rechtsberatung.', 'workflow-captain' ) ),
			'retention_runs'     => array( __( 'Erledigte Läufe mit Schrittprotokoll löschen nach (Tagen)', 'workflow-captain' ), __( 'Vorgabe 30 Tage, höchstens 180; gilt für erledigte, fehlgeschlagene und abgebrochene Läufe, offene bleiben. Protokolle enthalten keine Inhalte von Nachrichten oder Ereignisdaten außer der Kopie im Lauf.', 'workflow-captain' ) ),
			'retention_stuck'    => array( __( 'Fehlgeschlagene und pausierte Ereignisse löschen nach (Tagen)', 'workflow-captain' ), __( 'Vorgabe 90 Tage, höchstens 180. Die Vorgaben sind ein Vorschlag bis zur Rechtsprüfung.', 'workflow-captain' ) ),
			'require_own_key'    => array( __( 'Geheimnisse nur mit eigenem Schlüssel speichern', 'workflow-captain' ), __( 'An: Zugangsdaten (zum Beispiel von Verbindungen eines Add-ons) werden nur gespeichert, wenn WORKFLOW_CAPTAIN_SECRET_KEY in wp-config.php gesetzt ist. Ohne eigenen Schlüssel hängt die Verschlüsselung an den Salts von WordPress; ändern sie sich, sind gespeicherte Geheimnisse verloren. Gespeicherte Werte bleiben lesbar.', 'workflow-captain' ) ),
			'audit_keep'         => array( __( 'Einträge im Änderungsprotokoll', 'workflow-captain' ), __( 'So viele der letzten Änderungen (Einstellungen, Rechte, Notfallpause, Freigaben) bleiben im Tab Status sichtbar. Ältere fallen weg. Das Protokoll enthält Benutzer-IDs, aber keine Inhalte von Ereignissen.', 'workflow-captain' ) ),
			'delete_data_on_uninstall' => array( __( 'Beim Löschen des Plugins alle Daten entfernen', 'workflow-captain' ), __( 'Aus (Standard): Beim Löschen des Plugins bleiben Ereignisse, Läufe, Workflows und Einstellungen erhalten, damit eine Neuinstallation weitermachen kann. An: Beim Löschen werden alle Tabellen, Einstellungen und Rechte von Workflow Captain auf dieser Website endgültig entfernt. Zum Einschalten LÖSCHEN eintragen.', 'workflow-captain' ) ),
			'notify_daily'       => array( __( 'Tägliche Hinweis-Mail an die Betreiber-Adresse', 'workflow-captain' ), __( 'Kommt nur, wenn Entscheidungen warten, Ereignisse fehlgeschlagen sind, Arbeit hängt oder die Notfallpause aktiv ist. Ohne Inhalte von Ereignissen.', 'workflow-captain' ) ),
			'recipients'         => array( __( 'Empfänger', 'workflow-captain' ), __( 'Bis zu zehn Adressen, durch Komma getrennt. Leer: die Adresse der Website.', 'workflow-captain' ) ),
			'kinds'              => array( __( 'Arten', 'workflow-captain' ), '' ),
			'notify_admin_roles' => array( __( 'Rollen als Empfänger von „Nachricht an Betreiber“', 'workflow-captain' ), __( 'Im Workflow-Editor lassen sich dann zusätzlich alle Benutzer dieser Rollen als Empfänger wählen (höchstens 50 Adressen je Nachricht). Ohne Auswahl: die Adresse der Website oder die Inhaber der festen Rechte.', 'workflow-captain' ) ),
		);
	}
}
