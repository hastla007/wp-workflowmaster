# UI-Entwürfe: Abgleich mit der gebauten Oberfläche

Stand: 16.11.2026, Plugin 0.2.x. Aufgabe in [`../TODO.md`](../TODO.md): „UI-Entwürfe der Tabellen, Workflow-Canvas/Schrittfolge, Branching, Zeitplanung, Fehler-Replay und Lizenzablauf umsetzen“.

Grundlage: die Konzeptentwürfe in [`assets/workflow-captain/`](assets/workflow-captain/) (`dashboard.svg`, `workflow-builder.svg`, `run-details.svg`, `settings.svg`, `integrations.svg`) und die gebaute Oberfläche auf der Testinstallation (Bildschirmfotos 1280 × 800 in [`../.wordpress-org/`](../.wordpress-org/), aufgenommen am 16.11.2026 ohne Änderung an der Website; die Entwicklungshinweise des WooCommerce-Testbuilds sind ausgeblendet). Je Bereich: was gebaut ist, was vom Entwurf fehlt, und für das Fehlende ein Entwurf in ASCII. Die Entwürfe sind Vorschläge; gebaut wird erst nach Freigabe.

Legende: **gebaut** = im Code und im Bildschirmfoto belegt; **fehlt** = im Konzeptentwurf, aber nicht gebaut.

## 1. Tabellen

**Gebaut**

| Seite | Spalten | Filter / Aktionen | Bild |
|---|---|---|---|
| Übersicht | Kacheln: aktive Workflows, laufende Läufe, Entscheidungen, fehlgeschlagene Ereignisse, hängende Schritte, Worker; Liste „Brauchen eine Entscheidung“ (höchstens 10) | Notfallpause mit Bestätigung | `screenshot-3.png` |
| Ereignisse | Nr., Ereignis, Objekt, Zeitpunkt (UTC), Zustand, Versuche, letzter Fehler | Filter je Zustand mit Zahl, Filter nach Korrelation, seitenweise (50 je Seite, Gesamtzahl), Auswahl und „Ausgewählte erneut einreihen“, Diagnose-Download | `screenshot-4.png` (vor der Seitennavigation) |
| Workflows | Name, Zustand, Auslöser, Version, Letzter Lauf (UTC, mit Zustand und Link), Fehler (30 Tage) | Neu, Export, Import als Entwurf, seitenweise (50 je Seite, Gesamtzahl) | – |
| Läufe | Nr., Workflow, Ereignis, Zustand, Grund, begonnen (UTC) | Filter je Zustand mit Zahl, nach Workflow und nach Korrelation, seitenweise (50 je Seite, Gesamtzahl) | – |
| Provider | Produkt, Adapter, Plugin/Vertrag, Ereignistypen, gespeicherte Ereignisse, aktive Workflows | – | `screenshot-5.png` |
| Vorlagen | Vorlage, Auslöser, Provider, Verfügbarkeit | „Als Entwurf übernehmen“ | `screenshot-6.png` |

Seitenweise Anzeige (konzept.md 10, gebaut am 16.11.2026): Workflows, Läufe und Ereignisse blättern wie die Listen von WordPress (Markup und Parameter `paged` von `WP_List_Table`, Gesamtzahl, Erste/Vorherige/Nächste/Letzte Seite mit Text für Screenreader, `src/Admin/Pagination.php`). Gesetzte Filter (Zustand, Workflow, Korrelation) bleiben in den Links erhalten; eine Seite hinter dem Ende zeigt die letzte. Die Speicher zählen mit `count()`, `count_workflows()` und `count_runs()` (gleiche Bedingungen wie die Abfrage, getestet auf SQLite in `tests/admin-list-tests.php`).

Letzter Lauf und Fehler je Workflow (gebaut am 16.11.2026): „Letzter Lauf“ zeigt Zeitpunkt (UTC, als `time`) und Zustand des neuesten Laufs und verlinkt auf die Laufansicht; ein fehlgeschlagener Lauf oder einer mit „Aktion erforderlich“ ist rot hervorgehoben (nicht nur über die Farbe: der Zustand steht als Text daneben). „Fehler (30 Tage)“ zählt Läufe in `failed` oder `action_required` der letzten 30 Tage und verlinkt auf die Läufe dieses Workflows; der Link nennt die Zahl für Screenreader. Beide Werte kommen aus zwei Abfragen für die ganze Seite (`EngineStore::workflow_run_summary()`, getestet auf SQLite in `tests/admin-list-tests.php`).

