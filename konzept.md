# Workflow Captain – Produktkonzept

**Status:** Konzept zur Produktentscheidung und Umsetzung  
**Stand:** 15. November 2026  
**Produktname:** Workflow Captain  
**Repository:** `hastla007/wp-workflowmaster`  
**Kern-Plugin (kostenlos):** `workflow-captain`  
**Premium-Add-on:** `workflow-captain-pro`  
**Lizenzserver-Produktschlüssel:** `workflow-captain-pro` (im Katalog von WP License Captain anzulegen)  
**Rechte-Präfix:** `workflow.`  
**Kern-Plugin-Slug/Textdomain:** `workflow-captain`  
**Vorgesehener Pro-Ort:** `hastla007/captain-pro/addons/workflow-captain-pro/`

> Dieses Dokument ist die Produkt- und Umsetzungsgrundlage. Es beschreibt geplante Verträge und Funktionen, keine bereits vorhandenen Hooks, API-Rechte oder getestete Kompatibilität. Vor Releases sind Plattform-, Datenschutz-, Sicherheits- und Lizenzbedingungen erneut zu prüfen.

## 1. Produktidee und Ziel

Workflow Captain ist der zentrale, visuelle Automatisierungsbaukasten der Captain Suite. Betreiber verbinden Ereignisse aus WordPress, WooCommerce und installierten Captain-Plugins mit Bedingungen, Verzögerungen und Aktionen. Damit sollen wiederkehrende Abläufe ohne individuellen Code abgebildet werden, zum Beispiel:

- Wenn eine WooCommerce-Bestellung bezahlt ist, einen Gutschein versenden und nach Ablauf der Liefer-/Einlassfrist eine Review-Anfrage einplanen.
- Wenn ein Pass-Ticket ausgegeben wird, eine Bestellbestätigung ergänzen; bei Veranstaltungsabsage Käufer benachrichtigen.
- Wenn ein Formular mit einem bestimmten Anliegen eingereicht wird, den Administrator informieren und einen Follow-up-Schritt terminieren.
- Wenn eine Partnerbewerbung eingeht, eine interne Aufgabe/Benachrichtigung starten; Freigabe und Provisionslogik bleiben bei Affiliate Captain.
- Wenn ein Coupon eingelöst oder ein Guthaben-Schwellwert erreicht wird, eine freigegebene Nachricht oder einen weiteren Suite-Ablauf auslösen.

**Versprechen:** „Ein Ereignis in der Suite löst den nächsten passenden Schritt aus – nachvollziehbar, sicher und ohne Datenkopien zwischen Plugins.“

Workflow Captain ist eine Orchestrierungsschicht. Jedes Fach-Plugin bleibt Eigentümer seiner Daten und Geschäftsregeln. Der Workflow liest nur die vom jeweiligen Provider freigegebenen Ereignisdaten und führt Aktionen ausschließlich über öffentliche APIs/Service-Verträge des Ziel-Plugins aus. Direkte Tabellenzugriffe, unsichere SQL-Updates und doppelte Fachlogik sind ausgeschlossen.

## 2. Zielgruppen und Probleme

### Zielgruppen

1. WooCommerce-Shops, die Bestellungen, Gutscheine, Tickets und Kundenkommunikation verbinden wollen.
2. Veranstalter mit Pass Captain und WooCommerce, die Ticket-, Storno- und Eventabläufe automatisieren.
3. Gutscheinanbieter, die Ausstellung, Zustellung, Einlösung und Feedback zusammenführen möchten.
4. Captain-Suite-Betreiber, die Suite-Plugins miteinander kombinieren und weniger manuelle Adminarbeit wollen.
5. Agenturen und technische Betreiber, die wiederholbare Abläufe dokumentieren und überwachen müssen.

### Kernprobleme, die das Produkt lösen soll

- Suite-Plugins erzeugen wichtige Ereignisse, bieten aber heute nicht zwingend überall einen gemeinsamen Workflow-Builder.
- WooCommerce-Status, Teilnehmer-/Ticketstatus, Gutscheinstatus, Formulareinsendungen und Affiliate-Zustände haben unterschiedliche Datenmodelle.
- Zeitversetzte Abläufe und Wiederholungsversuche dürfen nicht in ad-hoc-Cronjobs je Integration enden.
- Betreiber brauchen nachvollziehbare Ausführungsprotokolle, sichere Wiederholungen und eine Möglichkeit, Fehler gezielt zu reparieren.
- Fremdautomationsdienste benötigen API-Schlüssel und externe Konten. Workflow Captain soll grundlegende lokale Abläufe direkt in WordPress ausführen und externe Übergaben später kontrolliert ergänzen.

## 3. Wettbewerbsanalyse und Produktpositionierung

Die sichtbaren Produktmuster wurden am 15.11.2026 anhand offizieller Produkt- bzw. WordPress.org-Seiten recherchiert. Tarife, Funktionsumfänge und Integrationszahlen ändern sich laufend; vor Preis- oder Launch-Entscheidungen ist ein aktueller Tarifvergleich erforderlich.

| Produkt | Erkennbares Muster | Stärken | Chance für Workflow Captain |
|---|---|---|---|
| **Uncanny Automator** | „Recipes“ mit Triggern, Bedingungen/Tokens und Aktionen; viele WordPress- und App-Verbindungen; Pro ergänzt u. a. erweiterte Logik, Zeitsteuerung und weitere Integrationen. | Etablierte visuelle Trigger-Aktions-Logik und breite Plugin-Ökologie. | Captain Suite zuerst tief und vertraglich sauber integrieren; bei Datensouveränität, WooCommerce-Zuständen und Fehlerdiagnose auf WordPress-Nähe setzen. |
| **AutomatorWP** | Trigger, Aktionen und Filter über Integrationen; Add-ons erweitern Dienste und Logik. | Große Auswahl an Integrationen; konfigurierbare Trigger und Aktionen. | Keine leere Plattform für hunderte Adapter versprechen. Lieber wenige hochwertige Core-/Woo-/Captain-Provider, klar dokumentiert und stabil versioniert. |
| **WP Webhooks** | Webhooks, Datenübergabe und Pro-Flows für Folgeaktionen; Entwickler können eigene Hooks und Datenverbindungen abbilden. | Gut für technische Systemverbindungen und externe Endpunkte. | Visuelle, für Shopbetreiber verständliche Suite-Rezepte; Webhooks als optionaler, abgesicherter Adapter statt notwendiger Grundvoraussetzung. |
| **SureTriggers / OttoKit** | Visueller Builder und Verbindungen über WordPress-Plugins und externe Apps; cloud- bzw. plattformorientierte Automatisierung. | Breite App-Verbindungen und visuelle Workflow-Erstellung. | Lokale First-Party-Abläufe ohne zusätzliche Cloud-Abhängigkeit, mit Pro-Ausbau für Webhooks/API und nachvollziehbarer Ausführung. |

### Differenzierung

Workflow Captain soll nicht mit der größten Zahl von Integrationen starten. Die Differenzierung ist:

- **Suite-Tiefe:** Ereignisse und Aktionen der Captain Plugins sind konkrete Fachfunktionen statt generischer Meta-Feld-Schreibzugriffe.
- **Saubere Fachgrenzen:** Pass Captain verwaltet Tickets und Einlass; Voucher Captain verwaltet Gutscheine; Affiliate Captain verwaltet Partner und Provisionen; Review Captain verwaltet Bewertungen und Einladungen; Form Captain verwaltet Formulare und Einsendungen.
- **Idempotente Ausführung:** doppelte WooCommerce- oder Plugin-Hooks dürfen keine doppelten Gutscheine, Partnerbuchungen oder E-Mails erzeugen.
- **Betriebstransparenz:** jeder Lauf hat Status, Ursache, Zeit, betroffene Aktion und sichere Wiederholungsoption.
- **Lokal ausführbar:** Workflows, Warteschlange und Protokolle liegen auf der WordPress-Website; externe Konten sind nur für explizite Fremddienste nötig.
- **Free ist produktiv nutzbar:** Free bietet native WP-Ereignisse/-Aktionen und einfache lineare Abläufe. Pro monetarisiert erweiterte Logik, Suite-Adapter und Betriebskomfort.

## 4. Produktaufteilung: Free und Pro

### Workflow Captain (Free, eigenständiger Kern)

Free soll im Wettbewerb sofort benutzbar sein. Es enthält keine Lizenzprüfung und keine Nutzungs- oder Task-Credit-Grenzen.

- Unbegrenzte Workflows und Ausführungen auf einer WordPress-Website; kein externer Account erforderlich.
- Visueller Editor mit mehreren Auslösern, mehreren Aktionen, geordneter Schrittfolge, einfachen Bedingungen, Verzögerung und Datum/Zeitplanung.
- WordPress-Core- und WooCommerce-Provider für die gängigen Inhalts-, Benutzer-, Bestell-, Refund-, Produkt- und Coupon-Ereignisse.
- Einfacher AND/OR-Filter auf Ereignisfeldern, dynamische Tokens und sichere E-Mail-/Adminaktionen.
- WooCommerce-Aktionen: Bestellnotiz, zulässige Statusänderung, Kunden-/Adminbenachrichtigung und CRUD-Meta.
- Einfaches Ausführungsprotokoll für 30 Tage, Test-/Dry-Run, pausieren/aktivieren, duplizieren und Fehlerstatus.
- Entwickler-Registry für Drittanbieterprovider mit dokumentiertem Contract.
- Queue-Schutz gegen Lastspitzen; Systemlimits dürfen verzögern oder verständlich ablehnen, erzeugen aber niemals Task-Kosten.

Free umfasst keine Captain-Suite-Provider oder externen Serviceverbindungen. Premiumintegrationen sind ein legitimer Add-on-Mehrwert. Das kostenlose Kernplugin bleibt eigenständig und ohne Lizenzclient.

