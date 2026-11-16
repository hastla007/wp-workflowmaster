# Workflow Captain – Entwickler-SDK

Stand: Plugin 0.2.0, Spezifikation [`SPEC-suite-events.md`](SPEC-suite-events.md) 1.0.0-draft.6. Die Schnittstellen sind vor Version 1.0.0 der Spezifikation **nicht stabil** (R-13.2); ihre Version steht in `WORKFLOW_CAPTAIN_CONTRACT_VERSION`.

## 1. Bausteine

| Baustein | Klasse | Aufgabe |
|---|---|---|
| Adapter | `WorkflowCaptain\Adapter\Adapter` | liefert Ereignisse eines Providers an den Consumer; liegt für Captain-Produkte in einem Add-on, nie im Kern (A-1). Eingebaut sind nur die Adapter für WordPress und WooCommerce (`src/Providers`, F-20) |
| Consumer | `WorkflowCaptain\Consumer\Consumer` | baut den Umschlag, speichert einmal je Idempotenzschlüssel, wiederholt, schützt vor Schleifen |
| Engine | `WorkflowCaptain\Engine\Engine` | startet Läufe veröffentlichter Workflows und führt ihre Schritte aus |
| Aktion | `WorkflowCaptain\Engine\Action` | ein Befehl, den ein Schritt ausführt; nur Befehle aus Abschnitt 8.1 der Spezifikation |

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

Bis zum visuellen Editor werden Workflows als JSON bearbeitet (Admin „Workflows“). Format und Regeln stehen in `src/Engine/Graph.php`; Knoten sind `trigger`, `condition`, `action`, `delay` und `end`. Bedingungen: `src/Engine/Conditions.php`. Platzhalter in Texten: `{{type}}`, `{{occurred_at}}`, `{{subject.type}}`, `{{subject.id}}`, `{{source.product}}`, `{{data.<feld>}}`.

## 5. Hooks

| Hook | Art | Zweck |
|---|---|---|
| `workflow_captain_loaded` | Action | Kern ist geladen |
| `workflow_captain_register_adapters_v1` | Action | Adapter anmelden (`init`, Priorität 20). Die eingebauten Adapter `wordpress` und `woocommerce` sind dann schon angemeldet; ein zweiter Adapter für dasselbe Produkt wird abgelehnt (`already_registered`) |
| `workflow_captain_actions_v1` | Action | Aktionen anmelden |
| `workflow_captain_dispatcher` | Filter | eigene Engine statt der des Kerns (selten) |
| `workflow_captain_foreign_runs_v1` | Action | Läufe von einer anderen Website des Netzwerks: Resolver, Gate und Beobachter an `Engine\ForeignRuns` setzen (Abschnitt 5b) |
| `workflow_captain_worker_batch` | Filter | Ereignisse und Schritte je Worker-Durchlauf (1 bis 200, Standard 25) |
| `workflow_captain_retention_days` | Filter | Aufbewahrung nur verkürzen (`finished`, `stuck`) |

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

- `wp workflow-captain status|process|requeue|migrate|diagnostics|verify`
- Auf schwach besuchten Websites einen System-Cron einrichten, der `wp workflow-captain process` jede Minute aufruft.
- Der Diagnose-Export (Seite „Ereignisse“) enthält keine persönlichen Daten und keine Geheimnisse.
