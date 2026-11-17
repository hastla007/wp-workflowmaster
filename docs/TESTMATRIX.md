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
| Vertragsversion je Provider im Snapshot, Pause bei MAJOR-Wechsel (WF-CORE-02) | automatisch | `tests/engine-tests.php` |
| Zeitplan-Auslöser: lokale Uhrzeit, Sommerzeit, einmal nachholen, nur eigener Workflow, doppelter Zeitgeber (WF-CORE-04) | automatisch | `tests/engine-tests.php`, Formular in `tests/builder.test.js` |
| Mögliche Doppelmail zur Kernmail (R-9.4) | automatisch (Chromium) | `tests/builder.test.js` |
| Einstellungen: Feldfehler, Warnung bei ungespeicherten Änderungen, Zurücksetzen (WF-SET-13) | automatisch | `tests/engine-tests.php`, `tests/settings.test.js` |
| Canvas: Speichern, Veröffentlichen und Wiederöffnen in WordPress | offen | braucht WordPress |
| Admin-Seiten (Ereignisse, Workflows, Läufe, Provider, Einstellungen) im Browser | offen | braucht WordPress |

## Sicherheit

| Fall | Stand | Fundstelle |
|---|---|---|
| Ausgehende Ziele: privat, Metadaten, Schreibweisen, IPv6, Ausnahme je Verbindung | automatisch | `tests/security-tests.php` |
| Tresor: Kontext, Rotation, Manipulation, Redaktion | automatisch | `tests/security-tests.php` |
| Ausgehende Anfragen: Profile, IP-Festnageln, geprüfte Weiterleitungen, Fehlercodes ohne URL (`SafeHttp`) | automatisch (mit Fake-Transport) | `tests/security-tests.php` |
| Eingehende Anfragen: Größe, Rate-Limit, Zeitfenster, HMAC, Replay, Allowlist (`InboundGuard`) | automatisch | `tests/security-tests.php` |
| Echter HTTP-Aufruf mit cURL-Festnageln und Proxy | offen | braucht WordPress und ein Ziel |
| Keine Frontend-Ausgabe, keine Yoast-Interna, keine Beitrags-Schreibzugriffe (WF-SEO-03 bis -05) | automatisch | `tests/security-tests.php` |
| REST-Einstiege von Pro mit `InboundGuard` | offen | kommen mit Workflow Captain Pro |

## Adapter (14.2) und Provider

| Fall | Stand |
|---|---|
| A-3 bis A-9 | automatisch in `captain-pro` (`tests/Addons/WorkflowSuiteAdaptersTest.php`, 44 Tests, prüft jedes Ereignis zusätzlich mit dem Testkit `tests/adapter-kit/` dieses Kerns; am 16.11.2026 gegen `captain-pro` `8d9ba77` und diesen Kern grün) |
| A-1, A-2, A-10, A-11 | A-2 (ohne Lizenz des Produkt-Add-ons keine Anmeldung) und A-11 (Lizenzende während der Anfrage) automatisch in `captain-pro`; A-1 durch den Wächtertest `tests/license-guard.php`; A-10 beschrieben in `tests/adapter-kit/README.md` |
| Plugin fehlt oder deaktiviert, Lizenz fehlt oder abgelaufen, Vertrags-MAJOR | Kern-Seite automatisch (C-15, Providerverfügbarkeit); Adapter-Seite automatisch in `captain-pro` (`test_an_adapter_registers_only_with_a_known_contract`, `test_a_new_major_of_the_license_contract_keeps_the_adapter_off`, `test_without_the_license_of_the_product_add_on_its_adapter_does_not_register`, `test_without_the_workflow_pro_license_no_adapter_is_hooked`, `test_a_product_license_that_ends_inside_the_request_stops_the_capture`); Form und Review haben in Version 1 keinen Provider (4.6); in einer echten Installation offen |
| Timeout nach erfolgreichem Zielaufruf, Replay, Doppelte | Kern-Seite automatisch (R-8.12, C-4, C-10); Adapter-Seite (gleicher Schlüssel für dieselbe Action, A-7) automatisch in `captain-pro`; Gutschein-Befehle als Workflow-Aktionen offen (WF-INT-03) |
| Refund, Woo HPOS/Classic, Checkout Blocks | offen (braucht WooCommerce und Adapter) |
| Wiederherstellung aus Backup | Verfahren in `docs/RUNBOOK.md`; Konsistenzprüfung und sichere Wiederholung einer abgebrochenen Aktualisierung (`Migrator`, WF-CORE-07) automatisch getestet; Durchlauf mit echter Sicherung offen |

## WordPress, WooCommerce und eigene Connectoren

| Fall | Stand | Fundstelle |
|---|---|---|
| Sieben Ereignisse aus WordPress und WooCommerce: gültiger Umschlag, nur Katalogfelder, keine personenbezogenen Daten, Diskriminator, Gegenlesen, Ausnahmen bleiben im Adapter (F-20) | automatisch (mit Fake-WooCommerce) | `tests/provider-tests.php` |
| WooCommerce fehlt oder zu alt: Adapter meldet sich nicht an | automatisch | `tests/provider-tests.php` |
| Echte Bestellung, HPOS und klassisch, Checkout Blocks, Erstattung | offen | braucht WooCommerce |
| Connector: Manifest, Gründe der Ablehnung, Freigabe, nicht deklarierte und personenbezogene Felder, Typ/Produkt, Lauf startet, Starter-Connector (F-22) | automatisch | `tests/connector-tests.php` |
| Workflow fehlt, deaktiviert, Lizenz abgelaufen (Pro), Vertrags-MAJOR | Kern-Seite automatisch (C-15, Snapshot, Connector-Gründe); Pro-Seite in `captain-pro` | – |

