# Anforderungen an andere Repositories der Suite

Stand: 16.11.2026, zu [`SPEC-suite-events.md`](SPEC-suite-events.md) Version 1.0.0-draft.4 (nach der Prüfung von Pass und Voucher Captain am Code). Aufgabe WF-SPEC-14 in [`../TODO.md`](../TODO.md).

Die Spezifikation verlangt von anderen Repositories Änderungen. Dieses Dokument sammelt sie je Repository so, dass sie dort ohne weitere Recherche als Aufgaben angelegt werden können. Angelegt sind sie noch nicht: Diese Sitzung hat keine Schreibrechte in den anderen Repositories. Wer eine Aufgabe dort anlegt, trägt den Ort hier in der Spalte „Angelegt“ ein; sind alle angelegt, ist WF-SPEC-14 erledigt.

Regeln, die für alle gelten:

- Nach D12 rangiert alle Workflow-Arbeit hinter dem Verkaufsstart der drei vorhandenen Add-ons. Ausnahmen sind ausdrücklich genannt (Entfernen der vorläufigen Provider vor dem Release, D13).
- Nach D5 und D9 werden die Kerne für den Transport der Ereignisse nicht umgebaut. Verlangt werden nur Dokumentation, eine Vertragskonstante, fehlende Actions im Lizenzserver und die vier Gutschein-Einstiegspunkte (D11b).
- Kein Kern bekommt Lizenzwissen (R-2.4, R-3.11).

## Voucher Captain (`wp-couponmaster`)

| Nr. | Anforderung | Grundlage | Dringlichkeit | Angelegt |
|---|---|---|---|---|
| VC-1 | Vier öffentliche Einstiegspunkte für automatische Aufrufe: `voucher.issue`, `voucher.void`, `voucher.reactivate`, `voucher.redeem`, mit Pflichtfeldern, Idempotenz, atomaren Tageszählern, Protokoll ohne Code, Abschaltbarkeit je Befehl (ab Werk aus) und den festen Grenzen der Tabelle in 8.3.1; additive MINOR-Version von `VCAP_CONTRACT_VERSION` | Spezifikation 8.3, R-8.1 bis R-8.3f; D11b, F-05 | nach D12 | |
| VC-2 | Gutschein über `voucher_id` annehmen, nie über den Code; Empfänger über `recipient_user_id`, nie über eine E-Mail-Adresse; eigener Code, `allow_weak`, Prozent-Gutscheine und `paid = true` ablehnen; `$refund`/`$after_refund` fest `false`; `refund()` der Einlöse-API nicht erreichbar | 8.3.2 bis 8.3.4 | nach D12 | |
| VC-3 | Höchstbeträge in Shops ohne Euro nach der Antwort auf F-05a | F-05a | nach der Antwort | |
| VC-4 | Satz in `README.md:96` („Kein Formular und kein Workflow stellt selbstständig Gutscheine aus.“) an D11b anpassen | 8.3.4 | sofort | |
| VC-5 | Vorläufigen Workflow-Provider aus Pull Request 16 vor dem Release entfernen und auf einem geparkten Branch aufbewahren; Wächtertest wie im Affiliate-Kern | D13, R-4.4 bis R-4.6, Z-16 (geprüft: auf `hvollo-refactors` `82145ed` noch vorhanden, auf `main` nie; dort VOUCH-WF-10) | vor dem nächsten Release | |
| VC-6 | „Nach Commit“ für die Actions ohne Vermerk in der Hook-Tabelle belegen oder als „nicht nach Commit“ kennzeichnen | R-3.9, Z-05 | Dokumentation, jederzeit | |
| VC-7 | Lesefunktionen für Zustand eines Gutscheins und für Kontakt und Einwilligung des Empfängers als dokumentierten Vertrag führen (additiv) | 8.1, F-13, Z-07 | nach der Antwort auf F-13 | |
| VC-8 | Wächtertest „kein Lizenzcode im Kern“ in der CI | R-3.11 | jederzeit | |
| VC-9 | `reactivate()` prüft die Berechtigung nur, wenn ein Benutzer angemeldet ist; der neue Einstiegspunkt darf sich darauf nicht verlassen. `void()` und die Einlöse-API prüfen in PHP keine Berechtigung | 8.3.3, 8.3.4, Z-15 | mit VC-1 | |
| VC-10 | Kartenimport bildet die Idempotenzreferenz aus einem Hash des Codes (`src/Admin/CardImport.php:92-94`, der Docblock in Zeile 13 nennt fälschlich `import:<code>`); Referenz ohne Bezug zum Code bilden | R-4.7 | jederzeit | |

## Pass Captain (`wp-passmaster`)

