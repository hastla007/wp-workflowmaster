# Runbook: Betrieb und Wiederherstellung

Stand: Plugin 0.2.x. Aufgabe WF-CORE-10. Für Betreiber und Support.

## Lagebild in einer Minute

1. **Werkzeuge → Website-Zustand:** Worker geplant? Schema aktuell? Gibt es Entscheidungen, die warten?
2. **Workflow Captain → Einstellungen → Status:** Zeitzone, nächster Worker-Lauf, WP-Cron, eigener Schlüssel, hängende Schritte.
3. `wp workflow-captain status` auf der Kommandozeile.
4. Für den Support: **Ereignisse → „Diagnose herunterladen“** oder `wp workflow-captain diagnostics`. Die Datei enthält keine persönlichen Daten und keine Geheimnisse.

## Typische Lagen

| Lage | Erkennen | Tun |
|---|---|---|
| WP-Cron läuft nicht (wenig Besucher, `DISABLE_WP_CRON`) | Website-Zustand „Worker überfällig“; Schritte „überfällig“ | System-Cron: `* * * * * wp --path=/pfad workflow-captain process` (bei Multisite je Website mit `--url`). Der Befehl holt auch die tägliche Wartung (Aufbewahrung, Datenschutz) nach, wenn sie länger als 25 Stunden nicht lief. Überfällige Schritte in **Läufe** einzeln „Fortsetzen“. |
| Ereignisse in `dead_letter` | **Ereignisse**, Filter „Fehlgeschlagen“, Spalte „Letzter Fehler“ | Ursache beheben (Datenbank, Provider), dann auswählen → „Ausgewählte erneut einreihen“ oder `wp workflow-captain requeue <nr>` |
| Ereignisse `paused` (`adapter_unregistered`) | **Provider**: Adapter „kein Adapter“ | Add-on aktivieren oder Lizenz verlängern. Es wird nichts von selbst nachgeholt (R-10.8). Danach gezielt erneut einreihen. |
| Lauf „Aktion erforderlich“ – `unclear_outcome` | **Läufe** → Lauf öffnen | Der Worker brach während einer Nachricht ab; sie ging vielleicht schon hinaus. Nur mit Häkchen „Erneut senden?“ fortsetzen, sonst Lauf abbrechen. |
| Lauf pausiert – `not_authorized` | **Läufe**, Grund `not_authorized` | Der veröffentlichende Benutzer hat eine Berechtigung verloren oder existiert nicht mehr. Recht wiederherstellen und fortsetzen, oder den Workflow mit einem berechtigten Benutzer neu veröffentlichen (neue Läufe). |
| Lauf pausiert – `action_unavailable` | **Läufe** | Die Aktion (Provider) fehlt. Add-on wieder aktivieren und fortsetzen; nie auf eine andere Aktion umbauen (WF-CORE-02). |
| Lauf pausiert – `provider_contract_changed` | **Läufe** | Der Provider einer Aktion hat seit dem Veröffentlichen eine neue MAJOR-Version seines Vertrags (oder fehlt). Workflow prüfen, neu veröffentlichen (neue Läufe nutzen den neuen Vertrag), alte Läufe abbrechen oder nach Rückkehr zur alten Version fortsetzen. |
| Viele Ereignisse fehlgeschlagen oder pausiert nach einer Störung | **Ereignisse** | `wp workflow-captain reconcile` zeigt, welche beim Provider noch stimmen und welche sich geändert haben; `--requeue` reiht nur die stimmigen wieder ein. Geänderte von Hand prüfen. |
| Hängende Schritte | Website-Zustand / Einstellungen → Status | Ein Worker starb und kein anderer lief seitdem. Sobald der Cron läuft, übernimmt der nächste Worker. Ist eine Nachricht betroffen, entscheidet ein Mensch (siehe `unclear_outcome`). |
| Aktualisierung abgebrochen (Zeitlimit, Fehler beim Update) | Einstellungen → Status: „Aktualisierung der Datenbank: abgebrochen“; Worker wartet | Nichts löschen. Nach 10 Minuten übernimmt der nächste Aufruf im Admin, per Cron oder `wp workflow-captain migrate` die Aktualisierung und wiederholt sie ab dem letzten fertigen Schritt (Tabellen per `dbDelta`, Datenschritte wiederholbar). Das Protokoll in Einstellungen → Status nennt „Abgebrochene Datenbank-Aktualisierung wiederholt“. Meldet `migrate` „Another request is upgrading“, läuft gerade eine andere Aktualisierung. |
| Nach einem Downgrade | Hinweis „Datenbank stammt von einer neueren Version“ | Neuere Version wieder installieren. Das ältere Plugin ändert nichts am Schema und verarbeitet nichts. |

## Last und System-Cron (gemessen)

Gemessen am 16.11.2026 auf einer echten Installation (WooCommerce mit HPOS, MariaDB, 50 und 5000 Bestellungen; `docs/E2E-RESULTS.md`, „Zweiter Lauf“, Teil 2). Ein Worker-Durchgang erledigt bis zu *Batch* Ereignisse **und** bis zu *Batch* Schritte; eine Bestellung mit einem Workflow aus drei Schritten braucht drei Ereignisse und drei Schritte.

