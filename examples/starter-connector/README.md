# WFC Starter Connector

Ein kleines, eigenständiges Beispiel-Plugin für einen **eigenen Connector** von Workflow Captain (Entscheidung F-22, Spezifikation 4.8, Vertrag in [`../../docs/CONNECTOR-CONTRACT.md`](../../docs/CONNECTOR-CONTRACT.md)). Es liegt bewusst außerhalb von `src/` und wird nicht mit Workflow Captain ausgeliefert (`/examples export-ignore` in `.gitattributes`).

## Was es zeigt

| Teil | Datei | Inhalt |
|---|---|---|
| Manifest | `src/Manifest.php` | Schlüssel `starter-connector`, Präfix `xstarterconnector`, ein Ereignistyp, eine Aktion |
| Ereignis | `src/TicketAdapter.php` | `xstarterconnector.ticket.closed` mit `ticket_id` (Integer) und `priority` (String), beide Datenklasse „normal“ |
| Aktion | `src/TicketNoteAction.php` | `xstarterconnector.ticket.note`: setzt einen Vermerk am Ticket, idempotent über den Idempotenzschlüssel des Schritts |
| Anmeldung | `wfc-starter-connector.php` | Action `workflow_captain_register_connectors_v1` (Connector) und `workflow_captain_actions_v1` (Aktion) |

## Ausprobieren

1. Ordner `examples/starter-connector` als Plugin `wfc-starter-connector` nach `wp-content/plugins/` kopieren und aktivieren.
2. Unter **Workflow Captain → Eigene Connectoren** erscheint „Starter Connector“ mit dem Status „nicht freigegeben“. Erst nach der Freigabe speichert Workflow Captain seine Ereignisse und bietet seine Aktion an. Dafür braucht man das Recht `manage_workflow_integrations` (Administratoren haben es).
3. Einen Workflow mit dem Auslöser `xstarterconnector.ticket.closed` anlegen, zum Beispiel mit der Bedingung `data.priority` gleich `high` und der Aktion „Ticket: Vermerk setzen“.
4. Das Demo-Ereignis auslösen: `wp eval 'do_action( "wfc_starter_ticket_closed", 42, "high" );'`
5. Nach dem nächsten Worker-Lauf (`wp workflow-captain process`) steht der Lauf unter **Läufe**, der Vermerk in der Option `wfc_starter_connector_notes`.

## Regeln, die das Beispiel einhält

- Die Nutzdaten entstehen aus einer festen Liste (R-5.2). Workflow Captain baut sie zusätzlich aus dem Manifest neu auf: Nicht angemeldete Felder fallen weg, ein falscher Datentyp lehnt das Ereignis ab.
- Der Callback fängt jede Ausnahme ab und schreibt genau einmal (R-7.6). Der Diskriminator `once` macht einen doppelten Aufruf zum Duplikat.
- Die Aktion prüft den Idempotenzschlüssel selbst und meldet deshalb `idempotent() = true`. Ihre Berechtigung ist die, die ein Mensch für dieselbe Handlung bräuchte (`manage_options`).
- Personenbezogene Felder gibt es hier nicht. Hätte das Manifest welche (Datenklasse `personal`), wären sie ab Werk aus und müssten einzeln freigegeben werden.
- Keine Lizenzprüfung durch Workflow Captain (R-2.4). Ein echtes Projektprodukt prüft seine eigene Lizenz.

## Test

`php tests/run.php` im Repository von Workflow Captain lädt die Klassen dieses Beispiels und prüft es Ende zu Ende (`tests/connector-tests.php`, Abschnitt „starter“).
