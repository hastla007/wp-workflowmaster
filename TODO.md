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
- [ ] Die bestätigten Entitlements workflow.suite_integrations, workflow.custom_connectors, workflow.webhooks, workflow.event_correlation, workflow.advanced_logic, workflow.recipe_templates und workflow.extended_logs in wp-license/captain-pro anlegen.
- [ ] Produktkey und drei Jahrespläne im Shop/WP License Captain mit 1/5/25 Sites und oben festgelegten Preisen konfigurieren.
- [ ] WordPress.org-Verfügbarkeit des Produktnamens/Slugs vor Einreichung prüfen.
- [ ] UI-Entwürfe der Tabellen, Workflow-Canvas/Schrittfolge, Branching, Zeitplanung, Fehler-Replay und Lizenzablauf umsetzen.
- [ ] WF-BUILDER-01 – Drag-&-Drop-Canvas mit Palette für Trigger, Aktion, If/Else, Delay/Wait und Ende implementieren; Knoten per Tastatur hinzufügen und verschieben können.
- [ ] WF-BUILDER-02 – Graph-Datenmodell mit stabilen Node-/Edge-IDs, Positionen, Typen, Konfigurationen, Provider-ID und Contract-Version definieren; Drafts und unveränderliche veröffentlichte Snapshots versionieren.
- [ ] WF-BUILDER-03 – Gerichtete Verbindungen mit Pfeilen und Anschluss-Punkten bereitstellen; If/Else-Ausgänge dauerhaft als „Dann“ und „Sonst“ beschriften und visuell getrennt führen.
- [ ] WF-BUILDER-04 – Knoten-Konfigurator für Provider, Trigger/Ereignis, Aktion, Feld, Operator, Wert und Tokenpicker aus der maschinenlesbaren Providerregistry speisen; Datentypen und zulässige Payload-Felder anzeigen.
- [ ] WF-BUILDER-05 – Bedingungseditor für einfache Vergleiche sowie begrenzte AND/OR-Gruppen umsetzen; fehlende Werte mit explizitem Verhalten (false/stop/error) behandeln.
- [ ] WF-BUILDER-06 – Servervalidierung vor Speichern und Veröffentlichen implementieren: genau ein Startpunkt je Flow, Pflichtkonfiguration, ungültige/unverbundene Kanten, unerreichbare Knoten, offene Branches, unerreichbare Enden, Zyklen und Provider-/Lizenz-/Contract-Kompatibilität prüfen.
- [ ] WF-BUILDER-07 – Ausführungsengine so anbinden, dass If/Else-Bedingungen genau einmal pro Step-Snapshot evaluiert und nur die ausgewählte Kante eingeplant wird; Run-Protokoll muss gewählten Ausgang, übersprungene Schritte und Bedingungsergebnis redigiert darstellen.
- [ ] WF-BUILDER-08 – Canvas-Werkzeuge für Zoomen, Verschieben, automatische Anordnung, Undo/Redo und Speichern als Entwurf bereitstellen; veröffentlichte Runs bleiben an ihren Graph-Snapshot gebunden.
- [ ] WF-BUILDER-09 – Responsives und barrierearmes Bedienen spezifizieren: Tastaturverbindungen/Node-Auswahl, Fokusführung, Screenreader-Beschriftungen, ausreichende Kontraste sowie alternative lineare Schrittansicht für kleine Displays.
- [ ] WF-BUILDER-10 – Testlauf/Dry Run mit Beispieldaten im Canvas integrieren; ausgewählten Ablaufpfad hervorheben, Fehler am betroffenen Knoten zeigen und keine echten Seiteneffekte auslösen.
- [ ] WF-BUILDER-11 – Provider-Verfügbarkeit im Canvas behandeln: fehlendes/deaktiviertes Plugin, fehlendes Fachplugin-Pro-Recht oder inkompatibler Major-Contract muss Knoten klar markieren und Veröffentlichung sicher blockieren.
- [ ] WF-BUILDER-12 – Canvas-E2E-Abnahme für linearen Ablauf, Dann-/Sonst-Pfad, verschachtelte Bedingung im erlaubten Limit, Delay/Wait, Abbruch, Fehler/Retry, Draft/Publish und Wiederöffnung gespeicherter Workflows dokumentieren und ausführen.
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

