# n8n: Kompatibilitäts- und Sicherheitsmatrix

Stand: 16.11.2026. Aufgabe WF-N8N-12 in [`../../TODO.md`](../../TODO.md). Vertrag: [`../../konzept.md`](../../konzept.md), Abschnitt 17.12 („Vertrag der Version 1“); Umsetzung in Workflow Captain Pro (`captain-pro`, `addons/workflow-captain-pro/src/Webhooks/`, `src/N8n/`), Bausteine im Kern (`SafeHttp`, `OutboundPolicy`, `InboundGuard`, `Vault`).

**Ehrlich vorweg:** Gegen eine echte n8n-Instanz ist noch nichts gelaufen, weder n8n Cloud noch selbst gehostet. Der E2E-Lauf hat n8n übersprungen (`npm install n8n` zu groß, siehe [`../E2E-RESULTS.md`](../E2E-RESULTS.md)), und die Vorlagen in diesem Ordner sind nicht importiert worden. Alles, was unten „geprüft“ heißt, ist mit Tests ohne n8n belegt (Kern: `tests/security-tests.php`; Pro: `tests/Addons/WorkflowWebhooksTest.php` in `captain-pro`). Was nur mit n8n selbst zu klären ist, steht als **ungeprüft**.

## 1. Kompatibilität

| Ziel | Version | Was zu prüfen ist | Stand |
|---|---|---|---|
| n8n Cloud | aktuelle Version zum Release-Zeitpunkt | Webhook-Knoten mit „Header Auth“ nimmt `POST` mit JSON an; Produktions-URL `/webhook/…`, Test-URL `/webhook-test/…`; Antwort 200 sofort; Vorlagen lassen sich importieren | **ungeprüft** |
| n8n selbst gehostet | 1.x (unterstützt werden soll die jeweils letzte Minor-Version und die davor) | wie Cloud; dazu Basic Auth, eigene Basis-URL, Pfad hinter einem Reverse-Proxy | **ungeprüft** |
| n8n selbst gehostet im lokalen Netz / Docker | 1.x | private Adresse nur mit Ausnahme für genau Host und Port (IP/CIDR), HTTP nur mit `allow_http`; Hinweis „localhost ist der WordPress-Server“ | Regeln **geprüft** (Kern `SafeHttp n8n: local Docker target …`, `SEC-01 exception is bound to …`); gegen echtes Docker-n8n **ungeprüft** |
| n8n → WordPress (eingehend) | 1.x, Knoten „HTTP Request“ | Signatur `X-Workflow-Captain-*` (HMAC-SHA256 über `timestamp.nonce.body`) lässt sich in n8n erzeugen (Code-Knoten, Crypto) | Prüfung in WordPress **geprüft** (Kern `SEC-03`, Pro); Erzeugen in n8n **ungeprüft**, Anleitung fehlt (WORKFLOW-PRO.md, „Was folgt“ 4) |
| JWT-Authentifizierung des n8n-Webhook-Knotens | – | nicht unterstützt | entfällt in Version 1 |

Freigabe für ein Release erst, wenn je Zeile 1 und 2 eine Version eingetragen und der Abschnitt 3 mit ihr durchlaufen ist.

## 2. Sicherheitsfälle

