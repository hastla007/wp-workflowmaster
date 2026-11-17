# Praxisbeispiele für eigene Connectoren

Stand: 16.11.2026, Plugin 0.2.x (mit eigenen Connectoren nach F-22). Aufgabe WF-CONNECTOR-14 in [`../TODO.md`](../TODO.md). Gehört zu [`CONNECTOR-CONTRACT.md`](CONNECTOR-CONTRACT.md) und [`SDK.md`](SDK.md).

## Die eine Regel

**Der Connector besitzt die Fachlogik. Workflow Captain koordiniert nur.**

| Aufgabe | Wer |
|---|---|
| Entscheiden, ob ein Kunde Zugang bekommt, eine Buchung gültig ist oder ein CRM-Kontakt angelegt wird | Connector (bzw. das Projekt dahinter) |
| Daten des Projekts lesen und schreiben, eigene Tabellen, eigene Lizenz | Connector |
| Idempotenz: denselben Befehl zweimal ausführen, ohne doppelt zu wirken | Connector, mit `ActionContext::$idempotency_key` |
| Personenbezogene Daten auflösen (Adresse, Name, Konto) | Connector, erst bei der Ausführung, nie im Ereignis |
| Auslöser, Bedingungen, Wartezeiten, Wiederholung, Schleifenschutz, Protokoll, Rechteprüfung des Veröffentlichenden | Workflow Captain |

Ein Workflow ist damit eine **Abfolge von Aufrufen**, nie der Ort, an dem Geschäftsregeln stehen. Steht eine Regel nur im Workflow, kann der Betreiber sie versehentlich löschen. Steht sie im Connector, gilt sie auch ohne Workflow.

## Was heute schon geht

- **Eigene Ereignisse eines Connectors gehen.** Der Connector meldet sich mit Manifest an `workflow_captain_register_connectors_v1` an und gibt Ereignisse mit dem Präfix `x…` über `Consumer::capture()` ab (F-22, [`CONNECTOR-CONTRACT.md`](CONNECTOR-CONTRACT.md)). Der Kern baut `data` aus den Feldern des Manifests neu auf; personenbezogene Felder sind ab Werk aus.
- **Aktionen eines Connectors gehen.** Er meldet sie an `workflow_captain_actions_v1` an (Beispiel unten) und nennt ihre Schlüssel im Manifest unter `actions`. Der Builder bietet sie an, die Engine prüft die Berechtigung des Veröffentlichenden vor jeder Ausführung (R-8.6 bis R-8.8) und bucht den Seiteneffekt (R-8.12).
- **Beides erst nach der Freigabe.** Bis der Betreiber den Connector unter „Eigene Connectoren“ freigibt, speichert Workflow Captain keine seiner Ereignisse und bietet keine seiner Aktionen an.
- Ein lauffähiges Beispiel mit einem Ereignis und einer Aktion ist [`../examples/starter-connector/`](../examples/starter-connector/).

## Beispiel 1: Membership-Zugang nach Gutschein-Einlösung

**Ziel:** Wer einen bestimmten Gutschein einlöst, bekommt 30 Tage Zugang zum Mitgliederbereich.

- **Auslöser:** `voucher.voucher.redeemed` (Voucher Captain, über den Adapter aus Workflow Captain Pro). Felder: `order_id`, `amount_minor`, `currency`, `channel`, `redemption_source`, `committed_at`, `balance_after_minor`.
- **Bedingung im Workflow:** zum Beispiel keine Einlösungen am Schalter (`data.channel` ungleich `counter`). Welcher Gutschein zu welchem Plan gehört, steht **nicht** im Workflow, sondern im Connector (Zuordnung Gutscheinprodukt → Plan).
- **Aktion des Connectors:** `xmembership.access.grant` mit der Konfiguration `days`.
  - Der Connector löst über `order_id` selbst den Kunden auf. Das Ereignis enthält bewusst keinen Namen und keine Adresse.
  - Er schreibt den Zugang mit dem Idempotenzschlüssel. Ein zweiter Aufruf mit demselben Schlüssel verlängert nicht noch einmal.
  - Er gibt als Ergebnis nur `plan` und `until` zurück, keine Kundendaten.
- **Berechtigung:** `capability()` liefert die Berechtigung, die ein Mensch für dieselbe Freischaltung bräuchte, zum Beispiel `edit_users`. Verliert der Veröffentlichende sie, pausieren die Läufe (R-8.8).