### Kundenspezifische Connectoren – Umsetzung v1

- [ ] WF-CONNECTOR-01 – Öffentlichen, versionierten `workflow_captain_connectors_v1`-Providervertrag festlegen: stabiler Connector-Schlüssel, Name, Version, Contract-Major, Abhängigkeiten, Trigger-/Action-Schemas, Datenklassen, Capabilities/Entitlements, Idempotenz, Retry und Seiteneffekte.
- [ ] WF-CONNECTOR-02 – Öffentliche Event-Publish- und Action-Dispatch-API im Core definieren; Events nur nach Commit mit stabiler Event-ID und typisiertem Envelope annehmen, Actions als typisierte Commands an den registrierten Connector zurückgeben.
- [ ] WF-CONNECTOR-03 – Registry/Discovery implementieren: Connector-Plugin-Status, Versionskompatibilität, fehlende Abhängigkeit und optionale Fähigkeiten erkennen; Provider isoliert deaktivieren, wenn Vertrag oder Zielplugin nicht passt.
- [ ] WF-CONNECTOR-04 – Separate Connector-Plugins für kundenspezifische WordPress-Projekte ermöglichen; diese adaptieren nur deren öffentliche Hooks/API, schreiben keine Workflow- oder Fachplugin-Tabellen direkt und validieren ihre Commands im eigenen Domainservice erneut.
- [ ] WF-CONNECTOR-05 – Starter-Connector mit Beispiel-Trigger, Beispiel-Action, Contract-Manifests, Fehlercodes, Idempotenz und Integrationstest bereitstellen; Entwicklerdokumentation mit End-to-End-Beispiel ergänzen.
- [ ] WF-CONNECTOR-06 – Eingehende externe Webhooks implementieren: HTTPS, eigener Schlüssel/Secret je Verbindung, HMAC-SHA256 über den rohen Body, Zeitstempel-/Replay-Schutz, stabile Event-ID, Rate Limits und Deduplizierung vor Eventannahme.
- [ ] WF-CONNECTOR-07 – Ausgehende Webhook-Aktion nur für vorher angelegte Ziele implementieren: HTTPS und Host-Allowlist, sichere HTTP-Funktionen, keine Weiterleitungen, DNS-/IP-Prüfung gegen localhost/private/reservierte Bereiche, keine URL-Werte aus Tokens, Timeout/Größenlimit sowie definierte Retryklassen.
- [ ] WF-CONNECTOR-08 – Secrets geschützt speichern, bei Einrichtung nur einmal vollständig anzeigen, bei späteren Ansichten maskieren und mit Erneuern/Widerrufen verwaltbar machen; Secrets, Signaturen und sensible Antworten aus Run-Logs redigieren.
- [ ] WF-CONNECTOR-09 – Admin-Unterseite „Eigene Connectoren“ bauen: lokal erkannte Plugins und externe Verbindungen, Typ/Name, Connector- und Contract-Version, Trigger/Aktionen, Berechtigungen, Kompatibilität, Freigabe, letzter erfolgreicher Kontakt, Testfunktion und Diagnose.
- [ ] WF-CONNECTOR-10 – Eigene Connector-Trigger und -Aktionen in Providerkatalog, Drag-&-Drop-Builder, Tokenpicker, Validierung, Dry Run und Run-Details einbinden; unbekannte bzw. nicht mehr registrierte Felder müssen Veröffentlichungen blockieren und Migration verlangen.
- [ ] WF-CONNECTOR-11 – Freigabe- und Datenschutzmodell umsetzen: Betreiber aktiviert jeden Connector ausdrücklich; Payload-Allowlist, PII-Default-off, Capability-Prüfung, Aufbewahrung, Export/Löschung und Datenfluss-Diagnose pro Verbindung dokumentieren.
- [ ] WF-CONNECTOR-12 – Connectoren mit Entitlement `workflow.custom_connectors` und externe Webhook-Verbindungen mit `workflow.webhooks` absichern; Lizenzablauf pausiert neue Pro-Runs, erhält Konfiguration/Logs lesbar und zeigt betroffene Workflows.
- [ ] WF-CONNECTOR-13 – Vertrags-/E2E-Abnahme für App fehlt/deaktiviert, falscher Contract-Major, fehlende Rechte, ungültige Signatur, Replay, Duplikat, Timeout nach erfolgreichem Zielaufruf, Secret-Rotation, Retry, PII-Redaktion und Lizenzablauf ergänzen.
- [ ] WF-CONNECTOR-14 – Praxisbeispiele und Dokumentation für Kundenportal/CRM, kundenspezifische Buchung, Membership-Zugang nach Gutschein-Einlösung und benutzerdefinierte Benachrichtigung veröffentlichen; klarmachen, dass der Connector die Fachlogik besitzt und Workflow Captain nur koordiniert.

