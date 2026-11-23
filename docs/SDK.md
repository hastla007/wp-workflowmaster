# Workflow Captain – Entwickler-SDK

Stand: Plugin 0.2.0, Spezifikation [`SPEC-suite-events.md`](SPEC-suite-events.md) 1.0.0-draft.7. Die Schnittstellen sind vor Version 1.0.0 der Spezifikation **nicht stabil** (R-13.2); ihre Version steht in `WORKFLOW_CAPTAIN_CONTRACT_VERSION`.

## 1. Bausteine

| Baustein | Klasse | Aufgabe |
|---|---|---|
| Adapter | `WorkflowCaptain\Adapter\Adapter` | liefert Ereignisse eines Providers an den Consumer; liegt für Captain-Produkte in einem Add-on, nie im Kern (A-1). Eingebaut sind nur die Adapter für WordPress und WooCommerce (`src/Providers`, F-20) |
| Consumer | `WorkflowCaptain\Consumer\Consumer` | baut den Umschlag, speichert einmal je Idempotenzschlüssel, wiederholt, schützt vor Schleifen |
| Engine | `WorkflowCaptain\Engine\Engine` | startet Läufe veröffentlichter Workflows und führt ihre Schritte aus |
| Aktion | `WorkflowCaptain\Engine\Action` | ein Befehl, den ein Schritt ausführt; nur Befehle aus Abschnitt 8.1 der Spezifikation |

## 0. Anforderungen (MINVER-1)

Seit 17.11.2026 gelten für Workflow Captain, alle Pro-Add-ons und eigene Connectoren dieselben Mindestversionen:

| Komponente | Mindestens | Hinweis |
|---|---|---|
| PHP | 8.5 | PHP 8.6 läuft in der CI experimentell mit; Code soll unter 8.6 ohne Deprecations laufen |
| WordPress | 7.0 | getestet bis 7.1 |
| WooCommerce | 11.2 | nur wo genutzt (eingebauter WooCommerce-Adapter, Connectoren mit Bestellbezug) |
| Datenbank | MySQL 9.7 oder MariaDB 12.3 | ältere Server nur Warnung im Website-Zustand, keine Sperre |

Ein Connector setzt im Plugin-Kopf `Requires at least: 7.0`, `Requires PHP: 8.5` und `Requires Plugins: workflow-captain` (Beispiel: `examples/starter-connector/wfc-starter-connector.php`). Unter PHP 8.5 oder WordPress 7.0 lädt Workflow Captain nicht (Hinweis im Admin, keine Aktivierung); die Hooks `workflow_captain_*` feuern dann nicht, ein Connector läuft also ohne den Kern weiter. Die Werte stehen im Code als Konstanten von `WorkflowCaptain\Requirements` (`PHP`, `WORDPRESS`, `WOOCOMMERCE`, `MYSQL`, `MARIADB`).

## 1a. Welche Adapter es gibt

| Quelle | Produkt (`source.product`) | Wo der Adapter liegt | Voraussetzung |
|---|---|---|---|
| WordPress | `wordpress` | Kern, `src/Providers/WordPressAdapter.php` | WordPress ≥ 7.0 |
| WooCommerce | `woocommerce` | Kern, `src/Providers/WooCommerceAdapter.php` | WooCommerce ≥ 11.2 aktiv (HPOS und klassisch); darunter meldet sich der Adapter nicht an (Grund `provider_too_old` auf der Seite „Provider“) |
| Zeitplan | `workflow-captain` | Kern, `src/Providers/ScheduleAdapter.php`, `src/Engine/Schedule.php` | – |
| Eingehende Auslöser | `workflow-captain` (`workflow.inbound.*`) | Kern, `src/Engine/InboundTriggers.php`; die Annahme (REST-Route, Signatur) liegt im Add-on | ein Add-on meldet Auslöser an |
| Pass, Voucher, Affiliate Captain, Lizenzserver | `pass-captain`, `voucher-captain`, `affiliate-captain`, `wp-license` | Workflow Captain Pro (`src/Suite/`) | Vertragsversionen in [`KOMPATIBILITAET.md`](KOMPATIBILITAET.md); je Produkt dessen Pro-Add-on (Spezifikation 10.1) |
| Eigene Connectoren | eigenes Präfix `x…` | eigenes Plugin nach [`CONNECTOR-CONTRACT.md`](CONNECTOR-CONTRACT.md), Beispiel `examples/starter-connector/` | Laufzeit durch Workflow Captain Pro (Filter `workflow_captain_connector_runtime_allowed_v1`, F-18a); Freigabe unter „Eigene Connectoren“; die eigene Lizenz prüft der Connector selbst |

