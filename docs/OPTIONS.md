# Optionen, Tabellen und Datenflächen

Stand: Plugin 0.2.x. Aufgaben WF-SET-01, WF-SET-14 und WF-SEO-01 in [`../TODO.md`](../TODO.md).

## Optionen (je Website, `autoload = no`)

| Option | Inhalt | Standard | Geschrieben von | Gelöscht bei Deinstallation (nur mit `delete_data_on_uninstall`, SET-WC-05) |
|---|---|---|---|---|
| `workflow_captain_db_version` | Version des Datenbankschemas | Version des Plugins | `Install::create_tables()` | ja |
| `workflow_captain_settings` | Batchgröße, Parallelität, Nachholgrenze, Aufbewahrungsfristen, Ruhezeit und Tagesgrenze der Nachrichten, tägliche Hinweis-Mail | Register (`SettingsRegistry`, unten) | Einstellungsseite (`manage_workflow_captain`) | ja |
| `workflow_captain_emergency_pause` | Notfallpause: aktiv, seit, von Benutzer | aus | Übersicht, `wp workflow-captain pause` | ja |
| `workflow_captain_adapters` | Produkte, deren Adapter zuletzt angemeldet waren (für das Pausieren bei Wegfall, C-15) | leer | `Plugin::register_adapters()` | ja |
| `workflow_captain_audit` | letzte 50 (einstellbar 10–500, `audit_keep`) sicherheitsrelevante Änderungen: Zeit, Benutzer-ID, Art (Einstellungen, Rechte, Notfallpause), geänderte Schlüssel | leer | `Audit::record()` | ja |
| `workflow_captain_notify` | Empfänger (bis zu zehn Adressen) und Arten der täglichen Hinweis-Mail | leer = Adresse der Website, alle Arten | Einstellungen → Benachrichtigungen | ja |
| `workflow_captain_settings_backup` | Stand vor dem letzten Import, Zurücksetzen oder Rückgängig der Einstellungen (Zeit, Benutzer-ID, Quelle, Werte inkl. Empfänger), 30 Tage für „Rückgängig“, danach von der täglichen Wartung gelöscht | leer | `SettingsTransfer::apply()` | ja |
| `workflow_captain_rejected` | Zahl abgelehnter Umschläge und die letzten Regelcodes, nie Werte | leer | `Plugin::record_rejection()` | ja |

### Register der Einstellungen (SET-WC-01)

`src/SettingsRegistry.php` ist die einzige Quelle für Option, Typ, Vorgabe, Bereich, Tab, Beschriftung, Hilfetext, Recht und Export-Regel. Jeder Wert lässt sich mit dem Filter `workflow_captain_setting_<feld>` setzen; der Filter gewinnt, das Ergebnis wird trotzdem gegen Typ und Bereich geprüft (ein Schalter nimmt auch `true`/`false`, Adressen auch eine Liste; ein Ergebnis vom falschen Typ lässt den gespeicherten Wert gelten). Formular und `wp workflow-captain settings get|list` zeigen den gespeicherten Wert und nennen den Wert des Filters daneben; Speichern schreibt nie den gefilterten Wert fest. Ändern darf nur `manage_workflow_captain`. Ein Test prüft, dass diese Tabelle jedes Feld des Registers nennt.