### Kundenconnector 1 – BoatingSchool B2B / Laravel

- [ ] WF-CUST1-01 – Vorhandenen WooCommerce-Connector als fachlichen Owner von Paid-Order-Codezustellung, Idempotenz pro Order-Position, Retry und Refund-Sperrung festhalten; Workflow darf diese Befehle nicht nochmals ausführen.
- [ ] WF-CUST1-02 – In BoatingSchool einen eigenen dauerhaften Workflow-Event-Outbox-Dispatcher nach Commit ergänzen. Nicht die vorhandene `tenant.provision`-Shop-Outbox oder bloße Commerce-Logs als allgemeine Zustellgarantie voraussetzen.
- [ ] WF-CUST1-03 – Versionierte Events für Shopkonto registriert/verknüpft/deaktiviert, Provisionierung erfolgreich/fehlgeschlagen sowie Code-Batch geliefert/entwertet veröffentlichen; stabile Event-IDs und tenant-/shop-scoped Correlation-IDs nutzen.
- [ ] WF-CUST1-04 – Allowlist für Event-Payloads implementieren: Referenzen, Status, Menge und Kurs-/Produktcode; nie Gutscheinwerte, Linkcodes, Passwörter, unbestätigte E-Mail-/USt-Daten oder vollständige Lern-/Kontodaten versenden.
- [ ] WF-CUST1-05 – Separaten Workflow-Vertrag und Credentials mit eigener Key-ID, HMAC, Zeitfenster, Nonce/Replay-Schutz, Rotation, Rate-Limits und Contract-Handshake entwickeln; bestehende Commerce-Keys und `/api/commerce/v1`-Scopes nicht wiederverwenden.
- [ ] WF-CUST1-06 – Tenant-Scope serverseitig authentifizieren und RLS-Kontext pro Anfrage setzen; Tenant-IDs aus Ereignissen gelten nur als Referenz. Plattformweite Rechte als separaten, überprüfbaren Scope führen.
- [ ] WF-CUST1-07 – Begrenzte App-Aktionen nur über öffentliche Services zulassen: tenant-scoped Schuladmin-Hinweis und optional Gruppenmitglied-Einladung mit Rollenprüfung, Sitzplatz-/Tageslimits und Command-Idempotenz. Account-Freigabe/-Verknüpfung, Codeausgabe/-widerruf und Kursrechte bleiben ausgeschlossen.
- [ ] WF-CUST1-08 – BoatingSchool-Provider im WordPress-Connector/Workflow-Captain über Version, Status, Testevent, Payload-Mapping, Eventzustellung, Retry und Fehlerdiagnose sichtbar machen.
- [ ] WF-CUST1-09 – Integrationstests für Tenant-Isolation, RLS, falsche/abgelaufene Signatur, Replay, doppelte Event-ID, HMAC-Key-Rotation, Timeout nach erfolgreichem Zielaufruf, Refund, Code-Redaktion und Outbox-Replay ergänzen.

### Kundenconnector 2 – LearnBoating B2C / WordPress

