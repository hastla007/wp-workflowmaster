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

Das Free-Plugin muss ohne Lizenzserver und ohne Pro-Add-on nutzbar sein.

- Einfache visuelle Rezepte aus einem Ereignis und einer oder mehreren Aktionen (lineare Folge).
- WordPress-Core-Provider für Benutzer- und Inhaltsereignisse.
- WooCommerce-Basisereignisse und sichere Grundaktionen, sofern WooCommerce aktiv ist.
- Aktivieren, pausieren, duplizieren und manuell testen.
- Einfache statische Bedingungen (z. B. Rolle, Post-Typ, Bestellstatus), sofern das Feld vom Event bereitgestellt wird.
- Sofortausführung mit begrenztem Ausführungsprotokoll und Detailansicht.
- Grundlegende Tokens/Platzhalter aus dem Event-Payload, mit Vorschau und kontextgerechtem Escaping.
- E-Mail-Aktion an Website-Admin bzw. konfigurierte Empfänger, mit sicheren Standardvorlagen.
- Admin-Menü, Hilfetexte, Rezeptübersicht, Konnektorstatus und einfache Diagnose.
- Datenschutz: Aufbewahrungsdauer für Logs, Personenexport/-löschung, keine Übertragung externer Daten standardmäßig.
- Developer-API/Provider-Registry und Dokumentation für Drittautoren.
- Kostenloser Kern funktioniert ohne Lizenzcode, Pro-Hinweise nur kontextuell und ohne harte Sperren vorhandener Daten.

### Workflow Captain Pro (separates Add-on, lizenziert)

Pro wird als separates Add-on nach dem etablierten Captain-Pro-Modell gebaut. Lizenzprüfung, Updater und Premiumfunktionen befinden sich in `captain-pro`; Workflow Captain Free kennt das Add-on nicht.

- Suite-Adapter für Voucher, Pass, Affiliate, Form und Review Captain.
- Erweiterte Conditions: AND/OR-Gruppen, numerische/Text-/Datumvergleiche, Produkt-/Kategorie-/Coupon-/Event-/Formularfilter sowie sichere Negation.
- Mehrere Trigger für ein Rezept (UND-Semantik und unabhängig gestartete Runs klar unterscheidbar).
- Verzögerungen, geplante Schritte, Warteschlange und kontrollierte Wiederholungen.
- Abzweigungen (If/Else), Stop-/Abbruchpfade und Schritt-für-Schritt-Ausführung.
- Erweiterte WooCommerce- und Suite-Aktionen über Anbieter-Serviceverträge.
- Webhooks als Trigger und Aktion; HTTP-Request-Aktion mit erlaubten Domains, Secrets, Timeout, Größenlimit und SSRF-Schutz.
- Rezeptvorlagen, Export/Import mit Schema-Validierung, Ablaufprotokolle mit konfigurierbarer Aufbewahrung.
- Filter, Suche, Logs-Export, Ausführungsdetails und manuelle Wiederholung fehlgeschlagener Runs.
- Zeit-/Volumenlimits nach Lizenzplan als Entitlements (z. B. Websites und Supportlevel nach WP License Captain-Konfiguration); konkrete Tarifgrenzen noch festzulegen.
- Externe App-Plattformen und Cloud-Runs sind nicht Teil von Version 1; interne WordPress-/WooCommerce-/Captain-Suite-Verbindungen haben Vorrang.

### Lizenzvertrag