**Fehlt**

- Filter der Läufe nach Zeitraum (nach Workflow gibt es).
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

„Fehler“ zählt Läufe der letzten 30 Tage in `failed` oder `action_required` und verlinkt auf die gefilterten Läufe. Gebaut ohne die Zustandsfilter über der Liste; der Link führt auf alle Läufe des Workflows (dort gibt es die Filter je Zustand).

## 2. Workflow-Canvas und Schrittfolge

**Gebaut** (`screenshot-1.png`): Elementleiste (Auslöser, Wenn/Sonst, Aktion, Warten, Ende), Canvas mit verschiebbaren Knoten und gerichteten Pfeilen, Zoom, Rückgängig/Wiederholen, „Anordnen“, Einstellungsbereich rechts, Tastaturbedienung (Pfeile, C verbindet, Entf löscht), „Schritte der Reihe nach“ als Liste für kleine Bildschirme und Screenreader, darunter das JSON. Testlauf mit Beispieldaten und „Prüfen und veröffentlichen“ unter dem Editor.

**Gebaut am 16.11.2026**

- Herkunft am Knoten: kleine Zeile über dem Titel mit Provider und Art („PASS CAPTAIN · AUSLÖSER“, „WORKFLOW CAPTAIN · AKTION“, „WENN/SONST“). Der Provider eines Auslösers ist der angemeldete Adapter, der den Ereignistyp liefert; der einer Aktion folgt dem Präfix des Schlüssels (`notify.*`, `workflow.*` sind der Kern, `voucher.*` Voucher Captain, `x…` ein eigener Connector; `src/Admin/Provenance.php`). Plugin- und Vertragsversion stehen im Tooltip des Knotens und im Einstellungsbereich („Herkunft: Pass Captain 1.5.0, Vertrag 1.1.0 · ID: t“).
- Kurzbeschreibung statt der Knoten-ID: Zeitplan („wöchentlich Montag 07:30“) oder „je Objekt nacheinander“ beim Auslöser, Betreff/Markierung/Notiz bei Aktionen, erste Regel bei Wenn/Sonst („total_minor ≥ 10000 (+1)“). Die ID steht im Tooltip und im Einstellungsbereich. Der zugängliche Name des Knotens enthält Titel, Kurzbeschreibung und Herkunft.
- Kopfzeile über dem Editor: Name, Zustand und veröffentlichte Version („Aktiv · Version 3 veröffentlicht“), Auslöser mit Provider, Plugin- und Vertragsversion („Auslöser pass.ticket.checked_in von Pass Captain 1.5.0, Vertrag 1.1.0“, sonst „Provider nicht verfügbar“), letzter Lauf mit Link, und oben die Schaltflächen „Testlauf“ und „Prüfen und veröffentlichen“. Die Schaltflächen unter dem Editor bleiben. Auf schmalen Bildschirmen bricht die Kopfzeile um.

**Fehlt gegenüber `workflow-builder.svg`**

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

**Gebaut am 16.11.2026**: Das Feld einer Regel ist eine Auswahlliste statt eines Textfelds, gegliedert in „Ereignis“ (`type`, `subject.*`, `source.product`, `actor_user_id`) und „Daten des Ereignisses“ (Felder des Katalogs für den Ereignistyp des Auslösers, mit Datentyp, z. B. „device_id · integer“). Daneben steht der Wert aus dem Beispielereignis des Testlaufs („Beispiel: 7“), sofern es das Feld enthält. „Anderes Feld …“ schaltet auf ein beschriftetes Textfeld für einen Pfad außerhalb des Katalogs; ein solcher Pfad bleibt erhalten und steht als „(nicht im Katalog)“ in der Liste. Der Provider ergibt sich aus dem Auslöser und wird nicht eigens gewählt. Im Einstellungsbereich warnt ein Hinweis, wenn der Dann- oder Sonst-Ausgang nicht verbunden ist.

**Fehlt**: „+ Gruppe“ im Formular (verschachtelte Gruppen weiter nur über JSON); Warnzeichen am Knoten selbst bei offenem Zweig (heute im Einstellungsbereich und in der Prüfung beim Veröffentlichen).

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