- [ ] WF-CUST2-01 – In LearnBoating einen öffentlichen, versionierten Workflow-Event-/Action-Vertrag ergänzen. Das Plugin bleibt ohne Workflow Captain eigenständig; keine direkten Tabellen-/Meta-Zugriffe des Workflow-Adapters.
- [ ] WF-CUST2-02 – Separaten `LearnBoating Workflow Connector`-Adapter mit Providerregistrierung, Mindestversion, Status und Lizenz-/Capability-Prüfung bereitstellen.
- [ ] WF-CUST2-03 – Idempotente, nachholbare Ereignisse aus OrderSync/EntitlementService für geänderte Zugänge, aus GroupService für Gruppenmitgliedsstatus, aus VoucherGrants für bestätigte Voucher-Zugänge und optional aus Ticket-Hooks publizieren; Event-ID muss Wiederholung und echten Statuswechsel unterscheiden.
- [ ] WF-CUST2-04 – Ereignis `learning.entitlement.changed` als kanonische Quelle für Zugangs-Onboarding definieren; Order-, Voucher- und Auto-Reconcile-Wege korrelieren, damit ein entitlementspezifischer Workflow nicht zusätzlich auf dasselbe Woo-Zahlungsereignis reagiert.
- [ ] WF-CUST2-05 – Prüfungssubmission höchstens als freigegebenen Zusammenfassungsstatus publizieren; keine Fragen, Antworten, Einzelversuche oder vollständigen Lern-/Leistungsprofile in Workflow-Payloads, Bedingungen oder Logs aufnehmen.
- [ ] WF-CUST2-06 – Vorlagenbasierte transaktionale Lernnachricht nur mit serverseitiger Auflösung der Nutzerreferenz, zweckgebundenen Feldern und Datenschutz-/Abmeldeprüfung bereitstellen; keine freie Empfängeradresse aus einem Workflow-Token.
- [ ] WF-CUST2-07 – Gruppenmitglied-Einladung optional als Action über GroupService anbieten; aktuellen Gruppeneigentümer/Capability verifizieren, Sitzplatz-/Tageslimits respektieren, stabile Idempotenz-ID ergänzen und Einladungs-Token aus Ausgabe/Logs ausschließen.
- [ ] WF-CUST2-08 – Workflow Captain darf WooCommerce-Zahlungen/-Refunds, VoucherGrants, Lernzugangsstatus und Prüfungsergebnisse nicht selbst schreiben. Jeder bestehende Domainservice bleibt einzige zuständige Instanz.
- [ ] WF-CUST2-09 – E2E-Matrix für Zahlung mit Zustimmung/ohne Zustimmung, Dispute, Voll-/Teilrefund, manuelle Zugangsentscheidung, Voucher-Grant/-Revoke, Auto-Reconcile, Gruppeninvite/-beitritt, Ticketstatus, doppelte Events, Lizenzende und Datenschutzexport ergänzen.
- [ ] WF-CUST2-10 – Customer-Connector-Beispiele sowie WordPress-Admin-Diagnose für fehlendes LearnBoating, inkompatiblen Contract, abgeschaltete Ticket-Erweiterung, fehlende Berechtigung und wartende Workflow-Runs dokumentieren.

### Workflow-Captain-Einstellungen und WordPress-Admin-Sortierung