- Produktschlüssel: `workflow-captain-pro`; Add-on-Ordner, Textdomain und Produktschlüssel folgen der Captain-Pro-Regel.
- Rechtepräfix: `workflow.`; Schlüssel z. B. `workflow.advanced_logic`, `workflow.delays`, `workflow.webhooks`, `workflow.suite_integrations`, `workflow.recipe_templates`, `workflow.extended_logs`.
- Kernplugin bleibt GPL-2.0-or-later und vollständig eigenständig. Keine Lizenzprüfung, Update-Client-Kopie oder Premiumklasse im WordPress.org-Kern.
- Das Pro-Add-on verwendet den bestehenden WP-License-Client und Add-on-Kernel in `captain-pro`; öffentliche Ed25519-Schlüssel, Server-URL, Minimum-Kernversion und Produktkatalogeintrag sind Release-Voraussetzungen.
- Jedes Premiumfeature erhält genau einen eindeutigen `workflow.*`-Entitlement-Schlüssel. Bei Lizenzablauf werden keine neuen Premium-Läufe gestartet; vorhandene Workflows bleiben sichtbar und lesbar, Logs und Export bleiben für Diagnose erhalten. Geplante Jobs werden sicher gestoppt/als „Pro-Lizenz erforderlich“ markiert. Es werden keine Daten gelöscht.
- Suite-Integrationen im Free-Kern sollten nur Basis-/Leseereignisse enthalten, falls dies Produktstrategie und Free-Limits zulassen. Erweiterte Suite-Aktionen und der Workflow-Umfang sind Pro. Die genaue Zuordnung ist eine Inhaberentscheidung vor Umsetzung, siehe To-do.
- In `captain-pro/README.md` die neue Produktzeile ergänzen; `docs/ARCHITECTURE.md`, Build, Übersetzungen und Release-Dokumente nach bestehenden Mustern erweitern.

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
**Nicht in MVP:** Datei-/Plugin-Änderungen, SQL, arbitrary PHP/Code, Benutzerpasswort aus Tokens, ungeprüfte Adminaktionen.

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

## 11. Roadmap und Abnahmepunkte

### Phase 0 – Suite-Verträge festlegen

- Lizenzierungs-/Free-Pro-Verteilung mit Captain-Pro-Inhaber bestätigen.
- Für jedes Quellplugin Providervertrag, stabile Event-IDs, minimalen Payload und Serviceaktionen definieren.
- Ermitteln, welche bestehenden Hooks/Services stabil genug sind; Lücken in den Fachplugins zuerst ergänzen.
- WordPress/WooCommerce-Kompatibilitätsfenster und PHP-Minimum vereinbaren.
- Wettbewerbs-Tarife/WordPress.org-Richtlinien und Sicherheitsreview dokumentieren.

**Abnahme:** kein Workflow-Ziel greift direkt auf fremde Tabellen oder unversionierte interne Funktionen zu.

### Phase 1 – Workflow Captain Free 1.0

- Plugin-Grundgerüst, Capability/Settings, Rezeptdatenmodell und Provider Registry.
- Core-Events und einfache lineare Workflows; sichere E-Mail-Aktion.
- WooCommerce Basis-Trigger, Bestellnotiz und erlaubtes CRUD-Meta, HPOS-/Blocks-kompatibel.
- Workflow-Liste, Editor, Dry Run, Logs und Queue-/Fehlerdiagnose.
- Datenschutzexport/-löschung und Aufbewahrung.
- Entwicklerdokumentation für Provider.

**Abnahme:** kein Duplicate Run bei wiederholtem Event; Fehler sind sichtbar; fehlende Provider verursachen keinen Fatal; Rezept lässt sich exportieren/archivieren, Daten bleiben ohne Pro erhalten.

### Phase 2 – Workflow Captain Pro & erste Suite-Provider

- Pro-Add-on in `captain-pro`, Produktkatalog-/Entitlement-Eintrag und Lizenzseite über gemeinsamen Kernel.
- Verzögerung, erweiterte Conditions, Wiederholen, Rezeptvorlagen und erweiterte Logs.
- Voucher Captain und Pass Captain nach Verfügbarkeit der Anbieter-Verträge.
- Bestehende WooCommerce- und Suite-Fachprozesse auf Doppelereignisse testen.