### Workflow Captain Pro (separates Add-on, lizenziert)

- Alle sechs Captain-Provider: WP License Captain, WooCommerce (erweiterte Suite-Aktionen), Pass, Voucher, Affiliate, Form und Review Captain.
- Sichere, signierte Ein-/Ausgangswebhooks als Brücke zu Fremdsystemen und anderen WordPress-Websites. V1 enthält keine native App-Bibliothek für Hunderte Drittanbieter.
- Erweiterte Event-Korrelation, If/Else-Verzweigung, Bedingungsgruppen und Freigabeschritte.
- Geplante Queue-Aktionen mit Geschäftslogik zur Stornierung bei Refund/Storno oder Statusänderung.
- Idempotente Aktionen, begrenzte Retry-Strategie, kontrolliertes Replay, Audit-Historie und vollständige Einzelschritt-Protokolle.
- Suite-Rezeptvorlagen, Versionierung sowie schema-geprüfter Export/Import.
- Erweitertes Rollen-/Capability-Modell, Protokollexport und bis zu 365 Tage einstellbare Pro-Aufbewahrung.
- Alle Pro-Funktionen in jedem Lizenzplan; Tarife unterscheiden sich ausschließlich über Live-Sites und Support.

Externe App-Plattformen und Cloud-Runs sind nicht Teil von Version 1; Webhooks sind der ausdrücklich begrenzte Interoperabilitätsweg.

### Lizenzvertrag

- Produktschlüssel/Add-on-Ordner/Textdomain: `workflow-captain-pro`; Rechtepräfix: `workflow.`.
- Pro nutzt den bestehenden WP-License-Client und Add-on-Kernel in `captain-pro`. Free enthält keinerlei Lizenzcode.
- Die Pläne verwenden nur `sites.live_limit` und `support.level` als Tarifunterschiede; es gibt keine pro-Feature-Tiers, Run-Limits oder Task-Credits.
- Premiumrechte: `workflow.suite_integrations`, `workflow.webhooks`, `workflow.event_correlation`, `workflow.advanced_logic`, `workflow.recipe_templates`, `workflow.extended_logs`.
- Alle Pro-Pläne erteilen dieselben sechs Rechte; Site-Limit/Support kommen aus dem Standardvertrag. Die Katalog-Entitlements müssen vor dem Verkauf angelegt werden.
- Bei Lizenzablauf bleiben Rezepte, Versionen, Logs und Export lesbar. Neue Pro-Runs starten nicht. Geplante Pro-Aktionen werden pausiert und nicht automatisch nach Lizenzverlängerung nachgeholt; Betreiber prüft eine Wiederaufnahme mit Vorschau.
- In `captain-pro/README.md`, Architektur, Build, Übersetzungen und Release-Checkliste wird das Add-on nach dem bestehenden Produktmuster ergänzt.

## 5. Integrationsmodell

### Architekturprinzip: Event-Provider und Action-Provider

Jede Integration registriert Metadaten und Handler an einer stabilen Registry:

- **Trigger-Provider:** Ereignis-ID, Titel/Beschreibung, erforderliche Plugins/Mindestversion, Eingabeschema, bereitgestellte Tokens, Hook/Event-Handler, Datenschutzklassifikation.
- **Action-Provider:** Aktions-ID, Eingabeschema, Felder/Token-Auswahl, Validierung, Ausführung über öffentliche API, Retry-Regeln, Idempotenzstrategie und erwartete Seiteneffekte.
- **Condition-Provider:** erlaubte Operatoren und Typen für die vom Trigger ausgegebenen Werte.
- **Adapterstatus:** verfügbar, Plugin fehlt, Mindestversion nicht erfüllt, Vertragsversion inkompatibel oder Provider-Fehler.
- **Contract-Version:** semantische Version je Provider. Bei inkompatiblen Änderungen bleibt ein Workflow deaktiviert oder wird mit einem klaren Migrationshinweis markiert; Felder werden nie stillschweigend umgedeutet.

Workflow Engine ruft Anbieter-Handler auf. Ein Provider darf nie interne Tabellen anderer Plugins lesen oder beschreiben. Wo es noch keinen stabilen Servicevertrag gibt, gehört dessen Entwicklung in die vorbereitende Arbeit des Quellplugins.

### Event-Vertrag

Vorgeschlagene öffentliche PHP-Schnittstelle (Namen sind Entwurf und müssen mit den Suite-Repositories abgestimmt werden):

- `workflow_captain_register_providers` als Registry-Einstieg oder versionierte `ProviderInterface`.
- Stabile Ereignis-IDs in der Form `woocommerce.order.paid`, `voucher.voucher.issued`, `pass.ticket.checked_in`.
- Einheitlicher Envelope: `event_id`, `event_key`, `occurred_at_utc`, `site_id`, `actor_user_id` (falls vorhanden), `object_type`, `object_id`, `schema_version`, `payload`, `privacy_tags`.
- Payload enthält nur benötigte Felder; sensible Daten standardmäßig minimieren. Tokens zeigen keine Auth-Secrets, Lizenzschlüssel, Zahlungskartendaten, vollständige Gutschein-/Einlasstokens oder Affiliate-Bankdaten.
- Jeder Event wird mit stabiler Event-ID und Objektversion verarbeitet. Retry eines Events erhält denselben Idempotency-Key.

### Ausführungsmodell

1. Der Fachplugin-Hook erzeugt ein kompaktes Event und übergibt es an die Registry.
2. Workflow Captain findet aktive Rezeptversionen, prüft Trigger, Bedingungen und Berechtigungen.
3. Sofortige Läufe werden synchron nur für kurze, risikoarme Aktionen ausgeführt; E-Mail, HTTP, Verzögerung und längere Folgeketten gehen in eine Queue.
4. Queue-Einträge werden atomar beansprucht, mit Lock/Lease und Idempotency-Key ausgeführt.
5. Jede Aktion schreibt Status und Ergebnis-Metadaten; sensible Antworten/Token werden redigiert.
6. Fehlerklassen bestimmen Retry: vorübergehend mit Backoff; endgültig sofort als fehlgeschlagen. Keine Endlosschleifen; maximale Versuche konfigurierbar.
7. Manuelles Replay erzeugt eine neue Run-Instanz, behält aber ursprüngliches Event und Idempotenzkontrolle. „Aktion erneut ausführen“ zeigt mögliche externe Seiteneffekte.
8. Action Scheduler wird verwendet, wenn WooCommerce verfügbar ist; sonst eigener WP-Cron-/Queue-Fallback mit dokumentierter System-Cron-Empfehlung.
9. Rezeptänderungen betreffen neue Läufe. Ein bereits gestarteter Run nutzt einen unveränderlichen Rezept-Snapshot.
10. Rekursive Triggerketten werden über Korrelations-ID, Run-Ursprung und Loop-Grenze erkannt.

### Datenmodell (Entwurf)

- `wp_workflow_recipes`: Identität, Titel, Status, Eigentümer, aktuelle Version.
- `wp_workflow_recipe_versions`: versionierter/validierter Graph oder einfacher Schrittplan, Trigger-, Condition- und Action-Konfiguration, Provider-Verträge.
- `wp_workflow_events`: kurzlebiger Event-Puffer nur soweit für Verarbeitung erforderlich; keine dauerhafte Kopie vollständiger Bestell-/Kundendatensätze.
- `wp_workflow_runs`: Status, Rezeptversions-ID, Event-Referenz, Start-/Endzeit, Korrelations-/Idempotency-Key.
- `wp_workflow_steps`: Schrittstatus, Retry-Zähler, Fehlerklasse, redigierte Ausgabe.
- `wp_workflow_queue`: fällige Arbeit, Lease, Versuche, nächste Ausführung.
- Tabellen haben eindeutige Schlüssel gegen Doppelausführung; Multisite bleibt je Site getrennt.
- Datenbank-Updates laufen kontrolliert im Admin, über WP-Cron oder WP-CLI und nicht bei jedem Frontend-Aufruf. Schemaänderungen werden versioniert.

## 6. Integrationskatalog und priorisierte Ereignisse

Die folgende Liste ist Zielumfang. Vor Implementierung muss jedes Ereignis/Aktion gegen aktuelle öffentliche Hooks/APIs und Tests des Quellplugins verifiziert werden. Es werden keine internen Funktionsnamen als stabil versprochen.

### WordPress Core (Free zuerst)

**Trigger:** Benutzer registriert, Benutzer angemeldet (optional und sparsam), Rolle geändert, Beitrag/Seite veröffentlicht, Beitrag erstellt/aktualisiert, Kommentar eingereicht/freigegeben.  
**Aktionen:** E-Mail versenden, Benutzerrolle ändern (mit Schutz für Administratoren), Beitrag erstellen/aktualisieren (nur explizite Felder und Berechtigung), Kommentar moderieren nur mit klarer Autorisierung.  
**Grundsätzlich ausgeschlossen:** Datei-/Plugin-Änderungen, SQL, arbitrary PHP/Code, Benutzerpasswort aus Tokens und ungeprüfte Adminaktionen.

### WooCommerce (Free-Basis, Pro-Vertiefung)

**Trigger:** Bestellung erstellt, bezahlt, Status geändert, erfüllt/versandt, teilweise/vollständig erstattet, storniert; Produkt erstellt/Status geändert; Coupon angewandt nur soweit ein stabiler Hook und saubere Deduplizierung vorhanden sind.  
**Daten/Tokens:** Bestell-ID/Nummer, Status, Währung, Brutto-/Netto-Summe, Produkt-IDs/-Namen/Mengen, Coupon-Codes nur bei Bedarf, Kundennummer/Opt-in-Status, Versanddatum; E-Mail nur für passende Kommunikationsaktion. Gastkäufer ohne WP-User-ID zulassen, aber keine User-Aktion vortäuschen.  
**Aktionen:** Bestellnotiz hinzufügen; Order-Meta ausschließlich über Woo CRUD APIs setzen; erlaubte Statusänderung; Kunden-/Admin-E-Mail; Gutschein-/Pass-/Review-/Affiliate-Aktionen nur über Zielplugin-Service.  
**Pflichten:** HPOS-kompatibel, Classic- und Block-Checkout, Subscriptions getrennte Folgephase, Refund-Lineitems, Teilrefunds, Hook-Reentrancy, idempotente Verarbeitung.