| Shop | Bestellungen | Einstellung | Warum |
|---|---|---|---|
| klein | bis etwa 50 am Tag, Spitzen bis 300 in der Stunde | Standard: Batch 25, geplanter Worker (Action Scheduler oder WP-Cron) | schafft etwa 500 Bestellungen je Stunde; ein Durchgang dauert 0,2 s |
| mittel | bis etwa 1000 am Tag oder Aktionen mit Spitzen | System-Cron `* * * * * wp --path=/pfad workflow-captain process --limit=100` (ohne `--limit` gilt seit SET-WC-36 die Einstellung „Ereignisse und Schritte je Worker-Durchlauf“, ab Werk 25) | etwa 2000 Bestellungen je Stunde; ein Durchgang 0,6 s, mit WordPress-Start 1,3 s |
| groß | 5000 am Tag und mehr, Verkaufsstarts | System-Cron mit `--limit=200`; in den Einstellungen Batch 200 | etwa 4000 Bestellungen je Stunde; ein Durchgang 1,3 s, Speicher wie ein normaler WP-CLI-Aufruf (etwa 110 MB mit WooCommerce) |

- Ohne Besucher und mit `DISABLE_WP_CRON` läuft der geplante Worker nicht: dann immer System-Cron.
- **Einen** Cron-Eintrag je Website. Zwei Worker gleichzeitig sind sicher (keine Doppelausführung, geprüft mit 5050 Bestellungen), bringen aber nur gut 10 % mehr, weil sie dieselben Zeilen beanspruchen. Mehr Durchsatz: höherer `--limit`, nicht mehr Prozesse.
- Die Laufzeitgrenze je Durchgang (Standard 20 s) wurde bei Batch 200 nie erreicht (langsamster Durchgang 3,6 s). Sie schützt vor langsamen Mailservern: ein echter SMTP-Versand kostet je Nachricht oft 0,2–1 s, dann begrenzt die Laufzeit statt der Batch.
- Rückstand beobachten: `wp workflow-captain status` (Ereignisse `received`, Schritte „überfällig“). Wächst er über mehrere Minuten, Batch erhöhen.
- Die Erfassung kostet im Shop etwa 6 ms je Bestellung (3 Ereignisse); gemessen +2,6 % beim Anlegen und Bezahlen einer Bestellung.
- Die Tabellen wachsen um etwa 1,3 KB je Ereignis und 3 KB je Lauf mit drei Schritten; die Aufbewahrung (Einstellungen → Datenschutz) räumt sie auf.

## Wiederherstellung aus einem Backup

1. Datenbank und `wp-config.php` gemeinsam zurückspielen. Ohne dieselbe `WORKFLOW_CAPTAIN_SECRET_KEY` (oder dieselben Salts) sind gespeicherte Geheimnisse nicht lesbar; die betroffenen Verbindungen pausieren.
   Nach einem gewollten Schlüsselwechsel: alten Schlüssel als `WORKFLOW_CAPTAIN_SECRET_KEY_PREVIOUS` setzen, `wp workflow-captain secrets --dry-run`, dann `wp workflow-captain secrets`; danach den alten Schlüssel entfernen.
2. `wp workflow-captain migrate` stellt das Schema her (nur aufwärts), `wp workflow-captain verify` meldet Läufe ohne Version, Schritte ohne Lauf und aktive Workflows ohne Version.
3. Läufe, die beim Backup offen waren, laufen weiter. Schritte, deren Nachricht schon vor dem Backup hinausging, erkennt das Plugin nicht. Bei Zweifel die offenen Läufe in **Läufe** prüfen und abbrechen. Ein Mail-Schritt, der beim Backup gerade sendete, wird nicht von selbst wiederholt („Aktion erforderlich“, `unclear_outcome`).
4. Ereignisse, die zwischen Backup und Wiederherstellung entstanden, fehlen. Die Kerne wiederholen nicht für die Suite (R-7.3).
5. **Nie zwei Seiten mit derselben Sicherung arbeiten lassen.** Wird die Sicherung auf einer zweiten Seite (Staging, Umzug) eingespielt, während die alte weiterläuft, führen beide die offenen Schritte aus. Auf der Kopie sofort die Notfallpause setzen (`wp workflow-captain pause on`) und erst aufheben, wenn die alte Seite abgeschaltet ist.

Geprüft auf einer echten Installation (mysqldump, Einspielen in eine Kopie, Worker weiter, Downgrade-Schutz, abgebrochenes Upgrade, wiederholtes `migrate`): `docs/E2E-RESULTS.md`, „Zweiter Lauf“, Teil 1.

## Protokoll der Eingriffe

Einstellungen → Status zeigt die letzten 50 Einträge (Option `workflow_captain_audit`): Rechte, Notfallpause, Einstellungen, Freigabe eigener Connectoren, erneut eingereihte Ereignisse (Admin, `requeue`, `reconcile --requeue`), abgebrochene Läufe, fortgesetzte Schritte, Veröffentlichen/Archivieren/Wiederherstellen/Pausieren/Fortsetzen von Workflows und wiederholte Datenbank-Aktualisierungen. Gespeichert werden Benutzer-ID, Zeit und Nummern, nie Inhalte von Ereignissen.

## Grenzen, die man kennen muss

- Ereignisse, die zwischen dem Commit eines Kerns und dem Speichern verloren gehen, holt nichts nach (R-7.2).
- „Mindestens einmal“, nicht „genau einmal“: Aktionen mit Idempotenzschlüssel sind sicher wiederholbar. Nachrichten werden nach einem unklaren Ausgang nie von selbst wiederholt.
- Abbrechen stoppt wartende Schritte, macht aber Erledigtes nicht rückgängig.