**Abnahme:** Lizenzverhalten wie bestehende Add-ons; abgelaufene Lizenz sperrt neue Premiumausführung und lässt Diagnose/Lesen intakt; keine Doppelbuchung.

### Phase 3 – weitere Suite-Verbindungen

- Affiliate Captain und Form Captain Trigger/Aktionen nach vorhandenen Kern-Serviceverträgen.
- Review Captain erst nach Abschluss des eigenständigen Review-Konzepts und dessen Providerverträgen.
- Import/Export mit Schema-Migration, Vorlagen, Multisite und Rollenmodell.
- Externe Webhooks in Pro nach Security Review.

### Phase 4 – Ausbau nach Nutzungsdaten

- Weitere externe Plattformen (z. B. CRM, Mail, Sheets) nur bei Nachfrage und klaren API-/Kosten-/Datenschutzbedingungen.
- Workflow-Statistiken, Adminberichte und Entwickler-SDK-Verbesserungen.
- Keine KI-Erstellung oder Agent-Ausführung in Version 1; falls später gewünscht, nur Vorschlagsmodus mit menschlicher Prüfung und separaten Berechtigungen.

## 12. Detaillierte To-do-Liste

### Produktentscheidungen

- [ ] Free-Limit und Pro-Limit definieren: Anzahl Workflows, Run-Volumen und Sites; keine künstliche Sperre für bereits gespeicherte Workflows.
- [ ] Entscheidung: einzelne Captain-Basis-Trigger im Free oder sämtliche Suite-Adapter Pro.
- [ ] Verbindliche Mindestversionen der Captain-Plugins und WooCommerce festlegen.
- [ ] Welche Aktionen dürfen automatisiert schreiben? Risikoabstufung und Admin-Schutz definieren.
- [ ] Festlegen, ob der Login-Trigger aus Datenschutz- und Volumengründen später ergänzt wird; Benutzerregistrierung ist für Version 1 vorgesehen.
- [ ] Bestätigen, ob Pro komplett offline/self-hosted läuft; Webhook/HTTP ist optional und klar als externe Übertragung ausgewiesen.
- [ ] Namen/Marken/WordPress.org-Slug und Supportkanal bestätigen.

### Quellplugin-Verträge

- [ ] Workflow-Event-Contract und SemVer-Regel definieren.
- [ ] WP Core Provider und Standardtokenliste dokumentieren.
- [ ] WooCommerce-Events für Zahlung, Status, Refund, Storno, HPOS, Classic/Blocks verifizieren.
- [ ] Voucher Captain Ereignisse/Aktionen über Servicevertrag ergänzen; Ausgabe-/Einlösungs-Deduplizierung festlegen.
- [ ] Pass Captain Ticket-/Event-/Wartelistenverträge festlegen; Ticketgeheimnisse aus Payload ausschließen.
- [ ] Affiliate Captain Ereignisse gegen Vertrag 1.3+ ergänzen; keine Provisionen neu berechnen.
- [ ] Form Captain sichere Submission- und Statusprovider definieren.
- [ ] Review Captain Trigger und Grenzen nach Review Captain Freigabe spezifizieren.
- [ ] Alle Provider-Dokumente mit Payload-Beispielen und Privacy Tags versionieren.

### Technik & UX

- [ ] Engine-/Registry-Schnittstellen und Rezept-Schema versionieren.
- [ ] Recipe/Version/Run/Step/Queue Schema, Indizes, Sperrstrategie und Migration entwerfen.
- [ ] Idempotency-/Correlation-/Loop-Prevention-Strategie implementieren.
- [ ] Provider-Discovery, fehlendes Plugin und Vertragsmigration in UX spezifizieren.
- [ ] Editor mit Tastaturbedienbarkeit, Dry Run und Tokenvorschau gestalten.
- [ ] Queue mit Action Scheduler und WP-Cron-Fallback implementieren.
- [ ] Retry-/Abbruch-/Dead-letter-Verhalten und manuellen Replay spezifizieren.
- [ ] Log-Redaktion, Aufbewahrung, Export und personenbezogene Löschung implementieren.
- [ ] REST-/Webhook Auth, SSRF-Abwehr und Secret-Rotation entwerfen.
- [ ] Multisite-Speicherung und Lizenzaktivierungen mit WP License Captain abgleichen.
- [ ] Workflow-Datenexport/-Import mit Version und Preview/Validierung spezifizieren.
- [ ] Rollen, Fähigkeiten, E-Mail-Absender und Systemstatusseiten entwerfen.
- [ ] Entwickler-SDK, Beispielprovider und Hook-Dokumentation erstellen.