**Gebaut am 16.11.2026**: Vorschau der nächsten drei Termine im Formular des Zeitplans, in der Zeitzone der Website (`wp_timezone_string()`, auch feste Abstände wie „+05:30“), als Liste mit `time`-Elementen; nach jeder Änderung sagt die Live-Region den nächsten Termin an. Die Termine rechnet `builder.js` im Browser mit denselben Regeln wie `Engine\Schedule` (Sommerzeitlücke: später; doppelte Stunde: erstes Vorkommen, R-4.15). Dafür gibt es `Schedule::upcoming()` in PHP; dieselben zehn Fälle (Berlin, New York, Sydney, UTC, +05:30, Lücke und doppelte Stunde) prüfen `tests/engine-tests.php` und `tests/builder.test.js`, und jeder Vorschautermin ist genau der Termin, den `Schedule::last_due()` auslöst. Die Umrechnung wurde zusätzlich für jede Viertelstunde um alle Umstellungen 2026/2027 in neun Zonen (auch Lord Howe und Chatham mit halben Stunden) gegen PHP verglichen (keine Abweichung). Dabei fiel ein Fehler der Engine auf: PHP `DateTime` nimmt in der doppelten Stunde östlich von UTC das zweite Vorkommen (02:30 in Berlin am letzten Oktobersonntag wurde 02:30 MEZ statt MESZ), entgegen konzept.md 15.3 und R-4.15. `Schedule::local_time()` nimmt jetzt das erste Vorkommen; Tests in `tests/engine-tests.php`.

**Fehlt**: eine Übersicht aller Zeitpläne (heute nur über die Spalte „Auslöser“) und „Zuletzt ausgelöst“ im Formular (der letzte Lauf steht in der Kopfzeile des Editors).

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

- Laufansicht (gebaut am 16.11.2026): Angaben zum Lauf (Workflow mit Link zum Editor und zu allen Läufen dieses Workflows, Version, Ereignis, Objekt, eingetreten, begonnen, beendet bzw. zuletzt geändert, Grund), Korrelations-ID mit Links „Läufe mit dieser Korrelation“ und „Ereignisse mit dieser Korrelation“, Idempotenzschlüssel. Zeitleiste der Schritte mit angelegt, wartet bis (Wartezeit oder Wiederholung), beendet bzw. angehalten, Versuchen, redigiertem Ergebnis und Fehlercode; „Fortsetzen“ steht in der Zeile des Schritts. „Ereignisdaten (redigiert)“ zum Aufklappen: nur die Felder des Umschlags ohne `actor_user_id` und aus `data` nur Felder, die der Katalog für den Ereignistyp nennt (Kernkatalog und nicht personenbezogene Connector-Felder), ohne `…_user_id`, ohne adressähnliche oder zusammengesetzte Werte (`Engine::redact`), Geheimnisse ersetzt (`Redact::scrub`); die Zahl der ausgeblendeten Felder steht dabei (`src/Privacy/EnvelopeView.php`).

- Lauf exportieren (gebaut am 16.11.2026): Schaltfläche „Lauf exportieren (JSON, redigiert)“ über der Laufansicht (Recht `view_workflow_logs`, Nonce, Protokolleintrag `run_export`). Die Datei enthält Lauf (Nummer, Workflow und Name, Version, Zustand, Grund, Zeiten in UTC), die Ereignisdaten genau wie „Ereignisdaten (redigiert)“, die Zahl der ausgeblendeten Felder und die Zeitleiste der Schritte mit redigierten Ergebnissen (`EnvelopeView::run_export()`). Nicht enthalten: `actor_user_id`, der Schlüssel „Läufe nacheinander“ (kann eine Benutzer-ID sein), Herkunft aus dem Netzwerk, Ereigniszeile; Geheimnisse sind in allen Texten ersetzt. Getestet in `tests/admin-list-tests.php`.
- Sprung vom Lauf zum Knoten (gebaut am 16.11.2026): In der Zeitleiste steht bei jedem Schritt „Im Editor zeigen“ (für Benutzer mit `edit_workflows`, nicht bei Läufen aus dem Netzwerk). Der Editor öffnet mit `node=<ID>`, markiert und wählt den Knoten (gestrichelter Rahmen, „aus dem Lauf“ im Namen), rollt ihn ins Bild und sagt es in der Live-Region an; in „Schritte der Reihe nach“ trägt der Schritt `aria-current="step"`, auf schmalen Bildschirmen ist die Liste dann aufgeklappt. Fehlt der Knoten im Entwurf, sagt der Editor das. Ein Hinweis erinnert, dass der Lauf die veröffentlichte Version nutzte.

