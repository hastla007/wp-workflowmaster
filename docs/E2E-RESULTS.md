# E2E-Ergebnisse: Workflow Captain auf einer echten WordPress- und WooCommerce-Installation

Stand: 16.11.2026, Branch `wt/e2e` (Basis `claude/plugin-todo-liste-2a83v4`, `f8ab812`, Schema 4). Das Ergebnis jeder Prüfung
steht unten in den Tabellen; erzeugt mit `bin/e2e/run-checks.sh` und `bin/e2e/release-zip.sh` (nicht ausgeliefert,
`/bin` ist `export-ignore`). 189 von 199 Prüfungen des Hauptlaufs und alle 28 des ZIP-Laufs bestehen; die 10 offenen sind
unten unter „Offene Befunde" erklärt (8 Barrierefreiheit, 2 Umgebung bzw. Pro-Add-on, keiner im Kern-Code als Fehler).

## Umgebung

| Teil | Version / Herkunft |
| --- | --- |
| PHP | 8.3.6 (CLI und `php -S`, 4 Worker, Router `bin/e2e/router.php`) |
| Datenbank | MariaDB 10.11.14 aus apt, ohne systemd gestartet (`mysqld_safe`) |
| WordPress | 6.9.11 (Git-Tag von `WordPress/WordPress`); `WP_DEBUG`, `WP_DEBUG_LOG`, `WP_DEBUG_DISPLAY`, `DISABLE_WP_CRON` |
| WooCommerce | 9.9.5 (Monorepo-Tag, `plugins/woocommerce`, `composer install --no-dev --prefer-source`), Action Scheduler 3.9.2, HPOS an (und aus), Währung EUR |
| Yoast SEO | 28.7 (Git-Tag; 28.8 verlangt WordPress 7.0) |
| WP-CLI | 2.12.0 (`wp-cli/wp-cli-bundle` mit `--prefer-source`) |
| Browser | Playwright 1.63.0, Chromium 153, `deviceScaleFactor` 1; axe-core 4.10.3 |
| Workflow Captain Pro | `captain-pro` `1a49c97`, `lib/` mit `php build/build.php --in-place --addon=workflow-captain-pro` |

Downloads von wordpress.org, wpackagist und GitHub-Release-Archiven sind im Container gesperrt; alles kommt aus Git-Klonen
und Composer-Quellinstallationen. Nötige Notbehelfe, alle nur auf der Testseite:

- **WooCommerce ohne JS-Build:** `includes/react-admin/feature-config.php` mit WooCommerces eigenem
  `bin/generate-feature-config.php` erzeugt (sonst Fatal beim Laden). Leere Asset-Registries (`*.asset.php`) für die
  nicht gebauten wc-admin-Bundles, sonst Ausnahmen im Log und ein Fatal auf `plugins.php`. WooCommerce zeigt im Admin
  seinen Hinweis „development version … requires files to be built“; 404 auf seine JS-Dateien sind erwartet.
- **Yoast:** `vendor_prefixed/` und der kompilierte DI-Container entstehen nur mit den Entwicklungswerkzeugen; drei
  wpackagist-Testpakete (EDD, Site Kit, WooCommerce) wurden vorher entfernt, weil wpackagist gesperrt ist. Yoast meldet
  ohne JS-Build Hinweise „Block type names must contain a namespace prefix“ (Yoast, nicht Workflow Captain).
- **mu-plugin `bin/e2e/mu-plugins/e2e-test-site.php`:** fängt `wp_mail()` über `pre_wp_mail` in `logs/mail.jsonl` ab,
  kann mit `E2E_FAIL_DISPATCH=1` einen dauerhaft scheiternden Dispatcher über den Filter `workflow_captain_dispatcher`
  setzen (für Dead Letter) und schaltet die Update-Abfragen der Offline-Seite ab. `e2e-fake-providers.php` meldet auf
  Wunsch einen Adapter mit ungültiger Vertragsversion und einen Connector, der einen neueren Kern verlangt.
- **Zeit:** Für den Zeitplan-Trigger wird `created_at` der Version um 30 Minuten zurückgesetzt („heute früher
  veröffentlicht“), für den Action-Scheduler-Pfad das Fälligkeitsdatum der wiederkehrenden Aktion auf „jetzt“.

## Gefundene und behobene Fehler (Commits auf `wt/e2e`, nicht gepusht)

| Commit | Fehler | Folge | Regressionstest |
| --- | --- | --- | --- |
| `332a306` | `wp workflow-captain process`, der dokumentierte System-Cron-Ersatz für WP-Cron, rief Consumer und Engine direkt auf und übersprang den Zeitplan-Tick (und die Schema-Sperre) | Zeitplan-Trigger feuerten auf Websites mit `DISABLE_WP_CRON` nie (E2E f3 schlug fehl) | `tests/engine-tests.php` (WF-CORE-04 …process…) |
| `03c71d8` | `uninstall.php` lud `Scheduler` nicht; `Install::uninstall_site()` endet mit `deactivate()` → `Scheduler::clear()` | „Plugin löschen“ endete mit Fatal, nachdem Tabellen, Optionen und Rechte entfernt waren; Dateien blieben, bei Multisite nur die erste Website bereinigt | `tests/run.php` (uninstall.php ohne Autoloader, eigener PHP-Prozess) |
| `4c889cb` | Eine nach der Netzwerkaktivierung angelegte Website bekam ihre Tabellen erst beim ersten Admin-, Cron- oder WP-CLI-Aufruf | Ereignisse vom Frontend dieser Website gingen bis dahin verloren (Insert in fehlende Tabelle) | `tests/run.php` (WF-MS new site …) |
| `d726188` | Dateifeld „Workflow importieren“ ohne zugänglichen Namen (axe: `label`, critical) | Screenreader sagen nur „Datei auswählen“ | `tests/run.php` (WF-BUILDER-09: alle sichtbaren Formularfelder der Admin-Seiten haben ein Label) |