### Release & Vermarktung

- [ ] `workflow-captain-pro` als Produkt im WP License Captain-Katalog anlegen.
- [ ] Add-on-Konfiguration, Entitlements, Update URI, öffentliche Schlüssel/Server-URL im `captain-pro` nach Release-Entscheidungen setzen.
- [ ] Plugin-Zeile, Build, Übersetzungen, ZIP-Allowlist und Release-Gates in `captain-pro` ergänzen.
- [ ] Datenschutzerklärung/Datenflussbeschreibung sowie WordPress.org External Services prüfen.
- [ ] Changelog, Installations- und Migrationsanleitung, Support-/Debugreport erstellen.
- [ ] Beta mit WooCommerce-Shop und den installierten Captain-Plugins durchführen; HPOS/Classic/Blocks, Refunds, Cronausfall, Replay und abgelaufene Lizenz abdecken.
- [ ] Vor Verkaufsstart Lizenz, Updates, reale UI, echte WooCommerce-Zahlungsstatus und Restore/Backup-Verhalten end-to-end freigeben.

## 13. Offene Entscheidungen / Risiken

1. **Suite-Verträge sind möglicherweise unterschiedlich reif.** Integrationstiefe richtet sich nach öffentlichen, versionierten APIs; unklare Verträge werden nicht durch interne DB-Zugriffe umgangen.
2. **Doppelte Geschäftsereignisse:** WooCommerce-Order und Voucher-/Affiliate-Service können dieselbe Verkaufssituation melden. Es braucht einen einzigen System-of-Record und idempotente Quelle.
3. **Cron-Verlässlichkeit:** zeitverzögerte Workflows brauchen echten Cron oder Action Scheduler; UI zeigt ausstehende Jobs und einen Cronstatus.
4. **Schreibaktionen mit Folgewirkung:** Rückerstattung, Einlass, Auszahlung und Provisionsbuchung sind im MVP entweder nicht verfügbar oder ausschließlich über autorisierte Fachplugin-Services.
5. **Lizenzmodell/Free-Abgrenzung:** Captain-Pro bestätigt die Zahl-/Run-Limits und den Umgang mit bereits gespeicherten Premium-Rezepten.
6. **Marktumfang:** Tausende Integrationen sind kein realistisches Versprechen für Version 1. Erweiterung folgt Nachfrage und stabilen Verträgen.

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

- Uneingeschränkt nutzbarer Kern ohne Aktivierungszwang, Lizenzcode, Updateclient oder externe Konten.
- WordPress-Core-Trigger: Benutzer registriert, Rolle geändert, Beitrag/Seite veröffentlicht oder aktualisiert, Kommentar eingereicht/freigegeben.
- WooCommerce-Trigger: Bestellung erstellt, bezahlt, Status geändert, teilweise/vollständig erstattet und storniert.
- Ein Trigger oder alternative Einstiegsereignisse; pro Ereignis startet ein eigener Run. AND-Bedingungen zu verfügbaren Eventfeldern.
- Einfache lineare Rezepte mit sofortigen Aktionen: Admin-/Kunden-E-Mail und Bestellnotiz; klar begrenzte WordPress-Aktionen nach Capability.
- Tokenpicker mit Datentyp, Quelle, Verfügbarkeit und Datenschutzmarkierung; Testvorschau mit Beispieldaten.
- Editor, Aktivieren/Pausieren, Testlauf, Rezeptduplikat, Listen-/Suchansicht und Fehlerstatus.
- Basislaufprotokoll für 30 Tage mit redigierten Werten; Aufbewahrungszeit einstellbar, Export/Löschung über WordPress-Datenschutzwerkzeuge.
- WooCommerce HPOS sowie Classic- und Block-Checkout. Keine direkten Abfragen oder Updates an WooCommerce-Bestelltabellen.