- [ ] WF-SET-01 – Alle globalen Optionen, Workflow-eigene Optionen und Connector-Secrets inventarisieren; pro Option Quelle, Standardwert, Besitzer, Berechtigung und Anwendungsbereich dokumentieren. Doppelte WordPress-/WooCommerce-Grundeinstellungen entfernen oder sichtbar als geerbt markieren.
- [ ] WF-SET-02 – WordPress-Menüstruktur finalisieren: eigener „Workflow Captain“-Menüpunkt für Dashboard, Workflows, Vorlagen, Läufe, Integrationen, Einstellungen, Systemstatus/Diagnose und Hilfe; kein globaler WooCommerce-Einstellungs-Tab für das Plugin.
- [ ] WF-SET-03 – Settings-Seite mit WordPress-`nav-tab-wrapper` und Tabs Allgemein, Ausführung, Benachrichtigungen, Datenschutz & Aufbewahrung sowie Rollen & Rechte aufbauen; Erweitert standardmäßig einklappen und nur Administratoren anzeigen.
- [ ] WF-SET-04 – Tab Allgemein: Workflow-Standardzeitzone und Datumsformat von WordPress erben, Quelle sichtbar machen; optionale Abweichung ausdrücklich kennzeichnen; Standardwerte nur bei neu erstellten Workflows anwenden.
- [ ] WF-SET-05 – Tab Ausführung: Action Scheduler bei WooCommerce automatisch nutzen, sonst WP-Cron/System-Cron erklären; Retry/Backoff, Batchgröße, Laufzeit und Parallelität mit sicheren Mindest-/Höchstwerten definieren; per Workflow nur unterstützte Defaults überschreibbar machen.
- [ ] WF-SET-06 – Tab Benachrichtigungen: Empfänger und Arten von Adminhinweisen für fehlgeschlagene Runs, Queue-Ausfälle und inkompatible Provider verwalten; Absender aus WP/WooCommerce übernehmen; Testmail und Testereignis anbieten, ohne Secrets oder PII zu loggen.
- [ ] WF-SET-07 – Tab Datenschutz & Aufbewahrung: Run-Metadaten, Event-Payloads und Debugdaten getrennte Retention-Werte geben; sensible Inhalte standardmäßig redigieren; WordPress Privacy Exporter/Eraser und Ausnahme für aktive Runs integrieren.
- [ ] WF-SET-08 – Tab Rollen & Rechte: Capabilities für Anzeigen, Entwerfen, Veröffentlichen, Logs lesen, Retry/Abbruch und Connectorverwaltung auf Rollen abbilden; Shop-Manager erhalten keine zusätzlichen Rechte ohne bewusste Zuweisung; hochwirksame Schritte separat schützen.
- [ ] WF-SET-09 – Connector-URLs, Hosts, HMAC-Schlüssel, Scopes und Payload-Mappings ausschließlich in Integrationen bzw. im einzelnen Connectorprofil verwalten; Secrets maskieren, Rotation/Widerruf protokollieren und wp-config-Konstanten als „extern verwaltet“ anzeigen.
- [ ] WF-SET-10 – Queue, Cron, Providerverfügbarkeit, Contract-Versionen, Retention und Supportdiagnose auf separater Statusseite als überwiegend schreibgeschützte Informationen darstellen; Systembericht exportiert keine PII/Secrets.
- [ ] WF-SET-11 – Globale Notfallpause auf Dashboard/Systemstatus bereitstellen: Zahl betroffener Workflows/Jobs, Wirkung, Bestätigung, Statusmeldung und kontrollierte Wiederaufnahme anzeigen; Aktion muss auditierbar und capability-geschützt sein.
- [ ] WF-SET-12 – Import/Export aus den Plugin-Einstellungen herausnehmen und als Workflow-Listenaktion mit Schema-/Versions-/Providerprüfung, Secret-Ausschluss und Importvorschau implementieren.
- [ ] WF-SET-13 – Änderungen pro Tab speichern; Validierungsfehler am Feld, Statusmeldungen, Warnung bei ungespeicherten Änderungen, bestätigtes Zurücksetzen des aktuellen Tabs und Audit-Eintrag für sicherheitsrelevante Änderungen ergänzen.
- [ ] WF-SET-14 – Änderungssemantik festlegen: neue globale Defaults verändern keine bestehenden Draft-/Published-Snapshots; Migration vorhandener Werte und Rollback beim Upgrade dokumentieren.
- [ ] WF-SET-15 – Mockup, Hilfetexte, Übersetzungen, Tastaturbedienung, Screenreader-Labels und responsive Darstellung der fünf Settings-Tabs sowie Statusseite abnehmen; Einstellungen in Free/Pro, Lizenzablauf und Multisite darstellen.

### Yoast SEO-Kompatibilität

