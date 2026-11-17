# Testkit für Adapter (A-1 bis A-11)

Adapter für die Captain-Produkte liegen in Add-ons (`captain-pro`), nie im kostenlosen Kern; einzige Ausnahme sind die Adapter für WordPress und WooCommerce (Spezifikation 4.7, `src/Providers/`). Die Spezifikation verlangt für jeden Adapter die Nachweise A-1 bis A-11 (`docs/SPEC-suite-events.md`, Abschnitt 14.2). Dieses Kit liefert die Prüfungen, die ohne das Add-on gehen, und beschreibt die übrigen.

## Einbinden

```php
require '/pfad/zu/workflow-captain/tests/adapter-kit/AdapterConformance.php';
use WorkflowCaptain\AdapterKit\AdapterConformance as Kit;

// Action des Providers mit Markerwerten auslösen, das an Consumer::capture() übergebene Ereignis abfangen:
$fail = Kit::check_event( $adapter, $captured_event );
```

Jede Prüfung gibt eine Liste von Fehlern zurück; leer heißt bestanden.

| Nachweis | Prüfung im Kit | So testen |
|---|---|---|
| A-1 Adapter liegt im Add-on | – | Wächtertest des Kerns (`tests/license-guard.php`) und eine Suche im Kern nach dem Adapter-Namen |
| A-2 ohne beide Lizenzen kein Callback | – | im Add-on: mit Lizenz-Stub ohne Workflow Pro bzw. ohne Produkt-Pro `has_action()` für jede Action prüfen |
| A-3 keine verbotenen Werte | `check_event()` mit `Kit::MARKERS` | jede Action mit Markerwerten in allen Argumenten (Code, Token, E-Mail, Name, Freitext) auslösen |
| A-4 nur Katalog-Actions | `check_hooks()`, `check_event()` | Liste der angehängten Actions übergeben, dazu die ausgeschlossenen (zum Beispiel `wplc/license_issued`) |
| A-5 Ausnahmen bleiben im Adapter | `check_callback_isolated()` | Callback mit einem Consumer aufrufen, dessen Speicher eine Ausnahme wirft |
| A-6 Schema | `check_event()` | – |
| A-7 Idempotenzschlüssel | `check_idempotency()` | dieselbe Action zweimal und zwei echte Ereignisse |
| A-8 Vertragsgrenze | `check_contract_gate()` | Funktion übergeben, die für eine Vertragsversion sagt, ob der Adapter sich anmelden würde |
| A-9 keine verbotenen Befehle | `check_commands()` | Schlüssel aller registrierten Aktionen übergeben |
| A-10 entzogene Berechtigung | – | Engine-Test im Kern belegt R-8.8 (`tests/engine-tests.php`); im Add-on für jeden schreibenden Befehl wiederholen |
| A-11 Lizenzende entfernt Callbacks | – | im Add-on: Lizenz ablaufen lassen, `has_action()` prüfen; im Kern: offene Ereignisse werden `paused` (C-15) |