Der Kern kennt keine Lizenz. Fehlt ein Adapter, bleiben seine offenen Ereignisse als `paused` (`adapter_unregistered`) liegen und werden nicht von selbst nachgeholt (R-10.8).

## 2. Einen Adapter schreiben

```php
use WorkflowCaptain\Adapter\Adapter;
use WorkflowCaptain\Envelope\IdempotencyKey;

final class Pass_Adapter implements Adapter {
	public function product(): string { return 'pass-captain'; }
	public function core_version(): string { return WPCAP_VERSION; }
	public function contract_version(): ?string { return defined( 'WPCAP_CONTRACT_VERSION' ) ? WPCAP_CONTRACT_VERSION : null; }
	public function types(): array { return array( 'pass.ticket.checked_in' ); }
	public function needs_reread( string $type ): bool { return false; }
	public function reread( array $envelope ): bool { return true; }

	public function hook(): void {
		// Priorität größer als 10 (R-7.5).
		add_action( 'wpcap_ticket_checked_in', array( $this, 'on_checked_in' ), 20, 2 );
	}

	public function on_checked_in( $ticket_id, $context ): void {
		try {
			$ticket = \PassCaptain\Orders::tickets_for_order( /* … */ ); // nur lesen, nur Felder der Positivliste
			\WorkflowCaptain\Plugin::instance()->consumer()->capture( $this, array(
				'type'          => 'pass.ticket.checked_in',
				'subject'       => array( 'type' => 'ticket', 'id' => $public_id, 'id_kind' => 'public' ),
				'data'          => array( 'checkin_source' => (string) $context['source'], 'device_id' => (int) $context['device_id'] ),
				'actor_user_id' => get_current_user_id() ?: null,
				'discriminator' => (string) ( $context['idempotency_key'] ?? '' ),
			) );
		} catch ( \Throwable $e ) {
			// Nichts darf den Provider erreichen (R-7.6).
		}
	}
}

add_action( 'workflow_captain_register_adapters_v1', function ( $registry ) {
	// Nur wenn Workflow Captain Pro UND das Pro-Add-on des Produkts lizenziert sind (D6, R-10.2) – geprüft im Add-on.
	$adapter = new Pass_Adapter();
	if ( $registry->add( $adapter ) ) {
		$adapter->hook();
	}
} );
```

Regeln, die der Adapter einhält:

- **Positivliste je Ereignistyp** (R-5.2): Die erlaubten Felder stehen in der Spezifikation 4.2 bis 4.5 und maschinenlesbar in [`catalog/suite-events-v1.json`](catalog/suite-events-v1.json). Nie Codes, Tokens, E-Mail-Adressen, Namen oder Freitext (Abschnitt 5).
- **Diskriminator** nach R-7.9: gleich bei einer Wiederholung derselben Action, verschieden bei zwei echten Ereignissen.
- **Gegenlesen:** Feuert eine Action nicht belegt nach dem Commit, gibt `needs_reread()` `true` zurück und `reread()` liest den Zustand über die Lesefunktionen des Providers (R-7.4, Tabelle in 8.1).
- **Vertragsgrenze:** Der Adapter meldet sich nur an, wenn der Vertrag des Providers mindestens die nötige Version hat und dieselbe MAJOR (R-13.4).
- **Nachweise A-1 bis A-11:** [`../tests/adapter-kit/`](../tests/adapter-kit/).

Den Consumer erreicht ein Add-on über `WorkflowCaptain\Plugin::instance()->consumer()` (Action `workflow_captain_loaded` abwarten).

## 3. Eine Aktion anmelden

```php
add_action( 'workflow_captain_actions_v1', function ( $registry ) {
	$registry->add( new My_Action() ); // implementiert WorkflowCaptain\Engine\Action
} );
```

