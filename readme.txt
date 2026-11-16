=== Workflow Captain ===
Contributors: captainsuite
Tags: automation, workflow, woocommerce, events
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatisierungsbaukasten der Captain Suite: Ereignisse speichern, Workflows im visuellen Editor bauen und zuverlässig ausführen.

== Description ==

Workflow Captain verbindet Ereignisse aus den Plugins der Captain Suite mit Abläufen, die der Betreiber selbst baut.

**Ereignisse**

* speichert jedes Ereignis einmal je Website und erkennt Doppelte
* wiederholt bei vorübergehenden Fehlern nach einem festen Plan; fehlgeschlagene Ereignisse lassen sich im Admin gezielt erneut einreihen
* verhindert Schleifen zwischen Abläufen (Kausalkette, Tiefe höchstens 3)
* bietet die Schnittstelle, an der sich Adapter aus Add-ons anmelden

**Workflows**

* visueller Editor mit Auslöser, Bedingungen (Dann/Sonst), Wartezeiten, Aktionen und Ende; per Tastatur bedienbar, mit Listenansicht
* Testlauf mit Beispieldaten ohne Seiteneffekte
* Entwurf und Veröffentlichen: jede veröffentlichte Version bleibt für laufende Läufe unverändert
* Aktionen: Nachricht an Betreiber, Nachricht an Benutzer, Markierung setzen und entfernen, Notiz anlegen
* Läufe mit Schritten, Abbrechen und Fortsetzen nach einer Entscheidung
* Export und Import einzelner Workflows als JSON, Duplizieren, Archivieren
* Grenzen für Parallelität, faire Verteilung, Notfallpause; auf Wunsch laufen Läufe je Objekt oder je Benutzer nacheinander

**Auslöser, die eingebaut sind**

* WordPress: Benutzer registriert, Beitrag veröffentlicht (nur öffentliche Inhaltstypen). Übergeben werden Kennungen und Status, nie Name, E-Mail-Adresse, Login, Titel oder Inhalt.
* WooCommerce (ab Version 8.2, HPOS und klassische Bestelltabellen): Bestellung angelegt, bezahlt, Statuswechsel, erstattet, storniert. Übergeben werden Status, Beträge in kleinster Einheit, Währung und Kennungen, nie Adressen, E-Mail, Namen, IP-Adresse, Kundennotiz oder Gutscheincodes.
* Zeitplan: täglich, wöchentlich oder monatlich zu einer Uhrzeit in der Zeitzone der Website. Ein verpasster Termin wird höchstens einmal nachgeholt.
* Eingehende Auslöser: Ein Add-on kann Auslöser mit festen, typisierten Feldern anmelden. Workflow Captain prüft jeden Aufruf noch einmal und speichert ihn wie jedes andere Ereignis. Die Annahme von außen (signierte Webhooks, n8n) gehört nicht zum kostenlosen Plugin.

**Captain Suite und eigene Connectoren (mit Workflow Captain Pro)**

* Pass Captain, Voucher Captain, Affiliate Captain und der Lizenzserver des Anbieters liefern ihre Ereignisse über Adapter, die mit dem Add-on Workflow Captain Pro kommen. Für ein Captain-Produkt braucht es außerdem dessen eigenes Pro-Add-on. Welche Versionen zusammenpassen, steht in der Kompatibilitätsmatrix (siehe FAQ).
* Eigene Connectoren sind eigene Plugins, die sich nach einem öffentlichen Vertrag anmelden. Sie laufen, wenn Workflow Captain Pro aktiv ist, und müssen unter „Eigene Connectoren“ freigegeben werden. Ihre eigene Lizenz prüft jeder Connector selbst.
* Ohne Adapter bleiben neue Ereignisse eines Anbieters pausiert aufbewahrt. Nichts wird gelöscht und nichts von selbst nachgeholt.

**Betrieb**

* Worker über Action Scheduler, wenn vorhanden, sonst WP-Cron; WP-CLI-Befehle für System-Cron, Migration, Prüfung und Diagnose
* Prüfungen im Website-Zustand, Diagnose-Download ohne persönliche Daten
* eigene Rechte: `manage_workflow_captain`, `edit_workflows`, `activate_workflows`, `view_workflow_logs`, `retry_workflow_runs`, `manage_workflow_integrations`
* Protokoll der Eingriffe: Rechte, Notfallpause, Einstellungen, erneut eingereihte Ereignisse, abgebrochene Läufe, veröffentlichte, pausierte und archivierte Workflows
* Datenbank-Aktualisierung mit Versionsstand: eine abgebrochene Aktualisierung wird sicher wiederholt, nach einem Downgrade wird nichts geändert

Workflow Captain gibt auf öffentlichen Seiten nichts aus und registriert keine REST-Routen, Shortcodes oder Sitemaps.

== Installation ==

1. Plugin hochladen und aktivieren. Die Tabellen entstehen bei der Aktivierung (bei Multisite je Website).
2. Unter **Workflow Captain → Einstellungen** Grenzen, Aufbewahrung und Rechte prüfen.
3. Wenn WP-Cron abgeschaltet ist: `wp workflow-captain process` jede Minute per System-Cron aufrufen.

== Frequently Asked Questions ==

= Ersetzt ein Workflow die Mails meiner Plugins? =

Nein. Jeder Kern behält seine Mails. Ein Workflow sendet nur zusätzliche Nachrichten. Der Editor zeigt, welche Mail des Kerns zum selben Anlass ohnehin hinausgeht.

= Wird eine Nachricht doppelt verschickt, wenn der Worker abbricht? =

Nein. Ist unklar, ob eine Nachricht schon hinausging, hält der Lauf an und ein Mensch entscheidet.

