# n8n: Kompatibilitäts- und Sicherheitsmatrix

Stand: 16.11.2026, Prüfung mit echtem n8n am 24.11.2026. Aufgabe WF-N8N-12 in [`../../TODO.md`](../../TODO.md). Vertrag: [`../../konzept.md`](../../konzept.md), Abschnitt 17.12 („Vertrag der Version 1“); Umsetzung in Workflow Captain Pro (`captain-pro`, `addons/workflow-captain-pro/src/Webhooks/`, `src/N8n/`), Bausteine im Kern (`SafeHttp`, `OutboundPolicy`, `InboundGuard`, `Vault`).

**Ehrlich vorweg:** Am 24.11.2026 lief die Matrix gegen **n8n 1.123.92, selbst gehostet** (npm-Paket, nur an `127.0.0.1:5678` gebunden) mit `bin/e2e/n8n.sh`: 42 von 44 Prüfungen bestanden, 2 abweichend (Rate-Limit, ein Fehler in Workflow Captain Pro, Abschnitt 4). **n8n Cloud ist nicht geprüft und in dieser Umgebung nicht prüfbar** (kein Testkonto, kein Netzzugang zu n8n Cloud). Einschränkungen des Laufs: WordPress 6.9.11 und PHP 8.3.6 statt der Mindestplattform PHP 8.5 / WordPress 7.0 (MINVER-1; das Skript senkt dafür nur die Versionsgrenze in Kopien von Kern und Pro, `E2E_LOWER_MINVER=1`); Workflow Captain aus diesem Branch, Workflow Captain Pro aus `captain-pro` `main` (`0ee78f6`); Pro lizenziert gegen WP License Captain auf derselben Testseite. Die Spalte „Beleg ohne n8n“ bleibt gültig (Kern: `tests/security-tests.php`; Pro: `tests/Addons/WorkflowWebhooksTest.php`); die Spalte „Mit n8n“ nennt die Prüfung von `bin/e2e/n8n.sh` (Ergebnisse in `logs/n8n-results.jsonl`).

## 1. Kompatibilität

| Ziel | Version | Was zu prüfen ist | Stand |
|---|---|---|---|
| n8n Cloud | aktuelle Version zum Release-Zeitpunkt | Webhook-Knoten mit „Header Auth“ nimmt `POST` mit JSON an; Produktions-URL `/webhook/…`, Test-URL `/webhook-test/…`; Antwort 200 sofort; Vorlagen lassen sich importieren | **ungeprüft, hier nicht prüfbar** (kein Cloud-Testkonto, kein Netzzugang); vor dem Release mit einem Testkonto nachholen |
| n8n selbst gehostet | 1.x (unterstützt werden soll die jeweils letzte Minor-Version und die davor) | wie Cloud; dazu Basic Auth, eigene Basis-URL, Pfad hinter einem Reverse-Proxy | **1.123.92 geprüft am 24.11.2026** (Header Auth, eigene Basis-URL, Produktions- und Test-URL, Vorlagen); **ungeprüft:** Basic Auth, Reverse-Proxy-Pfad, die Minor-Version davor, n8n 2.x (inzwischen `latest` bei npm) |
| n8n selbst gehostet im lokalen Netz / Docker | 1.x | private Adresse nur mit Ausnahme für genau Host und Port (IP/CIDR), HTTP nur mit `allow_http`; Hinweis „localhost ist der WordPress-Server“ | Regeln **geprüft** (Kern `SafeHttp n8n: local Docker target …`, `SEC-01 exception is bound to …`); **mit n8n 1.123.92 auf `127.0.0.1:5678` geprüft** (Ausnahme `127.0.0.1/32`, Port 5678, HTTP; ohne Ausnahme `target:private_address`; anderer Port `target:exception_not_for_this_target`); Docker-Netz (Containername) **ungeprüft** (kein Docker in der Prüfumgebung) |
| n8n → WordPress (eingehend) | 1.x, Knoten „HTTP Request“ | Signatur `X-Workflow-Captain-*` (HMAC-SHA256 über `timestamp.nonce.body`) lässt sich in n8n erzeugen (Code-Knoten, Crypto) | Prüfung in WordPress **geprüft** (Kern `SEC-03`, Pro); **Erzeugen in n8n 1.123.92 geprüft:** Knoten „Crypto“ (HMAC, SHA256, hex) über `{{ts}}.{{nonce}}.{{body}}`, „HTTP Request“ mit Body „raw“ (genau die signierten Bytes) und den vier Headern; Beispiel `bin/e2e/n8n/e2e-eingehend.json`. Anleitung für Kunden fehlt noch (WORKFLOW-PRO.md, „Was folgt“ 4) |
| JWT-Authentifizierung des n8n-Webhook-Knotens | – | nicht unterstützt | entfällt in Version 1 |

