# Einstellungen für den Verkauf – Workflow Captain (Analyse vom 23.11.2026)

Quelle: Analyse `SETTINGS-workflow.md` der Lizenz-Sitzung vom 23.11.2026 (Stand `main` 47acd39), gegengeprüft mit sieben Berichtigungen derselben Sitzung. Die Aufgaben stehen als SET-WC-01 bis -27 und SET-WC-P1 bis -P6 in [`../TODO.md`](../TODO.md), Abschnitt „Einstellungen für den Verkauf“.

## Urteil

Technisch sauber, aber für fremde Shops und Länder kaum anpassbar: 9 Zahlenfelder, Empfänger und Arten der Hinweis-Mail, Rechte je Rolle, Notfallpause; die meisten Geschäftsregeln sind Konstanten. Lücken: keine Rechtsgrundlage/Einwilligung/Abmeldung bei Nachrichten an Kunden, keine Ruhezeit und Höchstzahl je Empfänger, Wiederholungen und Schleifentiefe fest, kein Register, kein Export/Import, keine Kommandozeile für Einstellungen, Deinstallation löscht immer, Quelltexte deutsch in Sie-Form.

## Entscheidungen der Inhaberin (23.11.2026: „mach was du empfiehlst“)

| Nr. | Frage | Entscheidung |
|---|---|---|
| E-S1 | Recht `workflow.delays` | **gestrichen**: Wartezeiten bleiben im freien Kern |
| E-S2 | Werbe-Nachricht ohne Einwilligung | **wird nicht gesendet**; Vorgabe und Texte bleiben unter Rechtsprüfung |
| E-S3 | Quellsprache des Kerns | **Englisch**; `de_DE` (du) und `de_DE_formal` (Sie) als Übersetzungen |
| E-S4 | Höchstbetrag Gutschein-Befehle in anderer Währung | schon entschieden als F-05a (100 auch in CHF, USD, GBP, sonst Betreiber-Eintrag) |
| E-S5 = F-15 | Wiederholungen und Schleifentiefe | **einstellbar in 1–8**, Standard 8 und 3 |
| E-S6 | Deinstallation | **Daten behalten** ist die Vorgabe; Löschen nur nach ausdrücklichem Einschalten |
| E-S7 = F-04 | Aufbewahrung länger als 30/90 Tage | **bis 180 Tage frei** im Kern, darüber `workflow.extended_logs` (Pro) |
| E-S8 | Pro-Hinweise, Ruhezeit | **graue Hinweiszeile** am Ende des Reiters mit Schalter „Pro-Hinweise zeigen“; Ruhezeit für die ganze Website frei, je Workflow und Kalender Pro (`workflow.advanced_logic`); neues Recht `workflow.branding` vormerken (Lizenz-Sitzung) |

## Grenzen, die beim Umsetzen gelten

- Hinweistexte im Kern nennen keine Rechteschlüssel (`suite_integrations`, `custom_connectors`, `multisite_network`, `event_correlation`, `advanced_logic`, `recipe_templates`, `extended_logs`, kein `*-captain-pro`-Slug, kein „entitlement“): `tests/license-guard.php`. „Workflow Captain Pro“ als Klartext ist erlaubt.
- Keine öffentliche Ausgabe und keine REST-Routen ohne ausdrückliche Entscheidung (`docs/OPTIONS.md`, `tests/security-tests.php`) – betrifft die Abmeldung (SET-WC-04).
- Veröffentlichte Versionen sind unveränderliche Schnappschüsse (WF-SET-14): neue Felder an Aktionen gelten für bestehende Workflows mit einer Vorgabe, die das heutige Verhalten nicht ändert.
- Rechtliche Werte werden nicht erfunden: Felder ohne Vorgabetext, Länderwerte „ungeprüft“.
- Beide heutigen Optionen (`workflow_captain_settings`, `workflow_captain_notify`) mit ihren Einstellungsgruppen bleiben erhalten.

## Free / Pro