- `capability()`: Berechtigung, die ein Mensch für dieselbe Handlung bräuchte (R-8.6). Der veröffentlichende Benutzer muss sie haben (R-8.7). Vor jeder Ausführung prüft die Engine sie erneut; fehlt sie, pausiert der Lauf (R-8.8). `null` für Befehle, die nur im Consumer schreiben.
- `idempotent()`: `true` nur, wenn der Empfänger den Idempotenzschlüssel (`ActionContext::$idempotency_key`) erneut prüft. Sonst bucht die Engine den Seiteneffekt und wiederholt nach einem unklaren Ausgang nie von selbst (R-8.12).
- `execute()` wirft `TransientFailure` (Wiederholung nach Plan, R-8.11) oder `PermanentFailure` (Aktion erforderlich; mit `pause = true` pausiert). Das Ergebnis ist ein flaches Array ohne persönliche Daten; Werte mit `@` werden verworfen.
- Ereignisse, die eine Aktion im selben Prozess auslöst, bekommen die Kausalkette automatisch (R-7.12). Erzeugt eine Aktion ein Objekt, das später Ereignisse auslöst, ruft sie `Consumer::remember_cause()` auf.
- Ausdrücklich verboten sind alle Befehle aus 8.2. Das Testkit prüft das (A-9).

## 4. Workflow-Format

Workflows entstehen im visuellen Editor (Admin „Workflows“); darunter steht dasselbe JSON, das Export und Import nutzen. Format und Regeln stehen in `src/Engine/Graph.php`; Knoten sind `trigger`, `condition`, `action`, `delay` und `end`. Bedingungen: `src/Engine/Conditions.php`. Platzhalter in Texten: `{{type}}`, `{{occurred_at}}`, `{{subject.type}}`, `{{subject.id}}`, `{{source.product}}`, `{{data.<feld>}}`. Am Auslöser: `schedule` (für `workflow.schedule.due`) und `serialize` (`subject` oder `actor`: je Objekt oder Benutzer höchstens ein Lauf dieses Workflows gleichzeitig, die übrigen warten in Startreihenfolge; ohne `serialize` gibt es keine Reihenfolge, Spezifikation 7.5).

## 5. Hooks

| Hook | Art | Zweck |
|---|---|---|
| `workflow_captain_loaded` | Action | Kern ist geladen |
| `workflow_captain_register_adapters_v1` | Action | Adapter anmelden (`init`, Priorität 20). Die eingebauten Adapter `wordpress` und `woocommerce` sind dann schon angemeldet; ein zweiter Adapter für dasselbe Produkt wird abgelehnt (`already_registered`) |
| `workflow_captain_actions_v1` | Action | Aktionen anmelden |
| `workflow_captain_dispatcher` | Filter | eigene Engine statt der des Kerns (selten) |
| `workflow_captain_foreign_runs_v1` | Action | Läufe von einer anderen Website des Netzwerks: Resolver, Gate und Beobachter an `Engine\ForeignRuns` setzen (Abschnitt 5b) |
| `workflow_captain_worker_batch` | Filter | Ereignisse und Schritte je Worker-Durchlauf (1 bis 200, Standard 25) |
| `workflow_captain_event_processed_v1` | Action | Ereignis vom Worker verarbeitet (für Ausgangswarteschlangen von Add-ons, 4.10) |
| `workflow_captain_inbound_triggers_v1` | Filter | Auslöser für eingehende Aufrufe anmelden; speichern mit `Consumer::capture_inbound()` |
| `workflow_captain_register_connectors_v1` | Action | Eigenen Connector mit Manifest anmelden (`init`, Priorität 20, nach den Adaptern; [`CONNECTOR-CONTRACT.md`](CONNECTOR-CONTRACT.md)) |
| `workflow_captain_templates` | Filter | weitere Vorlagen-Dateien (absolute Pfade zu JSON im Exportformat) für die Seite „Vorlagen“ |
| `workflow_captain_secret_stores` | Filter | Speicher von Add-ons mit Geheimnissen im Tresor anmelden, damit sie nach einem Schlüsselwechsel neu verschlüsselt werden (`context`, `load`, `save`; `src/Security/SecretStores.php`) |
| `workflow_captain_audit_labels_v1` | Filter | Beschriftungen für Audit-Einträge von Add-ons (Status-Tab) |
| `workflow_captain_connector_runtime_allowed_v1` | Filter | Laufzeit eigener Connectoren erlauben (ab Werk ja; für Pro, F-18a) |
| `workflow_captain_retention_days` | Filter | Aufbewahrung in Tagen (`finished`, `stuck`, `runs`), seit 0.3.0 bis zur Obergrenze verlängerbar |
| `workflow_captain_test_mode` | Filter | Testmodus der Website (0 aus, 1 Nachrichten umleiten, 2 nichts ausführen); Add-ons mit eigenen Wirkungen (Webhooks) SOLLEN ihn beachten |
| `workflow_captain_has_consent_v1` | Filter | Einwilligung eines Benutzers in Werbenachrichten (`bool $consent = false, int $user_id, array $context` mit `workflow_id` und `event`); nur ein striktes `true` zählt. Workflow Captain speichert keine Einwilligung (SET-WC-03, E-S2, S11) |
| `workflow_captain_retention_max_days` | Filter | Obergrenze der Aufbewahrung (180 im kostenlosen Kern; ein Add-on darf sie anheben) |
| `workflow_captain_setting_<feld>` | Filter | Wert einer Einstellung des Registers (`docs/OPTIONS.md`); gewinnt, wird aber gegen Typ und Bereich geprüft |
| `workflow_captain_settings_fields_v1` | Filter | Felder eines Add-ons in einem Reiter der Einstellungen (Abschnitt 5d); der Kern zeichnet sie, speichert sie aber nicht |

