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
| Nach einem Downgrade | Hinweis „Datenbank stammt von einer neueren Version“ | Neuere Version wieder installieren. Das ältere Plugin ändert nichts am Schema und verarbeitet nichts. |

## Wiederherstellung aus einem Backup

1. Datenbank und `wp-config.php` gemeinsam zurückspielen. Ohne dieselbe `WORKFLOW_CAPTAIN_SECRET_KEY` (oder dieselben Salts) sind gespeicherte Geheimnisse nicht lesbar; die betroffenen Verbindungen pausieren.
   Nach einem gewollten Schlüsselwechsel: alten Schlüssel als `WORKFLOW_CAPTAIN_SECRET_KEY_PREVIOUS` setzen, `wp workflow-captain secrets --dry-run`, dann `wp workflow-captain secrets`; danach den alten Schlüssel entfernen.
2. `wp workflow-captain migrate` stellt das Schema her (nur aufwärts), `wp workflow-captain verify` meldet Läufe ohne Version, Schritte ohne Lauf und aktive Workflows ohne Version.
3. Läufe, die beim Backup offen waren, laufen weiter. Schritte, deren Nachricht schon vor dem Backup hinausging, erkennt das Plugin nicht. Bei Zweifel die offenen Läufe in **Läufe** prüfen und abbrechen.
4. Ereignisse, die zwischen Backup und Wiederherstellung entstanden, fehlen. Die Kerne wiederholen nicht für die Suite (R-7.3).

## Grenzen, die man kennen muss

- Ereignisse, die zwischen dem Commit eines Kerns und dem Speichern verloren gehen, holt nichts nach (R-7.2).
- „Mindestens einmal“, nicht „genau einmal“: Aktionen mit Idempotenzschlüssel sind sicher wiederholbar. Nachrichten werden nach einem unklaren Ausgang nie von selbst wiederholt.
- Abbrechen stoppt wartende Schritte, macht aber Erledigtes nicht rückgängig.
