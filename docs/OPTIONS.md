# Optionen, Tabellen und Datenflächen

Stand: Plugin 0.2.x. Aufgaben WF-SET-01, WF-SET-14 und WF-SEO-01 in [`../TODO.md`](../TODO.md).

## Optionen (je Website, `autoload = no`)

| Option | Inhalt | Standard | Geschrieben von | Gelöscht bei Deinstallation |
|---|---|---|---|---|
| `workflow_captain_db_version` | Version des Datenbankschemas | Version des Plugins | `Install::create_tables()` | ja |
| `workflow_captain_settings` | Batchgröße, Parallelität, Nachholgrenze, Aufbewahrungsfristen, tägliche Hinweis-Mail | `Settings::FIELDS` | Einstellungsseite (`manage_workflow_captain`) | ja |
| `workflow_captain_emergency_pause` | Notfallpause: aktiv, seit, von Benutzer | aus | Übersicht, `wp workflow-captain pause` | ja |
| `workflow_captain_adapters` | Produkte, deren Adapter zuletzt angemeldet waren (für das Pausieren bei Wegfall, C-15) | leer | `Plugin::register_adapters()` | ja |
| `workflow_captain_audit` | letzte 50 sicherheitsrelevante Änderungen: Zeit, Benutzer-ID, Art (Einstellungen, Rechte, Notfallpause), geänderte Schlüssel | leer | `Audit::record()` | ja |
| `workflow_captain_notify` | Empfänger (bis zu zehn Adressen) und Arten der täglichen Hinweis-Mail | leer = Adresse der Website, alle Arten | Einstellungen → Benachrichtigungen | ja |
| `workflow_captain_rejected` | Zahl abgelehnter Umschläge und die letzten Regelcodes, nie Werte | leer | `Plugin::record_rejection()` | ja |

Kurzlebige Transients je Benutzer (60 bis 300 Sekunden): `workflow_captain_notice_{user}` (Ergebnis von Speichern und Import) und `workflow_captain_dryrun_{user}` (letzter Testlauf mit Beispieldaten). Sie enthalten nur, was der Benutzer selbst eingegeben hat.

Capabilities bei Aktivierung für Administratoren: `manage_workflow_captain`, `edit_workflows`, `activate_workflows`, `view_workflow_logs`, `retry_workflow_runs`. Andere Rollen bekommen Rechte nur über „Einstellungen → Rollen & Rechte“. Die Deinstallation entfernt die Rechte von allen Rollen.

## Tabellen (je Website unter `{prefix}workflow_captain_`)

| Tabelle | Inhalt | Personenbezug | Frist |
|---|---|---|---|
| `events` | gespeicherte Umschläge, Zustand, Versuche | `actor_user_id`, geleert sobald kein Lauf wartet | erledigt 30 Tage, `dead_letter`/`paused` 90 Tage (nur kürzer einstellbar) |
| `causes` | welcher Befehl welches Objekt erzeugt hat (R-7.12) | keiner | 7 Tage |
| `workflows` | Name, Zustand, Entwurf | keiner | bis zur Deinstallation; „Löschen“ archiviert |
| `versions` | unveränderliche veröffentlichte Graphen, `authorized_by_user_id` | Benutzer-ID des Veröffentlichenden | mit dem Workflow |
| `runs` | Lauf mit Kopie des Umschlags | `actor_user_id` | erledigte Läufe 30 Tage (eigene Frist), offene bleiben |
| `steps` | Schritte, Sperrfrist, Buchung des Seiteneffekts, redigiertes Ergebnis | keiner | mit dem Lauf |
| `tags`, `notes` | Markierungen und Notizen an Objekten | nur wenn der Betreiber Personenbezug in eine Notiz schreibt | bis zur Löschung |

## Änderungssemantik (WF-SET-14)

- Neue Standardwerte und geänderte Einstellungen ändern **keine** veröffentlichte Version und keinen laufenden Lauf. Läufe behalten Graph, Ereignis und `authorized_by_user_id` ihrer Version (WF-CORE-02).
- Grenzen der Ausführung (Batch, Parallelität, Nachholgrenze) gelten ab dem nächsten Worker-Durchlauf für alle Schritte.
- Kürzere Aufbewahrungsfristen wirken bei der nächsten täglichen Wartung, auch auf ältere Einträge.
- Eine neue Plugin-Version ändert das Schema nur im Admin, per Cron oder per WP-CLI; ein neueres Schema bleibt nach einem Downgrade unberührt.

## Öffentliche und private Datenflächen (WF-SEO-01, -02, -03)

- Workflow Captain gibt auf öffentlichen Seiten nichts aus: keine Hooks an `wp_head`, `wp_footer`, `template_redirect`, Inhalt, Titel, Robots oder Sitemaps, keine Shortcodes, Rewrite-Regeln, eigenen Beitragstypen und keine REST-Routen. Ein Test in `tests/security-tests.php` sichert das ab.
- Alle Seiten liegen im Admin und verlangen die passende Capability; jede Aktion prüft zusätzlich eine Nonce.
- Ausgehende Daten gibt es nur als Nachrichten der Aktionen `notify.admin` und `notify.user` an Empfänger der eigenen Website. Ausgehende HTTP-Aufrufe macht der Kern nicht.
- Downloads (Diagnose, Export eines Workflows) liefert nur `admin-post.php` mit Capability und Nonce.