### Voucher Captain

**Mögliche Trigger:** Gutschein ausgestellt, Gutschein-E-Mail zugestellt/fehlgeschlagen, Gutschein eingelöst, Guthaben geändert, Gutschein abgelaufen/gesperrt, Einlösung rückgängig gemacht.  
**Mögliche Aktionen:** Gutschein erneut senden; Gutschein erstellen nur mit vollständiger, validierter Produkt-/Empfänger-Konfiguration und einer öffentlichen Service-API; Guthaben-/Statusänderung zunächst bewusst ausschließen oder stark berechtigen; Gutscheinstatus lesen/prüfen.  
**Integrationshinweise:** Gutscheincode oder signierter Downloadlink nur als speziell freigegebenes Token mit Redaktionsregeln ausgeben. Keine direkte Coupon-Tabellenmanipulation. Ereignisquellen aus WooCommerce und Voucher Captain werden über gemeinsame Quelle/Idempotenz nicht doppelt gebucht.

### Pass Captain

**Mögliche Trigger:** Ticket reserviert/ausgestellt, Zahlung bestätigt, Ticket übertragen, eingecheckt, Einlass verweigert, Wartelistenangebot erstellt/angenommen/abgelaufen, Veranstaltung veröffentlicht/geändert/abgesagt.  
**Mögliche Aktionen:** Teilnehmer-/Käufer-E-Mail; Eventbestellung kommentieren; Voucher Captain Gutscheinaktion nur wenn die Bridge/Reservierung abgestimmt ist; auf freigegebene Status-Serviceaktionen zugreifen.  
**Grenzen:** Workflow darf Ticketcode/QR-Sicherheitsgeheimnis nicht veröffentlichen oder per E-Mail an unbestätigte Empfänger verschicken. Pass Captain bleibt Eigentümer von Kontingent, Einlass und Tickettransfer. Bestehende Event- und Order-Integrationen bleiben maßgeblich.

### Affiliate Captain

**Mögliche Trigger:** Partnerbewerbung eingegangen, Partner freigegeben/abgelehnt/pausiert, Provision erstellt/freigegeben/abgelehnt, Auszahlung abgeschlossen (wenn offizieller Hook verfügbar).  
**Mögliche Aktionen:** Admin-/Partnerbenachrichtigung; erlaubte Partnerstatusaktion nur über vorhandene Kern-API und eigene Capability. Keine eigene Provisionsberechnung, Auszahlung, Bankdatenbearbeitung oder Kopie von Partner-/Kundendaten.  
**Sicherheit:** Steuer-, IBAN-, Bewerbungs- und Kundendaten sind keine Workflow-Tokens. Provisionsereignisse bleiben idempotent und werden nicht durch parallele WooCommerce-/Voucher-Verkäufe doppelt erzeugt.

### Form Captain

**Mögliche Trigger:** Formular abgeschickt, bestimmtes Formular/Antwort-/Feldbedingung erfüllt, Spam erkannt, Einreichung freigegeben/abgelehnt.  
**Mögliche Aktionen:** Admin-Mail, interner Follow-up, Felder an erlaubte Suite-API übergeben, Einreichung markieren/archivieren. Formular besitzt Einsendungen; Workflow bekommt nur explizit freigegebene, datenschutzmarkierte Felder. Datenweitergabe an Dritte erfordert klare Betreiberkonfiguration und Einwilligungsprüfung.

### Review Captain (Folgephase)

**Mögliche Trigger:** Review eingegangen, moderiert/freigegeben, Anfrage geplant/gesendet/fehlgeschlagen, Profil-Synchronisierung fehlgeschlagen.  
**Mögliche Aktionen:** Admin-Benachrichtigung, internem Team Review-Link senden, erlaubte Antwort-/Moderations-APIs nur wenn Zielvertrag vorhanden.  
**Grenzen:** keine „Review-Gates“, keine Belohnung für positive Reviews, keine Kundenselektion nach positiver Bewertung; kein automatisches Veröffentlichen oder Umschreiben fremder Reviews. Review Captain bleibt Eigentümer von Reviews und Einladungen.

## 7. Anwendungsrezepte (Produktbeispiele)

1. **Tatsächliches Erlebnis → Review-Anfrage:** Nach echtem Einlass über Pass Captain wird eine Anfrage geplant; ein bezahlter Auftrag oder eine bloße Gutscheinausstellung löst allein keine öffentliche Review-Anfrage aus. Der Review-Provider prüft vor Versand Einwilligungs-/Rechtsgrundlage, Opt-out, Refund/Storno und Frequenzlimit. Bei Erlebnisgutscheinen darf erst eine bestätigte Einlösung als Quelle dienen.
2. **Ticket gekauft → Eventabsage:** bei Eventabsage alle zugehörigen Besteller über Pass Captain informieren; keine erneute Ticket- oder Erstattungsbuchung durch den Workflow.
3. **Warteliste → Voucher:** angenommenes Angebot erzeugt Ticket über Pass Captain; danach optional Gutschein für eine separate Kampagne – nur, wenn Empfänger und Regeln festgelegt sind.
4. **Formular → Partnerbewerbung:** bei freigegebenem Bewerbungsformular Partnerdatensatz im Status „pending“ über einen zukünftigen Affiliate-Servicevertrag anlegen; Annahme/Provisionsregeln bleiben beim Affiliate-Plugin.
5. **WooCommerce-Erstattung → Affiliate-Korrektur:** Woo bleibt Verkaufsquelle; Affiliate Captain bucht Refund-Korrektur selbst. Workflow darf nur informieren oder über deduplizierten offiziellen Servicevertrag den Vorgang anstoßen, nie die Provision nachbauen.
6. **Gutschein eingelöst → Feedback:** Admin-/Kunden-Mail oder interne Aufgabe auslösen; externe Bewertungsplattformen ohne passende Einwilligung/Policy nicht direkt ansprechen.
7. **Review freigegeben → Benachrichtigung:** zuständige Redaktion informieren; automatisches Posten fremder Inhalte in externe Kanäle ist Folgefunktion.

Jede Vorlage muss Voraussetzungen, beteiligte Plugins, ausgelöste E-Mails/Datenübertragungen und Rückerstattungs-/Stornoverhalten anzeigen.

## 8. Admin-Navigation und Seiteninhalte

Hauptmenü: **Workflow Captain**. Navigation wird nach verfügbaren Kernfunktionen aufgebaut. Lizenzseite kommt nur vom Pro-Add-on und wird analog zu bestehenden Add-ons unter das Kernmenü gehängt.

### 8.1 Übersicht / Dashboard

**Zweck:** Zustand und nächste sinnvolle Schritte zeigen, nicht nur Kennzahlen.  
**Inhalt:** aktive Workflows; Läufe heute/letzte 7 Tage; fehlgeschlagene und wartende Läufe; Integrationsstatus (WordPress, WooCommerce und Captain-Plugins); letzter erfolgreicher Queue-Lauf; Hinweise zu deaktivierten/inkompatiblen Providern.  
**Primäraktionen:** „Workflow erstellen“, „Vorlage verwenden“, „Fehler ansehen“.  
**Leere Ansicht:** kurze Erklärung „Wähle ein Ereignis, lege Bedingungen fest und verbinde eine Aktion“ plus ein minimales WooCommerce-/WP-Beispiel. Keine irreführenden Zahlen bei leerer Installation.

### 8.2 Workflows

**Listeninhalt:** Name, Trigger, angeschlossene Plugins, Status (Entwurf/Aktiv/Pausiert/Fehler/Pro gesperrt), Läufe, letzte Ausführung, Eigentümer/Änderungsdatum.  
**Aktionen:** Neu, Bearbeiten, Duplizieren, aktivieren/pausieren, archivieren/löschen nach Bestätigung, nach Status/Plugin suchen.  
**Sicherheit:** Deaktivierte oder nicht mehr verfügbare Integrationen werden als Grund angezeigt; nicht stillschweigend übersprungen.

### 8.3 Workflow-Editor

Schrittweiser Editor mit verständlichen Feldern und optionalem Canvas, der Tastatur/Screenreader unterstützt:

1. **Name & Beschreibung:** interner Zweck, Owner, Draft.
2. **Auslöser:** Plugin und Ereignis wählen; Bedingungen für Eventdaten; Beispielpayload im Testmodus.
3. **Bedingungen:** Free bietet einfache AND-Regeln; Pro-Gruppen/OR/Datum-/Text-/Zahlvergleiche. Operatoren abhängig vom Felddatentyp.
4. **Aktionen:** Action-Provider, Eingabefelder, Token-Picker mit Beschreibung und Sensitivitätskennzeichen.
5. **Ablauf:** Folge/Verzögerung/Verzweigung abhängig vom Entitlement.
6. **Testlauf:** Beispiel-/Testevent und Aktionsvorschau; Schreibaktionen standardmäßig Dry Run, Live-Test erfordert expliziten Klick und Adminbestätigung. Keine echten E-Mails standardmäßig.
7. **Prüfen & Aktivieren:** Zusammenfassung mit Auslöser, Empfängern, Seiteneffekten, Datenschutz und Pro-Anforderungen. Aktivierung ist erst nach erfolgreicher Validierung möglich.
8. **Version veröffentlichen:** jeder Save erstellt einen nachvollziehbaren Snapshot; laufende Runs verwenden ihren Snapshot.

