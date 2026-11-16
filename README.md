# Workflow Captain

Dieses Repository gehört zum künftigen kostenlosen Kern-Plugin **Workflow Captain** (`workflow-captain`), dem Automatisierungsbaukasten der Captain Suite. Das bezahlte Add-on `workflow-captain-pro` entsteht später im Repository `captain-pro`.

## Stand

**Konzept, Spezifikation und erster Code.** Abweichend von D12 wird seit dem 16.11.2026 schon vor dem Verkaufsstart der drei vorhandenen Add-ons gebaut. Vorhanden sind im kostenlosen Kern (Plugin 0.2.0) der Consumer (Ereignisspeicher, Wiederholung, Schleifenschutz, Anmeldeschnittstelle für Adapter) und die Workflow-Engine (Workflows mit unveränderlichen Versionen, Bedingungen, Wartezeiten, Läufe und Schritte mit sicherer Wiederholung, Aktionen `notify.admin`, `notify.user`, Markierungen und Notizen), dazu Datenschutz und Admin-Seiten. Workflows werden bis zum visuellen Builder als JSON bearbeitet. Adapter für die Captain-Produkte fehlen noch (sie gehören nach `captain-pro`).

## Inhalt

| Datei | Zweck |
|---|---|
| [`docs/SPEC-suite-events.md`](docs/SPEC-suite-events.md) | **Normativ.** Die eine gemeinsame Spezifikation dafür, wie die Plugins der Captain Suite zusammenarbeiten: Ereignisse, Umschlag, Zustellung, Befehle, Lizenzen. Version 1.0.0-draft.5, Entwurf zur Abnahme. |
| [`docs/SPEC-open-questions.md`](docs/SPEC-open-questions.md) | Offene Fragen zur Spezifikation mit Empfehlung und die Widersprüche zwischen den Entscheidungen. |
| [`docs/schema/suite-event-v1.schema.json`](docs/schema/suite-event-v1.schema.json) | JSON Schema des Umschlags. |
| [`docs/examples/`](docs/examples/) | Beispiel-Umschläge, je Ereignistyp von Pass und Voucher Captain einer. |
| [`docs/catalog/suite-events-v1.json`](docs/catalog/suite-events-v1.json) | Maschinenlesbarer Ereigniskatalog (Typ, Hook, erlaubte Felder, Bedeutung von `actor_user_id`); Vorstufe der Providerregistry (WF-INT-01). |
| [`konzept.md`](konzept.md) | Produktkonzept. Wo es der Spezifikation widerspricht, gilt die Spezifikation; überholte Stellen sind gekennzeichnet. |
| [`workflow-captain.php`](workflow-captain.php), [`src/`](src/) | Plugin `workflow-captain` 0.2.0: Consumer (`src/Consumer`, `src/Store`, `src/Envelope`), Workflow-Engine (`src/Engine`), Adapter-Schnittstelle (`src/Adapter`), Datenschutz, Admin-Seiten „Ereignisse“, „Workflows“, „Läufe“. |
| [`tests/`](tests/) | `php tests/run.php` (Tests ohne WordPress, SQLite) und `php tests/license-guard.php` (Wächtertest „kein Lizenzcode im Kern“). |
| [`TODO.md`](TODO.md) | Umsetzungsliste. |
| [`docs/recipes/`](docs/recipes/) | Importierbare Beispiel-Workflows, im Test gegen Graph-Validierung und Katalog geprüft. |
| [`docs/n8n/`](docs/n8n/) | Entwürfe von n8n-Vorlagen ohne Zugangsdaten (WF-N8N-10). |
| [`docs/TESTMATRIX.md`](docs/TESTMATRIX.md) | Was automatisch getestet ist und was echtes WordPress, WooCommerce oder Adapter braucht. |
| [`docs/OPTIONS.md`](docs/OPTIONS.md) | Optionen, Tabellen, Datenflächen und Änderungssemantik. |
| [`docs/RELEASE-CHECKLIST.md`](docs/RELEASE-CHECKLIST.md) | Release-Gate des kostenlosen Kerns. |
| [`docs/RUNBOOK.md`](docs/RUNBOOK.md) | Betrieb und Wiederherstellung: typische Lagen, Backup, Grenzen. |
| [`docs/SDK.md`](docs/SDK.md) | Entwickler-SDK: Adapter, Aktionen, Workflow-Format, Hooks, Datenfluss, Betrieb. |
| [`docs/CONNECTOR-CONTRACT.md`](docs/CONNECTOR-CONTRACT.md) | Entwurf des öffentlichen Connector-Vertrags für eigene Projekte (wartet auf F-22). |
| [`docs/KOMPATIBILITAET.md`](docs/KOMPATIBILITAET.md) | Entwurf der Kompatibilitätsmatrix: welche Stände der Kerne die Spezifikation voraussetzt. |
| [`docs/ANFORDERUNGEN-andere-repos.md`](docs/ANFORDERUNGEN-andere-repos.md) | Anforderungen der Spezifikation an die anderen Repositories, fertig zum Anlegen dort (WF-SPEC-14). |
| [`docs/UEBERGABE-E17-kundenconnectoren.md`](docs/UEBERGABE-E17-kundenconnectoren.md) | Übergabe der Aufgaben der Kundenconnectoren BoatingSchool und LearnBoating an die Projekt-Repositories (Entscheidung 17). |

Andere Repositories der Suite verweisen für Ereignisnamen, Umschlag und Regeln auf `docs/SPEC-suite-events.md` und legen dafür nichts Eigenes fest.

## Schema und Beispiele prüfen

```bash
pip install jsonschema   # nicht Teil des Repositorys
python3 bin/validate-spec-examples.py
```

Dieselbe Prüfung läuft in GitHub Actions (`.github/workflows/validate-spec.yml`), sobald Schema, Beispiele oder Skript sich ändern.

Das Skript prüft, dass das Schema gültig ist, dass jedes Beispiel dazu und zum Katalog passt, dass es für jeden Typ von Pass und Voucher Captain ein Beispiel gibt und dass 20 Gegenbeispiele (zum Beispiel ein Code oder eine E-Mail-Adresse in den Nutzdaten) abgelehnt werden.