## 5a. Bausteine für Webhooks und n8n (für Add-ons)

- `WorkflowCaptain\Security\SafeHttp`: ausgehende Anfragen mit den Profilen `webhook` (nur öffentliches HTTPS) und `n8n` (privat und HTTP nur mit Ausnahme je Verbindung), IP-Festnageln, geprüfte Weiterleitungen, Zeit- und Größenlimit, Fehlercodes ohne URL oder Token.
- `WorkflowCaptain\Security\InboundGuard`: Prüfung eingehender Anfragen in fester Reihenfolge (Größe, Verbindung, Rate-Limit, Zeitfenster, HMAC-SHA256 über `timestamp.nonce.body`, Replay, Trigger-Allowlist); jede Ablehnung ist ein fester Code mit HTTP-Status.
- `WorkflowCaptain\Security\Vault`: Geheimnisse verschlüsselt speichern, rotieren, redigieren.

Der Kern selbst registriert keine REST-Route und macht keinen ausgehenden Aufruf; die Routen und Verbindungen liegen in Workflow Captain Pro.

## 5b. Läufe von einer anderen Website des Netzwerks (für Add-ons, Spezifikation Abschnitt 17)

Der Kern speichert keine netzwerkweiten Workflows; er bietet nur den Einstieg, über den ein Add-on einen Lauf in die Warteschlange der Ziel-Website legt. Netzwerk-Workflows, Freigaben und Elternläufe liegen im Add-on (Workflow Captain Pro).

- `Engine\ForeignRuns::enqueue( $store, $graph, $envelope, $network, $key, $origin, $name, $now )` legt auf der **aktuellen** Website einen Lauf mit seinem ersten Schritt an, einmal je `$key` (64 Hex-Zeichen, aus Netzwerklauf und Ziel-Website). Das Add-on wechselt dafür mit `switch_to_blog()` in `try`/`finally` auf die Ziel-Website; ausgeführt wird der Schritt später vom Worker der Ziel-Website mit deren Plugins, Aktionen und Rechten (R-17.4).
- `$network` ist die Hülle neben dem Umschlag (R-17.5): genau `network_id`, `source_blog_id`, `workflow_id`, `version`, `run_id`, jeweils positive Ganzzahlen. Sie steht in der Spalte `network` des Laufs, nicht im Umschlag. `actor_user_id` und `caused_by_workflow_id` entfernt der Kern aus dem Umschlag (R-17.3).
- Alle Läufe einer Herkunft (`$origin`, z. B. `network:12`) gehören zu einem Anker-Workflow der Ziel-Website mit dem Status `network`: nie gelistet, nie bearbeitbar, nie veröffentlicht, von keinem lokalen Ereignis gestartet. Ereignisse, die ein Schritt auslöst, tragen Korrelation und Kettentiefe des Quellereignisses weiter; die Kette zählt über Websites hinweg (R-17.7).
- Vor jedem Aktionsschritt eines solchen Laufs gilt: Die Aktion implementiert `Engine\NetworkSafe` (sonst Pause `action_not_network_safe`), das Gate des Add-ons stimmt zu, und `NetworkSafe::network_check()` stimmt zu (`notify.user` z. B. nur für Mitglieder der Ziel-Website, `user_not_member`). Die fünf Aktionen des Kerns sind netzwerkfähig (`network_safe` in `docs/catalog/actions-v1.json`). Ohne Resolver pausiert der Schritt (`foreign_unavailable`).
- Der Beobachter hört jeden Zustand des Laufs (`running`, `succeeded`, `paused` mit Grund, …). `ForeignRuns::find()`, `resume()` und `cancel()` lesen, setzen fort oder brechen ab, ebenfalls auf der aktuellen Website.