Alle Kernprüfungen grün nach jedem Commit: `php tests/run.php` (619), `php tests/license-guard.php`,
`php bin/make-pot.php --check` (294 Texte), `python3 bin/validate-spec-examples.py`, `node tests/builder.test.js`,
`node tests/settings.test.js`.

## Offene Befunde (nicht behoben)

1. **Reflow bei 390 px (WCAG 1.4.10):** Die Tabellen von Läufe, Ereignisse, Provider, Vorlagen, Einstellungen → Rollen
   und Status waren breiter als der Bildschirm (63–401 px). **Behoben** auf `claude/plugin-todo-liste-2a83v4`: unter
   782 px scrollen breite Tabellen der Workflow-Captain-Seiten in ihrem eigenen Kasten, die Seite nicht mehr.
2. **Zielgröße (WCAG 2.5.8):** Das Bestätigungs-Kästchen der Notfallpause ist 16×16 px (WordPress-Standard). Es steckt in
   seinem `<label>`, die Beschriftung ist also klickbar; axe erkennt diese Ausnahme nicht. Bewusst gelassen.
3. **Statuswechsel und Gegenlesen (R-7.4):** Wechselt eine Bestellung schneller den Status, als der Worker läuft
   (z. B. processing → completed in derselben Minute), wird das ältere `status_changed`-Ereignis beim Gegenlesen als
   `discarded`/`reread_mismatch` abgelegt (E2E d1b). Das entspricht der Spezifikation, kann aber Workflows auf
   „processing“ verpassen lassen; Entscheidung für die Spezifikation, kein Code-Fehler.
4. **Tägliche Wartung ohne WP-Cron:** Die tägliche Wartung (Aufbewahrung, Datenschutz-Löschung, Tagesbericht) hing nur an
   WP-Cron und lief mit `DISABLE_WP_CRON` nie. **Behoben** auf `claude/plugin-todo-liste-2a83v4`: der Worker (auch
   `wp workflow-captain process`) holt sie nach, wenn sie länger als 25 Stunden nicht lief; RUNBOOK ergänzt.
5. **Pro im Netzwerk:** Netzwerkweit aktiviertes Workflow Captain Pro hat im Netzwerk-Admin keine Lizenzseite
   (`settings.php?page=workflow-captain-pro-license` → 403); die Lizenzseite und der Hinweis „license settings …
   incomplete“ erscheinen nur in den Admin-Bereichen der einzelnen Websites. Gehört nach captain-pro (Kernel
   `license_screen_parent()`/`is_network()`), dort nicht geändert.
6. **Umgebung:** Die Netzwerk-Pluginseite zeigt eine Warnung aus `plugins_api()`, weil WordPress für „Requires Plugins:
   workflow-captain“ wordpress.org fragt (gesperrt). Site Health meldet `get_core_checksums()` aus demselben Grund.

## Nicht geprüft und warum

- **n8n (WF-N8N-12):** übersprungen. `npm install n8n` ist sehr groß, und der Verbindungstest von Workflow Captain Pro
  sendet nur mit Lizenz (Funktion `workflow.webhooks` ohne Lizenz aus); ein Lizenzserver (wp-license) lief hier nicht.
- **Workflow Captain Pro mit Lizenz:** Das Add-on hat keinen Schalter, der Funktionen ohne Lizenz freigibt (nur
  `WORKFLOW_CAPTAIN_PRO_LICENSE_DEV` für andere Server-Schlüssel). Geprüft wurde: lädt netzwerkweit ohne PHP-Fehler,
  alle Netzwerk- und Website-Seiten antworten 200, jede sagt, warum Funktionen aus sind („The license does not grant
  network workflows …“, „license settings … incomplete“); Kern-Tabellen je Website und `wpmu_drop_tables` mit Pro aktiv.
  Netzwerkweite Abläufe selbst und die Suite-Adapter liefen nicht.
- **Vertrags-MAJOR-Ablehnung eines Connectors:** Mit Kernvertrag 0.1.0 nicht auslösbar (es gibt keine kleinere
  Hauptversion); sichtbar geprüft sind „braucht eine neuere Version“ (Connectoren) und `invalid_contract_version`
  (Provider). Das Pausieren laufender Schritte bei MAJOR-Wechsel eines Providers braucht eine Provider-Aktion aus Pro.
