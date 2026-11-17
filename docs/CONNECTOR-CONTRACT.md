# Öffentlicher Connector-Vertrag v1

Stand: 16.11.2026. Entschieden mit F-22 (Spezifikation [4.8](SPEC-suite-events.md), R-4.10 bis R-4.13), gebaut mit den Aufgaben WF-CONNECTOR-01 bis -05, -10 und -11 in [`../TODO.md`](../TODO.md). Code: `src/Connector/`, Admin-Seite `src/Admin/ConnectorsPage.php`, Beispiel [`../examples/starter-connector/`](../examples/starter-connector/), Tests `tests/connector-tests.php`.

## Wozu

Ein Connector bindet ein Projekt an Workflow Captain an, das kein Captain-Produkt ist. Beispiele sind die eigenen Projektprodukte für BoatingSchool und LearnBoating (Entscheidung 17) oder das Kundenportal eines Betreibers. Ein Connector ist ein eigenes Plugin. Er adaptiert nur die öffentlichen Hooks und APIs seines Projekts und besitzt dessen Fachlogik. Workflow Captain koordiniert nur (WF-CONNECTOR-04, -14, Beispiele in [`CONNECTOR-BEISPIELE.md`](CONNECTOR-BEISPIELE.md)).

## Kennung und Schema

- **Schlüssel:** Kleinbuchstaben, Ziffern und Bindestrich, 2 bis 40 Zeichen, nicht mit Bindestrich beginnend, kein Schlüssel eines Captain-Produkts, nicht `wordpress` oder `woocommerce` (`Validator::is_connector()`). Der Schlüssel ist `source.product`.
- **Präfix der Ereignistypen:** `x` und der Schlüssel ohne Bindestriche (`Validator::connector_prefix()`), zum Beispiel `learnboating-connector` → `xlearnboatingconnector.entitlement.changed`. Typ und Produkt müssen zusammenpassen, sonst lehnt der Consumer ab (`type:product_mismatch`).
- **Aktionen** tragen denselben Präfix, zum Beispiel `xlearnboatingconnector.access.grant`.
- Alle übrigen Regeln gelten unverändert: Umschlag (6), verbotene Werte (5), Idempotenz (7.3), Zustellung und Schleifenschutz (7.6).

## Anmeldung

Ein Connector meldet sich an der Action `workflow_captain_register_connectors_v1` an. Sie feuert auf `init` mit Priorität 20, nach den Adaptern, und übergibt die `WorkflowCaptain\Connector\ConnectorRegistry`:

```php
add_action( 'workflow_captain_register_connectors_v1', function ( $registry ) {
	$adapter = new My_Connector_Adapter(); // implementiert WorkflowCaptain\Adapter\Adapter
	if ( $registry->add( My_Connector::manifest(), $adapter ) ) {
		$adapter->listen(); // eigene Hooks erst nach erfolgreicher Anmeldung
	}
} );
add_action( 'workflow_captain_actions_v1', fn( $registry ) => $registry->add( new My_Connector_Action() ) );
```

- `ConnectorRegistry::add( array $manifest, Adapter $adapter ): bool` prüft das Manifest und die Versionen. `product()` des Adapters muss gleich `key` sein. `needs_reread()` und `reread()` kommen vom Adapter; ein Typ mit `after_commit: false` wird immer gegengelesen (R-7.4).
- Bei Erfolg trägt die Registry eine Hülle (`ConnectorAdapter`) über den internen Weg `AdapterRegistry::add_connector()` beim Consumer ein. `AdapterRegistry::add()` lehnt Connector-Schlüssel weiter ab (`unknown_product`).
- `add()` liefert `true`, wenn der Connector angenommen ist. Er bleibt trotzdem aus, bis der Betreiber ihn freigibt (siehe unten).
- Ereignisse gibt der Connector wie jeder Adapter über `Consumer::capture( $adapter, $event )` ab (`WorkflowCaptain\Plugin::instance()->consumer()`).

## Manifest

Geprüft von `WorkflowCaptain\Connector\Manifest::violations()`. Das Ergebnis sind Fehlercodes ohne Werte.

| Feld | Pflicht | Inhalt |
|---|---|---|
| `key` | ja | Schlüssel nach der Regel oben |
| `name` | ja | Anzeigename, höchstens 80 Zeichen |
| `version` | ja | Plugin-Version des Connectors (SemVer, Zusatz wie `-beta.1` erlaubt); wird `source.core_version` |
| `contract_version` | ja | Version des eigenen Vertrags des Connectors (`MAJOR.MINOR.PATCH`); wird `source.contract_version` |
| `requires` | ja | `workflow_captain`: Mindestversion von `WORKFLOW_CAPTAIN_CONTRACT_VERSION` (`MAJOR.MINOR.PATCH`). Weitere Schlüssel (zum Beispiel das eigene Projekt) sind erlaubt und werden nur auf ihre Form geprüft |
| `events` | ja | Objekt `Typ → Angaben`, höchstens 50 Typen; jeder Typ beginnt mit dem Präfix des Schlüssels |
| `events.<typ>.fields` | ja | Objekt `Feld → { "type": …, "class": … }`, höchstens 24 Felder. `type`: `integer`, `number`, `boolean`, `string`, `currency`, `datetime` (wie `data_types` im Katalog). `class`: `normal` oder `personal`. Feldnamen nach dem Schema (`^[a-z][a-z0-9_]*$`), keine verbotenen Namen nach Abschnitt 5 (`Validator::FORBIDDEN_DATA_NAME`) |
| `events.<typ>.after_commit` | ja | `true`, wenn der Hook belegt nach dem Commit feuert; sonst wird gegengelesen |
| `events.<typ>.actor` | ja | Bedeutung von `actor_user_id` für diesen Typ (Text, leer erlaubt) |
| `actions` | nein | Liste der Aktionsschlüssel (höchstens 50), jeder mit dem Präfix und genau zwei weiteren Teilen |
| `description` | nein | Kurzbeschreibung, höchstens 500 Zeichen |

