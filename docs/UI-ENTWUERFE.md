# UI-Entwürfe: Abgleich mit der gebauten Oberfläche

Stand: 16.11.2026, Plugin 0.2.x. Aufgabe in [`../TODO.md`](../TODO.md): „UI-Entwürfe der Tabellen, Workflow-Canvas/Schrittfolge, Branching, Zeitplanung, Fehler-Replay und Lizenzablauf umsetzen“.

Grundlage: die Konzeptentwürfe in [`assets/workflow-captain/`](assets/workflow-captain/) (`dashboard.svg`, `workflow-builder.svg`, `run-details.svg`, `settings.svg`, `integrations.svg`) und die gebaute Oberfläche auf der Testinstallation (Bildschirmfotos 1280 × 800 in [`../.wordpress-org/`](../.wordpress-org/), aufgenommen am 16.11.2026 ohne Änderung an der Website; die Entwicklungshinweise des WooCommerce-Testbuilds sind ausgeblendet). Je Bereich: was gebaut ist, was vom Entwurf fehlt, und für das Fehlende ein Entwurf in ASCII. Die Entwürfe sind Vorschläge; gebaut wird erst nach Freigabe.

Legende: **gebaut** = im Code und im Bildschirmfoto belegt; **fehlt** = im Konzeptentwurf, aber nicht gebaut.

## 1. Tabellen

**Gebaut**

| Seite | Spalten | Filter / Aktionen | Bild |
|---|---|---|---|
| Übersicht | Kacheln: aktive Workflows, laufende Läufe, Entscheidungen, fehlgeschlagene Ereignisse, hängende Schritte, Worker; Liste „Brauchen eine Entscheidung“ (höchstens 10) | Notfallpause mit Bestätigung | `screenshot-3.png` |
| Ereignisse | Nr., Ereignis, Objekt, Zeitpunkt (UTC), Zustand, Versuche, letzter Fehler | Filter je Zustand mit Zahl, Seiten „Ältere“, Auswahl und „Ausgewählte erneut einreihen“, Diagnose-Download | `screenshot-4.png` |
| Workflows | Name, Zustand, Auslöser, Version | Neu, Export, Import als Entwurf | – |
| Läufe | Nr., Workflow, Ereignis, Zustand, begonnen (UTC), Netzwerk | Filter je Zustand | – |
| Provider | Produkt, Adapter, Plugin/Vertrag, Ereignistypen, gespeicherte Ereignisse, aktive Workflows | – | `screenshot-5.png` |
| Vorlagen | Vorlage, Auslöser, Provider, Verfügbarkeit | „Als Entwurf übernehmen“ | `screenshot-6.png` |

**Fehlt**

- Seitenweise Anzeige für **Workflows** und **Läufe**: beide zeigen höchstens 100 Zeilen, ohne Weiterblättern (konzept.md 10: „Admin-Listen paginiert“). Die Ereignisse haben es.
- Spalte „Letzter Lauf“ und Zahl der Fehler je Workflow (Entwurf `dashboard.svg`, „Zuletzt bearbeitete Workflows“).
- Filter der Läufe nach Workflow und Zeitraum.
- Kachel „Läufe · 30 Tage“ und Providerstatus auf der Übersicht (Entwurf `dashboard.svg`); heute steht der Providerstatus nur auf der Seite „Provider“.

**Entwurf: Workflows-Liste**

```
Workflows                                                   [ Neu ]
Alle (14) | Aktiv (9) | Pausiert (2) | Entwürfe (3) | Archiviert (1)
+--------------------------------+---------+-------------------------+---------+-----------------+--------+
| Name                           | Zustand | Auslöser                | Version | Letzter Lauf    | Fehler |
+--------------------------------+---------+-------------------------+---------+-----------------+--------+
| Große Bestellungen markieren   | Aktiv   | woocommerce.order.paid  | 3       | heute 10:42 ✓   | 0      |
| Wochenbericht montags          | Aktiv   | Zeitplan: Mo 07:30      | 1       | 16.11. 07:30 ✓  | 0      |
| Einlass: Hinweis               | Pausiert| pass.ticket.checked_in  | 2       | gestern 18:03 ! | 2      |
+--------------------------------+---------+-------------------------+---------+-----------------+--------+
                                                    ‹ Neuere   Seite 1 von 2   Ältere ›
```

