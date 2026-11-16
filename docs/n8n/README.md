# n8n-Vorlagen für Workflow Captain

Stand: 16.11.2026. Aufgabe WF-N8N-10 in [`../../TODO.md`](../../TODO.md). Vertrag: [`../../konzept.md`](../../konzept.md), Abschnitt 17.12, „Vertrag der Version 1“.

**Entwurf.** Der Versand an n8n kommt mit Workflow Captain Pro. Die Vorlagen zeigen, wie ein n8n-Workflow die Ereignisse empfängt, die Workflow Captain nach der Spezifikation sendet (Umschlag aus [`../SPEC-suite-events.md`](../SPEC-suite-events.md), Abschnitt 6). Sie sind gegen keine n8n-Instanz importiert worden. Vor der Freigabe in der unterstützten n8n-Version importieren und testen (WF-N8N-12).

| Datei | Ereignis | Zweck |
|---|---|---|
| [`voucher-eingeloest.json`](voucher-eingeloest.json) | `voucher.voucher.redeemed` | Einlösung ins Reporting |
| [`voucher-ausgestellt.json`](voucher-ausgestellt.json) | `voucher.voucher.issued_standalone` | Hinweis an den Kundenservice |
| [`pass-eingecheckt.json`](pass-eingecheckt.json) | `pass.ticket.checked_in` | Teilnahme im CRM vermerken |
| [`woocommerce-bezahlt.json`](woocommerce-bezahlt.json) | `woocommerce.order.paid` | Umsatz ins Reporting (kostenloser Kern liefert das Ereignis, Spezifikation 4.7) |

## Aufbau jeder Vorlage

1. **Webhook** mit Header-Authentifizierung und sofortiger Antwort. Zugangsdaten sind nicht enthalten; sie werden in n8n angelegt und im Knoten ausgewählt. Den Header-Wert erzeugt Workflow Captain beim Anlegen der Verbindung und zeigt ihn einmal an.
2. **Doppelte verwerfen** über die Ereignis-`id`. Workflow Captain stellt mindestens einmal zu und wiederholt mit derselben `id`.
3. **Filter auf den Ereignistyp**, falls eine Verbindung mehrere Typen sendet.
4. **Felder auswählen:** nur, was der nächste Schritt braucht.
5. **Ihr nächster Schritt:** Platzhalter für CRM, Helpdesk, Tabelle oder Reporting.

## Test- und Produktions-URL

n8n hat je Webhook eine Test-URL (nur während „Listen for test event“) und eine Produktions-URL (nur bei aktivem n8n-Workflow). In Workflow Captain gehört die **Produktions-URL** in die Verbindung. Die Test-URL ist nur für den Verbindungstest gedacht und wird für aktive Workflows abgelehnt.

## Was nie im Ereignis steht

Kein Gutschein-, Ticket- oder Lizenzcode, keine E-Mail-Adresse, kein Name, kein Freitext (Spezifikation, Abschnitt 5). Braucht ein n8n-Schritt Kontaktdaten, holt er sie mit eigener Rechtsgrundlage aus dem führenden System. Gutscheine ausstellen, sperren oder einlösen bleibt in Voucher Captain; n8n kann das nur über einen freigegebenen Auslöser von Workflow Captain anstoßen, und dort gelten die Grenzen der Spezifikation (Abschnitt 8.3).

## Noch nicht möglich

- **WooCommerce und WordPress:** Die Spezifikation legt den Umschlag nur für Ereignisse der Captain-Produkte fest. Wie Workflow Captain Ereignisse aus WordPress und WooCommerce an n8n sendet, ist nicht festgelegt (offene Frage F-20 in [`../SPEC-open-questions.md`](../SPEC-open-questions.md)). Eine Vorlage folgt danach.
- **Form Captain:** Der Katalog `form.*` kommt in einer späteren MINOR-Version der Spezifikation (4.6).

## Freigabe, Rechte und Lizenz (WF-N8N-11)

- n8n-Verbindungen sind eine Funktion von Workflow Captain Pro (Recht `workflow.webhooks`) und zählen wie jede externe Integration auf das Website-Limit der Lizenz.
- Anlegen, Ändern und Widerrufen einer Verbindung braucht `manage_workflow_integrations`; jede Änderung steht im Audit (Einstellungen → Status).
- Jede Verbindung ist ab Werk aus und sendet nur die ausgewählten Ereignistypen und Felder.
- Läuft die Lizenz ab, sendet Workflow Captain nichts Neues mehr; offene Zustellungen pausieren und werden nicht verworfen. Eingehende Aufrufe von n8n werden mit einem festen Code abgelehnt. Nach der Verlängerung geht es mit derselben Ereignis-`id` weiter, n8n verwirft Doppelte.
- Ereignisse aus WordPress und WooCommerce liefert der kostenlose Kern; den Versand an n8n übernimmt Pro.
