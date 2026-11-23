# Entscheidungsvorlage Workflow Captain

Stand: 23.11.2026. Alles, was in [`../TODO.md`](../TODO.md) noch offen ist und **nicht** ohne Entscheidung der Inhaberin oder etwas Externes erledigt werden kann. Je Punkt: Frage, Empfehlung, was die Antwort freigibt. Die ausführlichen Begründungen stehen in [`SPEC-open-questions.md`](SPEC-open-questions.md).

Antwort genügt in der Form „F-02: ja“ oder „F-05a: anders, nämlich …“. Danach trage ich die Entscheidung ein und erledige die abhängigen Aufgaben.

## 1. Nur bestätigen: schon so gebaut

Diese Fragen sind offen, aber Code und Spezifikation folgen schon der Empfehlung. Ein „ja“ ändert nichts am Code, schließt aber die Frage und macht den Weg frei für Spezifikation 1.0.0 (WF-SPEC-01).

| Nr. | Frage (kurz) | Empfehlung = heutiger Stand |
|---|---|---|
| F-02 | Öffentliche Kennung von Gutschein, Provision, Partner, Lizenz | Datensatznummer mit `id_kind` = `record`; `key_prefix` der Lizenz nie |
| F-03 | Muster der Vertragskonstanten, Abkündigungsfrist | `<PRODUKT>_CONTRACT_VERSION`; inzwischen haben Review (`REVIEW_CAPTAIN_CONTRACT_VERSION`), Lizenzserver (`WPLC_CONTRACT_VERSION`) und Course (`COURSE_CAPTAIN_CONTRACT_VERSION`, Branch) dieses Muster. Abkündigung: zwei MINOR-Versionen und mindestens sechs Monate |
| F-06 | Lizenz eines anderen Add-ons prüfen | Erweiterung des Kernels in captain-pro (N12); bis dahin die Übergangslösung `Suite\Gate`/`Suite\ProductLicenses` in Workflow Captain Pro (auf captain-pro `main`) |
| F-08 | Wo liegen Adapter, wer plant Review-Einladungen | Adapter in Workflow Captain Pro (gebaut, captain-pro `main`); Einladungen plant Review Captain Pro |
| F-09 | Reicht eine gültige Lizenz des Produkt-Add-ons | ja, kein eigenes Recht je Produkt |
| F-10 | Verarbeitet der kostenlose Kern Captain-Ereignisse | nein: Captain-Produkte nur mit Workflow Captain Pro; Lizenzereignisse im Shop über Pro |
| F-14 | Verlust zwischen Commit des Kerns und Speichern | für Version 1 hinnehmen, im Handbuch nennen |
| F-15 | Acht Versuche, Kettentiefe 3 | ja (gebaut, getestet) |
| F-21 | Beantragter Partnercode „öffentlich“? | nein, erst nach Freigabe |
| F-23 | Review-Einladung als Workflow-Befehl | nicht in Version 1; später höchstens `review.request.plan`/`.cancel` über den Planer von Review Captain Pro |
| W-1 bis W-9, W-13 | Widersprüche zwischen den Entscheidungen vom 16.11. | so auflösen, wie Teil 2 der offenen Fragen es beschreibt (alle so gebaut) |

**Gibt frei:** WF-SPEC-01 und „Suite-weite Regeln abnehmen“ (Spezifikation auf 1.0.0).

## 2. Echte Entscheidungen