Freigabe für ein Release erst, wenn je Zeile 1 und 2 eine Version eingetragen und der Abschnitt 3 mit ihr durchlaufen ist.

## 2. Sicherheitsfälle

| Fall | Erwartetes Verhalten | Beleg ohne n8n | Mit n8n |
|---|---|---|---|
| Eingehend mit gültiger Signatur | angenommen, `202` mit Ereignisnummer | Kern `SEC-03 inbound: valid signed request accepted`; Pro (Reihenfolge und Statuscodes) | **bestanden** (n8n 1.123.92, 24.11.2026): `202`, `captured`; nur die erklärten Felder gespeichert, E-Mail und Name aus n8n verworfen (n17, n18) |
| Eingehend ohne oder mit falscher Signatur | abgelehnt, fester Code, Vergleich in konstanter Zeit | Kern `SEC-03 … wrong signature refused` | **bestanden** (1.123.92, 24.11.2026): falsches Geheimnis in n8n, `401 bad_signature` (n20) |
| Eingehend: unbekannte oder widerrufene Verbindung | `401 unknown_connection`, vor jeder weiteren Prüfung; zu großer Body zuerst abgelehnt | Kern `SEC-03 … revoked or unknown connection refused, oversized body refused first` | **bestanden** (1.123.92, 24.11.2026): unbekannt und widerrufen, `401 unknown_connection` (n23, n26); zu großer Body nicht mit n8n geprüft |
| Eingehend: Auslöser nicht freigegeben (unzulässiger Umfang) | abgelehnt; nur Auslöser der Allowlist der Verbindung | Kern `SEC-03 … trigger not on the allowlist refused`; Kern prüft Felder und Typen noch einmal (`InboundTriggers`) | **bestanden** (1.123.92, 24.11.2026): `403 trigger_not_allowed` (n22) |
| Ausgehend: Ereignistypen und Felder außerhalb der Verbindung | nicht gesendet; verbotene Namen nie | Pro (Feldfilter) | **bestanden** (1.123.92, 24.11.2026): nicht gewählter Typ ohne Outbox-Zeile (n8); in der n8n-Ausführung nur die vier gewählten Felder (n6) |
| Replay (gleiche Nonce) | abgelehnt | Kern `SEC-03 … same nonce again is a replay` | **bestanden** (1.123.92, 24.11.2026): zweiter Aufruf aus n8n mit derselben Nonce, `409 replay` (n19) |
| Zeitstempel außerhalb des Fensters | abgelehnt | Kern `SEC-03 … timestamp outside the window refused` | **bestanden** (1.123.92, 24.11.2026): 15 Minuten alt, `401 stale_timestamp` (n21) |
| Doppelte Zustellung ausgehend | dieselbe Ereignis-`id`; n8n verwirft über „Doppelte verwerfen“ | Pro (Wiederholung mit derselben `id`); Vorlagen enthalten den Schritt | **bestanden nach Korrektur der Vorlagen** (1.123.92, 24.11.2026): gleiche `id` zweimal zugestellt, „Doppelte verwerfen“ lässt die erste durch und verwirft die zweite (n9). Vorher nannten die Vorlagen eine Operation, die n8n nicht kennt, und ließen jedes Duplikat durch (Abschnitt 4) |
| Rate-Limit eingehend | abgelehnt je Verbindung | Kern `SEC-03 inbound: rate limit per connection` | **abweichend** (1.123.92, 24.11.2026): bei Limit 2/min wird schon der zweite Aufruf mit `429` abgelehnt (n24); ein angenommener Aufruf zählt doppelt (n24b). Fehler in Workflow Captain Pro, Abschnitt 4 |
| n8n nicht erreichbar / Netzwerkfehler | `TransientFailure`, Wiederholung nach Plan, höchstens 8 Mal; Outbox `retry` | Pro | **bestanden** (1.123.92, 24.11.2026): n8n beendet, `transport:http_request_failed`, Outbox `retry_wait`, Versuch 1 (n31); Code ohne URL, Header, Geheimnis (n32) |
| Zeitüberschreitung | wie Netzwerkfehler; Zeitlimit 10 s (`SafeHttp::TIMEOUT`) | Kern `SafeHttp: transport errors become fixed codes …`; Pro | **bestanden, Zeitlimit abweichend** (1.123.92, 24.11.2026): n8n antwortet nach 40 s; Abbruch nach **10 s** (`SafeHttp::TIMEOUT`), `retry_wait` (n13). Die „30 s“ dieser Zeile stimmen nicht mit dem Code überein |
| `5xx`, `408`, `425`, `429` | vorübergehend, Wiederholung | Pro | **bestanden** für `500` und `429` (1.123.92, 24.11.2026): `retry_wait` (n10, n11); `408`, `425` nicht mit n8n geprüft |
| andere `4xx` (z. B. `404` bei inaktivem n8n-Workflow) | dauerhaft, Lauf im Fehlerzustand, Outbox `failed` | Pro | **bestanden** (1.123.92, 24.11.2026): n8n-Workflow deaktiviert, `404`, Outbox `failed` (n14); `400` `failed` (n12); falscher Header-Auth-Wert `403` `failed` (n15) |
| Test-URL `/webhook-test/` bei aktiver Verbindung | abgelehnt | Pro | **bestanden** (1.123.92, 24.11.2026): Aktivieren mit Test-URL abgelehnt, `n8n_test_url` (n4); der Verbindungstest darf sie nutzen (n8n antwortet dort nur während „Listen for test event“) |
| Rotation der Geheimnisse | neu erzeugt, einmal angezeigt, danach maskiert; alter Wert sofort ungültig | Pro; Schlüsselwechsel des Kerns Kern `SEC-02 rotation …`, `SEC-02 rekey …` | **bestanden** (1.123.92, 24.11.2026): neuer Header-Wert, n8n lehnt mit dem alten Credential ab (`403`, n15), nach dem Nachziehen des Credentials in n8n wieder `200` (n16); neues eingehendes Token, alter Wert sofort `401`, neuer `202` (n25); einmal angezeigt, danach maskiert (n1b) |
| SSRF: private, Loopback-, Metadaten-Adressen, DNS-Rebinding, IP-Schreibweisen | abgelehnt; Metadaten auch mit Ausnahme | Kern `SEC-01` (19 Fälle), `SafeHttp …` (Weiterleitungen geprüft, höchstens 3, auf geprüfte IP festgelegt) | betrifft WordPress, nicht n8n; auf der Testseite zusätzlich geprüft (24.11.2026): `127.0.0.1` ohne Ausnahme `target:private_address` (n27), `169.254.169.254` auch mit Ausnahme `target:metadata_address` (n28), Ausnahme für Port 5678 öffnet nicht Port 8087 (n29). Lokale Ziele in Pro: WF-N8N-15 |
| PII-Redaktion | kein Code, keine E-Mail, kein Name, kein Freitext im Umschlag; Fehlermeldungen ohne URL, Header, Body, Geheimnis | Kern Validator und `SEC-02 decrypted values are scrubbed from text`; Pro (Ergebnis nur als fester Code) | **bestanden** (1.123.92, 24.11.2026): Umschlag mit E-Mail, Vor- und Nachname, Kundennotiz und Gutscheincode gesendet, nichts davon in der gespeicherten n8n-Ausführung (n7). **Hinweis:** n8n speichert die eingehenden Header in der Ausführung, also auch den Header-Auth-Wert und die Signatur (n7c), siehe [`README.md`](README.md) |
| Lizenz von Pro abgelaufen | nichts Neues gesendet, offene Zustellungen pausiert; eingehend `403` | Pro | **bestanden** (Lizenz deaktiviert statt abgelaufen, 1.123.92, 24.11.2026): nichts Neues eingereiht, Aufruf aus n8n `403 license_inactive` (n30); Pausieren offener Zustellungen nicht mit n8n geprüft |

