# Löschen und Anonymisieren über Kern-Plugins und Workflow Captain

Stand: 16.11.2026. Aufgabe „Anonymisierung/Löschung über Source- und Workflow-Logs abstimmen, ohne Geschäftsledger zu löschen“ in [`../TODO.md`](../TODO.md). Technische Einordnung, keine Rechtsberatung (Spezifikation, Abschnitt 12).

## Grundsatz

Jedes Plugin löscht nur, was es selbst gespeichert hat. Workflow Captain löscht **nie** Daten eines Kern-Plugins: keine Bestellungen, Rechnungen, Gutscheine, Provisionen, Auszahlungen oder Lizenzen. Diese Geschäftsbücher unterliegen Aufbewahrungspflichten und bleiben in der Verantwortung des jeweiligen Kerns.

## Wer hält was

| Ort | Personenbezug | Löschung bei „Persönliche Daten löschen“ (WordPress) | Frist |
|---|---|---|---|
| Kern-Plugin (Pass, Voucher, Affiliate, Lizenzserver, WooCommerce) | Namen, Adressen, E-Mail, Zahlungsdaten im eigenen Geschäftsbuch | eigener Löscher des Kerns; Geschäftsbuch nach Aufbewahrungsregeln | Regeln des Kerns |
| Workflow Captain – Ereignisse | `actor_user_id`, Kennungen im Gegenstand | Löscher von Workflow Captain anonymisiert `actor_user_id` | erledigt 30 Tage, fehlgeschlagen 90 Tage (nur kürzer) |
| Workflow Captain – Läufe | Kopie des Umschlags mit `actor_user_id` | anonymisiert | 30 Tage nach Ende |
| Workflow Captain – Markierungen, Notizen | Objekt Benutzer (`subject_type = user`) oder Personenbezug, den der Betreiber hineinschreibt | Markierungen und Notizen am Benutzer werden mit exportiert und gelöscht (auch bei gelöschtem Benutzer); Freitext an anderen Objekten erkennt das Plugin nicht | bis zur Löschung, oder einstellbar 1–180 Tage (`retention_tags_notes`, SET-WC-34) |
| Workflow Captain – Abmeldung von Werbenachrichten | Benutzer-Option `workflow_captain_unsubscribed` (Zeitpunkt) | wird exportiert; die Löschung behält sie und meldet sie als behalten (sonst gingen wieder Werbenachrichten hinaus, R-9.7) – Rechtsprüfung | mit dem Benutzerkonto |
| Workflow Captain Pro – Ausgangswarteschlange (Webhooks) | Umschlag mit Feldfilter, ohne Personendaten | nicht nötig: keine Personendaten | gesendet 7 Tage, fehlgeschlagen 30 Tage |
| Workflow Captain Pro – Netzwerkläufe | Elternlauf mit minimalem Umschlag, ohne `actor_user_id` | Export und Löschung je Website | Netzwerk-Aufbewahrung |
| Empfänger außerhalb (n8n, Webhook-Ziele) | was der Betreiber weitergibt (nur freigegebene Felder) | in der Verantwortung des Empfängers | – |

## Abläufe

1. **Benutzerkonto gelöscht** (`deleted_user`): Workflow Captain anonymisiert `actor_user_id` in Ereignissen und Läufen. Der Kern entscheidet selbst über seine Daten.
2. **Partner in Affiliate Captain gelöscht** (`affiliate.affiliate.erased`): Workflow Captain leert Daten und Akteur aller gespeicherten Ereignisse zu diesem Partner (R-12.5). Provisionen und Auszahlungen im Kern bleiben.
3. **Löschanfrage über Werkzeuge → Persönliche Daten löschen**: WordPress ruft alle Löscher auf. Der von Workflow Captain meldet „anonymisiert“, nie „Geschäftsdaten gelöscht“.
4. **Workflow-Läufe einer gelöschten Bestellung**: Der Kern löscht oder anonymisiert die Bestellung nach seinen Regeln. Workflow Captain hält nur Kennungen; Läufe verfallen nach ihrer Frist.

## Was die Kerne liefern müssen

- Ereignisse ohne Namen, E-Mail, Adressen und Codes (Spezifikation Abschnitt 5) – erfüllt durch Positivlisten in den Adaptern.
- Lesefassaden ohne Personendaten – gebaut in Pass Captain, Voucher Captain und Lizenzserver (Branches `claude/plugin-todo-liste-2a83v4`).
- Kontakt und Einwilligung (F-13) bleiben offen; bis dahin speichert Workflow Captain keine Kontaktdaten.

## Offen

Rechtsprüfung der Fristen und Abläufe (F-04, „Datenschutz-/Rechtsprüfung“ in der TODO).