„Fehler“ zählt Läufe der letzten 30 Tage in `failed` oder `action_required` und verlinkt auf die gefilterten Läufe.

## 2. Workflow-Canvas und Schrittfolge

**Gebaut** (`screenshot-1.png`): Elementleiste (Auslöser, Wenn/Sonst, Aktion, Warten, Ende), Canvas mit verschiebbaren Knoten und gerichteten Pfeilen, Zoom, Rückgängig/Wiederholen, „Anordnen“, Einstellungsbereich rechts, Tastaturbedienung (Pfeile, C verbindet, Entf löscht), „Schritte der Reihe nach“ als Liste für kleine Bildschirme und Screenreader, darunter das JSON. Testlauf mit Beispieldaten und „Prüfen und veröffentlichen“ unter dem Editor.

**Fehlt gegenüber `workflow-builder.svg`**

- Herkunft am Knoten („WOOCOMMERCE · AUSLÖSER“, „VOUCHER CAPTAIN · AKTION“) und eine lesbare Kurzbeschreibung statt der Knoten-ID.
- Kopfzeile mit Zustand und Version („Entwurf · Version 4“) und den Schaltflächen Testlauf/Veröffentlichen oben; heute stehen sie unter dem JSON.
- Ziehen aus der Elementleiste auf den Canvas; heute fügt ein Klick den Knoten hinzu.

**Entwurf: Kopfzeile und Knoten**

```
Große Bestellungen markieren          Entwurf · Version 3 veröffentlicht    [Testlauf] [Prüfen und veröffentlichen]
[+ Auslöser] [+ Wenn/Sonst] [+ Aktion] [+ Warten] [+ Ende]   [Rückgängig] [Wiederholen] [Anordnen]  [−] 100 % [+]

  ╭──────────────────────────────╮
  │ WOOCOMMERCE · AUSLÖSER        │
  │ Bestellung bezahlt            │
  │ je Objekt nacheinander        │   ← nur wenn „Läufe nacheinander“ gesetzt
  ╰──────────────┬───────────────╯
                 ▼
  ┌──────────────────────────────┐
  │ WENN / SONST                  │
  │ Gesamtbetrag ≥ 100,00         │
  └──────┬────────────────┬──────┘
    Dann │                │ Sonst
         ▼                ▼
```

## 3. Branching (Wenn/Sonst)

**Gebaut** (`screenshot-1.png`): ein Eingang, zwei beschriftete Ausgänge „Dann“ (durchgezogen) und „Sonst“ (gestrichelt), Formular mit Verknüpfung UND/ODER, Feld aus dem Katalog mit Datentyp, Operator, Wert, „+ Regel“, „Wenn ein Feld fehlt“ (nicht erfüllt / Lauf beenden / Fehler). Ein offener Ausgang blockiert das Veröffentlichen. Verschachtelte Gruppen gehen nur über das JSON-Feld.

**Fehlt**: Auswahl von Provider und Feld als Liste mit Beispielwert (Entwurf: „Provider ⌄ / Feld ⌄ / Operator ⌄ / Wert“); heute ist das Feld ein Textfeld mit Vorschlagsliste. Hinweis am Knoten, wenn ein Zweig unvollständig ist (heute nur in der Prüfung beim Veröffentlichen).

**Entwurf: Feldauswahl**

```
BEDINGUNG EINSTELLEN
Verknüpfung   [alle Regeln (UND) ⌄]
Feld          [Gesamtbetrag (kleinste Einheit) · integer ⌄]   Beispiel: 12990
Operator      [≥ ⌄]
Wert          [10000      ]   = 100,00 EUR
[+ Regel]  [+ Gruppe]
Wenn ein Feld fehlt  [gilt als nicht erfüllt ⌄]
⚠ Der Sonst-Ausgang ist nicht verbunden. Verbinden oder mit „Ende“ abschließen.
```

## 4. Zeitplanung

**Gebaut** (`screenshot-2.png`): Auslöser `workflow.schedule.due` mit Wiederholung (täglich, wöchentlich, monatlich), Uhrzeit, Wochentag oder Tag im Monat (1 bis 28), Zeitzone der Website, Hinweis auf Nachholen (höchstens einmal) und Sommerzeitlücke. Nachholgrenze in Einstellungen → Ausführung.