| Feld | Option | Typ | Vorgabe | Bereich | Tab | Export |
|---|---|---|---|---|---|---|
| `batch` | `workflow_captain_settings` | Zahl | 25 | 1–200 | Ausführung | ja |
| `max_site` | `workflow_captain_settings` | Zahl | 5 | 1–20 | Ausführung | ja |
| `max_workflow` | `workflow_captain_settings` | Zahl | 2 | 1–10 | Ausführung | ja |
| `catch_up_hours` | `workflow_captain_settings` | Zahl | 24 | 1–168 | Ausführung | ja |
| `max_seconds` | `workflow_captain_settings` | Zahl | 20 | 5–120 | Ausführung | ja |
| `test_mode` | `workflow_captain_settings` | Auswahl | 0 = aus (1 = Nachrichten umleiten, 2 = nichts ausführen) | 0–2 | Ausführung | nein (gilt nur für diese Installation; Konstante `WORKFLOW_CAPTAIN_TEST_MODE` hat Vorrang) |
| `retry_attempts` | `workflow_captain_settings` | Zahl | 8 | 1–8 | Ausführung | ja |
| `max_chain_depth` | `workflow_captain_settings` | Zahl | 3 | 1–8 | Ausführung | ja |
| `retention_finished` | `workflow_captain_settings` | Zahl (Tage) | 30 | 1–180 | Datenschutz & Aufbewahrung | ja |
| `retention_runs` | `workflow_captain_settings` | Zahl (Tage) | 30 | 1–180 | Datenschutz & Aufbewahrung | ja |
| `retention_stuck` | `workflow_captain_settings` | Zahl (Tage) | 90 | 1–180 | Datenschutz & Aufbewahrung | ja |
| `require_own_key` | `workflow_captain_settings` | ja/nein | nein | – | Datenschutz & Aufbewahrung | nein (hängt an `wp-config.php` dieser Installation) |
| `audit_keep` | `workflow_captain_settings` | Zahl | 50 | 10–500 | Datenschutz & Aufbewahrung | ja |
| `delete_data_on_uninstall` | `workflow_captain_settings` | ja/nein | nein (Daten behalten, E-S6; einschalten nur mit dem Wort LÖSCHEN, LOESCHEN oder DELETE, im Formular und mit `--confirm` in WP-CLI, wie in allen Captain-Plugins, S10) | – | Datenschutz & Aufbewahrung | nein (gilt nur für diese Installation) |
| `quiet_hours_start` | `workflow_captain_settings` | Auswahl (volle Stunde) | −1 = aus | −1–23 | Nachrichten | ja |
| `quiet_hours_end` | `workflow_captain_settings` | Auswahl (volle Stunde) | −1 = aus | −1–23 (über Mitternacht erlaubt, z. B. 22–7) | Nachrichten | ja |
| `send_days` | `workflow_captain_settings` | Wochentage (Bitmaske, 1 = Montag … 64 = Sonntag; WP-CLI auch `1,2,3,4,5`) | 127 = alle | 1–127 | Nachrichten | ja |
| `daily_limit` | `workflow_captain_settings` | Zahl | 0 = keine Grenze | 0–20 je Kunde und Kalendertag | Nachrichten | ja |
| `daily_limit_action` | `workflow_captain_settings` | Auswahl | 0 = am nächsten Tag senden (1 = überspringen) | 0–1 | Nachrichten | ja |
| `notify_daily` | `workflow_captain_settings` | ja/nein | ja | – | Benachrichtigungen | ja |
| `recipients` | `workflow_captain_notify` | Adressen | leer = Adresse der Website | bis 10 | Benachrichtigungen | nein (personenbezogen, an die Installation gebunden) |
| `notify_admin_roles` | `workflow_captain_notify` | Rollen | keine | bis 20 Rollen dieser Website | Benachrichtigungen | ja |
| `kinds` | `workflow_captain_notify` | Auswahl | alle sechs Arten | – | Benachrichtigungen | ja |

**Übertragen (SET-WC-11, -12, -23):** Einstellungen → Status → „Einstellungen übertragen“ und `wp workflow-captain settings list|get|set|export|import|reset|undo`. Der Export (Format 1) enthält nur Felder mit „Export: ja“, nie Empfänger-Adressen oder Geheimnisse; ein Import prüft jedes Feld streng (Fehler statt stiller Korrektur), zeigt zuerst die Änderungen und lehnt eine neuere Formatnummer ab. Speichern im Formular (beide Optionen, auch das erste Speichern einer Website), Import, Zurücksetzen (je Tab oder alles) und WP-CLI schreiben einen Eintrag ins Änderungsprotokoll (Adressen ohne Wert); Import, Zurücksetzen und WP-CLI sichern den vorigen Stand 30 Tage lang für einmaliges „Rückgängig“. Transient der Import-Vorschau: `workflow_captain_settings_import_{user}` (15 Minuten, ohne Adressen). Export, Import, Zurücksetzen und Rückgängig nehmen nur POST mit eigener Nonce und `manage_workflow_captain` an; die Deinstallation löscht auch die Transients `workflow_captain_*`.

Kurzlebige Transients je Benutzer (60 bis 300 Sekunden): `workflow_captain_notice_{user}` (Ergebnis von Speichern und Import) und `workflow_captain_dryrun_{user}` (letzter Testlauf mit Beispieldaten). Sie enthalten nur, was der Benutzer selbst eingegeben hat.

Capabilities bei Aktivierung für Administratoren: `manage_workflow_captain`, `edit_workflows`, `activate_workflows`, `view_workflow_logs`, `retry_workflow_runs`. Andere Rollen bekommen Rechte nur über „Einstellungen → Rollen & Rechte“. Die Deinstallation entfernt die Rechte von allen Rollen.

## Tabellen (je Website unter `{prefix}workflow_captain_`)