| Nr. | Frage | Empfehlung | Gibt frei |
|---|---|---|---|
| E9 / F-19 | Preise Workflow Captain Pro (Vorschlag 129 / 229 / 399 € netto für 1 / 5 / 25 Websites) | bestätigen oder Beträge nennen; gleiche Pro-Funktionen in allen Tarifen | WF-INT-13, Produktkey und Jahrespläne im Shop, Website (WEB-1) |
| F-04 | Aufbewahrung (30 Tage erledigt, 90 Tage fehlgeschlagen, Benutzer-ID leeren, sobald kein Lauf wartet) | als Voreinstellung bestätigen, nur nach unten änderbar; endgültig nach der Rechtsprüfung | WF-SPEC-11 |
| F-16 | Ist Course Captain ein Suite-Produkt mit Add-on und Präfix `course.` | ja (Course Captain baut schon so: `COURSE_CAPTAIN_CONTRACT_VERSION`, Pro-Funktionen) | Katalog `course.*`, CC-1 bis CC-4 |
| F-24 | Schreibende Workflow-Aktionen für Course Captain (Zugang anlegen/entziehen) | nicht in Version 1; danach als Befehle nach dem Muster der Gutschein-Befehle, Aktion in Workflow Captain Pro | Antwort an Course Captain (E-39b) |
| r4b | Volle WooCommerce-Erstattung: `affiliate.commission.adjusted` ohne `refund_id` (Affiliate bucht über den Bestellstatus) | Spezifikation: `refund_id` bei voller Erstattung über den Status optional; Affiliate muss nichts ändern | „Woo/Refund-Verknüpfung testen“ |
| Z. 88 | Form und Review: Verhalten „Plugin fehlt, Lizenz fehlt, Vertrags-MAJOR“ nur per PHPUnit belegt, E2E erst mit freigegebenem Form/Review Captain Pro | so akzeptieren und abhaken | Provider-Verhalten |
| WF-SEO-09 | Headless-Anwendung | zurückstellen, bis es eine Produktanforderung gibt; Aufgabe schließen | – |
| Sprache | Die Texte im Admin sind im Code deutsch; WordPress.org erwartet englische Ausgangstexte (übersetzt über translate.wordpress.org) | vor dem ersten Release auf WordPress.org umstellen (deutsch dann als Übersetzung `de_DE`) | Veröffentlichung auf WordPress.org |

## 2a. Entschieden am 23.11.2026, Umsetzung läuft

Gemeldet von der koordinierenden Form-Sitzung (Entscheidungen der Inhaberin in der Gutschein-Sitzung); in der Spezifikation als entschieden vermerkt.

| Nr. | Entscheidung | Umsetzung |
|---|---|---|
| F-05a | Höchstbetrag 100 auch in CHF, USD und GBP; in anderen Währungen trägt der Betreiber den Betrag selbst ein | wp-couponmaster Pull Request #20 (offen) |
| F-13 | „Ja, nach Rechtsprüfung“: Kontakt und Einwilligung je Gutschein-ID bzw. Ticket lesbar, ab Werk aus | Voucher: Vertrag 1.5.0 in wp-couponmaster #20 (offen); Pass: mit Pass Captain 1.7.0 (PASS-E17-01) |

## 3. Extern (nicht durch eine Antwort lösbar)

| Punkt | Was es braucht |
|---|---|
| Datenschutz-/Rechtsprüfung der Datenflüsse | Rechtsberatung; Grundlage: Abschnitt „Privacy“ der `readme.txt`, `docs/LOESCHUNG.md`, Spezifikation 12 |
| Supportkapazität Standard/Priority | Organisation; vorher keine Antwortzeiten veröffentlichen |
| Screenreader-Prüfung (WF-BUILDER-09, WF-SET-15) | ein Mensch mit NVDA/VoiceOver; automatische Prüfungen (axe, Kontrast, Tastatur) sind grün |
| Abnahme von Oberfläche, Rezepten und Netzwerkmaterial (WF-MS-24) | Durchsicht durch die Inhaberin |
| Lizenzclient/Updater, Pro-ZIP, Shop-Sandbox, Update über HTTPS (WF-INT-18 Pro-Teil) | Freigabe von Workflow Captain Pro (Lizenz-Sitzung, zurückgehalten) und ein Testshop mit HTTPS |
| Multisite je Provider (Pass/Affiliate je Website) | Suite-Lauf mit lizenziertem Pro |
| n8n-Matrix gegen echtes n8n (WF-N8N-12) | laufende n8n-Instanz (Cloud oder selbst gehostet) |
| Review-Anfrage-API (TODO Z. 26) | Review Captain Pro ist seit dem 23.11.2026 auf captain-pro `main` (`4abdee4`, Pull Request #10), meldet seinen `RequestPlanner` aber nicht am Kern-Filter `review_captain_request_planner_v1` an; bis dahin antwortet `plan_request()` mit `rc.contract.unavailable`. Aufgabe für captain-pro; als Workflow-Befehl ohnehin nicht in Version 1 (F-23) |