## 3. Ablauf für die Prüfung mit echtem n8n (vor dem Release)

1. n8n in der Zielversion starten (Cloud-Testkonto oder `docker run n8nio/n8n:<version>`), Version in Abschnitt 1 eintragen.
2. Die vier Vorlagen importieren; je Vorlage Header-Auth-Credential anlegen.
3. In Workflow Captain Pro je Richtung eine Verbindung anlegen, Verbindungstest, aktivieren.
4. Je Zeile in Abschnitt 2 den Fall auslösen (n8n-Workflow deaktivieren für `404`, Docker anhalten für „nicht erreichbar“, Code-Knoten mit `sleep` für Zeitüberschreitung, Credential ändern für Rotation) und das Ergebnis in der Spalte „Mit n8n“ mit Datum und Version eintragen.
5. Ergebnis in `docs/E2E-RESULTS.md` festhalten.

## 4. Lauf vom 24.11.2026 (n8n 1.123.92, selbst gehostet)

`bin/e2e/n8n.sh` installiert n8n per npm in ein eigenes Verzeichnis (`xlsx` auf die Kopie aus der npm-Registry gepinnt, weil n8n sie sonst von `cdn.sheetjs.com` lädt), startet es nur auf `127.0.0.1:5678`, legt über die öffentliche API (API-Schlüssel) Eigentümer, Credentials und Workflows an, baut die Testseite auf `127.0.0.1:8087` und führt `bin/e2e/n8n-checks.php` aus. Hilfs-Workflows: `bin/e2e/n8n/e2e-antworten.json` (feste Statuscodes und 40 s Verzögerung; der Verbindungstest bekommt 200) und `bin/e2e/n8n/e2e-eingehend.json` (signierter Aufruf an WordPress). Am Ende werden n8n und der PHP-Server beendet.