Weitere Felder auf der obersten Ebene lehnt die Prüfung ab (`manifest:unknown_field`). Berechtigung (R-8.6), Idempotenz (R-8.4), Konfigurationsschema und Fehlercodes einer Aktion stehen nicht im Manifest, sondern in der Aktion selbst (`WorkflowCaptain\Engine\Action`).

## Prüfung und Gründe (WF-CONNECTOR-03)

Passt etwas nicht, bleibt der Connector aus, und die Admin-Seite „Eigene Connectoren“ zeigt den Grund. Andere Connectoren und Adapter laufen weiter.

| Grund | Bedeutung |
|---|---|
| `invalid_manifest` | Das Manifest verletzt eine Regel oben, oder `product()` des Adapters ist nicht `key` (`adapter:product_mismatch`); die Fehlercodes stehen dabei |
| `requires_newer_core` | `requires.workflow_captain` ist höher als `WORKFLOW_CAPTAIN_CONTRACT_VERSION` |
| `contract_major` | `requires.workflow_captain` hat eine andere Hauptversion als `WORKFLOW_CAPTAIN_CONTRACT_VERSION` |
| `duplicate` | Der Schlüssel ist schon angemeldet; die erste Anmeldung bleibt gültig |
| `not_enabled` | angenommen, aber noch nicht freigegeben |

## Freigabe durch den Betreiber (WF-CONNECTOR-11)

- Ein Connector ist **aus**, bis der Betreiber ihn unter **Workflow Captain → Eigene Connectoren** freigibt. Die Seite braucht das Recht `manage_workflow_integrations`; Administratoren bekommen es bei der Aktivierung, anderen Rollen gibt man es unter „Rollen & Rechte“. Jede Änderung landet im Protokoll der sicherheitsrelevanten Änderungen.
- Gespeichert wird in der Option `workflow_captain_connectors`: `Schlüssel → { "enabled": bool, "personal_fields": { Typ: [Feld, …] } }`.
- Bis zur Freigabe lehnt der Consumer jedes Ereignis des Connectors beim Erfassen ab (Grund `connector:not_enabled`) und speichert nichts. Seine Ereignistypen erscheinen nicht im Builder, seine Aktionen werden nicht angeboten, ein Workflow mit ihnen lässt sich nicht veröffentlichen, und ein Schritt mit ihnen pausiert (`action_unavailable`).
- Wird ein Connector nach dem Speichern eines Ereignisses abgeschaltet, pausiert das Ereignis (`connector_not_enabled`) und kann nach der erneuten Freigabe wieder eingereiht werden. Fehlt ein Connector, der beim letzten Aufruf angemeldet war, pausieren seine offenen Ereignisse (R-10.7). Gelöscht wird nichts.
- Felder der Datenklasse `personal` sind auch nach der Freigabe aus. Der Betreiber schaltet sie einzeln je Ereignistyp ein.

## Was der Kern beim Erfassen erzwingt (WF-CONNECTOR-04)

`Consumer::capture()` schickt jedes Ereignis eines Connector-Schlüssels durch die Hülle, die die Registry angemeldet hat, gleich welches Adapter-Objekt der Connector übergibt. So lässt sich die Prüfung nicht umgehen:

1. Nicht angemeldeter Schlüssel: abgelehnt (`connector:not_registered`).
2. Nicht freigegeben: abgelehnt (`connector:not_enabled`).
3. Typ nicht im Manifest: abgelehnt (`connector:undeclared_type`).
4. `data` wird aus den Feldern des Manifests neu aufgebaut (Positivliste, R-5.2). Nicht angemeldete Felder fallen weg, personenbezogene ohne Freigabe ebenso. Ein Wert mit falschem Datentyp lehnt das Ereignis ab (`connector:field_type`).
5. Danach gelten Schema und Validator wie für jedes Ereignis (R-5.5), einschließlich `type:product_mismatch`.

Abgelehnte Ereignisse zählen in der Diagnose (`rejected_envelopes`), nur mit den Codes, nie mit Werten.

## Builder (WF-CONNECTOR-10)

Die Ereignistypen freigegebener Connectoren stehen in der Auswahl der Auslöser, ihre Felder mit Datentyp in `config.fields` wie die Felder des Katalogs (personenbezogene nur nach Freigabe). Aktionen eines Connectors sind gewöhnliche `Action`-Objekte an `workflow_captain_actions_v1`. Eine Aktion mit dem Präfix `x` ist nur verfügbar, solange ein angemeldeter, freigegebener Connector mit diesem Präfix sie in `actions` nennt (`ActionRegistry::set_availability()`).

## Lizenz

Der Kern prüft keine Lizenz (R-2.4). Ein Projektprodukt prüft seine eigene Lizenz (Entscheidung 17). Ob die Ausführung eigener Connectoren zusätzlich Workflow Captain Pro voraussetzt, ist weiter F-18a.

## Starter-Connector (WF-CONNECTOR-05)

[`../examples/starter-connector/`](../examples/starter-connector/) ist ein eigenständiges Plugin `wfc-starter-connector` (Schlüssel `starter-connector`) mit dem Ereignis `xstarterconnector.ticket.closed`, der idempotenten Aktion `xstarterconnector.ticket.note`, dem Manifest und einer Anleitung. Es wird nicht mit Workflow Captain ausgeliefert. `tests/connector-tests.php` prüft es Ende zu Ende: Anmeldung, Ablehnung vor der Freigabe, Erfassen, Workflow-Lauf und Idempotenz der Aktion.
