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
* Grenzen für Parallelität, faire Verteilung, Notfallpause

**Betrieb**

* Worker über Action Scheduler, wenn vorhanden, sonst WP-Cron; WP-CLI-Befehle für System-Cron, Migration, Prüfung und Diagnose
* Prüfungen im Website-Zustand, Diagnose-Download ohne persönliche Daten
* eigene Rechte: `manage_workflow_captain`, `edit_workflows`, `activate_workflows`, `view_workflow_logs`, `retry_workflow_runs`

Die Adapter für Pass Captain, Voucher Captain, Affiliate Captain und den Lizenzserver kommen mit Workflow Captain Pro. Ohne Adapter speichert Workflow Captain Ereignisse anderer Plugins, die sich an die öffentliche Schnittstelle halten.

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

== Privacy ==

Gespeicherte Ereignisse und Läufe enthalten Kennungen, Status, Beträge und Zeitpunkte, keine Codes, E-Mail-Adressen, Namen oder Freitext. Die Benutzer-ID des Auslösers wird geleert, sobald kein Lauf mehr wartet. Workflow Captain meldet sich beim Export und Löschen persönlicher Daten von WordPress an. Erledigte Ereignisse und Läufe werden nach 30 Tagen gelöscht, fehlgeschlagene nach 90 Tagen; beides lässt sich verkürzen.

== External services ==

Keine. Workflow Captain ruft keinen externen Dienst auf.

== Changelog ==

= 0.2.0 =
* Workflow-Engine: Workflows, Versionen, Läufe, Schritte, Bedingungen, Wartezeiten, Aktionen der Version 1.
* Visueller Editor mit Testlauf, Bedingungsformular, Platzhaltern, Zoom und Hinweis auf die Mails der Kerne.
* Parallelitätsgrenzen, Fairness, Serialisierung, Heartbeat, Notfallpause.
* Einstellungen (Allgemein, Ausführung, Benachrichtigungen, Datenschutz, Rollen & Rechte, Status), Übersichtsseite, Website-Zustand.
* Export, Import, Duplizieren und Archivieren von Workflows; WP-CLI-Befehle; Action Scheduler, wenn vorhanden.

= 0.1.0 =
* Erster Stand des Consumers nach docs/SPEC-suite-events.md (Entwurf 1.0.0-draft.5).