**Validierungen:** nicht verfügbare Provider, fehlende erforderliche Felder, ungültige Tokens, Zyklen, fehlende Consent-/Empfängerbedingungen und unsichere Action-Parameter blockieren Veröffentlichung mit verständlichem Grund.

### 8.4 Vorlagen

Kuratierte Startpunkte mit Beschreibung, Voraussetzungen, Preview und importierter Draft-Version. Gruppen: WooCommerce, Gutscheine, Events/Tickets, Affiliate, Formulare, Reviews, WordPress-Betrieb. Jede Vorlage ist deaktiviert, bis Besitzer die Empfänger, Datenschutzbedingungen und Seiteneffekte geprüft hat. „Ein-Klick-Aktivierung“ ohne Review ausgeschlossen.

### 8.5 Läufe / Protokoll

**Liste:** Zeit, Workflow/Version, Ereignis, Objekt-Referenz, Status, Dauer, Fehlerklasse.  
**Detail:** Schrittfolge, rote Fehlerursache, Retry-Historie, Provider-Version, redigierte Eingabe-/Ausgabevorschau, Korrelations-ID, Links zu Bestell-/Pluginobjekten, Wiederholen/Abbrechen sofern sicher.  
**Funktionen:** Filter, Suche, Zeitbereich, Export (Pro), Log-Aufbewahrung, Fehlerwiederholung. Geheimnisse, Zahlungsdaten, vollständige Kundendaten, Gutschein-/Ticketcodes werden standardmäßig maskiert. Ein Replay warnt vor möglicherweise bereits ausgeführten externen Seiteneffekten.

### 8.6 Integrationen

Kacheln für WordPress, WooCommerce und erkannte Captain-Plugins. Status und Mindestversion, verfügbare Trigger/Aktionen, Vertragsversion, letzte Provider-Prüfung und Link zu Details. Fehlende Plugins bleiben als „nicht installiert“ erkennbar und blockieren nicht den Rest. Keine Zugangsdaten für interne Plugin-Integrationen erforderlich.

### 8.7 Einstellungen

- Queue: Status, Batchgröße/Zeitbudget, letzter Lauf; Cronhinweis.
- Sicherheit: Rolle/Capabilities, erlaubte HTTP-Ziele und Webhook-Schlüssel (Pro).
- Datenschutz: Aufbewahrungsdauer, Payload-Minimierung, personenbezogene Felder, Export/Löschung.
- E-Mail: Absender-/Admin-Standard, Vorlagen, Testadresse.
- Diagnose: Versionsmatrix, Provider-Fehler, Systembericht ohne Secrets.
- Developer: API-/Hook-Vertragsversion, Debugmodus (nur Admin; sensible Werte maskiert).

### 8.8 Pro-Lizenz (nur im Add-on)

Im bestehenden WP License Captain/Add-on-Kernel: Lizenz aktivieren/deaktivieren, Status, Plan, Sites, verfügbare Entitlements und Updatezustand. Kein eigenständiges Lizenzformular im Free-Kern. Lizenz server requests nur gemäß bestehendem Client-Verhalten; kein Lizenzaufruf bei jedem Frontend-Event.

### 8.9 Hilfe & Dokumentation

Setup, Beispiele, Tokenreferenz, Providerverfügbarkeit, Queue/Retry-Erklärung, Datenschutz/Datenfluss, Problembehandlung, Entwickler-API und Link zum Support. Versionshinweise und klare Kennzeichnung experimenteller Anbieter.

## 9. Rechte, Rollen und Datenschutz

- Eigene Capabilities: `manage_workflow_captain`, `edit_workflows`, `activate_workflows`, `view_workflow_logs`, `retry_workflow_runs`, optional `manage_workflow_integrations`. Standardzuweisung an Administratoren; keine automatische Freigabe an Shop-Manager ohne bewusste Entscheidung.
- Jede Admin-/REST-/AJAX-Aktion hat Capability, Nonce bzw. WordPress Auth, Schema-/Typvalidierung und Rate-Limit wo relevant.
- WP REST API hat explizite `permission_callback`; Webhook-Eingänge nutzen signierte Secrets, Timestamp/Replay-Schutz und engen Event-Scope.
- Webhook-/HTTP-Features brauchen Allowlist, DNS/IP-Prüfung, Redirectkontrolle, Response-Limit und Timeout gegen SSRF/DoS.
- Keine Ausführung dynamischen PHP, SQL, Shell, Dateizugriff oder arbitrary WordPress-Function-Namen.
- Tokens tragen Datenklassifizierung: normal, personenbezogen, besonders sensibel, Geheimnis. Sensible Felder sind nicht in Logs/Exports und benötigen eine explizite Auswahl.
- Workflow-Ausgaben/Logs sind personenbezogene Daten, wenn Kundendaten darin stehen. Retention standardmäßig knapp; Privacy Exporter/Eraser berücksichtigt Workflow-Events und verknüpfbare Runs. Löschung nur nach Policy, keine Löschung im Lizenzablauf.
- WooCommerce-Gastbestellungen werden als Auftragskontext behandelt, nicht in WP-Accounts umgewandelt. Marketing-E-Mails erfordern passenden Einwilligungs-/Kommunikationsstatus.
- Kein Versand auf Basis positiver Reviews; kein Umgehen von Gutschein-/Affiliate-/Review-Richtlinien.
- Admin kann eine Aktivierung jederzeit pausieren; Provider-/Lizenzfehler dürfen keinen fatalen Frontend-Fehler verursachen.

## 10. Qualität, Leistung und Kompatibilität

- Ausgangssprache Deutsch; Übersetzungsdateien, alle Adminstrings internationalisierbar. Deutsch (de_DE) zuerst, Englisch (en_US) für internationale Releases.
- WordPress-Coding-Standards, escaping/sanitizing, REST permission callbacks, PHPStan/PHPCS, Plugin Check und Release-ZIP-Validierung nach den Kern-Repositories.
- Support aktueller Captain-Mindestversionen in einer dokumentierten Kompatibilitätsmatrix. Nur stabile Anbieter-Verträge als kompatibel deklarieren.
- WooCommerce HPOS, Classic- und Block-Checkout. Orders nur über Woo CRUD APIs lesen/schreiben.
- Keine großen Abfragen im Frontend; Admin-Listen paginiert; Queue-Batches begrenzt und beobachtbar.
- Externe Requests nicht in Frontend-Hooks, keine unerwarteten Tracking-/Analytics-Skripte.
- Keine Tests wurden hier ausgeführt; alle Kompatibilitätsaussagen müssen mit realen Integrations- und Migrationstests belegt werden.

## 11. Roadmap zur vollständigen Version 1.0

Die folgenden Phasen beschreiben die Umsetzungsreihenfolge. **Alle Phasen 0–3 gehören zu Version 1.0**, einschließlich der Provider für alle Captain-Suite-Produkte.

### Phase 0 – Verträge und technische Vorbereitung

- Die in Abschnitt 15.10 festgelegten Free-/Pro-, Lizenz-, Preis- und Kompatibilitätsentscheidungen bilden die Vorgabe.
- Öffentliche Event-/Action-Schemas für WordPress, WooCommerce und alle Captain-Plugins verabschieden.
- Bestehende Plugin-Hooks auf öffentliche, stabile Verträge abbilden; fehlende Verträge zuerst in den jeweiligen Fachplugins ergänzen.
- Data ownership, Refund-/Stornoverhalten, PII/Secrets und Idempotency je Provider dokumentieren.

**Abnahme:** jeder V1-Provider besitzt eine verantwortliche Quellkomponente, versioniertes Schema, Fehlercodes und Testspezifikation.

### Phase 1 – Engine und kostenloser Kern

- Workflow-Schema, Rezeptversionen, Provider-Registry, Event-Inbox, Queue/Lease und Run-Log implementieren.
- WordPress-Core- und WooCommerce-Provider für den festgelegten Katalog erstellen.
- Editor, Conditions, Tokenpicker, Dry Run, Veröffentlichen, Aktivieren/Pausieren und Basisprotokoll bereitstellen.
- Datenschutzwerkzeuge, Capabilities, Systemstatus und Cron-/Queue-Diagnose integrieren.

**Abnahme:** Free läuft ohne Lizenzserverkontakt; HPOS, Classic/Blocks, Deduplizierung, Retention und sichere Fehleranzeige sind belegt.

### Phase 2 – Workflow Captain Pro und Suite-Provider

- Add-on workflow-captain-pro in captain-pro integrieren; Produktkey, Entitlements und Lizenzseite nach bestehendem Kernel-Modell.
- Eventkorrelation, If/Else, Queue-Retry/Replay, Webhooks, Vorlagen und Pro-Protokolle umsetzen.
- V1-Provider für WP License Captain, Pass, Voucher, Affiliate, Form und Review Captain implementieren.
- Crossflow-Rezepte mit Datenhoheit, Einwilligung/Suppression und Seiteneffektbeschreibung bereitstellen.

**Abnahme:** alle Fachaktionen laufen über öffentliche APIs; keine direkten Tabellenzugriffe, doppelten Buchungen oder Review-Gating.

### Phase 3 – V1 Integrations- und Release-Abnahme

- End-to-End-Flows mit echten Plugin-Kombinationen und WooCommerce-Zustandswechseln abnehmen.
- Refunds, Stornos, Queue-Ausfall, Retries, Parallelität, Site-/Lizenzende, Backup und Contract-Upgrades prüfen.
- Dokumentation, Kompatibilitätsmatrix, WordPress.org-Readme, Pro-Release, Übersetzungen, Datenschutztexte und Supportdiagnose finalisieren.

**Abnahme:** alle Releasekriterien aus Abschnitt 15.9 erfüllt; Free- und Pro-ZIP lassen sich getrennt bauen/aktualisieren.

### Phase 4 – Nach Version 1.0

- Native CRM-/Mail-/Social-App-Adapter, externe Cloud-Runs, Schleifen und KI-Vorschläge erst nach eigener Nachfrage-, Sicherheits- und API-Prüfung.
- Neue Captain-Integrationen werden als versionierte Providerupdates ergänzt; der V1-Enginevertrag bleibt abwärtskompatibel.