```php
add_action( 'workflow_captain_foreign_runs_v1', function ( \WorkflowCaptain\Engine\ForeignRuns $foreign ) {
	$foreign->set_resolver( fn( array $network, array $run ) => my_definition_for_this_site( $network ) ); // graph, version, authorized_by_user_id
	$foreign->set_gate( fn( array $network, array $run, array $node ) => my_consent_ok( $network, $node ) ? null : 'paused_site_consent' );
	$foreign->set_observer( fn( array $run, string $state, ?string $reason ) => my_child_step_update( $run, $state, $reason ) );
} );
```

## 5c. Status für die Admin-Seiten der Kerne

`workflow_captain_status_for( 'pass-captain' )` liefert einem Kern-Plugin für seine eigene Admin-Seite: Adapter angemeldet/abgelehnt/fehlt mit Grund, Vertragsversion, Zahl aktiver Workflows für seine Ereignisse, Ereignisse je Zustand, die letzte Korrelations-ID und Links zu Workflows, Läufen, Ereignissen und Providern. Nur Zahlen und Links, keine Ereignisdaten. Vor dem Start von Workflow Captain gibt die Funktion `null` zurück.

## 5d. Felder eines Add-ons in den Einstellungen (SET-WC-02)

Ein Add-on hängt eigene Felder an einen Reiter der Einstellungen (`general`, `execution`, `messages`, `notify`, `privacy`). Der Filter `workflow_captain_settings_fields_v1` bekommt eine leere Liste und den Reiter und gibt eine Liste von Feldern zurück. Der Kern zeichnet sie im Abschnitt „Einstellungen von Add-ons“ unter den eigenen Feldern, ein Formular je Einstellungsgruppe, das an `options.php` geht. **Gespeichert wird nichts vom Kern:** Das Add-on meldet seine Option mit `register_setting( $group, $option, … )` an und prüft die Werte selbst. Optionen und Gruppen des Kerns werden abgelehnt.

| Schlüssel | Pflicht | Inhalt |
|---|---|---|
| `key` | ja | `[a-z0-9_]`, Name im Array der Option |
| `label` | ja | Beschriftung (übersetzt) |
| `help` | nein | Hilfetext unter dem Feld |
| `option` | ja | eigene Option des Add-ons, das Feld heißt `option[key]` |
| `group` | nein | Einstellungsgruppe für `settings_fields()`, Standard: die Option |
| `render` | nein | `callable( $field, $value, $name, $id )`, gibt das Bedienelement aus |
| `type` | ohne `render` | `text`, `number`, `checkbox` oder `textarea` (Standard `text`) |

```php
add_filter( 'workflow_captain_settings_fields_v1', function ( array $fields, string $tab ) {
	if ( 'messages' === $tab ) {
		$fields[] = array( 'key' => 'brand_color', 'label' => __( 'Brand color', 'my-addon' ), 'option' => 'my_addon_settings', 'type' => 'text' );
	}
	return $fields;
}, 10, 2 );
```

Am Ende eines Reiters steht danach eine graue Hinweiszeile, wenn die statische Liste des Kerns für den Reiter Text enthält (`Admin\SettingsExtras::hint_lines()`) und der Schalter `show_pro_hints` an ist. Die Zeilen sind nur Text, ohne Feld und ohne Rechteprüfung; ein Add-on kann sie nicht ergänzen. Bis eine Funktion verkauft wird, ist die Liste leer (S5).