Free soll dauerhaft nützlich bleiben: ohne künstliche Anzahlbegrenzung von Workflows oder Ausführungen, ohne Task-Credits und ohne willkürliches Entziehen bereits gespeicherter Inhalte. Systemschutzgrenzen drosseln Last kontrolliert und melden Überlastung sichtbar.

#### Workflow Captain Pro 1.0

- Eigenständiges Add-on im Repository captain-pro, lizenziert nach dessen Produktschlüssel-, Entitlement-, Updater- und Site-Limit-Modell.
- Vollständiger Suite-Provider für WP License Captain, Pass Captain, Voucher Captain, Affiliate Captain, Form Captain und Review Captain. Für jede Integration werden die in 15.4 festgelegten Ereignisse und sicheren Aktionen implementiert.
- WooCommerce-Ausbau: Produkt-/Kategorie-/Coupon-/Zahlartbedingungen, Zeilenpositionsfilter, Versand-/Erfüllungsstatus, Auftragsnotizen und freigegebene Statusaktionen per Woo CRUD.
- Mehrschrittige Abläufe mit mehreren Aktionen, Verzögerungen, geplantem Datum, If/Else, AND-/OR-Bedingungsgruppen, Stop-/Abbruchpfad und manueller Freigabe.
- Alternative Starttrigger („eines dieser Ereignisse“); jeder eingehende Event startet separat und dedupliziert. Synchrones „auf ein zweites, späteres Ereignis warten“ ist kein implizites Verhalten; korrelierte Ereignis-Joins brauchen eigene explizite Knotenkonfiguration.
- Sichere Wiederholungen, Warteschlange, manuelle Wiederholung, Abbruch, Replay-Vorschau, Rezeptversionierung und Ausführungsberichte.
- Rezeptbibliothek der Captain Suite (Entwurf, nicht automatisch aktiviert), Export/Import mit Versionsprüfung, Schema-/Provider-Migration und Validierungsvorschau.
- Rollen-/Capability-Steuerung für Erstellen, Veröffentlichen, Retry und Einsicht in sensible Prozessdaten; Pro-Lizenzseite über den Add-on-Kernel.
- Lokale Zeitpläne, Zeitzonenwahl, Ausführung nach Website-Zeit und Anzeige des entsprechenden UTC-Werts.
- Keine Task-Credits. Pläne unterscheiden Site-Limit und Support wie in der bestehenden Suite. Konkrete Entitlements müssen vor Shop-Anlage bestätigt werden.

#### Nicht Teil von Version 1

- Beliebiger PHP-/SQL-/Shell-Code, freie Funktionsaufrufe, Dateisystemoperationen und Datenbankänderungen.
- Ungeprüfte externe HTTP-Aufrufe, beliebige ausgehende/inbound Webhooks oder Automationen über eine fremde Cloud.
- Endlosschleifen, freie Graphen mit unbeschränkten Zyklen, parallele unbegrenzte Fan-outs und dynamische Skripte.
- Automatische Auszahlung, Provisionsberechnung, Zahlungsrückerstattung, Ticket-Einlass-/Codeerzeugung oder irreversible Buchhaltungsschritte ohne autorisierten Fachplugin-Service und separate Freigaberegel.
- KI-generierte Rezepte/Aktionen und automatische Entscheidungen über Kunden anhand Review-Sterne oder sensibler Merkmale.
- Automatisierung beliebiger Drittanbieter-Plugins durch Erraten ihrer internen Hooks. Neue Plugins benötigen eigene, versionierte Provider.

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