- [ ] WF-SEO-01 – Öffentliche und private Datenflächen abschließend inventarisieren. Workflow-Definitionen, Run-Logs, interne Vorlagen und Connector-Konfigurationen bleiben nicht öffentliche Betriebsdaten; keine öffentlichen CPT-Archive, öffentlichen REST-Routen oder Sitemap-Einträge dafür registrieren.
- [ ] WF-SEO-02 – Jede Workflow-REST-Route und jedes Detail-/Exportziel mit restriktiver Capability-/Authentifizierungsprüfung absichern; negative Zugriffstests für nicht angemeldete und unberechtigte Benutzer spezifizieren. Robots/noindex niemals als Zugriffsschutz behandeln.
- [ ] WF-SEO-03 – Pluginweite Frontend-Ausgabe prüfen und sicherstellen, dass Workflow Captain keine Title-, Meta-Description-, Canonical-, Robots-, Open-Graph-/Social-Tags oder JSON-LD-Schema-Ausgabe erzeugt und Yoasts Presenter, Robots- oder Sitemap-Filter nicht global verändert.
- [ ] WF-SEO-04 – Bei Workflows zum Anlegen/Aktualisieren von Beiträgen ausschließlich WordPress-CRUD-/REST-Schnittstellen verwenden; Poststatus, Autor, Taxonomien und Berechtigungen validieren und vorhandene Yoast-Felder/Einstellungen nicht still überschreiben.
- [ ] WF-SEO-05 – Keine direkte Kopplung an Yoast-Indexables, interne Klassen oder undokumentierte `_yoast_wpseo_*`-Meta-Keys implementieren. Die Yoast-SEO-REST-API als lesend behandeln; etwaige spätere Schreibfunktionen erst nach verifizierter dokumentierter Schnittstelle separat spezifizieren.
- [ ] WF-SEO-06 – Kompatibilitätsmatrix und Supportgrenze für Yoast-Versionen dokumentieren. Yoast ist optional: Workflow Captain funktioniert mit Yoast installiert, deaktiviert oder nicht installiert und erzeugt keine fatalen Fehler bei Versionsänderungen.
- [ ] WF-SEO-07 – Regressionstests mit aktivem/deaktiviertem Yoast für Frontend-HTML und REST vorsehen: SEO-Tags, Canonical, Robots, Open Graph, Schema-Graph und XML-Sitemap bestehender Inhalte bleiben unverändert; Workflow-Daten tauchen in keiner öffentlichen Antwort auf.
- [ ] WF-SEO-08 – Beitragsaktionen mit Yoast in Classic Editor und Block Editor sowie mit öffentlichen und privaten Beitragsstatus prüfen; Yoast-Metabox/-Sidebar darf nicht gestört werden und SEO-Felder bleiben nach Workflow-Läufen erhalten.
- [ ] WF-SEO-09 – Einen späteren optionalen Headless-Use-Case nur bei konkreter Produktanforderung planen; bevorzugt dokumentierte `yoast_head`/`yoast_head_json`-REST-Daten lesen und Secrets/geschützte Workflow-Payloads nie an öffentliche SEO-Antworten anhängen.
- [ ] WF-SEO-10 – Freigabekriterium in Release-Checkliste und Supportdiagnose aufnehmen: keine doppelten Metadaten-/Schema-Ausgaben, keine Sitemap-/Robots-Nebenwirkungen und keine ungeschützten Workflow-Ressourcen.

### n8n-Connector – Umsetzung Version 1

