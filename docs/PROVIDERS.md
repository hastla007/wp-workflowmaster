# Anbieterübersicht

Stand: 16.11.2026, Plugin 0.2.x, Spezifikation 1.0.0-draft.5. Für Betreiber, Vertrieb und Support. Technische Einzelheiten: [`SPEC-suite-events.md`](SPEC-suite-events.md), Versionen: [`KOMPATIBILITAET.md`](KOMPATIBILITAET.md), Ereignisse und Felder: [`catalog/suite-events-v1.json`](catalog/suite-events-v1.json).

## Wer liefert was

Ein **Anbieter** (Provider) ist ein Plugin, dessen Ereignisse Workflows auslösen oder dessen Funktionen ein Workflow aufrufen kann. Workflow Captain selbst kennt keinen Anbieter fest. Ein Anbieter wird erst sichtbar, wenn sich sein **Adapter** anmeldet (Abschnitt 3 der Spezifikation).

| Anbieter | Auslöser (Ereignistypen) | Befehle für Workflows | Adapter | Stand |
|---|---|---|---|---|
| Workflow Captain (eingebaut) | – | Nachricht an Betreiber, Nachricht an Benutzer, Markierung setzen und entfernen, Notiz anlegen | im Kern | gebaut |
| Pass Captain | 13: Bestellung bezahlt, Tickets ausgestellt, storniert, erstattet, Erstattung abgeschlossen oder abgelehnt, Ticket widerrufen, Ein- und Auslass, Termin verschoben oder abgesagt, Veranstaltung abgesagt, Rechnung erstellt | Lesen (8.1) | Workflow Captain Pro | Adapter offen |
| Voucher Captain | 16: Gutscheine ausgestellt, zugestellt, Zustellung fehlgeschlagen, Rückläufer, eingelöst, Reservierung, Freigabe, Erstattung, Storno, abgelaufen, neu ausgestellt, ungültig, reaktiviert, Guthaben, Aufladung | Lesen; Gutschein ausstellen, sperren, verlängern, gutschreiben (8.1, braucht eine neue Version von Voucher Captain) | Workflow Captain Pro | Adapter und Befehle offen |
| Affiliate Captain | 21: Partner angelegt, Statuswechsel, geworben, gelöscht, Verkauf zugeordnet, Provisionen (angelegt, geprüft, freigegeben, abgelehnt, bezahlt, angepasst, erneut geprüft), Auszahlungen, Korrekturen, Werbemittel, Code-Anfragen | Lesen | Workflow Captain Pro | Adapter offen |
| Lizenzserver | 3: Lizenz ausgestellt, Statuswechsel, Plan gewechselt | Lesen | Workflow Captain Pro | Adapter offen; Vertragskonstante fehlt im Lizenzserver (F-03) |
| Form Captain, Review Captain, Course Captain | in Version 1 keine (4.6) | – | – | später |
| Eigene Connectoren und Kundenconnectoren | eigene Ereignisse nach dem öffentlichen Vertrag | eigene | eigene Projektprodukte mit eigener Lizenz (Entscheidung 17) | Vertrag in [`CONNECTOR-CONTRACT.md`](CONNECTOR-CONTRACT.md) |

## Was der Betreiber sieht

- **Workflow Captain → Provider** listet angemeldete Adapter mit Produkt, Vertragsversion und Zahl gespeicherter Ereignisse.
- Fehlt ein Adapter (Add-on deaktiviert, Lizenz abgelaufen, Kern zu alt), werden neue Ereignisse dieses Anbieters als `paused` (`adapter_unregistered`) aufbewahrt und **nicht** von selbst nachgeholt (R-10.8). Workflows mit diesem Auslöser zeigen „Provider nicht verfügbar“ und lassen sich nicht veröffentlichen.
- Der Builder zeigt am Nachrichten-Knoten, welche Mail das Kern-Plugin zum selben Ereignis ohnehin verschickt (R-9.4, `assets/data/core-mails.json`). Workflow Captain schaltet keine Mail eines Kerns ab (R-9.2).

## Was nie übertragen wird

Ereignisse enthalten Kennungen, Status, Beträge und Zeitpunkte, nie Gutschein-, Ticket- oder Lizenzcodes, E-Mail-Adressen, Namen oder Freitext (R-5.x). Der Kern prüft das bei der Annahme und lehnt Umschläge mit solchen Feldern ab.

## Mindeststände der Kerne

Ein Adapter meldet sich nur an, wenn der Kern die nötige Vertragsversion hat. Die heutigen Stände auf `main` von Pass Captain und Voucher Captain genügen dafür **nicht**; Einzelheiten in [`KOMPATIBILITAET.md`](KOMPATIBILITAET.md).