**Trigger:** Bestellung erstellt, Zahlung abgeschlossen, Status geändert, Versand-/Erfüllungsstatus geändert, teilweise/vollständig erstattet, storniert; Bestellung von Gast oder Konto. Produkt-/Lagerereignisse können bei bestätigtem Bedarf als V1.1 ergänzt werden.  
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

### 15.10 Was für V1 noch fehlt

Die Produktspezifikation ist jetzt festgelegt, aber folgende Voraussetzungen fehlen noch, bevor die Entwicklung als vollständig definiert beginnen kann:

**Providerverträge (kritischer Pfad)**
- [ ] Für WP License Captain, WooCommerce, Pass, Voucher, Affiliate, Form und Review je ein konkretes versioniertes Event-/Action-Schema in deren Repository erstellen.
- [ ] Bestehende Hooklisten mit den geplanten Provider-Events abgleichen. In Pass Captain sind z. B. Ticket-Check-in-/Ausgabe-/Widerrufhooks dokumentiert, aber nicht alle sind versionierte Drittanbieter-Verträge.
- [ ] Für Voucher Captain und Review Captain die öffentlichen Schedule-/Cancel-/Einlösungs-Services spezifizieren; bestehende Produktkonzepte allein sind noch kein ausführbares API.
- [ ] Affiliate- und WooCommerce-Refund-Eigentümerschaft je Bestellpfad so festlegen, dass jede Rückbuchung genau einmal läuft.
- [ ] Form-Captain-Felder nach Einwilligung, PII-Klasse und Übertragungszweck kennzeichnen.

**Produktentscheidungen**
- [ ] V1-Entitlements und Site-/Supportpläne mit WP License Captain/Captain Pro bestätigen und anlegen.
- [ ] Sichtbare Produktbezeichnung „Workflow Captain“ und WordPress.org-Slug nach Verfügbarkeitsprüfung festlegen; Repository-Name wp-workflowmaster kann bleiben.
- [ ] Standards für Default-Retention, Rollen und externe E-Mails bestätigen.
- [ ] Ob technische Admins alternative Trigger und Freigaben selbst konfigurieren dürfen, in Rollenmodell abbilden.

**UX und Betrieb**
- [ ] UI-Entwürfe für Editor, If/Else, verzögerte Aktion, Retry/Replay, überfällige Queue und abgelaufene Pro-Lizenz erstellen.
- [ ] Seiteneffekte und Warntexte für Aktionen verbindlich festlegen.
- [ ] Support-Diagnosebericht ohne PII/Secrets, Installationshandbuch und Upgrade-/Rollbackablauf erstellen.
- [ ] System-Cron-Voraussetzungen und Queue-SLO für kleine/große Shops dokumentieren.

**Releaseprüfung**
- [ ] Kompatibilitätsmatrix mit konkreten Mindestversionen (WP, PHP, WooCommerce und Captain-Plugins) beschließen.
- [ ] End-to-End-Prüfplan und kompatible reale Testinstallation bereitstellen.
- [ ] Datenschutz-/Rechtsprüfung der Kundenkommunikation und Review-Anfragen für relevante Zielregionen durchführen.
- [ ] License-Captain-Produktkey, Add-on-Featureklassen, ZIP-Build, Signatur/Updater und Verlängerungspfad gemeinsam abnehmen.
- [ ] V1.0-Rezeptbibliothek kuratieren; jedes Rezept mit Quell-/Zielplugin, Voraussetzungen und Datenschutzbeschreibung liefern.

Diese Punkte sind keine Einschränkung auf ein Minimalprodukt. Sie sind die noch offenen Verträge, Entscheidungen und Releasearbeiten, die ein vollständiges Version-1-Produkt benötigt.