- **Echter Screenreader:** keiner verfügbar; ersetzt durch axe-core (WCAG 2.2 AA) und Tastaturabläufe.
- **WooCommerce-JS und Block-Checkout:** ohne JS-Build nicht lauffähig; die Bestellungen entstehen über die CRUD-API.
- **WordPress 7.x:** nicht geprüft (Testseite auf 6.9.11, wie verlangt die neueste 6.x).

## Wo die Seite liegt, Neustart

- Websites: `/home/user/wp-e2e/www` (http://127.0.0.1:8080, Einzelseite mit WooCommerce, HPOS, Yoast installiert aber
  inaktiv), `/home/user/wp-e2e/ms` (http://127.0.0.1:8081, Multisite mit Unterverzeichnissen), `/home/user/wp-e2e/zip`
  (http://127.0.0.1:8082, aus dem Release-ZIP). Admin: `admin` / `admin-e2e-pass`. Datenbanken `wp_e2e`, `wp_e2e_ms`,
  `wp_e2e_zip` (Benutzer `wp`/`wp`). Logs, Mails und Ergebnisse unter `/home/user/wp-e2e/logs/`.
- Workflow Captain ist in `www` und `ms` ein Symlink auf den Checkout (`/home/user/wt-e2e`).
- Neustart nach einem Container-Neustart: `bin/e2e/setup.sh` (idempotent: startet MariaDB und die PHP-Server, baut
  fehlende Teile; `E2E_YOAST=1` baut Yoast). Vollständiger Lauf auf frischer Datenbank:
  `AXE=<pfad>/axe.min.js bin/e2e/run-checks.sh <screenshot-ordner>`; Release-ZIP: `bin/e2e/release-zip.sh`.
- WP-CLI: `wp` (Hauptseite), `wpms` (Multisite), `wpzip` (ZIP-Seite) in `/usr/local/bin`.

## Ablauf je Bereich

- **a Aktivierung:** acht Tabellen, Schema-Option, Rechte des Administrators, Worker genau einmal im Action Scheduler
  (Gruppe `workflow-captain`), kein WP-Cron-Duplikat, tägliche Wartung auf WP-Cron; der echte Action-Scheduler-Lauf
  (`wp action-scheduler run`) verarbeitet ein Ereignis und plant sich genau einmal neu (a10–a12).
- **b Provider:** wordpress, woocommerce und der Zeitplan-Adapter angemeldet, alle Ereignistypen angeboten.
- **c/e Bestellung:** Workflow über `Plugin::instance()->workflows()` gespeichert und veröffentlicht
  (`woocommerce.order.paid` → `notify.admin` mit Platzhaltern → `workflow.tag.set`), Bestellung über WC-CRUD,
  `payment_complete()`, Worker: Ereignis gültig und `done`, ein Lauf `succeeded`, Mail mit gerendertem Betreff
  („E2E Bestellung 12 bezahlt: 4990 EUR“), Markierung gesetzt, keine personenbezogenen Daten (E-Mail, Name, Adresse,
  Telefon, Ort der Rechnung) in irgendeiner `workflow_captain_*`-Tabelle; zweites `payment_complete()` startet nichts.
  Einmal mit HPOS, einmal mit klassischer Speicherung (Bestellung als `shop_order`-Post), beide bestanden.
- **d weitere Ereignisse:** status_changed, refunded (`wc_create_refund`, Notiz „Erstattet: 1000 EUR“), cancelled,
  `wordpress.user.registered` (`wp_create_user`), `wordpress.post.published` (Entwurf erzeugt keins).
- **f Zeitplan:** täglich, Zeit gerade vorbei: feuert einmal im Worker, nicht erneut; über `wp workflow-captain process`
  erst nach Fix `332a306`.
- **g Betrieb:** Notfallpause speichert Ereignisse, führt keinen Schritt aus, danach läuft er; Dead Letter über
  scheiternden Dispatcher, `wp workflow-captain requeue`, danach `done`; Requeue eines erledigten Ereignisses abgelehnt;
  `status`, `verify`, `diagnostics`, `pause on|off`.
- **t Provider fehlt:** WooCommerce deaktiviert, während ein Ereignis wartet → `paused`/`adapter_unregistered`, kein Lauf,
  Providerseite zeigt „kein Adapter … Pausiert“; wieder aktiv → Requeue → `done`, Lauf erfolgreich. Abgelehnter Adapter
  und Connector auf Provider- bzw. Connectorenseite sichtbar. Hinweis: die anderen pausierten WooCommerce-Ereignisse
  bleiben pausiert, bis ein Mensch sie wieder einreiht (R-10.8, gewollt).
- **h Admin:** alle Seiten 200 ohne PHP-Fehlertext, Lauf-Detail, Testereignis über den Link der Providerseite, Vorlage
  übernommen (Entwurf, Hinweis `provider_unavailable`, weil die Captain-Provider fehlen), alle sechs Einstellungs-Tabs,
  Wert gespeichert, Feldfehler (`aria-invalid`, Wert auf 200 begrenzt), Builder-Leinwand, Knoten hinzufügen, Entwurf
  speichern, erneut öffnen, Testlauf, keine JS-Fehler des Plugins, Plugin-Liste, Website-Zustand, Dashboard; im
  Fehlerprotokoll kein Eintrag des Plugins. Screenshots lagen im Scratchpad, nicht im Repository.
- **x Barrierefreiheit:** axe-core auf 15 Seiten bei 1280 und 390 px; Tastatur im Builder (Tab auf Knoten, sichtbarer
  Fokus, Pfeile verschieben und speichern die Position, Palette mit Enter, Entf löscht Knoten und Kanten, C + Enter
  verbindet mit Ansage in einer Live-Region) und in den Einstellungen (Tab, Label, Enter speichert).
- **i Frontend:** Startseite, Beitrag, Seite, Produkt, Shop, Feed, REST-Index, Sitemap mit Plugin aktiv und deaktiviert
  gleich (Nonces und Zeitstempel normalisiert); mit Yoast zusätzlich Yoast-Sitemaps, REST für Beitrag/Seite/Produkt und
  `yoast/v1/get_head` gleich, keine Erwähnung von Workflow Captain in öffentlichen Antworten (WF-SEO-03/-07).
- **j Deaktivieren/Löschen:** Daten bleiben, Worker abgemeldet, Reaktivierung plant einmal; `delete_plugins()` auf einer
  echten Kopie (nicht dem Symlink) entfernt Tabellen, Optionen, Rechte und Planungen und die Dateien (erst nach Fix
  `03c71d8`).
- **k Multisite:** Netzwerkaktivierung legt Tabellen, Schema-Option und Rechte je Website an; neue Website sofort (nach
  Fix `4c889cb`); `wpmu_delete_blog( …, true )` löscht ihre Tabellen; Netzwerk-Deinstallation entfernt alle; dazu Pro
  netzwerkweit (p-…).
- **z Release-ZIP:** `git archive` mit `.gitattributes` enthält nur `assets`, `languages`, `readme.txt`, `src`,
  `uninstall.php`, `workflow-captain.php`; mit `wp plugin install <zip> --activate` in einer frischen Seite installiert
  (kein Symlink), Aktivierung, Provider und Bestellablauf bestanden.

## Ergebnisse Hauptlauf (`bin/e2e/run-checks.sh`)

| ID | Prüfung | Ergebnis | Detail |
| --- | --- | --- | --- |
| a1 | all workflow_captain_* tables exist | **PASS** | 8 tables |
| a2 | schema option set to current version | **PASS** | 4 |
| a3 | adapters option lists registered providers | **PASS** | woocommerce,wordpress,workflow-captain |
| a4 | administrator has all capabilities | **PASS** | manage_workflow_captain,edit_workflows,activate_workflows,view_workflow_logs,retry_workflow_runs,manage_workflow_integrations |
| a5 | admin user can manage_workflow_captain | **PASS** |  |
| a6 | worker scheduled in Action Scheduler exactly once | **PASS** | 1 pending AS actions |
| a7 | no WP-Cron worker event besides Action Scheduler (no double schedule) | **PASS** |  |
| a8 | daily maintenance on WP-Cron | **PASS** |  |
| a9 | scheduler backend reported as action_scheduler | **PASS** |  |
| b1 | wordpress adapter registered (diagnostics) | **PASS** | workflow-captain,wordpress,woocommerce |
| b2 | woocommerce adapter registered (diagnostics) | **PASS** |  |
| b3 | schedule adapter (workflow-captain) registered | **PASS** |  |
| b4 | no refused adapters | **PASS** | [] |
| b5 | event types offered by the adapters | **PASS** | [] |
| c0/hpos | HPOS on | **PASS** |  |
| c1/hpos | workflow saved and published via Workflows API | **PASS** | workflow 1 |
| c1b/hpos | order stored in HPOS tables | **PASS** | wc_orders |
| c2/hpos | order.paid event captured on payment_complete() | **PASS** | 1 events, order 12 |
| c3/hpos | stored envelope valid (schema validator) | **PASS** | [] |
| c4/hpos | event processed to done | **PASS** | done  |
| c5/hpos | exactly one run, succeeded | **PASS** | ["succeeded "] |
| c6/hpos | admin mail captured with rendered subject | **PASS** | E2E Bestellung 12 bezahlt: 4990 EUR -> admin@example.test / Typ woocommerce.order.paid, Zahlart bacs, Artikel 1. |
| c7/hpos | message placeholders rendered | **PASS** |  |
| c8/hpos | tag set on the order | **PASS** |  |
| c9/hpos | no personal data (billing email/name/address/phone/city) in any workflow_captain_* table | **PASS** | 5343 bytes scanned |
| c10/hpos | status_changed (pending->processing) captured alongside | **PASS** | 1 events |
| c11/hpos | second payment_complete() does not start a second run | **PASS** |  |
| c0/posts | HPOS off | **PASS** |  |
| c1/posts | workflow saved and published via Workflows API | **PASS** | workflow 2 |
| c1b/posts | order stored in classic posts (shop_order) | **PASS** | shop_order |
| c2/posts | order.paid event captured on payment_complete() | **PASS** | 1 events, order 14 |
| c3/posts | stored envelope valid (schema validator) | **PASS** | [] |
| c4/posts | event processed to done | **PASS** | done  |
| c5/posts | exactly one run, succeeded | **PASS** | ["succeeded "] |
| c6/posts | admin mail captured with rendered subject | **PASS** | E2E Bestellung 14 bezahlt: 4990 EUR -> admin@example.test / Typ woocommerce.order.paid, Zahlart bacs, Artikel 1. |
| c7/posts | message placeholders rendered | **PASS** |  |
| c8/posts | tag set on the order | **PASS** |  |
| c9/posts | no personal data (billing email/name/address/phone/city) in any workflow_captain_* table | **PASS** | 10691 bytes scanned |
| c10/posts | status_changed (pending->processing) captured alongside | **PASS** | 1 events |
| c11/posts | second payment_complete() does not start a second run | **PASS** |  |
| d0 | wc_create_refund() succeeded | **PASS** | refund 19 |
| d1b | status passed before the worker ran is discarded on re-read (R-7.4, by design) | **PASS** | ["discarded reread_mismatch","done "] |
| d1 | status_changed events for processing and completed | **PASS** | ["done","done"] |
| d2 | status_changed workflow runs succeeded and tag set | **PASS** | ["succeeded","succeeded","succeeded","succeeded"] |
| d3 | refunded event done | **PASS** | done  |
| d4 | refund workflow note with rendered amount | **PASS** | Erstattet: 1000 EUR |
| d5 | cancelled event done, workflow tagged order | **PASS** | done |
| d6 | wordpress.user.registered captured and done; tag set | **PASS** | done  |
| d7 | wordpress.post.published captured and done | **PASS** | done  |
| d8 | draft post creates no published event | **PASS** |  |
| d9 | post workflow ran and sent mail | **PASS** | succeeded |
| d10 | no user login/email of the new user in workflow tables | **PASS** |  |
| a10 | event waits for the worker (received) | **PASS** |  |
| a11 | the Action Scheduler action runs the worker (event done, step ran) | **PASS** |  |
| a12 | recurring action rescheduled exactly once after running | **PASS** | 1 pending |
| f0 | schedule workflow published (daily 17:44 site time Europe/Berlin) | **PASS** |  |
| f1 | schedule fires once on worker run | **PASS** | ["succeeded"] |
| f2 | further worker runs do not fire again | **PASS** |  |
| f0 | schedule workflow published (daily 17:44 site time Europe/Berlin) | **PASS** |  |
| f3a | wp workflow-captain process succeeds | **PASS** | Success: 1 events, 2 steps processed. |
| f3 | schedule fires via "wp workflow-captain process" (system cron path) | **PASS** | 1 runs |
| g0 | emergency pause on | **PASS** |  |
| g1 | event still stored during pause | **PASS** | done |
| g2 | no step executed during pause (no tag) | **PASS** |  |
| g3 | after lifting the pause the step runs (tag set) | **PASS** | ["succeeded"] |
| g4 | permanently failing dispatcher moves event to dead_letter | **PASS** | dead_letter e2e_forced |
| g5 | wp workflow-captain requeue <dead-letter id> | **PASS** | Success: Requeued. |
| g6 | requeued dead-letter event processed to done | **PASS** | done |
| g7 | requeue of a done event is refused | **PASS** | Error: Not requeued: only dead_letter or paused events can be requeued. |
| g8 | wp workflow-captain status | **PASS** | Plugin 0.2.0, schema 4 (expected 4), adapter interface 0.1.0 Adapters: workflow-captain, wordpress, woocommerce Events: received=0 processing=0 done=31 retry... |
| g9 | wp workflow-captain verify | **PASS** | Success: No inconsistencies found. |
| g10 | wp workflow-captain diagnostics (valid JSON, scheduler, schema, adapters) | **PASS** | {"env":{"plugin":"0.2.0","schema":"4","schema_expected":"4","schema_state":"current","adapter_interface":"0.1.0","wordpress":"6.9.11","php":"8.3.6","multisit... |
| g11 | wp workflow-captain pause on/off | **PASS** | Success: Emergency pause on. |
| t0 | order.paid event queued (received), worker not yet run | **PASS** |  |
| t1 | WooCommerce deactivated: queued event is paused (adapter_unregistered / R-10.7), not lost or failed | **PASS** | paused adapter_unregistered |
| t2 | WooCommerce deactivated: no run started | **PASS** |  |
| t3 | diagnostics no longer list woocommerce as registered | **PASS** |  |
| t4 | Providers page shows woocommerce as missing with its stored events | **PASS** | <code>woocommerce</code></td><td>kein Adapter (Add-on fehlt, inaktiv oder ohne Lizenz)</td><td>– / –</td><td></td><td>Verworfen: 1, Erledigt: 18, Pausiert: 3... |
| t5 | WooCommerce active again: requeue of the paused event accepted | **PASS** |  |
| t6 | requeued event processed, run succeeded, tag set | **PASS** | done / succeeded |
| t7 | Providers page shows the refused adapter with its reason (invalid_contract_version) | **PASS** | <code>pass-captain</code></td><td>abgelehnt – invalid_contract_version</td><td>– / –</td><td></td><td></td><td></td></tr><tr><td><code>woocommerce</code>< |
| t8 | Connectors page shows the connector refused for its contract (needs newer core) | **PASS** | e2e-newer</code></td><td>1.0.0</td><td>1.0.0</td><td>abgelehnt</td><td>braucht eine neuere Version von Workflow Captain</td><td>1</td><td>0</td><td></td></tr... |
| h0 | login to wp-admin | **PASS** | http://127.0.0.1:8080/wp-admin/ |
| h1 | Übersicht (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h2 | Workflows list (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h3 | Läufe (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h3b | Lauf detail (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h4 | Ereignisse (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h5 | Provider (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h5a | Provider page offers a Testereignis link for woocommerce.order.paid | **PASS** |  |
| h5b | Testereignis woocommerce.order.paid (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h5c | Testereignis report shown | **PASS** |  |
| h6 | Vorlagen (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h6a | Vorlagen lists templates | **PASS** | 4 templates |
| h6b | template imported as draft (redirect to editor with canvas, notice shown, no PHP error) | **PASS** | http://127.0.0.1:8080/wp-admin/admin.php?page=workflow-captain-workflows&edit=14 notice=provider_unavailable |
| h7 | Hilfe (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h8-general | Einstellungen tab general (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h8-execution | Einstellungen tab execution (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h8-notify | Einstellungen tab notify (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h8-privacy | Einstellungen tab privacy (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h8-roles | Einstellungen tab roles (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h8-status | Einstellungen tab status (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h9 | Eigene Connectoren (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h10 | settings: valid value saved | **PASS** | 30 |
| h11 | settings: out-of-range value shows a field error and is clamped | **PASS** | value 200, aria-invalid 1 |
| h12 | Workflow builder (new) (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h12a | builder canvas renders nodes | **PASS** | 3 nodes |
| h12b | builder palette adds a node | **PASS** | 3 -> 4 |
| h12c | saving a draft works (redirect to edit=<id>, no PHP error) | **PASS** | http://127.0.0.1:8080/wp-admin/admin.php?page=workflow-captain-workflows&edit=15 |
| h12d | saved draft re-opens with the added node | **PASS** | 4 |
| h12e | no JavaScript errors in the builder | **PASS** |  |
| h13 | Workflow editor (existing) (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h13a | Testlauf (dry run) renders without PHP error | **PASS** | http://127.0.0.1:8080/wp-admin/admin.php?page=workflow-captain-workflows&edit=15&dryrun=1 |
| h14 | Plugins page (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h15 | Site Health status (HTTP 200, no PHP error text) | **PASS** | status 200 |
| h16 | Dashboard (HTTP 200, no PHP error text) | **PASS** | status 200 |
| x-desktop-overview | axe (WCAG 2.2 AA) overview at 1280px: no violation in Workflow Captain markup | **FAIL** | target-size (serious): input[type="checkbox"] :: Fix any of the following: Target has insufficient size (16px by 16px, should be at least 24px by 24px) Targe... |
| x-desktop-workflows | axe (WCAG 2.2 AA) workflows at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-builder | axe (WCAG 2.2 AA) builder at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-runs | axe (WCAG 2.2 AA) runs at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-events | axe (WCAG 2.2 AA) events at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-providers | axe (WCAG 2.2 AA) providers at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-templates | axe (WCAG 2.2 AA) templates at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-help | axe (WCAG 2.2 AA) help at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-connectors | axe (WCAG 2.2 AA) connectors at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-settings-general | axe (WCAG 2.2 AA) settings-general at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-settings-execution | axe (WCAG 2.2 AA) settings-execution at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-settings-notify | axe (WCAG 2.2 AA) settings-notify at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-settings-privacy | axe (WCAG 2.2 AA) settings-privacy at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-settings-roles | axe (WCAG 2.2 AA) settings-roles at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-desktop-settings-status | axe (WCAG 2.2 AA) settings-status at 1280px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-kb-1 | builder: a node is reachable with Tab | **PASS** | start |
| x-kb-1b | builder: focused node has a visible focus indicator | **PASS** |  |
| x-kb-2 | builder: arrow keys move the node (+10/+10), store the position and keep focus | **PASS** | 40,30 -> 50,40, stored 50,40, focus start |
| x-kb-3 | builder: palette button works with Enter | **PASS** | 3 -> 4 |
| x-kb-4 | builder: Delete removes the focused node and its edges | **PASS** | 3 nodes, 1 edges |
| x-kb-5 | builder: C then Enter on the target connects (announced in a live region) | **PASS** | live: Ziel wählen: einen anderen Knoten auswählen. |
| x-kb-6 | settings: field reachable with Tab and has a label | **PASS** |  |
| x-kb-7 | settings: Enter in the field saves | **PASS** |  |
| x-mobile-overview | axe (WCAG 2.2 AA) overview at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-overview-overflow | overview at 390px: plugin content does not overflow horizontally | **PASS** | 0px |
| x-mobile-workflows | axe (WCAG 2.2 AA) workflows at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-workflows-overflow | workflows at 390px: plugin content does not overflow horizontally | **PASS** | 0px |
| x-mobile-builder | axe (WCAG 2.2 AA) builder at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-builder-overflow | builder at 390px: plugin content does not overflow horizontally | **FAIL** | 2px |
| x-mobile-runs | axe (WCAG 2.2 AA) runs at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-runs-overflow | runs at 390px: plugin content does not overflow horizontally | **FAIL** | 63px |
| x-mobile-events | axe (WCAG 2.2 AA) events at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-events-overflow | events at 390px: plugin content does not overflow horizontally | **FAIL** | 401px |
| x-mobile-providers | axe (WCAG 2.2 AA) providers at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-providers-overflow | providers at 390px: plugin content does not overflow horizontally | **FAIL** | 317px |
| x-mobile-templates | axe (WCAG 2.2 AA) templates at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-templates-overflow | templates at 390px: plugin content does not overflow horizontally | **FAIL** | 173px |
| x-mobile-help | axe (WCAG 2.2 AA) help at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-help-overflow | help at 390px: plugin content does not overflow horizontally | **PASS** | 0px |
| x-mobile-connectors | axe (WCAG 2.2 AA) connectors at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-connectors-overflow | connectors at 390px: plugin content does not overflow horizontally | **PASS** | 0px |
| x-mobile-settings-general | axe (WCAG 2.2 AA) settings-general at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-settings-general-overflow | settings-general at 390px: plugin content does not overflow horizontally | **PASS** | 0px |
| x-mobile-settings-execution | axe (WCAG 2.2 AA) settings-execution at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-settings-execution-overflow | settings-execution at 390px: plugin content does not overflow horizontally | **PASS** | 0px |
| x-mobile-settings-notify | axe (WCAG 2.2 AA) settings-notify at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-settings-notify-overflow | settings-notify at 390px: plugin content does not overflow horizontally | **PASS** | 1px |
| x-mobile-settings-privacy | axe (WCAG 2.2 AA) settings-privacy at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-settings-privacy-overflow | settings-privacy at 390px: plugin content does not overflow horizontally | **PASS** | 0px |
| x-mobile-settings-roles | axe (WCAG 2.2 AA) settings-roles at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-settings-roles-overflow | settings-roles at 390px: plugin content does not overflow horizontally | **FAIL** | 366px |
| x-mobile-settings-status | axe (WCAG 2.2 AA) settings-status at 390px: no violation in Workflow Captain markup | **PASS** | outside plugin: none |
| x-mobile-settings-status-overflow | settings-status at 390px: plugin content does not overflow horizontally | **FAIL** | 23px |
| i1 | front-end HTML identical active vs. deactivated (home, post, product, shop, feed, REST index, sitemap) | **PASS** | SAME / (80671 bytes) SAME /?p=28 (96956 bytes) SAME /?page_id=9 (75906 bytes) SAME /?post_type=product&p=29 (94558 bytes) SAME /?post_type=product (82173 byt... |
| i2 | with Yoast SEO active: front end, XML sitemaps and REST identical with Workflow Captain active vs. deactivated, no Workflow Captain data in public responses | **PASS** | SAME / (83565 bytes) SAME /?p=28 (100757 bytes) SAME /?page_id=9 (78962 bytes) SAME /?post_type=product&p=29 (97888 bytes) SAME /sitemap_index.xml (2441 byte... |
| h20 | PHP error log has no entry from Workflow Captain (WP_DEBUG, WP_DEBUG_LOG) | **PASS** | 141 lines in debug.log, none from the plugin |
| j1 | after deactivation all tables still exist | **PASS** | [] |
| j2 | after deactivation workflows still stored | **PASS** |  |
| j3 | after deactivation options and caps kept | **PASS** |  |
| j4 | after deactivation no worker scheduled (AS and WP-Cron) | **PASS** | 0 AS |
| j5 | reactivation schedules the worker once (AS), no WP-Cron duplicate | **PASS** | AS pending=1 wp-cron=0 |
| j5b | delete_plugins() runs uninstall.php and deletes the files | **PASS** | true  |
| j6 | after uninstall no workflow_captain_* tables | **PASS** | [] |
| j7 | after uninstall no workflow_captain options | **PASS** | [] |
| j8 | after uninstall no capabilities left on any role | **PASS** | [] |
| j9 | after uninstall nothing scheduled | **PASS** |  |
| p-network-plugins | Pro multisite network-plugins renders (HTTP 200), no PHP error | **FAIL** | ntytwentyfive php-error customize-support sticky-menu"><br> <b>Warning</b>: plugins_api(): An unexpected error occurred. Something may be wrong with WordPres... |
| p-network-workflows | Pro multisite network-workflows renders (HTTP 200), no PHP error | **PASS** | /wp-admin/network/admin.php?page=workflow-captain-network :: Network workflows The license does not grant network workflows. No network run starts, open step... |
| p-network-consents | Pro multisite network-consents renders (HTTP 200), no PHP error | **PASS** | /wp-admin/network/admin.php?page=workflow-captain-network-consents :: Site consents The license does not grant network workflows. No network run starts, open... |
| p-network-runs | Pro multisite network-runs renders (HTTP 200), no PHP error | **PASS** | /wp-admin/network/admin.php?page=workflow-captain-network-runs :: Network runs The license does not grant network workflows. No network run starts, open step... |
| p-network-transfer | Pro multisite network-transfer renders (HTTP 200), no PHP error | **PASS** | /wp-admin/network/admin.php?page=workflow-captain-network-transfer :: Import and export The license does not grant network workflows. No network run starts, ... |
| p-network-license | Pro multisite network-license renders (HTTP 403), no PHP error | **FAIL** | /wp-admin/network/settings.php?page=workflow-captain-pro-license ::  |
| p-site2-overview | Pro multisite site2-overview renders (HTTP 200), no PHP error | **PASS** | /zwei/wp-admin/admin.php?page=workflow-captain :: Workflow Captain Workflow Captain Pro: the license settings of this plugin are incomplete. Open license set... |
| p-site2-providers | Pro multisite site2-providers renders (HTTP 200), no PHP error | **PASS** | /zwei/wp-admin/admin.php?page=workflow-captain-providers :: Provider Workflow Captain Pro: the license settings of this plugin are incomplete. Open license s... |
| p-site2-settings | Pro multisite site2-settings renders (HTTP 200), no PHP error | **PASS** | /zwei/wp-admin/admin.php?page=workflow-captain-settings :: Einstellungen Workflow Captain Pro: the license settings of this plugin are incomplete. Open licen... |
| p-site2-network-participation | Pro multisite site2-network-participation renders (HTTP 200), no PHP error | **PASS** | /zwei/wp-admin/admin.php?page=workflow-captain-network-site :: Network participation Workflow Captain Pro: the license settings of this plugin are incomplete... |
| p-site2-integrations | Pro multisite site2-integrations renders (HTTP 200), no PHP error | **PASS** | /zwei/wp-admin/admin.php?page=workflow-captain-pro-integrations :: Integrations Workflow Captain Pro: the license settings of this plugin are incomplete. Ope... |
| k1 | network activation creates per-site tables, schema option and caps on every existing site | **PASS** | 2 sites |
| k2 | site created after network activation has its tables at once (wp_initialize_site, before any request on it) | **PASS** | blog 3 |
| k3 | new site has its tables after the first admin request (maybe_upgrade) | **PASS** | blog 3 |
| k4 | wpmu_delete_blog( drop ) removes the site tables (wpmu_drop_tables) | **PASS** | [] |
| k5 | network uninstall removes the tables of every site | **PASS** | [] |
| k6 | multisite PHP error log has no entry from Workflow Captain | **PASS** | clean |

## Ergebnisse Release-ZIP (`bin/e2e/release-zip.sh`)

| ID | Prüfung | Ergebnis | Detail |
| --- | --- | --- | --- |
| z1 | release ZIP contains only shipped files (export-ignore honoured) | **PASS** |  assets languages readme.txt src uninstall.php workflow-captain.php  |
| a1/zip | all workflow_captain_* tables exist | **PASS** | 8 tables |
| a2/zip | schema option set to current version | **PASS** | 4 |
| a3/zip | adapters option lists registered providers | **PASS** | woocommerce,wordpress,workflow-captain |
| a4/zip | administrator has all capabilities | **PASS** | manage_workflow_captain,edit_workflows,activate_workflows,view_workflow_logs,retry_workflow_runs,manage_workflow_integrations |
| a5/zip | admin user can manage_workflow_captain | **PASS** |  |
| a6/zip | worker scheduled in Action Scheduler exactly once | **PASS** | 1 pending AS actions |
| a7/zip | no WP-Cron worker event besides Action Scheduler (no double schedule) | **PASS** |  |
| a8/zip | daily maintenance on WP-Cron | **PASS** |  |
| a9/zip | scheduler backend reported as action_scheduler | **PASS** |  |
| b1/zip | wordpress adapter registered (diagnostics) | **PASS** | workflow-captain,wordpress,woocommerce |
| b2/zip | woocommerce adapter registered (diagnostics) | **PASS** |  |
| b3/zip | schedule adapter (workflow-captain) registered | **PASS** |  |
| b4/zip | no refused adapters | **PASS** | [] |
| b5/zip | event types offered by the adapters | **PASS** | [] |
| c0/zip | HPOS on | **PASS** |  |
| c1/zip | workflow saved and published via Workflows API | **PASS** | workflow 1 |
| c1b/zip | order stored in HPOS tables | **PASS** | wc_orders |
| c2/zip | order.paid event captured on payment_complete() | **PASS** | 1 events, order 12 |
| c3/zip | stored envelope valid (schema validator) | **PASS** | [] |
| c4/zip | event processed to done | **PASS** | done  |
| c5/zip | exactly one run, succeeded | **PASS** | ["succeeded "] |
| c6/zip | admin mail captured with rendered subject | **PASS** | E2E Bestellung 12 bezahlt: 4990 EUR -> admin@example.test / Typ woocommerce.order.paid, Zahlart bacs, Artikel 1. |
| c7/zip | message placeholders rendered | **PASS** |  |
| c8/zip | tag set on the order | **PASS** |  |
| c9/zip | no personal data (billing email/name/address/phone/city) in any workflow_captain_* table | **PASS** | 5343 bytes scanned |
| c10/zip | status_changed (pending->processing) captured alongside | **PASS** | 1 events |
| c11/zip | second payment_complete() does not start a second run | **PASS** |  |