**Fehlt**: Vorschau der nächsten Termine im Editor und eine Übersicht aller Zeitpläne (heute nur über die Spalte „Auslöser“).

**Entwurf**

```
Zeitplan (Zeitzone der Website: Europe/Berlin)
Wiederholung [wöchentlich ⌄]  Uhrzeit [07:30]  Wochentag [Montag ⌄]
Nächste Termine: Mo 23.11.2026 07:30 · Mo 30.11.2026 07:30 · Mo 07.12.2026 07:30
Zuletzt ausgelöst: Mo 16.11.2026 07:30 (Lauf 41)
```

## 5. Fehler-Replay

**Gebaut**

- Ereignisse: Filter „Fehlgeschlagen (dead_letter)“ und „Pausiert“, einzeln oder gesammelt erneut einreihen (Recht `retry_workflow_runs`, Nonce, Protokolleintrag), `wp workflow-captain requeue` und `reconcile [--requeue]` (reiht nur ein, was beim Provider noch stimmt).
- Läufe: Schritt „Fortsetzen“ bei „Aktion erforderlich“ und „Pausiert“, mit Häkchen „Erneut senden?“ bei unklarem Ausgang einer Nachricht; „Lauf abbrechen“ mit Hinweis, dass Erledigtes nicht rückgängig gemacht wird.
- Der Testlauf markiert den Knoten, an dem er scheitert, im Canvas.

**Fehlt gegenüber `run-details.svg`**: Korrelations-ID und Idempotenzschlüssel im Lauf, Zeitleiste mit Uhrzeit je Schritt, Ansicht der (redigierten) Ereignisdaten, „Lauf exportieren“, Sprung vom Lauf zum Knoten im Editor. Die Daten liegen vor (Umschlag im Lauf, `correlation_id` im Umschlag); es fehlt nur die Anzeige.

**Entwurf: Lauf**

```
← Alle Läufe
Lauf 16: Aktion erforderlich                         [Lauf exportieren (JSON, redigiert)]
Große Bestellungen markieren · Version 3 · woocommerce.order.paid · Bestellung 30
Korrelation 72f3…91c0   Ereignis 9b1e…  (Ereignisse → Nr. 118)

 10:42:03  ✓ Auslöser               Ereignis gespeichert
 10:42:03  ✓ Wenn/Sonst             Dann (Gesamtbetrag 12990 ≥ 10000)
 10:42:04  ✓ Markierung setzen      grosse-bestellung
 10:42:04  ! Nachricht an Betreiber unclear_outcome – vielleicht schon gesendet
           [ ] Erneut senden?   [Fortsetzen]   [Im Editor zeigen]

▸ Ereignisdaten (redigiert)
  { "status": "processing", "total_minor": 12990, "currency": "EUR" }
```

## 6. Lizenzablauf

Der kostenlose Kern kennt keine Lizenz (Wächtertest `tests/license-guard.php`). Was der Betreiber bei einer abgelaufenen Lizenz sieht, zeigt Workflow Captain Pro auf seiner Lizenzseite (siehe `captain-pro/docs/WORKFLOW-PRO.md`). Im Kern wird es nur mittelbar sichtbar: Der Adapter meldet sich ab, die Seite „Provider“ zeigt ihn nicht mehr als angemeldet, neue Ereignisse liegen als „Pausiert“ (`adapter_unregistered`) in „Ereignisse“, und Workflows mit diesem Auslöser zeigen „Provider nicht verfügbar“. Nach der Verlängerung reiht der Betreiber gezielt wieder ein (Abschnitt 5).

## Offene Punkte für die Umsetzung

1. Seitenweise Anzeige von Workflows und Läufen (Abschnitt 1) – Fehler gegen konzept.md 10, klein.
2. Laufansicht mit Korrelation, Zeitleiste und redigierten Daten (Abschnitt 5) – größter Nutzen für den Support.
3. Herkunft und Kurzbeschreibung am Knoten, Kopfzeile des Editors (Abschnitt 2).
4. Terminvorschau im Zeitplan (Abschnitt 4).
5. Feldauswahl als Liste mit Beispiel (Abschnitt 3).