| Einstellung | Free | Pro | Recht |
|---|---|---|---|
| Zahlenfelder, Hinweis-Mail, Rechte je Rolle, Notfallpause, Freigabe von Connectoren | ✔ | | – |
| Wartezeiten und Zeitpläne | ✔ | | – (E-S1) |
| Art der Nachricht, Einwilligungsprüfung, Abmeldung und Sperrliste | ✔ | | – |
| Ruhezeit und Sendefenster für die Website, Höchstzahl je Empfänger und Tag | ✔ | | – |
| Absender, Antwortadresse, Fußzeile | ✔ | | – |
| Wiederholungen und Schleifentiefe (1–8) | ✔ | | – |
| Aufbewahrung bis 180 Tage | ✔ | | – (E-S7) |
| Deinstallation, Export/Import, Kommandozeile, Zurücksetzen, Protokollstufe, Testmodus, Länderprofile | ✔ | | – |
| Verbindungen (Webhook, n8n), Ausgang: Aufbewahrung und Wiederholungen | | ✔ | `workflow.webhooks` |
| Suite-Adapter, Gutschein-Befehle und ihre Grenzen | | ✔ | `workflow.suite_integrations` |
| Laufzeit eigener Connectoren | | ✔ | `workflow.custom_connectors` |
| Netzwerk-Standards und -Sperren | | ✔ | `workflow.multisite_network` |
| Aufbewahrung über 180 Tage, Protokoll-Export, längeres Änderungsprotokoll | | ✔ | `workflow.extended_logs` |
| Freigabeschritte; Sendefenster je Workflow und Feiertage; Höchstzahl je Workflow | | ✔ | `workflow.advanced_logic` |
| Zusätzliche Rezepte | | ✔ | `workflow.recipe_templates` |
| Ereignisse verknüpfen | | ✔ | `workflow.event_correlation` |
| Eigenes Design für Nachrichten | | ✔ | `workflow.branding` (neu, vorgemerkt) |

## Hinweistexte (englische Quelle, deutsch du)

| Bereich | Englisch | Deutsch |
|---|---|---|
| Verbindungen | Connect webhooks and n8n. Available with Workflow Captain Pro. | Verbinde Webhooks und n8n. Verfügbar mit Workflow Captain Pro. |
| Ausgang | Retention and retries for outgoing calls. Available with Workflow Captain Pro. | Aufbewahrung und Wiederholungen für ausgehende Aufrufe. Verfügbar mit Workflow Captain Pro. |
| Captain-Plugins | Events and actions of the other Captain plugins. Available with Workflow Captain Pro. | Ereignisse und Aktionen der anderen Captain-Plugins. Verfügbar mit Workflow Captain Pro. |
| Gutscheine | Limits for voucher commands at a glance. Available with Workflow Captain Pro. | Grenzen der Gutschein-Befehle auf einen Blick. Verfügbar mit Workflow Captain Pro. |
| Connectoren | Run your own connectors. Available with Workflow Captain Pro. | Betreibe eigene Connectoren. Verfügbar mit Workflow Captain Pro. |
| Netzwerk | Defaults and workflows for the whole network. Available with Workflow Captain Pro. | Standards und Abläufe für das ganze Netzwerk. Verfügbar mit Workflow Captain Pro. |
| Protokolle | Keep logs longer and export them. Available with Workflow Captain Pro. | Protokolle länger aufbewahren und exportieren. Verfügbar mit Workflow Captain Pro. |
| Freigaben | Approval steps with approver and deadline. Available with Workflow Captain Pro. | Freigabeschritte mit Genehmiger und Frist. Verfügbar mit Workflow Captain Pro. |
| Sendefenster | Sending windows per workflow and a holiday calendar. Available with Workflow Captain Pro. | Sendefenster je Workflow und Feiertagskalender. Verfügbar mit Workflow Captain Pro. |
| Grenzen | Limits per recipient for each workflow. Available with Workflow Captain Pro. | Grenzen je Empfänger für jeden Workflow. Verfügbar mit Workflow Captain Pro. |
| Rezepte | More ready-made recipes. Available with Workflow Captain Pro. | Mehr fertige Rezepte. Verfügbar mit Workflow Captain Pro. |
| Verknüpfen | Combine several events into one trigger. Available with Workflow Captain Pro. | Mehrere Ereignisse zu einem Auslöser verbinden. Verfügbar mit Workflow Captain Pro. |
| Design | Your own design for messages. Available with Workflow Captain Pro. | Dein eigenes Design für Nachrichten. Verfügbar mit Workflow Captain Pro. |