```php
final class Membership_Grant implements \WorkflowCaptain\Engine\Action {
	public function key(): string { return 'xmembership.access.grant'; }
	public function label(): string { return __( 'Mitgliederzugang freischalten', 'my-connector' ); }
	public function capability(): ?string { return 'edit_users'; }
	public function idempotent(): bool { return true; } // prüft den Schlüssel selbst
	public function validate_config( array $config ): array {
		$days = $config['days'] ?? null;
		return is_numeric( $days ) && $days >= 1 && $days <= 366 ? array() : array( 'days:invalid' );
	}
	public function execute( \WorkflowCaptain\Engine\ActionContext $c ): array {
		$order_id = (int) ( $c->envelope['data']['order_id'] ?? 0 );
		$result   = My_Membership::grant_for_order( $order_id, (int) $c->config['days'], $c->idempotency_key );
		if ( is_wp_error( $result ) ) {
			throw 'temporary' === $result->get_error_code()
				? new \WorkflowCaptain\Consumer\TransientFailure( 'membership_unavailable' )
				: new \WorkflowCaptain\Consumer\PermanentFailure( 'membership_' . $result->get_error_code() );
		}
		return array( 'plan' => $result['plan'], 'until' => $result['until'] );
	}
}
add_action( 'workflow_captain_actions_v1', fn( $registry ) => $registry->add( new Membership_Grant() ) );
```

## Beispiel 2: Kundenportal oder CRM

**Ziel:** Nach einer bezahlten Bestellung entsteht im CRM eine Aktivität. Wird später erstattet, bekommt sie die Markierung „erstattet“.

- **Auslöser:** `pass.order.paid` und `pass.order.refunded`, ein Workflow je Auslöser.
- **Aktionen des Connectors:** `xcrm.activity.upsert` (Bestellnummer, Art der Aktivität) und `xcrm.activity.tag`.
- **Fachlogik im Connector:** Kontakt suchen oder anlegen, Zuordnung Kunde ↔ Kontakt, Feldzuordnung, Zugangsdaten des CRM (im eigenen Tresor des Connectors oder später im Tresor von Workflow Captain, WF-SEC-02).
- **Ausgehende Aufrufe:** Ruft der Connector ein externes CRM auf, gelten für ihn dieselben Regeln wie für Webhooks: nur HTTPS, keine privaten Adressen, Zeitlimit (`WorkflowCaptain\Security\OutboundPolicy` kann er dafür nutzen).
- **Wiederholung:** Meldet das CRM „vorübergehend nicht erreichbar“, wirft die Aktion `TransientFailure`; die Engine wiederholt nach Plan. „Kontakt gesperrt“ ist `PermanentFailure` und landet als „Aktion erforderlich“ beim Betreiber.
- **Kein Spiegel:** Das CRM ist nicht die Wahrheit über Zahlungen. Der Connector schreibt nur, was das Ereignis belegt.

## Beispiel 3: Kundenspezifische Buchung

**Ziel:** Ein Kunde betreibt ein eigenes Buchungssystem für Bootsliegeplätze. Wird ein Termin in Pass Captain abgesagt, sollen die zugehörigen Liegeplatzbuchungen freigegeben werden.

- **Auslöser:** `pass.occurrence.cancelled`.
- **Aktion des Connectors:** `xberth.booking.release` mit dem Termin als Bezug.
- **Fachlogik im Connector:** Welche Buchung zu welchem Termin gehört, Stornoregeln, Gebühren, Benachrichtigung der Kunden des Buchungssystems. Workflow Captain kennt keine Liegeplätze.
- **Eigenes Ereignis (seit F-22):** Das Buchungssystem meldet selbst `xberthbooking.booking.confirmed`. Ein Workflow wartet dann zum Beispiel 2 Tage vor dem Termin und ruft `notify.user` auf.

## Beispiel 4: Benutzerdefinierte Benachrichtigung

**Ziel:** Bei einer abgelehnten Erstattung soll eine Nachricht in den internen Chat des Teams gehen statt per Mail.

- **Auslöser:** `pass.refund.rejected`.
- **Aktion des Connectors:** `xteamchat.message.post` mit Kanal und Text. Der Text darf Platzhalter wie `{{subject.id}}`, `{{data.order_id}}` und `{{data.amount_minor}}` nutzen; Personendaten gibt es darin nicht, weil das Ereignis keine enthält.
- **Wichtig:** `idempotent()` liefert `false`, wenn der Chat keinen Idempotenzschlüssel kennt. Dann wiederholt die Engine nach einem unklaren Ausgang nie von selbst, sondern fragt den Betreiber (R-8.12). Doppelte Chatnachrichten sind so ausgeschlossen, verlorene werden sichtbar.
- **Mails der Kerne:** Eine Chatnachricht ersetzt keine Kundenmail eines Kerns. Workflow Captain schaltet keine Mail ab (R-9.2).

## Prüfliste für jeden Connector

1. Jede Regel, die auch ohne Workflow gelten muss, steht im Connector.
2. Jede Aktion prüft den Idempotenzschlüssel oder meldet `idempotent() = false`.
3. Personendaten werden erst bei der Ausführung im Connector aufgelöst und nie als Ergebnis zurückgegeben.
4. `capability()` entspricht der Berechtigung, die ein Mensch für dieselbe Handlung bräuchte.
5. Vorübergehende Fehler werfen `TransientFailure`, fachliche Ablehnungen `PermanentFailure` mit einem kurzen Code ohne Werte.
6. Der Connector prüft seine eigene Lizenz; Workflow Captain prüft keine (Entscheidung 17, R-2.4).
7. Er funktioniert weiter, wenn Workflow Captain fehlt: Die Anmeldung an `workflow_captain_actions_v1` ist dann einfach wirkungslos.
