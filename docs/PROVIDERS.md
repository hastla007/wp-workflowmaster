# Anbieterübersicht

Stand: 16.11.2026 (Abend), Plugin 0.2.x, Workflow Captain Pro 0.1.0 (Entwicklungsstand), Spezifikation 1.0.0-draft.7. Für Betreiber, Vertrieb und Support. Technische Einzelheiten: [`SPEC-suite-events.md`](SPEC-suite-events.md), Versionen: [`KOMPATIBILITAET.md`](KOMPATIBILITAET.md), Ereignisse und Felder: [`catalog/suite-events-v1.json`](catalog/suite-events-v1.json).

## Wer liefert was

Ein **Anbieter** (Provider) ist ein Plugin, dessen Ereignisse Workflows auslösen oder dessen Funktionen ein Workflow aufrufen kann. Workflow Captain selbst kennt keinen Anbieter fest. Ein Anbieter wird erst sichtbar, wenn sich sein **Adapter** anmeldet (Abschnitt 3 der Spezifikation).

| Anbieter | Auslöser (Ereignistypen) | Befehle für Workflows | Adapter | Stand |
|---|---|---|---|---|
| Workflow Captain (eingebaut) | Zeitplan (`workflow.schedule.due`: täglich, wöchentlich, monatlich in der Zeitzone der Website; ein verpasster Termin wird höchstens einmal nachgeholt) | Nachricht an Betreiber, Nachricht an Benutzer, Markierung setzen und entfernen, Notiz anlegen | im Kern | gebaut |
| Eingehende Auslöser | `workflow.inbound.<auslöser>` mit typisierten Feldern, die ein Add-on am Filter `workflow_captain_inbound_triggers_v1` anmeldet; der Kern prüft Namen, Typen und verbotene Felder noch einmal | – | im Kern (`src/Engine/InboundTriggers.php`); die Annahme von außen (signierte Webhooks, n8n) bringt Workflow Captain Pro | gebaut |
| WordPress | 2: Benutzer registriert (`wordpress.user.registered`), Beitrag veröffentlicht (`wordpress.post.published`, nur öffentliche Inhaltstypen) | – | im Kern (`src/Providers/WordPressAdapter.php`), ohne Lizenz, ab WordPress 7.0 (MINVER-1) | gebaut (F-20) |
| WooCommerce | 5: Bestellung angelegt, bezahlt, Statuswechsel, erstattet, storniert (`woocommerce.order.*`); nur Status, Beträge in kleinster Einheit der Währung nach ISO 4217 (JPY ohne, KWD mit drei Nachkommastellen; nicht die Preis-Dezimalstellen des Shops, `src/Money.php`), Währung, Kennungen, nie Adressen, E-Mail, Namen, IP, Kundennotiz oder Kupon-Codes | – | im Kern (`src/Providers/WooCommerceAdapter.php`), ohne Lizenz, nur wenn WooCommerce 11.2.2 oder neuer aktiv ist (MINVER-1; ältere Fassung: „abgelehnt – provider_too_old“) | gebaut (F-20) |
| Pass Captain | 13: Bestellung bezahlt, Tickets ausgestellt, storniert, erstattet, Erstattung abgeschlossen oder abgelehnt, Ticket widerrufen, Ein- und Auslass, Termin verschoben oder abgesagt, Veranstaltung abgesagt, Rechnung erstellt | Lesen (8.1) | Workflow Captain Pro (`PassAdapter`), dazu Pass Captain Pro | gebaut (Entwicklungsstand), Vertrag ab 1.1.0, Lesefassade ab 1.2.0 |
| Voucher Captain | 16: Gutscheine ausgestellt, zugestellt, Zustellung fehlgeschlagen, Rückläufer, eingelöst, Reservierung, Freigabe, Erstattung, Storno, abgelaufen, neu ausgestellt, ungültig, reaktiviert, Guthaben, Aufladung | Lesen; Gutschein ausstellen, sperren, verlängern, gutschreiben (8.1, braucht eine neue Version von Voucher Captain) | Workflow Captain Pro (`VoucherAdapter`), dazu Voucher Captain Pro | Adapter gebaut (Entwicklungsstand); Einstiegspunkte der Befehle seit Voucher Captain 1.3.0 (Vertrag 1.3.0), die vier Befehle als Workflow-Aktionen in Pro (`VoucherCommand`) |
| Affiliate Captain | 21: Partner angelegt, Statuswechsel, geworben, gelöscht, Verkauf zugeordnet, Provisionen (angelegt, geprüft, freigegeben, abgelehnt, bezahlt, angepasst, erneut geprüft), Auszahlungen, Korrekturen, Werbemittel, Code-Anfragen | Lesen | Workflow Captain Pro (`AffiliateAdapter`), dazu Affiliate Captain Pro | gebaut (Entwicklungsstand), Vertrag ab 1.4.0 |
| Lizenzserver | 3: Lizenz ausgestellt, Statuswechsel, Plan gewechselt | Lesen | Workflow Captain Pro (`LicenseAdapter`), nur im Shop des Anbieters | gebaut (Entwicklungsstand); `WPLC_CONTRACT_VERSION` 1.0.0 und `LicenseReader` auf einem Branch des Lizenzservers |
| Form Captain | 8: Formular angelegt, geändert, Einträge importiert; Eintrag angelegt, Statuswechsel, gelöscht, Einwilligung widerrufen, Benachrichtigung fehlgeschlagen (`form.*`, 4.6.1). Antworten nur, wenn der Betreiber das Feld freigibt, und nur Auswahl, Zahl oder Einwilligung, nie Kontaktdaten oder Freitext (4.6.2) | Lesen | Workflow Captain Pro; braucht zusätzlich Form Captain Pro (gibt es noch nicht) | Katalog seit Spezifikation 1.0.0-draft.7; Adapter gebaut, bleibt bis Form Captain Pro aus |
| Review Captain | 3: Bewertung angelegt, Statuswechsel (Moderation), entfernt (`review.*`, 4.6.3). Art, Plattform, Herkunft, Status, Verifizierung, Kontext und Sterne; nie Name, E-Mail-Adresse, Titel oder Text | Lesen (Zähler); Befehle `review.request.plan`/`.cancel` über den Planer von Review Captain Pro (F-23 = ja, 23.11.2026; R-4.30) | Workflow Captain Pro; braucht zusätzlich Review Captain Pro (noch nicht freigegeben) | Katalog seit Spezifikation 1.0.0-draft.8; Adapter gebaut, bleibt bis Review Captain Pro aus |
| Course Captain | keine (4.6.4) | – | – | später |
| Eigene Connectoren und Kundenconnectoren | eigene Ereignisse nach dem öffentlichen Vertrag | eigene | eigene Plugins, die sich am Kern anmelden; zur Laufzeit braucht es Workflow Captain Pro (Filter `workflow_captain_connector_runtime_allowed_v1`, F-18a); die eigene Lizenz prüft jeder Connector selbst (Entscheidung 17) | gebaut; Vertrag in [`CONNECTOR-CONTRACT.md`](CONNECTOR-CONTRACT.md), Freigabe je Connector unter „Eigene Connectoren“ |