| Fall | Erwartetes Verhalten | Beleg ohne n8n | Mit n8n |
|---|---|---|---|
| Eingehend mit gültiger Signatur | angenommen, `202` mit Ereignisnummer | Kern `SEC-03 inbound: valid signed request accepted`; Pro (Reihenfolge und Statuscodes) | ungeprüft |
| Eingehend ohne oder mit falscher Signatur | abgelehnt, fester Code, Vergleich in konstanter Zeit | Kern `SEC-03 … wrong signature refused` | ungeprüft |
| Eingehend: unbekannte oder widerrufene Verbindung | `401 unknown_connection`, vor jeder weiteren Prüfung; zu großer Body zuerst abgelehnt | Kern `SEC-03 … revoked or unknown connection refused, oversized body refused first` | ungeprüft |
| Eingehend: Auslöser nicht freigegeben (unzulässiger Umfang) | abgelehnt; nur Auslöser der Allowlist der Verbindung | Kern `SEC-03 … trigger not on the allowlist refused`; Kern prüft Felder und Typen noch einmal (`InboundTriggers`) | ungeprüft |
| Ausgehend: Ereignistypen und Felder außerhalb der Verbindung | nicht gesendet; verbotene Namen nie | Pro (Feldfilter) | ungeprüft |
| Replay (gleiche Nonce) | abgelehnt | Kern `SEC-03 … same nonce again is a replay` | ungeprüft |
| Zeitstempel außerhalb des Fensters | abgelehnt | Kern `SEC-03 … timestamp outside the window refused` | ungeprüft |
| Doppelte Zustellung ausgehend | dieselbe Ereignis-`id`; n8n verwirft über „Doppelte verwerfen“ | Pro (Wiederholung mit derselben `id`); Vorlagen enthalten den Schritt | ungeprüft (Vorlagen nicht importiert) |
| Rate-Limit eingehend | abgelehnt je Verbindung | Kern `SEC-03 inbound: rate limit per connection` | ungeprüft |
| n8n nicht erreichbar / Netzwerkfehler | `TransientFailure`, Wiederholung nach Plan, höchstens 8 Mal; Outbox `retry` | Pro | ungeprüft |
| Zeitüberschreitung | wie Netzwerkfehler; Zeitlimit 30 s | Kern `SafeHttp: transport errors become fixed codes …`; Pro | ungeprüft |
| `5xx`, `408`, `425`, `429` | vorübergehend, Wiederholung | Pro | ungeprüft |
| andere `4xx` (z. B. `404` bei inaktivem n8n-Workflow) | dauerhaft, Lauf im Fehlerzustand, Outbox `failed` | Pro | ungeprüft |
| Test-URL `/webhook-test/` bei aktiver Verbindung | abgelehnt | Pro | ungeprüft |
| Rotation der Geheimnisse | neu erzeugt, einmal angezeigt, danach maskiert; alter Wert sofort ungültig | Pro; Schlüsselwechsel des Kerns Kern `SEC-02 rotation …`, `SEC-02 rekey …` | ungeprüft (n8n-Credential nachziehen) |
| SSRF: private, Loopback-, Metadaten-Adressen, DNS-Rebinding, IP-Schreibweisen | abgelehnt; Metadaten auch mit Ausnahme | Kern `SEC-01` (19 Fälle), `SafeHttp …` (Weiterleitungen geprüft, höchstens 3, auf geprüfte IP festgelegt) | – (betrifft WordPress, nicht n8n) |
| PII-Redaktion | kein Code, keine E-Mail, kein Name, kein Freitext im Umschlag; Fehlermeldungen ohne URL, Header, Body, Geheimnis | Kern Validator und `SEC-02 decrypted values are scrubbed from text`; Pro (Ergebnis nur als fester Code) | ungeprüft (Ansicht der Ausführung in n8n prüfen) |
| Lizenz von Pro abgelaufen | nichts Neues gesendet, offene Zustellungen pausiert; eingehend `403` | Pro | ungeprüft |

## 3. Ablauf für die Prüfung mit echtem n8n (vor dem Release)

1. n8n in der Zielversion starten (Cloud-Testkonto oder `docker run n8nio/n8n:<version>`), Version in Abschnitt 1 eintragen.
2. Die vier Vorlagen importieren; je Vorlage Header-Auth-Credential anlegen.
3. In Workflow Captain Pro je Richtung eine Verbindung anlegen, Verbindungstest, aktivieren.
4. Je Zeile in Abschnitt 2 den Fall auslösen (n8n-Workflow deaktivieren für `404`, Docker anhalten für „nicht erreichbar“, Code-Knoten mit `sleep` für Zeitüberschreitung, Credential ändern für Rotation) und das Ergebnis in der Spalte „Mit n8n“ mit Datum und Version eintragen.
5. Ergebnis in `docs/E2E-RESULTS.md` festhalten.