| Nr. | Anforderung | Grundlage | Dringlichkeit | Angelegt |
|---|---|---|---|---|
| PC-1 | „Seit“ je `wpcap_*`-Action des Katalogs in `docs/HOOKS.md` nachtragen; die Werte sind ermittelt (Spezifikation 4.2), ebenso die nicht im CHANGELOG vermerkte Erweiterung von `wpcap_order_cancelled` um zwei Argumente in 1.5.0 (`b6ef0b4`) | R-3.4, Z-01 | Dokumentation, jederzeit | |
| PC-2 | „Nach Commit“ für die sechs Actions aus Z-02 in `docs/HOOKS.md` vermerken (geprüft: alle nach COMMIT oder im Autocommit) | R-3.9, Z-02 | Dokumentation, jederzeit | |
| PC-6 | `wpcap_occurrence_rescheduled` und `wpcap_occurrence_cancelled` feuern im Admin auch, wenn das UPDATE fehlschlug (`src/Admin/EventsPage.php:207-218`, `:242-245`); Ergebnis prüfen | Z-02, 4.2 | jederzeit | |
| PC-3 | Dokumentieren, dass `$reason` bei `wpcap_order_cancelled` und `wpcap_ticket_revoked` Freitext sein kann (Admin; bei `wpcap_order_cancelled` auch beliebiger Text über `wp_pass_captain_ticket_service_v1`), und die festen Präfixe auflisten | Abschnitt 5, Z-03 | Dokumentation | |
| PC-4 | Lesefunktionen für Zustand von Bestellung und Ticket und für Kontakt und Einwilligung zu einem Ticket als dokumentierten Vertrag führen (additiv) | 8.1, F-13, Z-07 | nach der Antwort auf F-13 | |
| PC-5 | Wächtertest „kein Lizenzcode im Kern“ in der CI | R-3.11 | jederzeit | |

## Affiliate Captain (`wpaffiliatemaster`)

| Nr. | Anforderung | Grundlage | Dringlichkeit | Angelegt |
|---|---|---|---|---|
| AC-1 | „Seit“-Tabelle für alle öffentlichen Actions in `docs/CONTRACT.md` (13 Actions ohne Angabe) | R-3.4, Z-08 | Dokumentation, jederzeit | |
| AC-2 | Test, der die dokumentierten Actions gegen den Code prüft, falls es ihn nicht gibt | P-2, Z-09 | jederzeit | |
| AC-3 | Wiedereinführung des Workflow-Providers nur als Lesefunktionen nach Abnahme der Spezifikation (dort AFF-WF-08) | D13, F-11 | nach Abnahme | |

## Lizenzserver (`wp-license`)

| Nr. | Anforderung | Grundlage | Dringlichkeit | Angelegt |
|---|---|---|---|---|
| LIC-1 | Vier fehlende Actions: Website aktiviert, Website deaktiviert, Site-Limit erreicht, Release veröffentlicht; ohne Schlüssel in den Argumenten | Abschnitt 11, D2 (dort WFC-LIC-04) | nach D12 | |
| LIC-2 | Vertragskonstante nach R-3.1 (vorgeschlagen `WPLC_CONTRACT_VERSION`, F-03) | R-3.1, F-03 | nach der Antwort auf F-03 | |
| LIC-3 | „Seit“ je Action und Zusage „nach dem Commit“ für die drei Lizenz-Actions des Katalogs | R-3.4, R-3.9, Z-10 | Dokumentation | |
| LIC-4 | Lesefassade mit ausschließlich lesenden Methoden (dort WFC-LIC-05) | Abschnitt 11, 8.1 | nach D12 | |
| LIC-5 | Die sechs `workflow.*`-Schlüssel wandern mit dem Umbau (G2, E8) in die Katalogdatei von `workflow-captain-pro` | W-8 | mit dem Umbau | |

## Add-ons und Kernel (`captain-pro`)

| Nr. | Anforderung | Grundlage | Dringlichkeit | Angelegt |
|---|---|---|---|---|
| CP-1 | `Feature::requires()` um weitere Kerne mit Versions- und Vertragskonstante erweitern | F-06, R-10.3 | vor dem ersten Adapter | |
| CP-2 | Nur lesende Auskunft des Kernels, ob ein anderes Add-on auf dieser Website eine gültige Lizenz hat, ohne Zugriff auf dessen Schlüssel oder Status | F-06, D6, R-10.1 | vor dem ersten Adapter | |
| CP-3 | Add-on `workflow-captain-pro` mit Produktschlüssel, Präfix `workflow.` und `wplc-catalog.json` anlegen | 10.2, W-8 | nach D12 | |

## Form Captain (`wp-formmaster`)

| Nr. | Anforderung | Grundlage | Dringlichkeit | Angelegt |
|---|---|---|---|---|
| FC-1 | Eigenen Workflow-Vertrag und die gebaute Übergabe der Mails an einen Workflow herausnehmen oder abschalten (Empfehlung zu F-12) | D10, F-12, W-11 | nach der Antwort auf F-12 | |
| FC-2 | Belegen, dass der Code die sechs dokumentierten Actions auslöst | Z-11 | jederzeit | |
| FC-3 | Katalog `form.*` kommt in einer MINOR-Version der Spezifikation vor dem ersten Release; bis dahin keinen eigenen Suite-Vertrag veröffentlichen | D1, 4.6 | vor dem ersten Release | |

## Review Captain (`wp-reviewmaster`)

| Nr. | Anforderung | Grundlage | Dringlichkeit | Angelegt |
|---|---|---|---|---|
| RC-1 | `docs/contracts/workflow-provider-v1.md` als nicht normativ kennzeichnen oder herausnehmen (Empfehlung zu F-12) | D1, F-12 | nach der Antwort auf F-12 | |
| RC-2 | Belegen, dass der Code die Actions für Anfragen auslöst, die sein Workflow-Vertrag nennt | Z-12 | jederzeit | |
| RC-3 | Katalog `review.*` kommt in einer MINOR-Version der Spezifikation; Adapter für die Review-Einladung in Review Captain Pro nach den Regeln dieser Spezifikation (Empfehlung zu F-08) | F-08, 4.6 | nach der Antwort auf F-08 | |
