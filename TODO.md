# Workflow Captain – Umsetzungsliste

**Quelle:** migrierte Umsetzungspunkte aus den Abschnitten 15.11 und 18.7 des Produktkonzepts. Das Konzept bleibt maßgeblich für Umfang, Verträge und Produktentscheidungen.

**Festgelegt:** Free-Core bleibt eigenständig; Pro-Add-on workflow-captain-pro. WPLC Produktkey workflow-captain-pro, Präfix workflow.; Jahrespreise Single 129 EUR/1 Live-Site, Business 229 EUR/5, Agency 399 EUR/25. Alle Pläne erhalten denselben Pro-Funktionsumfang.

### 15.11 Was noch fehlt – Umsetzungsaufgaben, keine offenen Produktentscheidungen

**Öffentliche Verträge**
- [ ] Für jeden der sechs Captain-Provider Trigger-/Action-Schema, Vertragsversion, Berechtigung, Fehlercodes und minimale Payload in den Fachplugin-Repositories veröffentlichen.
- [ ] Pass Captain: versionierte Brücke für Check-in, Ausgabe, Widerruf und Eventabsage statt direkter Abhängigkeit auf interne Hooks.
- [ ] Voucher Captain: versionierte Events/Services für Ausstellung, Zustellung, Einlösung, Rückgabe und Geheimnisredaktion.
- [ ] Review Captain: idempotente Request-/Cancel-API mit Suppression, Opt-out, Frequenzlimit und Statusrückgabe.
- [ ] Form Captain: Feldklassen und Einwilligungs-/Übertragungsregeln je Feld festlegen und in den Submission-Service einbauen.
- [ ] Affiliate Captain: Provision-/Refund-Aktionen ausschließlich über kanonischen Verkaufsvertrag; keine zweite Buchung aus Workflow Captain.
- [ ] WP License Captain: nur benachrichtigungsfähige Lizenzereignisse; keine generischen Lizenzänderungen als Workflow-Aktionen.

**Produktumsetzung**
- [ ] Die bestätigten Entitlements workflow.suite_integrations, workflow.webhooks, workflow.event_correlation, workflow.advanced_logic, workflow.recipe_templates und workflow.extended_logs in wp-license/captain-pro anlegen.
- [ ] Produktkey und drei Jahrespläne im Shop/WP License Captain mit 1/5/25 Sites und oben festgelegten Preisen konfigurieren.
- [ ] WordPress.org-Verfügbarkeit des Produktnamens/Slugs vor Einreichung prüfen.
- [ ] UI-Entwürfe der Tabellen, Workflow-Canvas/Schrittfolge, Branching, Zeitplanung, Fehler-Replay und Lizenzablauf umsetzen.
- [ ] Release-Readme, Entwickler-SDK, Anbieterübersicht, Datenschutz-Datenfluss und Support-Diagnose schreiben.

**Abnahme und Betrieb**
- [ ] E2E-Matrix für HPOS, Classic/Blocks, Woo-Refunds, Suite-Event-Duplikate, Parallelität, Queue-Ausfall, Retry, Lizenzablauf, Backups und Plugin-Deaktivierung erstellen.
- [ ] Kompatible Versionen der einzelnen Captain-Provider je Plugin in einer Matrix veröffentlichen.
- [ ] Datenschutz-/Rechtsprüfung der E-Mail-, Review- und Webhook-Datenflüsse für Zielregionen durchführen.
- [ ] Queue-Batchlimits und System-Cron-Empfehlungen gegen kleine und große Shops messen.
- [ ] Supportkapazität für Standard/Priority einrichten; Responsezeiten erst danach veröffentlichen.
- [ ] Lizenzclient/Updater und beide ZIPs (Free/Pro) in einer echten WooCommerce-Testinstallation freigeben.

### 18.7 Neue technische To-dos nach der Suite-Prüfung

**P0 – Providerverträge**

- [ ] Suite-weite SemVer-, Envelope-, Privacy- und Ownership-Konvention in allen Plugin-Repositories festlegen.
- [ ] Für jeden Command Idempotency-ID, Seiteneffekt, Retryregeln, Capability/Entitlement und Auditformat dokumentieren.
- [ ] Ownership-Handshake mit „Plugin-eigen“ als Default in den Core-Plugins implementieren.
- [ ] Events nach Commit und Source-Outbox/Replay für kritische Ereignisquellen bereitstellen.
- [ ] Woo/Refund-Verknüpfung zu Voucher, Pass und Affiliate als eine kanonische Quelle testen.
- [ ] WP License, Woo, Voucher, Pass, Affiliate, Form und Review Provider mit Plugin-fehlt-, Lizenz-fehlt- und Contract-Major-Verhalten ergänzen.

**P1 – Adapter und Workflow-Steuerung**

- [ ] Adapter für alle v1-Trigger aus Abschnitt 17 umsetzen.
- [ ] Actions pro Zielplugin ausschließlich über öffentliche Serviceverträge implementieren.
- [ ] Installations-/Migrationsassistent für native Erinnerungen/Benachrichtigungen, Draftimport, Duplikaterkennung, Besitzerwechsel und Rollback bauen.
- [ ] Providerstatus, aktive Workflowlinks und gemeinsame Run-/Correlation-IDs in Suite-Admins anzeigen.
- [ ] Testmatrix für Workflow vorhanden/fehlend/deaktiviert, Free/Pro/abgelaufene Lizenz, Fachplugin-Pro-Recht, inkompatiblen Vertrag und doppelte Events ergänzen.

