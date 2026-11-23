# Übergabe: Aufgaben der Kundenconnectoren BoatingSchool und LearnBoating

Stand: 16.11.2026; **übergeben (WF-E17-02 erledigt am 23.11.2026):** `hastla007/boatingschool` `docs/commerce/connectoren-aufgaben.md`, Abschnitt „Workflow-Connector (WF-CUST1 …)“, und `hastla007/learnboating` `docs/workflow-connector-aufgaben.md`, beide bisher nur auf Branches (`claude/plugin-todo-liste-2a83v4`; LearnBoating weitergeführt auf `claude/loving-euler-c7h553`). Grundlage: Entscheidung 17 des Inhabers vom 16.11.2026 (`wp-formmaster` `docs/ENTSCHEIDUNGEN.md:23`).

## Warum diese Aufgaben gehen

Jeder Connector für BoatingSchool oder LearnBoating ist ein eigenes, nicht verkauftes Projektprodukt mit eigenem Produktschlüssel, eigenem Präfix und eigener Lizenz auf dem Lizenzserver des Inhabers. Er wird als eigenes Plugin installiert; die Pro-Lizenz eines Captain-Produkts deckt ihn nicht ab. Paket und Updates erhält nur der jeweilige Kunde. Damit gehören die 19 Aufgaben WF-CUST1-01 bis -09 und WF-CUST2-01 bis -10 nicht mehr in `TODO.md` von Workflow Captain, sondern in die Projekt-Repositories.

Workflow Captain behält nur, was der Kern für fremde Connectoren anbietet: WF-CONNECTOR-01 bis -14 (öffentlicher Connector-Vertrag, Registry, Webhooks, Secrets, Admin-Seite „Eigene Connectoren“).

## So übernehmen

1. Den Abschnitt des jeweiligen Projekts unten unverändert in dessen Aufgabenliste übernehmen (Datei oder Issues, wie im Projekt üblich). Die Kennungen WF-CUST… bleiben als Herkunftsangabe erhalten; das Projekt darf eigene Kennungen davor setzen.
2. Die Lizenzaufgabe des Connectors ergänzen (unten jeweils als „Neu“ markiert); sie ersetzt die Annahme, Workflow Captain Pro lizenziere den Connector.
3. Den Ort der übernommenen Aufgaben in `TODO.md` dieses Repositorys bei WF-E17-02 eintragen und die beiden Abschnitte dort durchstreichen.

Befund und Empfehlung zu beiden Connectoren stehen weiter in `konzept.md`, Abschnitte 17.10 und 17.11.

## Was im Projekt beachtet werden muss

- Ereignisse, die ein Connector an Workflow Captain gibt, folgen dem öffentlichen Connector-Vertrag von Workflow Captain (WF-CONNECTOR-01, -02) und den Regeln für verbotene Werte (`docs/SPEC-suite-events.md`, Abschnitt 5): keine Codes, Tokens, Schlüssel, E-Mail-Adressen oder Namen in den Nutzdaten.
- Der Connector prüft seine eigene Lizenz mit seiner eigenen Kopie der Lizenzbibliothek. Ob die Connector-Laufzeit zusätzlich ein installiertes, lizenziertes Workflow Captain Pro voraussetzt, ist offen (`docs/SPEC-open-questions.md`, F-01 und F-18; `TODO.md` WF-E17-03). Bis zur Antwort plant das Projekt beide Fälle nicht fest ein.
- Nach D12 rangiert alle Workflow-Arbeit hinter dem Verkaufsstart der drei vorhandenen Add-ons. Ein Connector kann erst gebaut werden, wenn der öffentliche Connector-Vertrag von Workflow Captain steht.

## Ziel: `hastla007/boatingschool` (B2B, Laravel)

Konzept: `konzept.md`, Abschnitt 17.10.

- [ ] WF-CUST1-01 – Vorhandenen WooCommerce-Connector als fachlichen Owner von Paid-Order-Codezustellung, Idempotenz pro Order-Position, Retry und Refund-Sperrung festhalten; Workflow darf diese Befehle nicht nochmals ausführen.
- [ ] WF-CUST1-02 – In BoatingSchool einen eigenen dauerhaften Workflow-Event-Outbox-Dispatcher nach Commit ergänzen. Nicht die vorhandene `tenant.provision`-Shop-Outbox oder bloße Commerce-Logs als allgemeine Zustellgarantie voraussetzen.
- [ ] WF-CUST1-03 – Versionierte Events für Shopkonto registriert/verknüpft/deaktiviert, Provisionierung erfolgreich/fehlgeschlagen sowie Code-Batch geliefert/entwertet veröffentlichen; stabile Event-IDs und tenant-/shop-scoped Correlation-IDs nutzen.
- [ ] WF-CUST1-04 – Allowlist für Event-Payloads implementieren: Referenzen, Status, Menge und Kurs-/Produktcode; nie Gutscheinwerte, Linkcodes, Passwörter, unbestätigte E-Mail-/USt-Daten oder vollständige Lern-/Kontodaten versenden.
- [ ] WF-CUST1-05 – Separaten Workflow-Vertrag und Credentials mit eigener Key-ID, HMAC, Zeitfenster, Nonce/Replay-Schutz, Rotation, Rate-Limits und Contract-Handshake entwickeln; bestehende Commerce-Keys und `/api/commerce/v1`-Scopes nicht wiederverwenden.
- [ ] WF-CUST1-06 – Tenant-Scope serverseitig authentifizieren und RLS-Kontext pro Anfrage setzen; Tenant-IDs aus Ereignissen gelten nur als Referenz. Plattformweite Rechte als separaten, überprüfbaren Scope führen.
- [ ] WF-CUST1-07 – Begrenzte App-Aktionen nur über öffentliche Services zulassen: tenant-scoped Schuladmin-Hinweis und optional Gruppenmitglied-Einladung mit Rollenprüfung, Sitzplatz-/Tageslimits und Command-Idempotenz. Account-Freigabe/-Verknüpfung, Codeausgabe/-widerruf und Kursrechte bleiben ausgeschlossen.
- [ ] WF-CUST1-08 – BoatingSchool-Provider im WordPress-Connector/Workflow-Captain über Version, Status, Testevent, Payload-Mapping, Eventzustellung, Retry und Fehlerdiagnose sichtbar machen.
- [ ] WF-CUST1-09 – Integrationstests für Tenant-Isolation, RLS, falsche/abgelaufene Signatur, Replay, doppelte Event-ID, HMAC-Key-Rotation, Timeout nach erfolgreichem Zielaufruf, Refund, Code-Redaktion und Outbox-Replay ergänzen.
- [ ] Neu (Entscheidung 17) – Produktschlüssel, Präfix und Lizenz des BoatingSchool-Workflow-Connectors auf dem Lizenzserver des Inhabers anlegen; Lizenzprüfung im Connector-Plugin mit eigener Kopie der Lizenzbibliothek; Paket und Updates nur für diesen Kunden.

## Ziel: `hastla007/learnboating` (B2C, WordPress)

Konzept: `konzept.md`, Abschnitt 17.11.

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
- [ ] Neu (Entscheidung 17) – Produktschlüssel, Präfix und Lizenz des `LearnBoating Workflow Connector` auf dem Lizenzserver des Inhabers anlegen; Lizenzprüfung im Connector-Plugin mit eigener Kopie der Lizenzbibliothek; Paket und Updates nur für diesen Kunden. WF-CUST2-02 („Lizenz-/Capability-Prüfung“) meint diese eigene Lizenz, nicht Workflow Captain Pro.
