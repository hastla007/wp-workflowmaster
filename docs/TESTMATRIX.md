# Testmatrix

Stand: Plugin 0.2.x, 16.11.2026. Aufgaben WF-INT-17, WF-BUILDER-12, WF-MS-23 und die E2E-Matrix unter „Abnahme und Betrieb“ in [`../TODO.md`](../TODO.md).

**Automatisch** heißt: Der Test läuft in der CI. **Offen** heißt: Der Fall braucht eine echte WordPress-Installation, WooCommerce oder einen Adapter aus `captain-pro` und ist noch nicht geprüft. Die Tests in `tests/run.php` laufen ohne WordPress auf SQLite. Sie prüfen das echte SQL der Speicher, aber nicht `dbDelta`, Hooks, Cron oder die Admin-Seiten im Browser einer echten Installation.

## Consumer (Spezifikation 14.3)

| Fall | Stand | Fundstelle |
|---|---|---|
| C-1 kein Lizenzcode, kein Produktschlüssel | automatisch | `tests/license-guard.php` mit Selbsttest |
| C-2 Schemaprüfung, Ablehnung ohne Werte | automatisch | `tests/run.php` „Capture“ |
| C-3 UUID v4, UTC | automatisch | `tests/run.php` |
| C-4 Doppelte | automatisch | `tests/run.php` |
| C-5 Wiederholungsplan, dead_letter sichtbar | automatisch | `tests/run.php` |
| C-6 abgebrochener Worker | automatisch | `tests/run.php` |
| C-7 Gegenlesen | automatisch | `tests/run.php` |
| C-8 Selbststart, Tiefe | automatisch | `tests/run.php`, `tests/engine-tests.php` (R-7.14 mit echter Engine) |
| C-9 Kausalkette | automatisch | beide Testdateien (auch aus einer laufenden Aktion) |
| C-10 keine doppelte Ausführung | automatisch | beide Testdateien |
| C-11, C-12 | automatisch | Wächtertest |
| C-13 Export und Löschung | automatisch (Callbacks) / offen (WordPress-Werkzeuge) | `tests/run.php`, `tests/engine-tests.php` |
| C-14 Aufbewahrung | automatisch | beide Testdateien |
| C-15 Adapter weg | automatisch | `tests/run.php` |
| C-16 Multisite-Trennung | automatisch für Ereignisspeicher und Engine | `tests/run.php`, `tests/engine-tests.php` |

## Engine und Builder

| Fall | Stand | Fundstelle |
|---|---|---|
| Graph-Validierung (Start, Kanten, Zweige, Zyklen, Konfiguration) | automatisch | `tests/engine-tests.php` |
| Linearer Ablauf, Dann/Sonst, Bedingung mit fehlendem Feld | automatisch | `tests/engine-tests.php` |
| Verschachtelte Bedingung im Limit | automatisch (Bedingungen) | `tests/engine-tests.php` |
| Wartezeit (Stunden, Kalendertage über Sommerzeit) | automatisch | `tests/engine-tests.php` |
| Abbruch, Fortsetzen nach Entscheidung | automatisch | `tests/engine-tests.php` |
| Fehler und Wiederholung (R-8.11) | automatisch | `tests/engine-tests.php` |
| Unklarer Ausgang einer Nachricht (R-8.12) | automatisch | `tests/engine-tests.php` |
| Entzogene Berechtigung (R-8.8, A-10) | automatisch | `tests/engine-tests.php` |
| Entwurf / Veröffentlichen / Version bleibt für laufende Läufe | automatisch | `tests/engine-tests.php` |
| Parallelität, Fairness, Serialisierung | automatisch | `tests/engine-tests.php` |
| Heartbeat, hängende Arbeit | automatisch | `tests/engine-tests.php` |
| Testlauf ohne Seiteneffekte, Providerverfügbarkeit | automatisch | `tests/engine-tests.php` |
| Datenfluss ohne persönliche Daten (WF-SEC-04) | automatisch | `tests/engine-tests.php` |
| Canvas: Knoten, Verbinden, Dann/Sonst, Tastatur, Rückgängig, Bedingungsformular, Platzhalter, Zoom | automatisch (Chromium) | `tests/builder.test.js` |
| Canvas: Speichern, Veröffentlichen und Wiederöffnen in WordPress | offen | braucht WordPress |
| Admin-Seiten (Ereignisse, Workflows, Läufe, Provider, Einstellungen) im Browser | offen | braucht WordPress |

## Sicherheit

| Fall | Stand | Fundstelle |
|---|---|---|
| Ausgehende Ziele: privat, Metadaten, Schreibweisen, IPv6, Ausnahme je Verbindung | automatisch | `tests/security-tests.php` |
| Tresor: Kontext, Rotation, Manipulation, Redaktion | automatisch | `tests/security-tests.php` |
| Weiterleitungen und Proxy beim echten HTTP-Aufruf | offen | kommt mit den Webhooks |
| REST-Einstiege, Rate-Limits (WF-SEC-03) | offen | es gibt noch keine REST-Einstiege |

## Adapter (14.2) und Provider

| Fall | Stand |
|---|---|
| A-3 bis A-9 | Testkit `tests/adapter-kit/` bereit; offen, bis es Adapter in `captain-pro` gibt |
| A-1, A-2, A-10, A-11 | beschrieben in `tests/adapter-kit/README.md`; offen |
| Plugin fehlt oder deaktiviert, Lizenz fehlt oder abgelaufen, Vertrags-MAJOR | Kern-Seite automatisch (C-15, Providerverfügbarkeit); Adapter-Seite offen |
| Timeout nach erfolgreichem Zielaufruf, Replay, Doppelte | Kern-Seite automatisch (R-8.12, C-4, C-10); Gutschein-Befehle offen (VC-1) |
| Refund, Woo HPOS/Classic, Checkout Blocks | offen (braucht WooCommerce und Adapter) |
| Wiederherstellung aus Backup | Verfahren in `docs/RUNBOOK.md`; Konsistenzprüfung automatisch getestet; Durchlauf mit echter Sicherung offen |

## Multisite

| Fall | Stand |
|---|---|
| Ereignisse je Website getrennt (C-16) | automatisch |
| Engine-Tabellen je Website getrennt | automatisch (`tests/engine-tests.php`, WF-CORE-09) |
| Netzwerkaktivierung, Löschen einer Website (`wpmu_drop_tables`) | offen |
| Netzwerkweite Abläufe Site A → Site B (WF-MS-23) | nicht gebaut; offen bis F-07 |

## Laufzeitumgebung (WF-INT-16)

| Umgebung | Stand |
|---|---|
| PHP 8.1 bis 8.4 | automatisch (CI-Matrix `php.yml`) |
| WordPress 6.4 bis aktuell, MySQL/MariaDB | offen |
| Action Scheduler statt WP-Cron | offen (heute WP-Cron mit Minutentakt) |
