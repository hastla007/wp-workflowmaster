# Workflow Captain

Dieses Repository gehört zum künftigen kostenlosen Kern-Plugin **Workflow Captain** (`workflow-captain`), dem Automatisierungsbaukasten der Captain Suite. Das bezahlte Add-on `workflow-captain-pro` entsteht später im Repository `captain-pro`.

## Stand

**Konzept und Spezifikation. Es gibt noch keinen Code.** Gebaut wird erst nach dem Verkaufsstart der drei vorhandenen Add-ons (Voucher, Pass und Affiliate Captain Pro).

## Inhalt

| Datei | Zweck |
|---|---|
| [`docs/SPEC-suite-events.md`](docs/SPEC-suite-events.md) | **Normativ.** Die eine gemeinsame Spezifikation dafür, wie die Plugins der Captain Suite zusammenarbeiten: Ereignisse, Umschlag, Zustellung, Befehle, Lizenzen. Version 1.0.0-draft.3, Entwurf zur Abnahme. |
| [`docs/SPEC-open-questions.md`](docs/SPEC-open-questions.md) | Offene Fragen zur Spezifikation mit Empfehlung und die Widersprüche zwischen den Entscheidungen. |
| [`docs/schema/suite-event-v1.schema.json`](docs/schema/suite-event-v1.schema.json) | JSON Schema des Umschlags. |
| [`docs/examples/`](docs/examples/) | Beispiel-Umschläge. |
| [`konzept.md`](konzept.md) | Produktkonzept. Wo es der Spezifikation widerspricht, gilt die Spezifikation; überholte Stellen sind gekennzeichnet. |
| [`TODO.md`](TODO.md) | Umsetzungsliste. |
| [`docs/ANFORDERUNGEN-andere-repos.md`](docs/ANFORDERUNGEN-andere-repos.md) | Anforderungen der Spezifikation an die anderen Repositories, fertig zum Anlegen dort (WF-SPEC-14). |
| [`docs/UEBERGABE-E17-kundenconnectoren.md`](docs/UEBERGABE-E17-kundenconnectoren.md) | Übergabe der Aufgaben der Kundenconnectoren BoatingSchool und LearnBoating an die Projekt-Repositories (Entscheidung 17). |

Andere Repositories der Suite verweisen für Ereignisnamen, Umschlag und Regeln auf `docs/SPEC-suite-events.md` und legen dafür nichts Eigenes fest.

## Schema und Beispiele prüfen

```bash
pip install jsonschema   # nicht Teil des Repositorys
python3 bin/validate-spec-examples.py
```

Dieselbe Prüfung läuft in GitHub Actions (`.github/workflows/validate-spec.yml`), sobald Schema, Beispiele oder Skript sich ändern.

Das Skript prüft, dass das Schema gültig ist, dass jedes Beispiel dazu passt und dass 20 Gegenbeispiele (zum Beispiel ein Code oder eine E-Mail-Adresse in den Nutzdaten) abgelehnt werden.