| Gruppe | Prüfungen | Ergebnis |
|---|---|---|
| Version, Bindung | v0 | n8n 1.123.92 lauscht nur auf `127.0.0.1` |
| Vorlagen | t1–t4 | alle vier importiert, unverändert geladen, gleichzeitig aktiv; Header Auth lehnt ohne und mit falschem Wert ab (`403`) |
| Verbindungen | n0–n4 | Lizenz, Anlegen, Verbindungstest `ok:200` gegen die Produktions-URLs, Aktivieren, Test-URL abgelehnt |
| Ausgehend | n5–n16 | siehe Abschnitt 2 |
| Eingehend | n17–n26 | siehe Abschnitt 2; **n24, n24b abweichend** |
| SSRF, Lizenz, nicht erreichbar, Protokoll | n27–n33 | siehe Abschnitt 2; kein PHP-Fehler von Kern oder Pro im Protokoll |

**Gefunden und behoben (Kern, `docs/n8n/*.json`, Test `tests/n8n-template-tests.php`):**

1. Den Webhook-Knoten der Vorlagen fehlte die `webhookId`. Über die API importiert, registriert n8n den Webhook dann unter `/webhook/<Workflow-ID>/workflow%20captain/<Pfad>` statt unter der dokumentierten Produktions-URL `/webhook/<Pfad>`. Jetzt mit fester `webhookId`.
2. `woocommerce-bezahlt.json` hatte denselben Pfad wie `pass-eingecheckt.json` (`workflow-captain-pass-checked-in`); nur eine der beiden Vorlagen konnte Aufrufe bekommen. Neuer Pfad `workflow-captain-woocommerce-order-paid`.
3. „Doppelte verwerfen“ nannte die Operation `removeItemsRepeatedInPreviousExecutions`, die n8n nicht kennt; der Knoten ließ jedes Duplikat durch. Jetzt `removeItemsSeenInPreviousExecutions` mit `logic` = `removeItemsWithAlreadySeenKeyValues`.

**Gefunden, nicht behoben (Workflow Captain Pro, nur berichtet):** Ein angenommener eingehender Aufruf zählt doppelt auf das Rate-Limit (und die Nonce wird zweimal gemerkt). Vermutliche Ursache: `InboundController::respond()` löscht das zwischengespeicherte Prüfergebnis; danach ruft WordPress für den `Allow`-Header (`rest_send_allow_header()`) den `permission_callback` mit derselben Anfrage noch einmal auf, und `InboundGuard::check()` läuft ein zweites Mal. Folge: das wirksame Limit ist die Hälfte des eingestellten.

**Abweichung in der Dokumentation (behoben am 24.11.2026: Dokumentation auf 10 s angeglichen; n8n-Webhooks antworten mit „Respond immediately“ sofort, lange Arbeit läuft danach in n8n):** Das Zeitlimit ausgehender Aufrufe ist 10 s (`SafeHttp::TIMEOUT`), nicht 30 s wie oben und in `konzept.md` (Zeitlimit für HTTP-Aktionen) genannt. Zu entscheiden, welcher Wert gilt.

**Nicht geprüft:** n8n Cloud; Basic Auth; Reverse-Proxy; Docker-Netz; n8n 2.x und die Minor-Version vor 1.123; `408`, `425`; zu großer Body aus n8n; Pausieren offener Zustellungen bei Lizenzablauf; PHP 8.5 / WordPress 7.0.
