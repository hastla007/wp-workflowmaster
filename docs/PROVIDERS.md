# Anbieterübersicht

Stand: 16.11.2026, Plugin 0.2.x, Spezifikation 1.0.0-draft.6. Für Betreiber, Vertrieb und Support. Technische Einzelheiten: [`SPEC-suite-events.md`](SPEC-suite-events.md), Versionen: [`KOMPATIBILITAET.md`](KOMPATIBILITAET.md), Ereignisse und Felder: [`catalog/suite-events-v1.json`](catalog/suite-events-v1.json).

## Wer liefert was

Ein **Anbieter** (Provider) ist ein Plugin, dessen Ereignisse Workflows auslösen oder dessen Funktionen ein Workflow aufrufen kann. Workflow Captain selbst kennt keinen Anbieter fest. Ein Anbieter wird erst sichtbar, wenn sich sein **Adapter** anmeldet (Abschnitt 3 der Spezifikation).

| Anbieter | Auslöser (Ereignistypen) | Befehle für Workflows | Adapter | Stand |
|---|---|---|---|---|
| Workflow Captain (eingebaut) | – | Nachricht an Betreiber, Nachricht an Benutzer, Markierung setzen und entfernen, Notiz anlegen | im Kern | gebaut |
| WordPress | 2: Benutzer registriert (`wordpress.user.registered`), Beitrag veröffentlicht (`wordpress.post.published`, nur öffentliche Inhaltstypen) | – | im Kern (`src/Providers/WordPressAdapter.php`), ohne Lizenz, ab WordPress 6.4 | gebaut (F-20) |
| WooCommerce | 5: Bestellung angelegt, bezahlt, Statuswechsel, erstattet, storniert (`woocommerce.order.*`); nur Status, Beträge in kleinster Einheit, Währung, Kennungen, nie Adressen, E-Mail, Namen, IP, Kundennotiz oder Kupon-Codes | – | im Kern (`src/Providers/WooCommerceAdapter.php`), ohne Lizenz, nur wenn WooCommerce 8.2 oder neuer aktiv ist | gebaut (F-20) |
| Pass Captain | 13: Bestellung bezahlt, Tickets ausgestellt, storniert, erstattet, Erstattung abgeschlossen oder abgelehnt, Ticket widerrufen, Ein- und Auslass, Termin verschoben oder abgesagt, Veranstaltung abgesagt, Rechnung erstellt | Lesen (8.1) | Workflow Captain Pro | Adapter offen |
| Voucher Captain | 16: Gutscheine ausgestellt, zugestellt, Zustellung fehlgeschlagen, Rückläufer, eingelöst, Reservierung, Freigabe, Erstattung, Storno, abgelaufen, neu ausgestellt, ungültig, reaktiviert, Guthaben, Aufladung | Lesen; Gutschein ausstellen, sperren, verlängern, gutschreiben (8.1, braucht eine neue Version von Voucher Captain) | Workflow Captain Pro | Adapter und Befehle offen |
| Affiliate Captain | 21: Partner angelegt, Statuswechsel, geworben, gelöscht, Verkauf zugeordnet, Provisionen (angelegt, geprüft, freigegeben, abgelehnt, bezahlt, angepasst, erneut geprüft), Auszahlungen, Korrekturen, Werbemittel, Code-Anfragen | Lesen | Workflow Captain Pro | Adapter offen |
| Lizenzserver | 3: Lizenz ausgestellt, Statuswechsel, Plan gewechselt | Lesen | Workflow Captain Pro | Adapter offen; Vertragskonstante fehlt im Lizenzserver (F-03) |
| Form Captain | 8: Formular angelegt, geändert, Einträge importiert; Eintrag angelegt, Statuswechsel, gelöscht, Einwilligung widerrufen, Benachrichtigung fehlgeschlagen (`form.*`, 4.6.1). Antworten nur, wenn der Betreiber das Feld freigibt, und nur Auswahl, Zahl oder Einwilligung, nie Kontaktdaten oder Freitext (4.6.2) | Lesen | Workflow Captain Pro; braucht zusätzlich Form Captain Pro (gibt es noch nicht) | Katalog seit Spezifikation 1.0.0-draft.7; Adapter gebaut, bleibt bis Form Captain Pro aus |
| Review Captain, Course Captain | keine (4.6.3, 4.6.4); Review Captain hat auf `main` noch keinen Code | – | – | später |
| Eigene Connectoren und Kundenconnectoren | eigene Ereignisse nach dem öffentlichen Vertrag | eigene | eigene Projektprodukte mit eigener Lizenz (Entscheidung 17) | Vertrag in [`CONNECTOR-CONTRACT.md`](CONNECTOR-CONTRACT.md) |

## Was der Betreiber sieht

- **Workflow Captain → Provider** listet angemeldete Adapter mit Produkt, Vertragsversion und Zahl gespeicherter Ereignisse.
- Fehlt ein Adapter (Add-on deaktiviert, Lizenz abgelaufen, Kern zu alt), werden neue Ereignisse dieses Anbieters als `paused` (`adapter_unregistered`) aufbewahrt und **nicht** von selbst nachgeholt (R-10.8). Workflows mit diesem Auslöser zeigen „Provider nicht verfügbar“ und lassen sich nicht veröffentlichen.
- Der Builder zeigt am Nachrichten-Knoten, welche Mail das Kern-Plugin zum selben Ereignis ohnehin verschickt (R-9.4, `assets/data/core-mails.json`). Workflow Captain schaltet keine Mail eines Kerns ab (R-9.2).

## Was nie übertragen wird

Ereignisse enthalten Kennungen, Status, Beträge und Zeitpunkte, nie Gutschein-, Ticket- oder Lizenzcodes, E-Mail-Adressen, Namen oder Freitext (R-5.x). Der Kern prüft das bei der Annahme und lehnt Umschläge mit solchen Feldern ab.

## Mindeststände der Kerne

Ein Adapter meldet sich nur an, wenn der Kern die nötige Vertragsversion hat. Die heutigen Stände auf `main` von Pass Captain und Voucher Captain genügen dafür **nicht**; Einzelheiten in [`KOMPATIBILITAET.md`](KOMPATIBILITAET.md).