## Was der Betreiber sieht

- **Workflow Captain → Provider** listet angemeldete Adapter mit Produkt, Vertragsversion und Zahl gespeicherter Ereignisse.
- Fehlt ein Adapter (Add-on deaktiviert, Lizenz abgelaufen, Kern zu alt), werden neue Ereignisse dieses Anbieters als `paused` (`adapter_unregistered`) aufbewahrt und **nicht** von selbst nachgeholt (R-10.8). Workflows mit diesem Auslöser zeigen „Provider nicht verfügbar“ und lassen sich nicht veröffentlichen.
- Der Builder zeigt am Nachrichten-Knoten, welche Mail das Kern-Plugin zum selben Ereignis ohnehin verschickt (R-9.4, `assets/data/core-mails.json`). Workflow Captain schaltet keine Mail eines Kerns ab (R-9.2).

## Was nie übertragen wird

Ereignisse enthalten Kennungen, Status, Beträge und Zeitpunkte, nie Gutschein-, Ticket- oder Lizenzcodes, E-Mail-Adressen, Namen oder Freitext (R-5.x). Der Kern prüft das bei der Annahme und lehnt Umschläge mit solchen Feldern ab.

## Mindeststände der Kerne

Ein Adapter meldet sich nur an, wenn der Kern die nötige Vertragsversion hat. Die Stände auf `main` von Pass Captain und Voucher Captain genügen dafür **nicht**, die Branches `claude/plugin-todo-liste-2a83v4` (Pass 1.6.0, Voucher 1.3.0, Affiliate 0.15.0) genügen; Einzelheiten in [`KOMPATIBILITAET.md`](KOMPATIBILITAET.md).