| Tabelle | Inhalt | Personenbezug | Frist |
|---|---|---|---|
| `events` | gespeicherte Umschläge, Zustand, Versuche | `actor_user_id`, geleert sobald kein Lauf wartet | erledigt 30 Tage, `dead_letter`/`paused` 90 Tage (einstellbar 1–180 Tage) |
| `causes` | welcher Befehl welches Objekt erzeugt hat (R-7.12) | keiner | 7 Tage |
| `workflows` | Name, Zustand, Entwurf | keiner | bis zur Deinstallation; „Löschen“ archiviert |
| `versions` | unveränderliche veröffentlichte Graphen, `authorized_by_user_id` | Benutzer-ID des Veröffentlichenden | mit dem Workflow |
| `runs` | Lauf mit Kopie des Umschlags | `actor_user_id` | erledigte Läufe 30 Tage (eigene Frist, einstellbar 1–180 Tage), offene bleiben |
| `steps` | Schritte, Sperrfrist, Buchung des Seiteneffekts, redigiertes Ergebnis | keiner | mit dem Lauf |
| `tags`, `notes` | Markierungen und Notizen an Objekten | nur wenn der Betreiber Personenbezug in eine Notiz schreibt | bis zur Löschung |
| `sends` | Zähler der Tagesgrenze (SET-WC-08): Kalendertag, Benutzer-ID, Zahl der Nachrichten | Benutzer-ID | 2 Tage (tägliche Wartung); Löscher und gelöschter Benutzer entfernen sie sofort |

## Änderungssemantik (WF-SET-14)

- Neue Standardwerte und geänderte Einstellungen ändern **keine** veröffentlichte Version und keinen laufenden Lauf. Läufe behalten Graph, Ereignis und `authorized_by_user_id` ihrer Version (WF-CORE-02).
- Grenzen der Ausführung (Batch, Parallelität, Nachholgrenze) gelten ab dem nächsten Worker-Durchlauf für alle Schritte.
- Ruhezeit, Sendetage und Tagesgrenze (SET-WC-07/-08) gelten für `notify.user` (Nachrichten an Kunden, Transaktion und Werbung) ab dem nächsten Schritt, auch in laufenden Läufen; Nachrichten an den Betreiber (`notify.admin`) werden nie zurückgehalten. Eine Nachricht außerhalb des Fensters wartet bis zum Beginn der nächsten erlaubten vollen Stunde (Zeitzone der Website, Sommerzeit eingerechnet); kein Versuch wird gezählt, im Schrittprotokoll steht `deferred` mit Grund und Zeit. Über der Tagesgrenze wartet sie bis zum nächsten Kalendertag (und dessen Fenster) oder wird mit `skipped: daily_limit` übersprungen. Der Platz wird atomar in der Tabelle `sends` belegt; scheitert der Versand, wird er zurückgegeben.
- Kürzere Aufbewahrungsfristen wirken bei der nächsten täglichen Wartung, auch auf ältere Einträge.
- Eine neue Plugin-Version ändert das Schema nur im Admin, per Cron oder per WP-CLI; ein neueres Schema bleibt nach einem Downgrade unberührt.

## Öffentliche und private Datenflächen (WF-SEO-01, -02, -03)

- Workflow Captain gibt auf öffentlichen Seiten nichts aus: keine Hooks an `wp_head`, `wp_footer`, `template_redirect`, Inhalt, Titel, Robots oder Sitemaps, keine Shortcodes, Rewrite-Regeln, eigenen Beitragstypen und keine REST-Routen. Ein Test in `tests/security-tests.php` sichert das ab.
- **Einzige öffentliche Adresse (S11, SET-WC-04):** der Abmelde-Link von Werbenachrichten, `wp-admin/admin-post.php?action=workflow_captain_unsubscribe&u=<Benutzer-ID>&t=<Signatur>` (`src/Unsubscribe.php`). Die Signatur ist ein HMAC-SHA256 mit einem Schlüssel aus den Salts der Website; ohne gültige Signatur antwortet die Adresse mit 400. Öffnen zeigt nur eine Schaltfläche, erst das POST meldet ab (auch das One-Click-POST nach RFC 8058). Antwort mit `X-Robots-Tag: noindex, nofollow`; kein Theme, keine weiteren Daten. Der Test in `tests/security-tests.php` lässt genau diese eine Aktion zu.
- Benutzer-Option `workflow_captain_unsubscribed` (Zeitpunkt in UTC; im Netzwerk je Website mit deren Präfix): Abmeldung von Werbenachrichten; bleibt bei der Deinstallation erhalten, außer „Beim Löschen des Plugins alle Daten entfernen“ ist an. *Aufbewahrung und Auskunft: Rechtsprüfung.*
- Alle Seiten liegen im Admin und verlangen die passende Capability; jede Aktion prüft zusätzlich eine Nonce.
- Ausgehende Daten gibt es nur als Nachrichten der Aktionen `notify.admin` und `notify.user` an Empfänger der eigenen Website. Ausgehende HTTP-Aufrufe macht der Kern nicht.
- Downloads (Diagnose, Export eines Workflows) liefert nur `admin-post.php` mit Capability und Nonce.
