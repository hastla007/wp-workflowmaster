# Beispiel-Workflows

Importierbare Beispiele im Exportformat von Workflow Captain (`src/Engine/Transfer.php`). Zum Übernehmen die Datei unter „Workflows → Workflow importieren“ hochladen oder `wp workflow-captain import <datei>` aufrufen; sie wird ein Entwurf. Dann Testlauf starten und veröffentlichen. Veröffentlichen geht erst, wenn ein Adapter für den Auslöser angemeldet ist.

| Datei | Auslöser | Ablauf |
|---|---|---|
| `einlass-hinweis.json` | `pass.ticket.checked_in` | Einlass am Scanner → Nachricht an den Betreiber |
| `einloesung-markieren.json` | `voucher.voucher.redeemed` | Gutschein markieren und Notiz anlegen; serialisiert je Gutschein |
| `provision-freigegeben-nachfass.json` | `affiliate.commission.approved` | ab 100 EUR einen Tag warten, dann Hinweis |
| `termin-abgesagt-markieren.json` | `pass.occurrence.cancelled` | Termin markieren, zusätzlicher Hinweis an den Betreiber |

Die Beispiele nutzen nur Befehle aus Abschnitt 8.1 der Spezifikation und ersetzen keine Mail eines Kerns (Abschnitt 9). Sie sind keine Rezeptvorlagen von Workflow Captain Pro (`workflow.recipe_templates`). `php tests/run.php` prüft jedes Beispiel gegen die Graph-Validierung und die Feldliste des Katalogs.
