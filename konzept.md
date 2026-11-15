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
- Optionale Integrationen zu externen Diensten erst nach gesonderter Priorisierung; externe App-Plattformen/Cloud-Runs sind kein MVP-Ziel.

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

1. **Bestellung → Gutschein → Review-Anfrage:** Ereignis Bestellung bezahlt; Filter auf Gutscheinprodukt und gültigen Kontakt-/Kommunikationsstatus; Gutschein-Ausstellung durch Voucher Captain; nach 14 Tagen Review-Captain-Anfrage, wenn Auftrag nicht erstattet und keine jüngere Anfrage gesendet wurde.
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

### Phase 1 – Kostenloser MVP

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
- Keine KI-Erstellung oder Agent-Ausführung im MVP; falls später gewünscht, nur Vorschlagsmodus mit menschlicher Prüfung und separaten Berechtigungen.

## 12. Detaillierte To-do-Liste

### Produktentscheidungen

- [ ] Free-Limit und Pro-Limit definieren: Anzahl Workflows, Run-Volumen und Sites; keine künstliche Sperre für bereits gespeicherte Workflows.
- [ ] Entscheidung: einzelne Captain-Basis-Trigger im Free oder sämtliche Suite-Adapter Pro.
- [ ] Verbindliche Mindestversionen der Captain-Plugins und WooCommerce festlegen.
- [ ] Welche Aktionen dürfen automatisiert schreiben? Risikoabstufung und Admin-Schutz definieren.
- [ ] Festlegen, ob Nutzeraktionen (Login, Registrieren) im MVP enthalten sind.
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
6. **Marktumfang:** Tausende Integrationen sind für ein Suite-Produkt keine realistische MVP-Zusage. Erweiterung folgt Nachfrage und stabilen Verträgen.

## 14. Quellen zur Wettbewerbsbeobachtung

- Uncanny Automator: [WordPress.org Pluginseite](https://wordpress.org/plugins/uncanny-automator/), [Features/Automationen](https://automatorplugin.com/automations/), [FAQ](https://automatorplugin.com/faq/)
- AutomatorWP: [WordPress.org Pluginseite](https://wordpress.org/plugins/automatorwp/), [Produktseite](https://automatorwp.com/)
- WP Webhooks: [WordPress.org Pluginseite](https://wordpress.org/plugins/wp-webhooks/), [Features](https://wp-webhooks.com/features/)
- SureTriggers / OttoKit: [WordPress.org Pluginseite](https://wordpress.org/plugins/suretriggers/)
- Recherche-Stichtag: 15.11.2026. Wettbewerbsaussagen sind qualitative Beobachtungen aus Anbieter-/Pluginseiten; sie sind keine unabhängige Tarif- oder Benchmarkprüfung.