## Abnahme eigener Connectoren (WF-CONNECTOR-13)

| Fall | Stand | Fundstelle |
|---|---|---|
| Connector-Plugin fehlt oder deaktiviert: offene Ereignisse pausieren, nach Rückkehr erneut einreihen | automatisch | `tests/connector-tests.php` („release: … pauses“, „requeued after switching on“) |
| Vertrag einer anderen Hauptversion, Kern zu alt, ungültiges Manifest, doppelt | automatisch | `tests/connector-tests.php` („registry: …“) |
| Laufzeit nicht freigeschaltet (Pro, F-18a) | automatisch | `tests/connector-tests.php` (WF-CONNECTOR-12) |
| Fehlende Rechte: Aktion nicht angeboten, Veröffentlichen verweigert, Schritt pausiert | automatisch | `tests/connector-tests.php` („actions: …“, „starter: graph …“), `tests/engine-tests.php` (R-8.8) |
| Ungültige Signatur, Replay, Rate-Limit bei eingehenden Aufrufen | automatisch | `tests/security-tests.php` (`InboundGuard`), Pro `WorkflowWebhooksTest` |
| Doppeltes Ereignis | automatisch | `tests/connector-tests.php` („starter: duplicate …“) |
| Timeout nach erfolgreichem Aufruf einer Connector-Aktion | automatisch (allgemein) | `tests/engine-tests.php` (R-8.12 unklarer Ausgang); idempotente Aktion wird mit demselben Schlüssel wiederholt („starter: repeating …“) |
| Personenbezogene Felder nur nach Freigabe, nicht deklarierte Felder verworfen, falscher Typ verwirft | automatisch | `tests/connector-tests.php` (WF-CONNECTOR-11, R-5.2) |
| Umgehung über eigenen Wrapper | automatisch | `tests/connector-tests.php` („bypass: …“) |
| Ende-zu-Ende mit echtem Connector-Plugin in WordPress | offen | Starter-Connector und LearnBoating-Connector in der echten Installation |

## Multisite

| Fall | Stand |
|---|---|
| Ereignisse je Website getrennt (C-16) | automatisch |
| Engine-Tabellen je Website getrennt | automatisch (`tests/engine-tests.php`, WF-CORE-09) |
| Netzwerkaktivierung, Löschen einer Website (`wpmu_drop_tables`) | offen |
| Netzwerkweite Abläufe Site A → Site B (WF-MS-23) | nicht gebaut; offen bis F-07 |

## Laufzeitumgebung (WF-INT-16)

Mindestversionen seit 17.11.2026 (MINVER-1): PHP 8.5, WordPress 7.0, WooCommerce 11.2.2 (Adapter), MySQL 9.7 oder MariaDB 12.3 (älterer Server nur Warnung). Einzelheiten in [`KOMPATIBILITAET.md`](KOMPATIBILITAET.md).

| Umgebung | Stand |
|---|---|
| Prüfung der Mindestversionen mit eingesetzten Versionen (PHP 8.4 abgelehnt, 8.5 ok; WordPress 6.9 abgelehnt, 7.0 ok; MySQL 8.4/MariaDB 11.4 Warnung, 9.7/12.3 ok; WooCommerce-Adapter unter 11.2 abgelehnt mit Grund) | automatisch (`tests/engine-tests.php`, `tests/provider-tests.php`, „MINVER-1 …“) |
| PHP 8.5 ohne WordPress (Syntax, `tests/run.php`; Deprecations gelten als Fehler); PHP 8.6 experimentell | automatisch (CI-Matrix `php.yml`; lokal läuft `tests/run.php` weiter auf 8.3) |
| PHP 8.5 mit WordPress 7.1.4 und WooCommerce 11.3.0 gegen MySQL 9.7 und MariaDB 12.3; Mindestversionen WordPress 7.0.8/WooCommerce 11.2.2 gegen MariaDB 12.3; PHP 8.6 gegen MySQL 9.7 experimentell | automatisch (`e2e-db.yml` mit `bin/e2e/ci-db.sh`; erster grüner Lauf steht aus) |
| Dieselben Prüfungen gegen MariaDB 10.11 mit PHP 8.3 | historisch (96 von 96 am 16.11.2026, vor MINVER-1) |
| WordPress 6.4.5, 6.9.11, 7.1.4 auf echter Installation mit PHP 8.3 | historisch ([`E2E-RESULTS.md`](E2E-RESULTS.md)); mit PHP 8.5 nur in der CI |
| Echte Installation mit PHP 8.5/8.6, MySQL 9.7 bzw. MariaDB 12.3 und Browser (Admin-Seiten, Multisite, klassische Bestellablage) | offen (lokal kein PHP 8.5; Test des Inhabers auf einem echten Host) |
| Action Scheduler statt WP-Cron | automatisch in `e2e-db.yml` (a6, a10–a12, j5); lokal geprüft |