= Was passiert, wenn ich das Plugin auf eine ältere Version zurücksetze? =

Ist das Datenbankschema neuer als das Plugin, verarbeitet das Plugin nichts und ändert nichts, bis die neuere Version wieder installiert ist.

= Was passiert, wenn ein Update abbricht? =

Die Aktualisierung der Tabellen merkt sich ihren Stand. Bricht sie ab, wartet der Worker, und nach zehn Minuten wiederholt der nächste Aufruf im Admin, per Cron oder `wp workflow-captain migrate` die Aktualisierung ab dem letzten fertigen Schritt. Es wird nichts gelöscht.

= Laufen Workflows in der Reihenfolge der Ereignisse? =

Nicht garantiert. Mehrere Läufe können gleichzeitig laufen. Wer eine Reihenfolge braucht, stellt am Auslöser „Läufe nacheinander“ ein: Dann wartet je Objekt (zum Beispiel je Bestellung) oder je Benutzer ein neuer Lauf, bis der ältere fertig ist.

= Welche Versionen der Captain-Plugins passen? =

Die Kompatibilitätsmatrix nennt je Plugin die Version und die Vertragsversion, ab der Ereignisse ankommen: https://github.com/hastla007/wp-workflowmaster/blob/main/docs/KOMPATIBILITAET.md. Ein Adapter meldet sich nur bei einem passenden Vertrag an; sonst zeigt die Seite **Workflow Captain → Provider**, warum nicht.

= Was schicke ich dem Support? =

Unter **Workflow Captain → Ereignisse** die Schaltfläche „Diagnose herunterladen“ oder `wp workflow-captain diagnostics`. Die Datei enthält Versionen, Zustand von Schema und Worker, angemeldete Adapter und Zahlen je Zustand, aber keine persönlichen Daten, keine Inhalte von Ereignissen und keine Geheimnisse. Die gleichen Prüfungen zeigt **Werkzeuge → Website-Zustand**.

== Privacy ==

Gespeicherte Ereignisse und Läufe enthalten Kennungen, Status, Beträge und Zeitpunkte, keine Codes, E-Mail-Adressen, Namen oder Freitext. Die Benutzer-ID des Auslösers wird geleert, sobald kein Lauf mehr wartet. Workflow Captain meldet sich beim Export und Löschen persönlicher Daten von WordPress an. Erledigte Ereignisse und Läufe werden nach 30 Tagen gelöscht, fehlgeschlagene nach 90 Tagen; beides lässt sich verkürzen.

So fließen die Daten:

1. Ein Plugin (WordPress, WooCommerce, ein Captain-Plugin über seinen Adapter, ein eigener Connector) meldet ein Ereignis. Workflow Captain prüft es und lehnt Ereignisse mit verbotenen Feldern (Codes, E-Mail, Namen, Adressen, Freitext) ab.
2. Das Ereignis wird in einer Tabelle der eigenen Website gespeichert, mit der Benutzer-ID des Auslösers, falls es einen gibt.
3. Ein Lauf kopiert das Ereignis. Schritte speichern nur ihren Zustand und ein gekürztes Ergebnis.
4. Eine Nachricht an Benutzer löst die E-Mail-Adresse erst beim Versand auf und speichert sie nicht.
5. Markierungen und Notizen enthalten nur den Text, den der Betreiber im Workflow festlegt.
6. Außer den E-Mails, die ein Workflow über WordPress verschickt, verlässt nichts die Website. Daten an externe Dienste (Webhooks, n8n) sendet nur ein Add-on und nur nach Einrichtung durch den Betreiber.

== External services ==

Keine. Workflow Captain ruft keinen externen Dienst auf.

== Screenshots ==

1. Visueller Editor: Auslöser, Wenn/Sonst mit Dann- und Sonst-Pfad, Aktionen, Wartezeit und Ende; rechts das Formular der Bedingung.
2. Zeitplan als Auslöser: wöchentlich zu einer Uhrzeit in der Zeitzone der Website.
3. Übersicht: aktive Workflows, laufende Läufe, Entscheidungen, Fehler, Zustand des Workers und Notfallpause.
4. Ereignisse mit Filtern je Zustand und Diagnose-Download ohne persönliche Daten.
5. Provider: angemeldete Adapter mit Versionen und Ereignistypen.
6. Vorlagen, die als Entwurf übernommen werden.
7. Einstellungen „Ausführung“: Grenzen für Parallelität, Batch und Laufzeit.
8. Einstellungen „Status“: Schema, Worker, Schlüssel und Protokoll der Eingriffe.

== Changelog ==

= 0.2.0 =
* Workflow-Engine: Workflows, Versionen, Läufe, Schritte, Bedingungen, Wartezeiten, Aktionen der Version 1.
* Visueller Editor mit Testlauf, Bedingungsformular, Platzhaltern, Zoom und Hinweis auf die Mails der Kerne.
* Parallelitätsgrenzen, Fairness, Serialisierung, Heartbeat, Notfallpause.
* Einstellungen (Allgemein, Ausführung, Benachrichtigungen, Datenschutz, Rollen & Rechte, Status), Übersichtsseite, Website-Zustand.
* Export, Import, Duplizieren und Archivieren von Workflows; WP-CLI-Befehle; Action Scheduler, wenn vorhanden.
* Auslöser für WordPress, WooCommerce, Zeitplan und eingehende Auslöser von Add-ons; Anmeldung eigener Connectoren.
* Datenbank-Aktualisierung mit Sperre und sicherer Wiederholung; Protokoll der Eingriffe; Einstellung „Läufe nacheinander“ im Editor.

= 0.1.0 =
* Erster Stand des Consumers nach docs/SPEC-suite-events.md (Entwurf 1.0.0-draft.5).