**P2 – Betrieb**

- [ ] Supportdiagnose-Export ohne PII/Secrets erstellen.
- [ ] Source-Outbox-Replay und Workflow-Queue-Retry unterscheiden und idempotent machen.
- [ ] Anonymisierung/Löschung über Source- und Workflow-Logs abstimmen, ohne Geschäftsledger zu löschen.
- [ ] Aktivierung/Deaktivierung, Backup/Restore und Multisite pro Provider validieren.

### Suite-Provider: verbindliche Implementierungsaufgaben

- [ ] WF-INT-01 – Eine maschinenlesbare Providerregistry aus der Trigger-/Action-Matrix in Abschnitt 17 erstellen. Pro Fähigkeit: stabile ID, Schema, Mindest-Core-Version, Free/Pro-Recht, Zielplugin-Recht, Capability, Payload-Allowlist, Side-Effect, Retry und Fehlercodes.
- [ ] WF-INT-02 – Providerverträge v1 mit WP License, WooCommerce, Voucher, Pass, Affiliate, Form und Review versionieren. Events nach Commit; Quell-Outbox für kritische Quellen; unbekannte optionale Felder tolerant, Major-Inkompatibilität sicher deaktivieren.
- [ ] WF-INT-03 – Fach-Commands in Suite-Plugins öffentlich und versioniert bereitstellen lassen; keine direkten Tabellen, Meta-Schreibpfade oder internen Klassen verwenden.
- [ ] WF-INT-04 – Ownership-Handshake als Plugin-eigenen Default implementieren. Nur optionale Kommunikation/Erinnerungen können nach Vorschau und Bestätigung übergeben werden; kritische Geschäftsabläufe bleiben Domainplugin-eigen.
- [ ] WF-INT-05 – Woo-Provider über Woo CRUD und Order-Abstraktionen für HPOS/Classic implementieren; Bestellung, Zahlung, Refund/Chargeback, Produkt/Bestand und Kundenstatus als Trigger.
- [ ] WF-INT-06 – License-Provider read-only; Status/Entitlement auslösen, aber keine Lizenzänderungen; Lizenzgeheimnisse ausschließen und Self-trigger-Loops verhindern.
- [ ] WF-INT-07 – Voucher-Provider auf Issuer-/Redemption-Services setzen; Zustellung, Einlösung, Ablauf, Refund und Widerruf idempotent abbilden; Code/PII redigieren.
- [ ] WF-INT-08 – Pass-Provider mit stabiler Ticket-/Order-Line-ID, Refund-Semantik, Teilnahme/Check-in und Code-/Teilnehmer-PII-Redaction umsetzen.
- [ ] WF-INT-09 – Affiliate-Provider auf CONTRACT v1.3 setzen, Verkaufs-/Refund-Dedupe erhalten und keine Payout-Automation anbieten.
- [ ] WF-INT-10 – Form-Provider nur freigegebene Felder und explizite Actions liefern; keine automatische Zahlung, Ticket-/Gutscheinausgabe, Partnerfreigabe oder Review-Veröffentlichung.
- [ ] WF-INT-11 – Review-Provider schützt Consent, Einladung, Opt-out, Suppression, Frequenzlimit, Plattformziel und Moderation vor Workflow-Umgehung.
- [ ] WF-INT-12 – Pro-Features nur bei workflow-captain-pro Recht registrieren; Fachplugin-Pro-Recht zusätzlich prüfen, ohne Free-Core oder Daten zu sperren.
- [ ] WF-INT-13 – WPLC-Katalog/Shop verbindlich abgleichen: workflow-captain-pro; workflow.*; Single 129 EUR/1 Site, Business 229 EUR/5 Sites, Agency 399 EUR/25 Sites pro Jahr; gleiche Pro-Funktionen in allen Plänen.
- [ ] WF-INT-14 – Adminseiten für Provider, Vertragsversion, Verfügbarkeit, Testevent, Ownership, aktive Workflows, Fehler, Retry und Diagnoseexport bauen.
- [ ] WF-INT-15 – Migrationsassistent zeigt Vorschau, sichert Nativeinstellungen, erkennt Duplikate, verlangt Bestätigung und erlaubt Besitzerwechsel/Rollback.
- [ ] WF-INT-16 – Kompatibilitätsmatrix für WP, Woo HPOS/Classic, Checkout Blocks, Multisite, PHP, Action Scheduler/Cron und alle Suite Core/Pro-Kombinationen festlegen.
- [ ] WF-INT-17 – Vertrags-/E2E-Matrix für Plugin fehlt/deaktiviert, Lizenz/Recht fehlt/abgelaufen, Contract Major, Timeout nach erfolgreichem Zielaufruf, Replay, Duplicate, Refund, Restore und Multisite ergänzen.
- [ ] WF-INT-18 – Release-Gate für getestete ZIPs aller geänderten Plugins, WPLC-Shop-Sandbox, Kauf/Aktivierung/Entitlement/Update/Refund/Deaktivierung, Migration und Rollback definieren.