- [ ] WF-N8N-01 – Version-1-Vertrag und Produktabgrenzung festschreiben: offener HTTP-Webhook-Connector mit n8n Webhook-/HTTP-Request-Standardknoten; keine verpflichtende Community Node und keine Abhängigkeit von der n8n Management API.
- [ ] WF-N8N-02 – Pro-Integrationsmodell implementieren: mehrere benannte Verbindungen, Richtung (Captain→n8n / n8n→Captain), editierbare n8n-Basis-URL (Schema, Host, Port, optional Pfad) und vollständige Webhook-URL, erlaubte Eventtypen/Trigger, Feld-Allowlist, Aktivstatus, letzte Zustellung und eigener Scope pro Credential.
- [ ] WF-N8N-03 – Ausgehenden Versand über persistente Outbox nach fachlichem Commit integrieren; Event-ID, Contract-Version, UTC-Zeit und schema-validierte Minimal-Payload senden; stabile Idempotenz bei Wiederholung und Empfänger-Deduplizierung dokumentieren.
- [ ] WF-N8N-04 – Ausgehende Authentifizierung für n8n Webhook-Headerauth und optional Basic/JWT nach dokumentiertem Verhalten unterstützen; HTTPS verpflichtend, Secrets verschlüsselt speichern, maskieren, rotieren und aus Logs/Exporten ausschließen.
- [ ] WF-N8N-05 – Inbound-REST-Trigger versionieren und pro Verbindung API-Token mit kleinsten Rechten ausgeben; Token darf nur ausdrücklich erlaubte Trigger starten, nie beliebige Runs, Adminfunktionen oder allgemeine REST-Ressourcen aufrufen.
- [ ] WF-N8N-06 – Eingehende Anfragen mit Schema-/Typ-, Trigger-Allowlist-, Berechtigungs-, Zeitstempel-/Nonce-/Idempotency-, Rate- und Payloadgrößenprüfung absichern; nach persistenter Annahme Run-ID zurückgeben, keine lang laufende synchrone HTTP-Verbindung.
- [ ] WF-N8N-07 – SSRF-/Egress-Abwehr für n8n-Endpunkte umsetzen: HTTPS als Produktionsstandard; private/Loopback/Link-Local-Ziele standardmäßig sperren, aber lokale/private n8n-Ziele nach ausdrücklicher Adminfreigabe je Verbindung ermöglichen; Host/IP/CIDR/Port festhalten, DNS-Rebinding und Redirects erneut prüfen, Cloud-Metadatenziele immer sperren, Timeout/Antwortgrößenlimit und sichere Fehlermeldungen.
- [ ] WF-N8N-08 – UI unter Integrationen → n8n für Verbindung, Richtung, frei editierbare Basis-URL und Webhook-URL, Auth, Events/Trigger, Feldfilter, lokale/private Netzwerkfreigabe, Verbindungstest, Aktivierung, Rotation/Widerruf und redigierten Zustellstatus bauen; Endpoint-, Host/IP- oder Scope-Änderung erfordert erneuten Verbindungstest.
- [ ] WF-N8N-15 – Lokale-URL-UX dokumentieren und umsetzen: Eingaben wie `http://localhost:5678`, `http://127.0.0.1:5678`, LAN-IP, Docker-DNS und VPN-Hostname erlauben; verständlich anzeigen, dass der Test vom WordPress-Server ausgeht und `localhost` nicht den Browser-PC bezeichnet; Verbindungstest, TLS-/HTTP-Hinweis und Diagnose für Container-/Firewall-/Reverse-Proxy-Routing ergänzen.
- [ ] WF-N8N-09 – Fehlerhandling festlegen: begrenzte Backoff-Retries bei Netzwerk-/5xx-Fehlern, 4xx in Fehlerwarteschlange, manuelles Replay mit gleicher Event-ID und Auditspur, Notfallpause und Anzeige veralteter Credentials.
- [ ] WF-N8N-10 – n8n JSON-Rezeptvorlagen und Setup-Anleitung für WooCommerce, Voucher, Pass und Form Captain liefern; echte Beispiel-Workflows ohne Credentials, mit Feldminimierung, Test-/Produktions-URL-Erklärung und Dedupe-Schritt.
- [ ] WF-N8N-11 – Freigabe-/Rechte-/Lizenzverhalten dokumentieren: Connector-Pro-Entitlement und Site-Limits wie andere externe Integrationen; bei Lizenzablauf keine neuen Events zustellen oder Inbound-Trigger annehmen, Credentials erhalten aber maskiert exportierbar nicht werden.
- [ ] WF-N8N-12 – Kompatibilitäts- und Sicherheitsmatrix für n8n Cloud sowie unterstützte selbst gehostete Releases pflegen; Tests für authentifizierte/unauthentifizierte Webhooks, unzulässige Scopes, Replay/Dedupe, Offline-/Timeout-/5xx-/4xx-Fälle, Rotation, SSRF-Ziele, PII-Redaktion und n8n nicht erreichbar definieren.
- [ ] WF-N8N-13 – Klären und dokumentieren, dass n8n Management API, API-Key-Scopes, Workflow-Import/-Sync und automatische Aktivierung in V1 nicht erforderlich sind; falls später benötigt: plan-/versionsabhängige Unterstützung und minimale Scopes separat prüfen.
- [ ] WF-N8N-14 – Native Suite-Ownership in Connector-Szenarien festlegen: n8n darf Notifications/CRM/ERP/Folgeprozesse koordinieren, aber keine parallele Zahlungs-, Gutschein-, Ticket-, Entitlement-, Provisions- oder Consent-Fachlogik implementieren.