Uhrzeiten stehen in UTC wie in den übrigen Listen.

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

Barrierefreiheit der neuen Teile (16.11.2026): Tabellen mit Beschriftung (`caption` für Screenreader), Spalten- und Zeilenköpfen und ohne leere Kopfzellen; Filter mit `aria-current`; Seitennavigation als beschriftetes `nav`; Zeiten als `time`. Auf schmalen Bildschirmen scrollen die Tabellen in sich (vorhandene Regel in `Plugin.php`, ergänzt um `position:relative`, damit versteckte Screenreader-Texte die Seite nicht verbreitern) und sind per Tastatur erreichbar (`tabindex="0"`). Geprüft mit axe-core (WCAG 2.2 AA und Best Practice) auf den gerenderten Seiten Läufe, Lauf (auch mit „Aktion erforderlich“), Ereignisse, Workflows bei 1280 und 360 Pixel Breite: keine Verstöße, kein waagerechtes Scrollen der Seite. Noch nicht in der echten Testinstallation geprüft (dort läuft ein anderer Stand).

Barrierefreiheit der Teile vom 16.11.2026 (zweite Runde): Felder im Einstellungsbereich sind jetzt über `for`/`id` mit ihrer Beschriftung verbunden (vorher nur daneben), die Feldauswahl ist beschriftet und per Tastatur bedienbar, „Anderes Feld …“ setzt den Fokus ins Textfeld, die Terminvorschau ist eine beschriftete Liste, Ansagen laufen über die vorhandene Live-Region. Die roten Schaltflächen „Löschen“/„Archivieren“ haben auf den Seiten des Plugins ein dunkleres Rot (#b32d2e statt #d63638, 4,4:1 → über 4,5:1). Auf schmalen Bildschirmen nimmt der Einstellungsbereich die volle Breite und seine Felder verbreitern die Seite nicht mehr. Geprüft mit axe-core (WCAG 2.2 AA und Best Practice) auf den gerenderten Seiten Workflows, Editor mit geladenem Builder und markiertem Knoten, Lauf und Lauf mit „Aktion erforderlich“ bei 1280 und 360 Pixel: keine Verstöße, kein waagerechtes Scrollen. Auch das noch nicht in der echten Testinstallation.

## Offene Punkte für die Umsetzung

1. ~~Seitenweise Anzeige von Workflows und Läufen (Abschnitt 1)~~ – gebaut am 16.11.2026, auch für die Ereignisse.
2. ~~Laufansicht mit Korrelation, Zeitleiste und redigierten Daten (Abschnitt 5)~~ – gebaut am 16.11.2026, „Lauf exportieren“ und der Sprung zum Knoten ebenfalls.
3. ~~Herkunft und Kurzbeschreibung am Knoten, Kopfzeile des Editors (Abschnitt 2)~~ – gebaut am 16.11.2026.
4. ~~Terminvorschau im Zeitplan (Abschnitt 4)~~ – gebaut am 16.11.2026.
5. ~~Feldauswahl als Liste mit Beispiel (Abschnitt 3)~~ – gebaut am 16.11.2026.
6. ~~Spalte „Letzter Lauf“ und Fehlerzahl in der Workflowliste (Abschnitt 1)~~ – gebaut am 16.11.2026.

Noch als „Fehlt“ vermerkt, aber nicht beauftragt (Entscheidung des Inhabers, ob nötig): Filter der Läufe nach Zeitraum, Kachel „Läufe · 30 Tage“ und Providerstatus auf der Übersicht (Abschnitt 1), Ziehen aus der Elementleiste (Abschnitt 2), „+ Gruppe“ und Warnzeichen am Knoten (Abschnitt 3), Übersicht aller Zeitpläne (Abschnitt 4). Der Lizenzablauf bleibt bei Pro (Abschnitt 6).