## 12. Umsetzungsliste für Version 1.0

Die Produkt-, Free/Pro-, Lizenz- und Preisentscheidungen stehen in Abschnitt 15.10. Diese Liste enthält nur Arbeiten, die in Code, Verträgen, Design und Release noch erledigt werden müssen.

### P0 – Providerverträge

- [ ] Workflow Event-Envelope, SemVer-Regel und Provider-Registry implementieren und dokumentieren.
- [ ] WooCommerce: Bestell-, Status-, Refund-, Produkt-/Lager- und Coupon-Events auf stabile Quellereignisse abbilden.
- [ ] Pass Captain: versionierte Brücke für Ticketcheck-in/-ausgabe/-widerruf und Eventabsage schaffen.
- [ ] Voucher Captain: versionierte Services für Ausgabe, Zustellung, bestätigte Serviceeinlösung, Refund und Geheimnisredaktion definieren.
- [ ] Review Captain: idempotente Schedule-/Cancel-API mit Opt-out, Suppression, Frequenzlimit und Statuscodes bereitstellen.
- [ ] Affiliate Captain: Partner- und Provisionsereignisse über den bestehenden Integrationsvertrag anbinden; Affiliate bleibt alleiniger Verkaufs-/Refund-System-of-Record.
- [ ] Form Captain: Einsendungsereignis und Feldklassen/Consent-Flags über eine versionierte API bereitstellen.
- [ ] WP License Captain: nur sichere Status-/Updatebenachrichtigungen als Provider-Events freigeben.
- [ ] Jede Schnittstelle mit Contract-Version, Beispielpayload, Mindestversion, Capability, Fehlercodes und Idempotenztests dokumentieren.

### P1 – Produktimplementierung

- [ ] Free-Engine für WP/Woo, Mehrfachaktionen, Filter, Zeitplanung, Eventverarbeitung und 30-Tage-Logs bauen.
- [ ] Pro-Add-on mit Site-Lizenz, sechs Suite-Providern, Webhooks, erweiterter Logik und Rezeptimport/-export in captain-pro bauen.
- [ ] Editor-, Dashboard-, Run-Detail-, Integrations- und Lizenzablaufansichten nach Abschnitt 15.7 umsetzen.
- [ ] Queue-Leases, Retry/Backoff, Dead-letter, Replay, Abbruch, Eventkorrelation und Recipe-Version-Snapshot implementieren.
- [ ] Capabilities, REST permission callbacks, Webhook-Signaturen/Replay-Schutz, HTTPS-Allowlist und Secret-Rotation umsetzen.
- [ ] Privacy export/erase, Logredaktion, Retention, CSV-Formelschutz, Backup und Restore-Verhalten implementieren.
- [ ] Entwickler-SDK, Beispielprovider und dokumentierte Action/Tokenreferenz veröffentlichen.

### P2 – Releasevorbereitung

- [ ] Name/Slug workflow-captain bei WordPress.org prüfen und reservieren/einreichen; Produktschlüssel und Core-Slug aneinander angleichen.
- [ ] Drei Pro-Pläne und sechs Entitlements in WP License Captain/Captain Pro eintragen; Shopprodukte mit den festgelegten Preisen anlegen.
- [ ] Supportbetrieb für Standard/Priority einrichten; Responsezeiten nur veröffentlichen, wenn sie eingehalten werden können.
- [ ] Kompatibilitätsmatrix der konkreten Mindestversionen je Captain-Provider veröffentlichen.
- [ ] End-to-End-Prüfungen für HPOS, Classic/Blocks, Refunds, Cronausfall, Retries, Lizenzablauf, Providerfehler, Plugin-Deaktivierung und Migration ausführen.
- [ ] Datenschutzhinweise/Datenfluss, E-Mail-/Review-Consent, Webhook-Information, externe Services, Readme, Handbuch, Changelog und Supportdiagnose finalisieren.

## 13. Technische Risiken und Gegenmaßnahmen

1. **Unterschiedliche Vertragsreife:** Ein Provider wird erst aktiviert, wenn sein öffentliches versioniertes Schema und seine Tests vorliegen; Tabellenzugriffe sind ausgeschlossen.
2. **Doppelte Geschäftsereignisse:** WooCommerce, Voucher und Affiliate können Teile derselben Kaufkette melden. Pro fachlichem Vorgang gilt ein klarer System-of-Record plus Idempotency-Key.
3. **Cron-Verlässlichkeit:** Verzögerungen brauchen Action Scheduler oder verlässlichen System-Cron; Dashboard weist überfällige Läufe aus.
4. **Nebenwirkungen:** Refunds, Einlass, Auszahlungen und Provisionen werden nur über zuständige Fachplugin-Services ausgelöst und auditiert.
5. **Datenminimierung:** Event-Payloads können personenbezogene Daten enthalten; Standardpayloads und Logs enthalten nur minimale, redigierte Daten.
6. **Kompatibilität:** Provider-Versionen unterscheiden sich; die Registry blockiert inkompatible Vertrags-Majors sichtbar und fail-closed.
7. **Integrationsbreite:** Version 1 liefert die sechs Suite-Provider plus sichere Webhooks. Eine große native App-Bibliothek ist bewusst Folgearbeit.

## 14. Quellen zur Wettbewerbsbeobachtung

