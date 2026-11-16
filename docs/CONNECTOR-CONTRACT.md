# Öffentlicher Connector-Vertrag v1 (Entwurf)

Stand: 16.11.2026. Aufgaben WF-CONNECTOR-01 bis -03 und -05 in [`../TODO.md`](../TODO.md). **Entwurf:** Er hängt an der offenen Frage F-22 in [`SPEC-open-questions.md`](SPEC-open-questions.md). Bis zur Antwort gibt es keinen Code dafür.

## Wozu

Ein Connector bindet ein Projekt an Workflow Captain an, das kein Captain-Produkt ist. Beispiele sind die eigenen Projektprodukte für BoatingSchool und LearnBoating (Entscheidung 17) oder das Kundenportal eines Betreibers. Ein Connector ist ein eigenes Plugin. Er adaptiert nur die öffentlichen Hooks und APIs seines Projekts und besitzt dessen Fachlogik. Workflow Captain koordiniert nur (WF-CONNECTOR-04, -14).

## Was es schon gibt (Plugin 0.2.0)

- **Ereignisse:** Die Adapter-Schnittstelle (`WorkflowCaptain\Adapter\Adapter`, Action `workflow_captain_register_adapters_v1`, Konstante `WORKFLOW_CAPTAIN_CONTRACT_VERSION`) ist dieselbe Mechanik, die ein Connector braucht: anmelden, Typen nennen, Ereignisse über `Consumer::capture()` abgeben, gegenlesen.
- **Aktionen:** Die Aktions-Schnittstelle (`WorkflowCaptain\Engine\Action`, Action `workflow_captain_actions_v1`) nimmt Befehle eines Connectors auf, mit Berechtigung (R-8.6 bis R-8.8), Idempotenzschlüssel (R-8.4) und Buchung des Seiteneffekts (R-8.12).

Es fehlt nur eines: Das Schema lässt in `type` und `source.product` bisher ausschließlich die Captain-Produkte zu.

## Vorschlag

1. **Kennung:** Jeder Connector hat einen festen Schlüssel aus Kleinbuchstaben, Ziffern und Bindestrich, zum Beispiel `learnboating-connector`. Der Schlüssel ist zugleich `source.product`. Der Präfix der Ereignistypen ist der Schlüssel ohne Bindestriche mit vorangestelltem `x`, zum Beispiel `xlearnboatingconnector.entitlement.changed`. Das `x` hält die Namen der Suite frei.
2. **Schema:** `type` erlaubt zusätzlich `^x[a-z0-9]{2,39}\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$`. `source.product` erlaubt zusätzlich `^[a-z0-9][a-z0-9-]{1,39}$`. Alle übrigen Regeln gelten unverändert: Umschlag, verbotene Werte (Abschnitt 5), Idempotenz, Zustellung und Schleifenschutz. Das ist eine MINOR-Version der Spezifikation.
3. **Manifest:** Ein Connector meldet beim Anmelden:

   | Feld | Inhalt |
   |---|---|
   | `key`, `name`, `version` | Schlüssel, Anzeigename, Plugin-Version |
   | `contract_version` | Version seines eigenen Vertrags (SemVer, R-3.1 sinngemäß) |
   | `requires` | Mindestversion von Workflow Captain (`WORKFLOW_CAPTAIN_CONTRACT_VERSION`) und des eigenen Projekts |
   | `events` | je Typ: erlaubte Felder in `data` mit Datentyp und Datenklasse (normal oder personenbezogen), Bedeutung von `actor_user_id`, „nach Commit“ ja oder nein |
   | `actions` | je Befehl: Schlüssel, Konfigurationsschema, Berechtigung (R-8.6), idempotent ja oder nein, Seiteneffekt, Wiederholungsklasse, Fehlercodes |

4. **Anmeldung und Prüfung (WF-CONNECTOR-03):** Der Kern prüft das Manifest, die Vertragsversion und die Mindestversionen. Passt etwas nicht, bleibt der Connector abgeschaltet und die Admin-Seite „Eigene Connectoren“ zeigt den Grund. Andere Connectoren und Adapter laufen weiter. Felder mit der Datenklasse „personenbezogen“ sind ab Werk aus. Der Betreiber muss sie je Connector einschalten (WF-CONNECTOR-11).
5. **Lizenz:** Der Kern prüft keine Lizenz (R-2.4). Ein Projektprodukt prüft seine eigene Lizenz (Entscheidung 17). Ob die Ausführung eigener Connectoren zusätzlich Workflow Captain Pro voraussetzt, ist F-18a.

## Starter-Connector (WF-CONNECTOR-05)

Folgt nach der Antwort auf F-22. Er wird ein Beispiel-Plugin mit einem Ereignis und einer Aktion, einem Manifest und einem Integrationstest auf Basis von [`../tests/adapter-kit/`](../tests/adapter-kit/).