## 6. Datenfluss und Datenschutz

| Ort | Was liegt dort | Personenbezug | Frist |
|---|---|---|---|
| Ereignisspeicher | Umschlag | `actor_user_id`; wird geleert, sobald kein Lauf mehr darauf wartet | erledigt 30 Tage, `dead_letter`/`paused` 90 Tage (Vorschlag, F-04) |
| Lauf | Kopie des Umschlags | `actor_user_id` | erledigte Läufe 30 Tage; offene bleiben |
| Schritt | Zustand, redigiertes Ergebnis | keiner | mit dem Lauf |
| Markierungen, Notizen | Text des Betreibers am Objekt | keiner, solange der Betreiber keinen hineinschreibt | bis zur Löschung |
| Mail | Adresse wird zur Ausführung aufgelöst | Adresse wird nicht gespeichert | – |

Export und Löschung persönlicher Daten über die Werkzeuge von WordPress erfassen Ereignisse und Läufe. Beim Löschen eines Benutzers und bei `affiliate.affiliate.erased` werden die Einträge anonymisiert (R-12.5). Die Fristen stehen unter dem Vorbehalt der Rechtsprüfung.

## 7. Betrieb

- `wp workflow-captain status|process|requeue|reconcile|migrate|diagnostics|verify|secrets`
- Auf schwach besuchten Websites einen System-Cron einrichten, der `wp workflow-captain process` jede Minute aufruft.
- Schema und Daten werden versioniert aktualisiert (`src/Migrator.php`): eine Aktualisierung je Website zur Zeit (Sperre `workflow_captain_upgrade_lock`), eine abgebrochene wird nach 10 Minuten übernommen und ab dem letzten fertigen Schritt wiederholt; Datenschritte stehen in `Install::migrations()` und müssen wiederholbar sein und dürfen keine Historie löschen. Solange das Schema nicht aktuell ist, wartet der Worker.
- Eingriffe (Wiedereinreihen, Abbrechen, Fortsetzen, Veröffentlichen, Pausieren, Archivieren) stehen im Protokoll (`WorkflowCaptain\Audit`, Einstellungen → Status); Add-ons schreiben mit `Audit::record()` und beschriften mit `workflow_captain_audit_labels_v1`.

### Support-Diagnose

Der Diagnose-Export (Seite „Ereignisse“ → „Diagnose herunterladen“ oder `wp workflow-captain diagnostics`) ist eine JSON-Datei ohne personenbezogene Daten und ohne Geheimnisse. Sie enthält:

| Teil | Inhalt |
|---|---|
| `environment` | Versionen von Plugin, Schema (gespeichert/erwartet, Zustand, Aktualisierungssperre), Adapter-Schnittstelle, WordPress, PHP, Datenbankserver (`database`, z. B. `9.7.1` oder `12.3.2-MariaDB`); Multisite; WP-Cron abgeschaltet; nächster Worker-Lauf; Planung (Action Scheduler oder WP-Cron); eigener Schlüssel gesetzt (ja/nein); Notfallpause; Zahl und Codes abgelehnter Umschläge |
| `adapters` | angemeldete Produkte und abgelehnte Adapter mit Grund |
| `events`, `runs`, `workflows` | Anzahl je Zustand; Workflows gesamt, aktiv, pausiert |
| `action_required_reasons` | Gründe der Läufe „Aktion erforderlich“ mit Anzahl (feste Codes) |
| `stuck` | Schritte mit seit über einem Tag abgelaufener Sperrfrist, Läufe ohne offenen Schritt |
| `consistency` | Zahl fehlender Verweise nach einer Wiederherstellung (Läufe ohne Version, Schritte ohne Lauf, aktive Workflows ohne Version); die Nummern nennt `wp workflow-captain verify` |

Nie enthalten: Umschläge, Felder von Ereignissen, Benutzer-IDs, E-Mail-Adressen, Namen, Codes, Geheimnisse, URLs von Verbindungen. Der Website-Zustand von WordPress zeigt dieselben Prüfungen (Worker, Schema, Entscheidungen, Schlüssel, Notfallpause, Version des Datenbankservers – nur Empfehlung).