- Uncanny Automator: [WordPress.org Pluginseite](https://wordpress.org/plugins/uncanny-automator/), [Features/Automationen](https://automatorplugin.com/automations/), [FAQ](https://automatorplugin.com/faq/)
- AutomatorWP: [WordPress.org Pluginseite](https://wordpress.org/plugins/automatorwp/), [Produktseite](https://automatorwp.com/)
- WP Webhooks: [WordPress.org Pluginseite](https://wordpress.org/plugins/wp-webhooks/), [Features](https://wp-webhooks.com/features/)
- SureTriggers / OttoKit: [WordPress.org Pluginseite](https://wordpress.org/plugins/suretriggers/)
- Recherche-Stichtag: 15.11.2026. Wettbewerbsaussagen sind qualitative Beobachtungen aus Anbieter-/Pluginseiten; sie sind keine unabhängige Tarif- oder Benchmarkprüfung.


## 15. Workflow Captain Version 1.0 – vollständiges Produktkonzept

Dieser Abschnitt definiert Version 1.0 als erstes vollständiges Produktrelease von Workflow Captain. Der Umfang umfasst einen produktiv nutzbaren kostenlosen WordPress-/WooCommerce-Kern und ein separat lizenziertes Pro-Add-on mit Verbindungen zu allen Captain-Suite-Produkten. „Vollständig“ heißt: die unten festgelegten Provider und Aktionen sind dokumentiert, getestet und betreibbar. Es bedeutet nicht beliebige PHP-Hooks, willkürliche Datenbankaktionen oder jede denkbare Fremdsoftware.

### 15.1 Produktziel und Releaseversprechen

Mit Version 1 können Betreiber typische Geschäftsabläufe innerhalb von WordPress und der Captain Suite selbständig erstellen, prüfen, aktivieren, überwachen und warten. Ereignisse aus einer Quelle können über Bedingungen, Verzögerungen und sichere Aktionen zu einem anderen Plugin führen.

**V1-Versprechen:**
- WordPress-Core- und WooCommerce-Abläufe funktionieren im kostenlosen Kern ohne Lizenzserverkontakt.
- Captain-Suite-Verbindungen sind im Pro-Add-on verfügbar, sobald das jeweilige Kernplugin installiert und mit kompatiblem Providervertrag aktiv ist.
- Workflows laufen auf der Website des Kunden. Keine externe Workflow-Cloud, Task-Credits oder Pflichtkonten bei einem Automationsanbieter.
- Pro-Ausführungen werden nachvollziehbar protokolliert, dedupliziert und bei Fehlern kontrolliert wiederholt.
- Datenbesitz und Geschäftsregeln bleiben bei den Fachplugins. Workflow Captain koordiniert; es ersetzt weder WooCommerce noch die Suite-Plugins.

### 15.2 V1-Umfang

#### Workflow Captain Free 1.0

- Coreplugin: WordPress 6.8+, PHP 8.3+; WooCommerce-Adapter optional ab WooCommerce 8.0. HPOS sowie Classic- und Blocks-Bestellungen werden unterstützt, sobald die Adapterversion dies deklariert.
- Unbegrenzte Workflows und Ausführungen, ohne Task-Credits oder externen Account.
- Mehrere alternative Start-Trigger, mehrere geordnete Actions pro Lauf, einfache AND/OR-Filter und Tokens aus dem jeweiligen Event-Schema.
- Grundlegende Zeitplanung: „sofort“, „nach Dauer“ und „zu Zeitpunkt“ für Aktionen. Trigger sind vor allem WordPress-Core und WooCommerce.
- WordPress: Registrierung, Rollenänderung, Inhaltsveröffentlichung/-änderung, Kommentarereignisse.
- WooCommerce: Bestellung erstellt/bezahlt/Status geändert, Erstattung/Storno, Produktstatus/Lagerwarnung und Coupon im Auftrag verwendet.
- Aktionen: E-Mail, Bestellnotiz, sichere Änderung des Bestellstatus, eingeschränkte Core-Post-/Benutzeraktion und WooCommerce-CRUD-Meta.
- Dashboard, Editor, Dry Run, Aktivieren/Pausieren, Versionsentwurf, Fehlerliste und redigierte 30-Tage-Protokolle.
- Datenschutzexport/-löschung und Developer-Provider-Registry.

#### Workflow Captain Pro 1.0

- Alle Premiumfunktionen sind in allen Plänen enthalten; es gibt nur Site-Limit und Supportstufe.
- Alle Captain-Suite-Provider, sichere Business-Aktionen und Rezeptvorlagen wie unter 15.4/15.5 spezifiziert.
- Freigabeschritte, ereignisübergreifende Korrelation mit konfiguriertem Schlüssel und Timeout, erweiterte Condition-Gruppen sowie If/Else.
- Warten bis Ereignis/Termin, Business-Statusprüfung vor Aktion und Abbruch geplanter Abläufe bei Refund, Storno, Widerruf oder Opt-out.
- Ein- und ausgehende HMAC-signierte Webhooks mit Timestamp-/Replay-Schutz, Rate-Limit, Schlüsselrotation und Zustellprotokoll; HTTP-Ausgänge nur per HTTPS-Host-Allowlist, ohne Redirects zu internen Adressen.
- Action Scheduler mit Retry/Backoff, Job-Leases, dedupliziertem Replay, Dead-letter-Status und Dashboard für überfällige Queue.
- Versionshistorie, Export/Import mit Provider-Migrationsvorschau, Freigabe vor Veröffentlichung und konfigurierbare Rezeptvorlagen.
- Detaillog bis 365 Tage (Standard 90 Tage), CSV-Export mit Formelschutz, Audit-Historie und Rollensteuerung.
- Keine nativen CRM-/Mail-/Social-App-Connectoren in v1; Webhooks decken generische externe Übergaben.

#### Nicht Teil von Version 1

- Beliebiger PHP-/SQL-/Shell-Code, freie Funktionsaufrufe, Dateisystemoperationen und unkontrollierte Datenbankänderungen.
- Native App-Bibliothek zu Hunderten Drittanbietern, Cloud-Ausführung und taskbasierte Abrechnung.
- Unbeschränkte Schleifen, parallele Fan-outs ohne feste Obergrenze und freie dynamische Skripte.
- Automatische Auszahlung, Provisionsberechnung, Zahlungsrückerstattung, Ticket-Einlass-/Codeerzeugung oder irreversible Buchhaltungsschritte ohne autorisierten Fachplugin-Service.
- KI-generierte Workflows und autonome Aktionen.
- Integration beliebiger Drittanbieter-Plugins ohne dokumentierten und versionierten Provider.

### 15.3 Workflow-Modell und Ausführungsregeln

Ein Workflow besteht aus einem Einstieg (ein Event oder alternative Events), Bedingungen und einem gerichteten Ablauf aus Aktionen, Wartezeiten und Verzweigungen. Jeder Lauf speichert Rezept-ID und veröffentlichte Version.

**Ereignisverarbeitung**
- Jede Quelle übergibt einen stabilen event_id, Contract-Version, occurred_at_utc, object_ref, correlation_id und minimalen typisierten Payload.
- Derselbe event_id darf pro Workflow-Version nur einen Startlauf erzeugen.
- Ein Event wird zuerst persistiert und dann in der Queue verarbeitet. Bei Datenbank-/Queue-Fehler wird es nicht stillschweigend als erfolgreich markiert.
- Rückrufe aus einer Aktion können neue Events auslösen. correlation_id und Run-Ursprung erkennen Schleifen und verhindern, dass ein Workflow sich selbst unbegrenzt erneut startet.
- Einmalige Einstiegsereignisse: Trigger „Order bezahlt“ und „Voucher ausgestellt“ sind getrennte Quellen, auch wenn beides zu derselben Kaufkette gehört. Ein Rezept, das beide Ereignisse abonniert, muss eine explizite fachliche Deduplizierung konfigurieren; Standard ist keine automatische Zusammenlegung.

**Bedingungen und Verzweigungen**
- Typisierte Operatoren für Text, Zahl, Betrag, Datum, Boolean, ID/Enumeration und Listenmitgliedschaft.
- AND/OR-Gruppen mit Klammerungstiefe und Maximalzahl an Regeln, um Laufzeit und UI verständlich zu halten.
- If/Else-Zweig muss genau einen Pfad pro Evaluation wählen; Bedingungen werden anhand desselben Event-Snapshots ausgewertet.
- PII- und Geheimnisfelder erscheinen nicht in Bedingungen, außer der Provider markiert sie explizit zulässig und der Betreiber hat die Capability.
- Bedingungen besitzen einen sichtbaren „wenn Feld fehlt“-Modus (false/stop/error), kein stilles Casting.

**Queue, Verzögerung und Wiederholung**
- Action Scheduler bei WooCommerce, ansonsten kompatible interne Queue mit WP-Cron-Fallback und klarer System-Cron-Diagnose.
- Verzögerungen werden mit UTC-Zielzeit und Website-Zeitzone gespeichert. Sommerzeitumstellung und Änderung der Site-Zeitzone sind definiert: bestehende Jobs behalten den absoluten UTC-Zeitpunkt; Oberfläche zeigt den neu umgerechneten lokalen Wert.
- Jeder Aktionstyp deklariert idempotent/nicht idempotent, retry-fähig/nicht retry-fähig und mögliche Seiteneffekte.
- Standard: bis zu drei automatische Versuche mit exponentiellem Backoff bei temporären Fehlern; Validierungs-/Berechtigungsfehler werden nicht wiederholt.
- Fehlgeschlagene Läufe landen in „Aktion erforderlich“. Betreiber können nach Einsicht in Step-Status, Event, Seiteneffektwarnung und Idempotency-Key wiederholen.
- Abbrechen stoppt noch wartende Schritte. Bereits erfolgreich ausgeführte Aktionen werden nicht automatisch rückgängig gemacht.
- Die Änderung oder Deaktivierung eines Workflows löscht keine laufenden Run-Datensätze. Der Betreiber entscheidet bei ausstehender Arbeit zwischen fortsetzen, abbrechen oder kontrolliert migrieren.

**Rezeptversionierung**
- Aktivieren veröffentlicht eine unveränderliche Version. Bearbeiten erzeugt Entwurf; „Änderungen veröffentlichen“ ist eigener Schritt.
- Laufende und geplante Runs verwenden ihre Startversion. Wird ein Provider inkompatibel, werden betroffene Runs pausiert und mit Migrationsgrund angezeigt.
- Löschen eines Workflows archiviert ihn mit Protokollen; eine endgültige Löschung richtet sich nach Retention- und Datenschutzregeln.

### 15.4 V1 Integrationskatalog

Die folgende Liste ist verbindlicher Zielumfang. Die PHP-Eventnamen sind nicht automatisch das öffentliche Produkt-API. Der Provideradapter stabilisiert Namen, Schema und Verhalten und wird von jedem Quellplugin versioniert dokumentiert.

#### WordPress Core

**Trigger:** Benutzer registriert, Rolle geändert; Beitrag/Seite veröffentlicht oder aktualisiert; Kommentar eingereicht oder moderiert.  
**Aktionen:** Admin-/Benutzer-E-Mail; Beitrag/Seite mit explizit freigegebenen Feldern erstellen/aktualisieren; Rolle ändern, aber niemals den letzten Administrator entfernen; Kommentar in Moderation setzen.  
**V1-Ausschlüsse:** Login-Trigger wegen hoher Frequenz/Privatheit; Benutzer löschen; Passwort setzen; Plugin-/Theme-/Dateiänderungen.

#### WooCommerce

**Trigger:** Bestellung erstellt, Zahlung abgeschlossen, Status geändert, Versand-/Erfüllungsstatus geändert, teilweise/vollständig erstattet, storniert; Bestellung von Gast oder Konto. Produkt erstellt/aktualisiert und Bestand unterschreitet einen konfigurierten Schwellenwert; Lagerereignisse werden nach WooCommerce-Produkt-/CRUD-Events dedupliziert.  
**Bedingungen/Tokens:** Bestell-ID, Status, Währung, Summen, Positionsprodukt/Kategorie/Variation/Menge, Versandart, Coupon-Referenz, User-Referenz und E-Mail nur dann, wenn eine dafür vorgesehene Kommunikation ausgeführt wird. Kartendaten und Gateway-Secrets nie verfügbar.  
**Aktionen:** Bestellnotiz; E-Mail; kontrollierte Statusänderung; Order-Meta über WooCommerce CRUD; Suite-Aktion über den Zielprovider. Kein eigenes Gutscheinausstellen oder Provisionsbuchen als Ersatz der jeweiligen Suite-Integration.  
**Refund-Regel:** jede Refund-ID wird höchstens einmal verarbeitet. Teilrefund-Payload enthält Positionsreferenz, Menge und Betrag, soweit WooCommerce sie stabil bereitstellt; wenn der Quellprovider keinen eindeutigen Zusammenhang liefert, gibt es ein Review-/Adminereignis statt stiller Zuordnung. HPOS, Classic und Blocks sind Release-Gates.

#### WP License Captain

**Trigger:** Lizenz erstellt/aktiviert/verlängert/abgelaufen/gesperrt sowie Updateversion veröffentlicht, wenn der Lizenzkern einen sicheren Outbound-Provider bereitstellt. Kein Trigger für Lizenzschlüsselwert oder geheime Kundendaten.  
**Aktionen:** Adminhinweis oder interne Benachrichtigung; keine Lizenz widerrufen/verlängern/ändern durch generische Workflows in Version 1.  
**Lizenzablauf von Workflow Captain selbst** wird nicht über diese Workflow-Engine gesteuert, sondern über den Add-on-Kernel.

#### Voucher Captain

**Trigger:** Gutschein ausgestellt; E-Mail zugestellt/fehlgeschlagen; Gutschein eingelöst; Guthaben geändert; Gutschein abgelaufen/gesperrt; Rückerstattung/Storno.  
**Aktionen:** Gutschein kontrolliert über Voucher-Service ausstellen, erneute Zustellung, Gutscheinstatus abfragen, zulässige Sperrung/Rückgabe. Wertänderung und manuelle Guthabenkorrektur bleiben dem Voucher-Admin vorbehalten.  
**Schutz:** Gutscheincode ist Geheimnis, standardmäßig kein Token und niemals im Run-Log. Downloadlinks nur als kurzlebige, gezielte Zustellaktion. Das Gutschein-/Guthaben-Ledger bleibt im Voucher-Plugin.

#### Pass Captain

**Trigger:** Ticket reserviert/ausgestellt/übertragen/eingecheckt/ausgecheckt/widerrufen; Event oder Termin veröffentlicht/geändert/verschoben/abgesagt; Wartelistenangebot erstellt/angenommen/abgelaufen.  
**Aktionen:** an Käufer/Teilnehmer über Pass-Service senden; Ticket/Eventhinweis setzen; freigegebene Benachrichtigung auslösen; eine Voucher-Aktion nur über die bereits abgestimmte Bridge.  
**Schutz:** Ticketcodes und QR-Geheimnisse nicht in Payloads oder Logs; Tickets nicht direkt erzeugen, transferieren oder einchecken lassen. Ticketkapazität, Bestellung und Einlass gehören Pass Captain.

#### Affiliate Captain

**Trigger:** Bewerbung eingegangen; Partner freigegeben/abgelehnt/pausiert; Provision erfasst/freigegeben/abgelehnt/ausgezahlt; Auszahlung abgebrochen.  
**Aktionen:** Benachrichtigung; Bewerbungs-/Partnerstatus nur über Affiliate-Service und mit explizitem Capability-Schutz. Provision und Auszahlung nicht selbst errechnen, ändern oder wiederholen; Refunds gehen über die definierte Verkaufsquelle/Idempotenz des Affiliate-Kerns.  
**Schutz:** Bank-/Steuerdaten und personenbezogene Partnerdaten sind keine Tokens. Umsatz- und Partneraktionen werden auditiert.

#### Form Captain

**Trigger:** Formular eingereicht; bestimmte freigegebene Felder erfüllen Bedingung; Spam erkannt; Einsendung markiert/archiviert.  
**Aktionen:** Admin-Benachrichtigung; Submission-Status markieren; Formularwerte an einen erlaubten Captain-Service übergeben; bei klarer Bestätigung einen Voucher- oder Partnerantrag über dessen Service erstellen.  
**Schutz:** Auswahl konkreter Felder im Editor, Datenschutzkennzeichnung und Consent-Check. Keine komplette Einsendung ungeprüft per E-Mail/HTTP kopieren. Keine Zahlung oder Vertragsannahme ohne ausdrückliche Bestätigung im Formularflow.

#### Review Captain

**Trigger:** Review angelegt/eingereicht/freigegeben/abgelehnt; Anfrage geplant/unterdrückt/gesendet/fehlgeschlagen; Syncfehler.  
**Aktionen:** zulässige Anfrage planen/abbrechen; Adminhinweis; lokale Moderationsaktion über Review-Service, falls Rolle dies erlaubt.  
**Schutz:** Kein Review-Gating, keine Belohnung für öffentliche Reviews, kein Routen nach Sternen, keine Umschreibung externer Reviews. Öffentliche Reviews bleiben in ihrer Quelle gekennzeichnet.

### 15.5 Suite-Crossflow-Szenarien für V1

Jede Vorlage zeigt beteiligte Plugins, Voraussetzungen, Seiteneffekte, betroffene Daten und Deaktivierungsverhalten. Vorlagen importieren immer als Entwurf.

1. **WooCommerce → Voucher Captain:** Order paid und qualifiziertes Produkt; Voucher Captain stellt den Gutschein idempotent nach seiner Produktkonfiguration aus. Workflow kann Zustellung beobachten, aber ersetzt nicht dessen Versandregeln.
2. **WooCommerce → Pass Captain:** Bestellung/Statusänderung führt über die bestehende Ticket-Woo-Integration; Workflow Captain kann nach Ticket-Ausgabe benachrichtigen. Es wird kein zweites Ticket erstellt.
3. **Pass Captain → Review Captain:** bestätigter Check-in, nach Event/Teilnahme definierte Verzögerung, Suppressionprüfung, Review-Anfrage. Ein bloßer Ticketkauf ist nicht ausreichend.
4. **Voucher Captain → Review Captain:** nur nach bestätigter Serviceeinlösung, wenn Produkt/Betreiber dieses Erlebnis freigegeben hat; Rabattcode oder Geschenkkartenaufladung qualifizieren nicht.
5. **Form Captain → Voucher Captain:** eingehende Firmen-/Gutscheinanfrage wird nach Adminfreigabe in Voucher Captain erstellt; Zahlung und Gutscheinledger verbleiben beim Voucher-Kern.
6. **Form Captain → Affiliate Captain:** geeignete Partnerbewerbung wird als „pending“ über die Affiliate-Service-API angelegt; Freigabe, Codes und Provisionsregeln bleiben beim Affiliate-Kern.
7. **WooCommerce Refund → Affiliate Captain:** Affiliate Captain verbucht Refund-Korrektur aus der kanonischen Verkaufsquelle. Workflow kann informieren/den Prozess überwachen, nie Provision parallel neu buchen.
8. **Pass Event abgesagt → WooCommerce/Review/Voucher:** Pass Captain besitzt die Absage, WooCommerce besitzt Refund, Voucher Captain besitzt Gutscheinrückgabe, Review Captain besitzt Anfrageabbruch. Workflow startet nur die offiziell freigegebenen Folgeaktionen und loggt Teilfehler einzeln.
9. **WP Registrierung → Form/Pass/Voucher:** Konto- und Rollenevents können interne Benachrichtigungen oder erlaubte Onboarding-Schritte auslösen; Marketing nur mit passendem Kommunikationsstatus.
10. **Review freigegeben → Redaktion:** internes Team wird informiert; keine automatische Weiterleitung zur öffentlichen Veröffentlichung außerhalb des Review-Plugins.

### 15.6 Öffentliche Provider-Verträge und Datenmodell

**Vertrag pro Provider:** Provider-ID, SemVer, Mindestversion Kernplugin, Trigger-/Action-Schema, Feldtypen und Datenschutzklassen, Capability-Anforderung, Retry-/Idempotenzregel, API-Fehlercodes, Beispielpayload, Migrationsanleitung, Tests und Changelog.

**Pflichtfelder eines Events:** event_id; event_key; contract_version; occurred_at_utc; source_plugin; object_type/object_ref; correlation_id; payload; privacy_tags. Das Quellplugin muss nach Commit feuern, sofern das Ereignis eine dauerhafte Buchung repräsentiert; bei Vorabvalidierung muss der Vertrag ausdrücklich „vor Commit“ markieren. Ein Ereignis darf keine rohen Datenbankobjekte transportieren.

**Provider-Registry:** Capability wird zur Laufzeit geprüft (Plugin vorhanden/Version/Vertrag). Unbekannte oder inkompatible Felder führen zu verständlichen Fehlern. Das Kernplugin kann Provider ohne Suite-Komponenten starten. Provider dürfen ihre Schnittstellen unabhängig aktualisieren, sofern die Contract-Major kompatibel bleibt.

**Persistenz:** Rezepte, immutable Rezeptversionen, Runs, Schritte, Queue/Lease, Providerfehler und Audit-Historie in eigenen tabellarischen Speicherstrukturen. IDs referenzieren Fachobjekte, duplizieren deren Fachdaten aber nicht. Keine dauerhafte Event-Payload über den für Retry/Retention notwendigen Zeitraum. Fremde Objekte werden beim Anzeigen live über deren öffentliche API geladen, soweit noch vorhanden.

**Multisite:** Daten und Runs pro Site getrennt; Site-ID gehört in Idempotency-Key. Netzwerkweite Rezepte/Engine sind nicht Teil von Version 1, auch wenn eine Lizenz mehrere Site-Aktivierungen umfasst.

### 15.7 Admin-Seiten und v1-Inhalte

- **Dashboard:** aktive Workflows; Warteschlange; fehlgeschlagene Runs; überfällige Jobs; Contract-/Pluginstatus; Aktionen „Erstellen“, „Vorlage prüfen“, „Fehler beheben“.
- **Workflows:** Suche/Filter nach Status und Plugin; Trigger-/Action-Zusammenfassung; Laufzahl; zuletzt ausgeführt; Version; Owner; pausieren/duplizieren/archivieren.
- **Editor:** Triggerauswahl, Eventfelder, Bedingungen, Aktionen, Verzweigung und Verzögerung, Tokenpicker, Testdaten, Dry Run und Veröffentlichungsvorschau. Jede Action zeigt Schreibwirkung und nötige Capability.
- **Vorlagen:** vorgeprüfte Suite-Flows; Voraussetzungen und Datenfluss; Draft-Import mit Prüfung, kein automatisches Live-Schalten.
- **Läufe:** Liste nach Status/Datum/Workflow/Provider; Detailansicht Schritt für Schritt, Fehlercode, Retry-Zähler, redigierte Ein-/Ausgabe, Correlation-ID; sicher wiederholen/abbrechen.
- **Integrationen:** installierte Suite-Plugins, Versions-/Vertragsstatus, Providerfeatures, Diagnose und Dokumentationslinks.
- **Einstellungen:** Rollen, Retention, Zeitzone, Queue/cron, E-Mail, Datenschutz, Diagnose und Export/Import.
- **Pro-Lizenz:** nur das Add-on-Kernel-Menü; Lizenzstatus, Entitlements, Sites und Updates.
- **Hilfe:** Providerkatalog, Datenfluss, Trigger-/Tokenverzeichnis, Rezeptbeispiele, Problembehandlung und Entwicklervertrag.

### 15.8 Lizenz- und Upgradeverhalten für Version 1

- Produktkey/Ordner/Textdomain: workflow-captain-pro. Rechtepräfix: workflow.
- Entitlements als einzelne Fähigkeiten, zum Beispiel workflow.suite_integrations, workflow.advanced_logic, workflow.delays, workflow.extended_logs und workflow.recipe_templates. Ein Recht je eigenem Feature im Captain-Pro-Kernel.
- Free enthält keine Lizenzlogik. Pro-Lizenzclient, Updater und Feature-Registry liegen ausschließlich in captain-pro und nutzen den bestehenden vendorten Client/Kernel.
- Site-Limits und Supportlevel folgen dem WP-License-Captain-Modell; keine neuen Lizenztypen, selbstgebauten Checks oder monatlichen Task-Credits.
- Ohne gültige Lizenz bleiben Workflows, Snapshots, Logs und Exporte lesbar. Neue Pro-Ausführungen und geplante Premiumaktionen werden pausiert, mit Grund angezeigt und nicht stillschweigend in Free-Aktionen konvertiert.
- Bei erneuter Lizenzaktivierung werden pausierte Jobs nicht unkontrolliert nachgeholt. Betreiber können fällige Runs einzeln oder gesammelt anhand einer Vorschau fortsetzen.
- Release-Schritte: Add-on im captain-pro, Produkt im License-Captain-Katalog, Entitlement-Mapping, öffentliche Schlüssel/Serverkonfiguration, Updatepaket, Support-/Changelog- und Kompatibilitätsdokumentation.

### 15.9 Qualitäts- und Releasekriterien

Version 1.0 ist erst releasebereit, wenn:

- alle oben aufgeführten Core-/Woo-/Suite-Provider reale versionierte Verträge besitzen;
- jede Schreibaktion Capability, Nonce/Auth, Schema-Validierung, Idempotenz und Audit erfüllt;
- doppelte Hooks, parallele Requests, Retries, Refunds, Statuswechsel und Lizenzablauf ohne doppelte Geschäftsaktionen verarbeitet werden;
- HPOS, Classic-/Block-Checkout, fehlende WooCommerce-/Suite-Plugins, inkompatible Vertragversion, WP-Cron-Ausfall und Multisite Site-Isolation geprüft sind;
- Rezept-/Schema-Upgrades, Import/Export, Datenschutzexport/-löschung und Wiederherstellung aus Backup dokumentiert sind;
- Queue-Monitoring zeigt, ob Jobs ausstehen, überfällig, in Arbeit oder fehlgeschlagen sind;
- jede Mailvorlage Empfänger, Anlass, Opt-out/Einwilligungsstatus und Absender nachvollziehbar macht;
- der Release-Build Lizenzclient/Kernel nur im Pro-Add-on enthält und das Free-ZIP vollständig ohne Lizenzkontakt funktioniert;
- Handbuch, Hook-/REST-Referenz, Datenschutzhinweise, WordPress.org-Readme, Release Notes und Supportdiagnose fertig sind.

Keine Tests wurden bei dieser Konzeptaktualisierung ausgeführt. Die obigen Punkte sind Abnahmekriterien für die spätere Implementierung.

### 15.10 Festgelegte Produktentscheidungen

| Thema | Festlegung für Version 1 |
|---|---|
| Produktname | Workflow Captain |
| Kostenloses Plugin | workflow-captain, WordPress.org-Slug nach gleichnamigem Namenscheck |
| Pro-Add-on/Produktschlüssel | workflow-captain-pro, im Captain-Pro-Repository |
| Lizenzmodell | Jahreslizenz über WP License Captain; 1/5/25 Live-Sites; Standard-Support bei Single/Business, Priority bei Agency; alle Premiumfunktionen in allen Plänen |
| Preise | Single 129 €/Jahr für 1 Site; Business 229 €/Jahr für 5 Sites; Agency 399 €/Jahr für 25 Sites. Preise im Woo-Shop gemäß dessen Steuerkonfiguration. |
| Verlängerung | Gleicher gespeicherter Preis bei Verlängerung, keine Lockpreise oder nur im ersten Jahr gültigen Rabatte |
| Keine Lifetime-/Monatslizenz zum Start | Der vorhandene Lizenzserver ist auf Monat/Jahr ausgelegt; v1 startet jährlich. Monatlich wird erst nach realer Nachfrage ergänzt; Lifetime wird ausgeschlossen. |
| Free-Versprechen | Unbegrenzte Workflows und Ausführungen; WP Core + WooCommerce; mehrere Aktionen, einfache Bedingungen, Basis-Zeitplanung und 30-Tage-Logs |
| Pro-Versprechen | Alle Captain-Provider, geschützte Webhooks, erweiterte Korrelation/Branches, Queue/Replay, Suite-Rezepte und erweitertes Protokoll |
| Nutzungslimits | Keine Task-Credits oder monatlichen Run-Limits. Nur Lizenz-Site-Limits und technische Queue-Schutzgrenzen. |
| Integrationsemantik | Mehrere Einstiegsereignisse starten grundsätzlich je einen eigenen Lauf; Warten auf zusammengehörige Ereignisse erfolgt nur über einen expliziten Pro-Korrelationsknoten. |
| Ausführungsbasis | Lokal auf der WordPress-Seite; Action Scheduler bei WooCommerce, WP-Cron-Fallback mit System-Cron-Empfehlung |
| Kompatibilität | WordPress 6.8+, PHP 8.3+; WooCommerce 8.0+ optional für Shop-Provider. Jeder Suite-Adapter verlangt zusätzlich die im Providervertrag definierte Kernplugin-Mindestversion. |
| Externe Systeme | Keine native Drittanbieter-App-Bibliothek in v1. Pro-Webhooks sind der sichere generische Adapter. |
| KI | Nicht in Version 1; keine automatische Veränderung oder Aktivierung ohne Betreiberfreigabe |
| Supportzeit | Support-Level folgen dem Captain-Plan. Keine Antwortzeitgarantie, bevor Supportbetrieb dafür ausgestattet ist. |

**Preisbegründung:** Die Wettbewerber zeigen für ihre Pro-Einstiegspläne ungefähr 149 USD/Jahr bei WP Webhooks (1 Site) und 199 USD/Jahr bei Uncanny Automator (1 Site). Zum EZB-Referenzkurs vom 13.11.2026 entsprechen 129 € ungefähr 143 USD; die Preisvergleiche sind vor Umsatzsteuerabgleich zu verstehen. AutomatorWP bietet darüber hinaus einen großen kostenlosen Integrationsumfang und wirbt auf seiner Preisseite mit befristeten Aktionspreisen. Workflow Captain kann diese Breite nicht versprechen und setzt daher auf eine günstigere Ein-Site-Einstiegslizenz, tiefe Captain-Suite-Integrationen und einen starken kostenlosen WP-/WooCommerce-Kern. 229 €/5 Sites und 399 €/25 Sites schließen an die bereits vorgeschlagene Voucher-Captain-Pro-Staffel an und halten die Suite-Preise einheitlich. Wechselkurse und Anbieterpreise müssen vor Veröffentlichung erneut geprüft werden.

Die Gratisversion muss dennoch glaubwürdig sein: Wettbewerber bieten zum Teil Hunderte Integrationen, Mehrfachaktionen, Filter, Zeitplanung und unbegrenzte Ausführungen kostenlos. Workflow Captain Free erhält deshalb seine vollwertige lokale WP-/WooCommerce-Engine, ohne Task- oder Workflow-Cap. Das Pro-Upgrade wird durch die fachlich sicheren Captain-Integrationen, Webhooks und den Betriebs-/Diagnoseumfang begründet, nicht durch künstliche Runlimits.

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

### 15.12 Wettbewerbsvergleich und abgeleitete Entscheidungen (Stand 15.11.2026)

| Wettbewerber | Aktuell sichtbarer Ansatz | Konsequenz für Workflow Captain |
|---|---|---|
| **AutomatorWP** | Die WordPress.org-Seite bewirbt mehr als 600 Trigger/Aktionen/Filter aus über 300 Plugins, unbegrenzte Ausführungen, Mehrfachtrigger/-aktionen, Filter, Zeitpläne und Loops kostenlos. Die Preisseite zeigt jährliche Add-on-Pässe für 2/10/unbegrenzte Sites; dort angezeigte Aktionspreise sind als Einführungspreise gekennzeichnet. | Kein künstliches Runlimit. Free muss echte Rezepte mit mehreren Schritten, Conditions und Zeitsteuerung können. V1 muss keine Hunderte Drittanbieter-Adapter vortäuschen; Suite-Tiefe ist unsere Differenzierung. |
| **WP Webhooks** | Starter 149 USD/Jahr für 1 Site, mit unbegrenzten Automationen/Webhooks/Triggern/Aktionen, Bedingungen, Verzögerung, Logs und 100+ Integrationen; höhere Pläne erhöhen Site-Limit und Support. | Eingehende/ausgehende sichere Webhooks gehören in Pro v1. Jahreslizenz nach Sites/Support ist ein verständliches Marktmodell. |
| **Uncanny Automator** | Lite bewirbt Hunderte Trigger/Aktionen und Integrationen; Basic wird mit 199 USD/Jahr für 1 Site angeboten und wirbt mit Zeitplanung, Conditions, unbegrenzten Rezepten/Ausführungen, erweiterten Integrationen und Webhooks. | Free darf nicht nur ein Demo-Builder sein. Pro soll ohne Task-Credits auskommen und die bestehende Suite-Lizenzlogik nutzen. |

**Recherchierte Anbieterquellen**

- AutomatorWP [WordPress.org](https://wordpress.org/plugins/automatorwp/) und [Preise](https://automatorwp.com/pricing/)
- WP Webhooks [Preise](https://wp-webhooks.com/pricing/)
- Uncanny Automator [Preise](https://automatorplugin.com/pricing/)
- Recherche-/Preisstand: 15.11.2026; Wechselkursvergleich mit EZB-Referenzkurs 13.11.2026. Preise und Funktionsumfang sind veränderlich; diese Entscheidung ist eine Preispositionierung, keine Preisabfrage im Live-Shop. Quelle zum Wechselkurs: [EZB EUR/USD](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/eurofxref-graph-usd.en.html).

