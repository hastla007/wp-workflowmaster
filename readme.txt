=== Workflow Captain ===
Contributors: captainsuite
Tags: automation, workflow, woocommerce, events
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatisierungsbaukasten der Captain Suite. Diese Vorabversion enthält den Ereignis-Abnehmer.

== Description ==

Workflow Captain verbindet Ereignisse aus WordPress, WooCommerce und den Plugins der Captain Suite mit Abläufen.

Diese Vorabversion 0.1.0 enthält nur den Abnehmer der Ereignisse (Consumer):

* speichert jedes Ereignis einmal je Website, erkennt Doppelte
* wiederholt bei vorübergehenden Fehlern nach einem festen Plan und zeigt fehlgeschlagene Ereignisse im Admin
* verhindert Schleifen zwischen Abläufen
* bietet die Schnittstelle, an der sich Adapter aus Add-ons anmelden
* meldet sich beim Export und Löschen persönlicher Daten von WordPress an und löscht gespeicherte Ereignisse nach einer Frist

Abläufe, Editor und Adapter folgen in späteren Versionen. Ereignisse enthalten nie Gutschein-, Ticket- oder Lizenzcodes, E-Mail-Adressen, Namen oder Freitext.

== External services ==

Keine. Workflow Captain ruft in dieser Version keinen externen Dienst auf.

== Changelog ==

= 0.1.0 =
* Erster Stand des Consumers nach docs/SPEC-suite-events.md (Entwurf 1.0.0-draft.5).
