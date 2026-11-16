# Last und Startwerte des Workers

Stand: 16.11.2026, Plugin 0.2.x. Aufgaben „Queue-Batchlimits und System-Cron-Empfehlungen gegen kleine und große Shops messen“ und WF-CORE-05 in [`../TODO.md`](../TODO.md).

## Messung ohne WordPress (`php bin/bench.php <ereignisse> <batch>`)

Ein Workflow mit zwei Schritten (Nachricht an Betreiber, Markierung), SQLite im Speicher, Mail nicht wirklich versendet. Die Zahlen zeigen die Rechenzeit des Plugins, nicht die einer echten Datenbank oder eines Mailservers.

| Ereignisse | Batch | Worker-Durchläufe | langsamster Durchlauf | je Ereignis (mit 2 Schritten) |
|---|---|---|---|---|
| 500 | 25 | 61 | 23 ms | 1,4 ms |
| 2000 | 100 | 61 | 202 ms | 3,9 ms |

Beobachtung: Mit vielen offenen Schritten steigt die Zeit je Schritt (Auswahl der fälligen Schritte und Zählen der laufenden). Bei 2000 offenen Schritten bleibt ein Durchlauf weit unter der Laufzeitgrenze von 20 Sekunden.

## Was das für den Durchsatz heißt

Ein Worker-Durchlauf verarbeitet bis zu `batch` Ereignisse **und** bis zu `batch` Schritte. Er läuft einmal je Minute.

| Batch | Ereignisse je Stunde (Workflow mit 2 Schritten) | passt für |
|---|---|---|
| 25 (Standard) | etwa 750 | kleine und mittlere Shops |
| 100 | etwa 3000 | große Shops, Ticketverkäufe zum Start |
| 200 (Höchstwert) | etwa 6000 | Spitzen; nur mit System-Cron |

## Empfehlungen

1. **System-Cron statt WP-Cron**, sobald mehr als einige Dutzend Ereignisse je Stunde anfallen: `* * * * * wp --path=/pfad workflow-captain process`.
2. **Batch erhöhen** (Einstellungen → Ausführung), wenn die Übersicht wachsende Rückstände zeigt; die Laufzeitgrenze je Durchlauf (Standard 20 Sekunden) schützt Seitenaufrufe.
3. **Mailversand** über einen SMTP- oder API-Dienst; eine langsame `wp_mail()` ist in echten Shops der größte Zeitfaktor je Schritt.
4. Startwerte für Parallelität (5 je Website, 2 je Workflow) bleiben; sie begrenzen gleichzeitig laufende Schritte, nicht den Durchsatz je Minute.

## Offen

Messung in echten Installationen mit MySQL/MariaDB, WooCommerce und echtem Mailversand (klein: 50 Bestellungen am Tag; groß: 5000 am Tag), dann Startwerte bestätigen oder anpassen.
