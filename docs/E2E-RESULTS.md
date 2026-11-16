# E2E-Ergebnisse: Workflow Captain auf einer echten WordPress- und WooCommerce-Installation

Stand: 16.11.2026, Branch `wt/e2e` (Basis `claude/plugin-todo-liste-2a83v4`, `f8ab812`, Schema 4). Das Ergebnis jeder Prüfung
steht unten in den Tabellen; erzeugt mit `bin/e2e/run-checks.sh` und `bin/e2e/release-zip.sh` (nicht ausgeliefert,
`/bin` ist `export-ignore`). 189 von 199 Prüfungen des Hauptlaufs und alle 28 des ZIP-Laufs bestehen; die 10 offenen sind
unten unter „Offene Befunde" erklärt (8 Barrierefreiheit, 2 Umgebung bzw. Pro-Add-on, keiner im Kern-Code als Fehler).

**Zweiter Lauf** (gleicher Tag, Abschnitt „Zweiter Lauf“ unten): Sicherung/Wiederherstellung und Migrationen,
Last mit 50 und 5000 Bestellungen, Builder-Pfade im Browser mit echten Läufen, Beitragsaktionen mit Yoast im Block- und
Classic-Editor, lineare Ansicht und Fokus; 79 von 79 Prüfungen bestehen, zwei Fehler im Kern behoben.

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
   **Nachgemessen im zweiten Lauf:** die Beschriftung war nur 16 px hoch und der nächste Knopf 10 px entfernt, die
   Ausnahme griff also nicht. **Behoben** (`1fd4e8f`): Beschriftung 24 px hoch.
3. **Statuswechsel und Gegenlesen (R-7.4):** Wechselt eine Bestellung schneller den Status, als der Worker läuft
   (z. B. processing → completed in derselben Minute), wird das ältere `status_changed`-Ereignis beim Gegenlesen als
   `discarded`/`reread_mismatch` abgelegt (E2E d1b). Das entspricht der Spezifikation, kann aber Workflows auf
   „processing“ verpassen lassen; Entscheidung für die Spezifikation, kein Code-Fehler. Optionen und Empfehlung: „Zweiter Lauf“, Teil 6.
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
- Zweiter Lauf: Ausgangsdatenbank `backups/baseline.sql` (Stand nach dem ersten Lauf), Sicherung `backups/br.sql`;
  Kopien `wp_e2e_restore` (wird am Ende gelöscht) und `wp_e2e_load` über `E2E_DB=<db> wp …`; Classic Editor in
  `www/wp-content/plugins/classic-editor` (inaktiv); Ergebnisse `logs/results-2.jsonl`, Lastzahlen `logs/load.jsonl`.

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

## Zweiter Lauf (16.11.2026)

Gleiche Testseite und Umgebung wie oben, Branch `wt/e2e` auf `ecd352e` und den Commits dieses Laufs. Alle Teile
zusammen: `AXE=<pfad>/axe.min.js bin/e2e/run-second.sh [screenshot-ordner] [große-bestellzahl]`; jedes Teil läuft auch
einzeln. Jedes Teil beginnt auf derselben Ausgangsdatenbank von `www` (`backups/baseline.sql`, Stand nach dem ersten
Lauf); Kopien heißen `wp_e2e_restore` und `wp_e2e_load` und werden mit `E2E_DB=<datenbank> wp …` angesprochen
(`www/wp-config.php` liest `DB_NAME` daraus). Die Site auf Port 8083 des anderen Agenten blieb unberührt.
**79 von 79 Prüfungen bestehen** – Tabelle am Ende dieses Abschnitts.

### Gefundene und behobene Fehler (zweiter Lauf)

| Commit | Fehler | Folge | Regressionstest |
| --- | --- | --- | --- |
| `b71b0c9` | Builder: jede Änderung baut die Oberfläche neu auf und warf dabei den Fokus an den Seitenanfang – nach „+ Baustein“, nach Entf, nach Tab aus einem geänderten Feld im Inspektor und beim Wählen eines Schritts in der linearen Ansicht; die lineare Liste klappte dabei zu; die Live-Region wurde bei jedem Neuaufbau ersetzt (Ansagen gingen verloren) | Tastatur- und Screenreader-Nutzer verloren nach jeder Änderung ihre Stelle (WCAG 2.4.3); auf schmalen Bildschirmen war der Builder praktisch nur mit der Maus bedienbar | `tests/builder.test.js` (5 neue Prüfungen „focus: …“, „live region …“, „small screen: …“; schlagen mit dem alten Code fehl) |
| `1fd4e8f` | Bestätigungs-Kästchen (Notfallpause, „Erneut senden?“ im Lauf) 16×16 px, Beschriftung 16 px hoch, nächster Knopf 10 px vom Mittelpunkt | WCAG 2.5.8 (Zielgröße) nicht erfüllt; der Befund des ersten Laufs („Ausnahme greift“) stimmte nicht: der Abstand reicht nicht | `tests/run.php` (WF-BUILDER-09 confirmation boxes …), E2E y10 |

Kernprüfungen nach den Commits grün: `php tests/run.php` (627), `php tests/license-guard.php` (86 Dateien),
`php bin/make-pot.php --check` (295 Texte), `python3 bin/validate-spec-examples.py` (61 Beispiele, 20 Gegenbeispiele),
`node tests/builder.test.js` (33), `node tests/settings.test.js` (7).

### 1. Sicherung und Wiederherstellung, Schema-Migrationen (R12, WF-CORE-07) – `bin/e2e/backup-restore.sh`

Ablauf auf der echten Seite:

1. **Laufende Workflows in verschiedenen Zuständen** (r1–r3b): „BR Warten“ (Bestellung bezahlt → Markierung → 1 Tag
   warten → Mail), „BR Sofort“ (bezahlt → Mail), „BR Absturz“ (Beitrag veröffentlicht → Mail). Zwei Bestellungen
   verarbeitet: zwei Läufe warten in ihrer Wartezeit, „BR Sofort“ hat je einmal gemailt. Eine Bestellung mit dauerhaft
   scheiterndem Dispatcher → `dead_letter`. Ein Worker wird **mitten in `wp_mail()` beendet** (PHP-Prozess endet im
   Filter `pre_wp_mail`): sein Mail-Schritt bleibt `running` mit Wirkung `started` und Sperre. Eine letzte Bestellung
   wird bezahlt, aber vom Worker nicht mehr gesehen (`received`).
2. **Sicherung:** `mysqldump --single-transaction` der ganzen Datenbank (360 KB), eingespielt in die Kopie
   `wp_e2e_restore`. Zeilen, Zustände und Schema (CREATE TABLE) aller Plugin-Tabellen sind gleich (r5);
   `wp workflow-captain verify` und die Konsistenzprüfung finden nichts (r5b, r6); `status` zeigt die Zustände der
   Sicherung (r6b).
3. **Der Worker macht auf der Kopie weiter** (r7–r12): das wartende Ereignis wird verarbeitet; „BR Sofort“ mailt nur
   diese Bestellung, nicht die beiden, die vor der Sicherung erledigt waren; die Wartezeiten bleiben stehen, bis sie
   fällig sind. Der Mail-Schritt des beendeten Workers wird nach Ablauf der Sperre **nicht wiederholt**
   (`action_required`, `unclear_outcome`, keine Mail). Das Dead-Letter-Ereignis lässt sich mit `requeue` wieder
   einreihen. Nach Ablauf der Wartezeit (Fälligkeit auf „jetzt“ gesetzt, Zeitreise) bekommt jede der vier Bestellungen
   genau eine „nach Warten“-Mail, alle Läufe enden erfolgreich, kein Schritt existiert doppelt. Summe: 2 Mails auf der
   Seite vor der Sicherung + 6 auf der Kopie = je Schritt und Bestellung genau eine.
4. **Schema-Migrationen auf der Kopie** (m1–m8): `migrate` auf aktuellem Schema ist harmlos. **Downgrade-Schutz:**
   Schema-Option auf 5 (neuer als der Code): `process` verweigert, `migrate` verweigert, die Tabellen bleiben gleich,
   die Diagnose meldet `newer`; eine in dieser Zeit bezahlte Bestellung wird **gespeichert** (`received`), aber nicht
   verarbeitet, und läuft, sobald die Option wieder passt. **Abgebrochenes Upgrade:** Index `state_updated` und Tabelle
   `notes` gelöscht, Option auf 3: der nächste WP-CLI-Aufruf stellt beides wieder her, keine Zeile geht verloren.
   **Wiederholung:** `migrate` zweimal hintereinander ändert nichts (gleiches Schema, gleiche Zeilen, Konsistenz sauber).

Befunde:

- Wird eine Sicherung zurückgespielt, während die alte Seite weiterläuft (zwei Seiten mit derselben Datenbank-Vergangenheit),
  führen **beide** die offenen Schritte aus; das Plugin kann das nicht erkennen. Das RUNBOOK sagt jetzt ausdrücklich: nach
  einer Wiederherstellung auf einer zweiten Seite dort den Worker anhalten (Notfallpause), bis klar ist, welche Seite
  weiterarbeitet.
- Während des Downgrade-Schutzes **speichert** das ältere Plugin weiter Ereignisse in die (neueren) Tabellen. Mit Schema 4
  ist das harmlos; eine spätere Migration, die eine Pflichtspalte ohne Vorgabewert hinzufügt, würde solche Inserts
  scheitern lassen. Hinweis für künftige Schema-Versionen (neue Spalten immer mit Vorgabewert), kein Fehler heute.
- `wordpress.post.published` kommt für jeden öffentlichen Beitragstyp, auch für Produkte (`data.post_type` =
  `product`); das ist so gebaut (`countable_post_type()`), Workflows, die nur Beiträge meinen, brauchen eine Bedingung
  auf `data.post_type` (so in WF-SEO-08 unten).

### 2. Last, Batchgrenzen, System-Cron, zwei Worker (WF-CORE-05) – `bin/e2e/load.sh`

Auf der Kopie `wp_e2e_load` (sonst wie `www`: WordPress 6.9.11, WooCommerce 9.9.5 mit HPOS, MariaDB 10.11, PHP 8.3,
4 CPU-Kerne). Workflow wie in `docs/LAST.md`: Bestellung bezahlt → Nachricht an den Betreiber → Markierung (3 Schritte
mit „Ende“). Bestellungen über WC-CRUD mit `payment_complete()`; jede erzeugt **3 Ereignisse** (`order.created`,
`order.status_changed`, `order.paid`), nur `paid` startet einen Lauf. Mails fängt das Test-mu-plugin ab (kein
Mailserver; ein echter SMTP-Versand kostet je Mail zusätzlich). Messwerte in `logs/load.jsonl`.

| Messung | Ergebnis |
| --- | --- |
| Erfassung: 50 Bestellungen ohne / mit Workflow Captain | 11,9 s / 12,2 s (+2,6 %, rund 6 ms je Bestellung für 3 Ereignisse) |
| Erfassung großer Shop: 5000 Bestellungen in 10 Prozessen à 500 | je 130–150 s, also rund 210 Bestellungen ≈ 630 Ereignisse je Minute; Speicher je Prozess 123 MB (WordPress + WooCommerce) |
| Kleiner Shop: 50 Bestellungen (150 Ereignisse, 150 Schritte), Batch 25, ein Worker | 7 Durchgänge à 0,17–0,20 s, also nach 7 Cron-Minuten alles erledigt |
| Ein Durchgang, Rückstand 15 000 Ereignisse, Limit 25 | 25 Ereignisse + 24 Schritte in 0,16–0,21 s |
| dto., Limit 100 | 100 + 100 in 0,58–0,60 s |
| dto., Limit 200 (Höchstwert der Einstellung) | 200 + 200 in 1,2–1,3 s |
| `wp workflow-captain process --limit=100` als ganzer CLI-Prozess (mit WordPress-Start) | 1,25 s |
| Speicher eines Worker-Durchgangs | Spitze des CLI-Prozesses 107–109 MB, davon der Durchgang selbst unter 2 MB (Start von WordPress + WooCommerce: 107 MB) |
| Zwei Worker gleichzeitig, je Limit 200, bis der Rückstand leer ist | 60 Doppel-Durchgänge in 152 s; je Doppel-Durchgang im Median 224 Ereignisse + 225 Schritte (also nur gut 10 % mehr als ein Worker mit 200), langsamster Durchgang 3,6 s; die 20-Sekunden-Grenze griff nie |
| Doppelausführung | keine: 5050 bezahlte Bestellungen → 5050 Läufe `succeeded`, 5050 Mails (keine doppelt), 5050 Markierungen, kein Schritt- oder Laufschlüssel doppelt, nichts offen, `verify` sauber (l-small, l-large, l-verify) |
| Tabellengröße nach 15 150 Ereignissen | Ereignisse 19 MB, Schritte 9 MB, Läufe 6,6 MB (bis die Aufbewahrung greift) |

Durchsatz daraus (ein Durchgang je Minute, Workflow mit 3 Schritten je Bestellung, also 3 Ereignisse + 3 Schritte):

| Batch | Durchgang | Bestellungen je Stunde, die der Worker schafft |
| --- | --- | --- |
| 25 (Standard, geplanter Worker) | 0,2 s | etwa 500 |
| 100 (Standard von `wp workflow-captain process`) | 0,6 s | etwa 2000 |
| 200 | 1,3 s | etwa 4000 |

Die Rechenzeit des Plugins ist also klein; was zählt, ist, wie oft der Worker läuft und wie viel er je Durchgang darf.
Zwei Worker gleichzeitig sind sicher, bringen aber wenig, weil sie dieselben Zeilen beanspruchen. Die Empfehlung steht
in `docs/RUNBOOK.md` („Last und System-Cron“), die Zahlen ersetzen die Schätzung ohne WordPress in `docs/LAST.md`.

### 3. Builder im Browser mit echten Läufen (WF-BUILDER-12) – `bin/e2e/builder-flows.js`

Alle Workflows werden im echten wp-admin auf der Leinwand gebaut (Palette, Knoten anklicken, Inspektor, „Verbinden
mit …“, „Dann/Sonst verbinden …“), gespeichert, wieder geöffnet und veröffentlicht; jede Bestellung entsteht über
WC-CRUD, der Worker läuft über `wp workflow-captain process`, das Ergebnis wird auf der Seite „Läufe“ gelesen.

| Pfad | Ergebnis |
| --- | --- |
| Dann/Sonst | Bedingung `data.total_minor ≥ 5000` aus dem Formular; 99 € → Dann (Mail, genau einmal), 20 € → Sonst (Markierung); Lauf-Seite zeigt `{"outcome":"then"}` (b1, b7–b7c) |
| Verschachtelte Bedingung | Gruppe in zweiter Ebene (`≥ 5000 UND (bacs ODER cod)`) wird veröffentlicht; 99 € per Scheck → Sonst. Eine dritte Ebene wird mit `condition:too_deep` abgelehnt (b4–b7) |
| Wartezeit | „Warten: 5 Min.“; Lauf „Läuft“, Mail-Schritt fällig 5 Minuten später, keine Mail vorher; nach der Wartezeit genau eine (b9, b10, b12) |
| Abbruch | „Lauf abbrechen“ im Browser: Lauf „Abgebrochen“, wartender Schritt `cancelled`, nach der Wartezeit keine Mail (b11, b12) |
| Fehler/Wiederholung | Mailserver scheitert einmal: Schritt `retry_wait`, Versuch 1, Grund `mail_failed`; nach der Wartezeit genau eine Mail, Versuch 2, Lauf erfolgreich (b13, b14) |
| Unklarer Ausgang | Worker stirbt beim Senden: „Aktion erforderlich“ mit Kästchen „Erneut senden?“; „Fortsetzen“ ohne Haken abgelehnt, mit Haken genau eine Mail (b15, b16) |
| Entwurf/Veröffentlichen | Entwurf startet keine Läufe; eine als Entwurf gespeicherte Änderung erreicht neue Läufe erst nach erneutem Veröffentlichen (Version 2) (b2, b6, b8) |
| Wiederöffnen | Knoten, Kanten, Regel und Positionen kommen gleich zurück (b3) |

Befund (kein Fehler, offen für den Builder): Gruppen in Bedingungen lassen sich nur als JSON eingeben (im Feld
„Ablauf (JSON)“ oder, wenn schon eine Gruppe besteht, im Regel-Feld des Inspektors); das Formular kennt nur eine Ebene.

### 4. Beitragsaktionen mit Yoast SEO (WF-SEO-08) – `bin/e2e/seo.js`

Der Kern bietet für Beiträge: Markierung setzen/entfernen, Notiz, Nachricht. Alle drei schreiben nur in die eigenen
Tabellen des Plugins (`workflow_captain_tags`, `…_notes`) bzw. senden eine Mail; eine Aktion „Beitrag ändern“ gibt es
im Kern nicht. Geprüft mit Yoast SEO 28.7 aktiv und dem Classic-Editor-Plugin aus Git (`WordPress/classic-editor`,
`trunk` `bb8d0cc`, 1.7.0; `setup.sh` mit `E2E_CLASSIC=1`).

- Workflow „Beitrag veröffentlicht → Bedingung `data.post_type = post` → Markierung → Notiz → Mail“.
- **Block-Editor:** Entwurf mit Yoast-Titel, -Beschreibung, -Fokus-Keyphrase und -Canonical, im Editor über die
  Oberfläche veröffentlicht. Workflow lief (Markierung, Notiz, eine Mail). Yoast-Metadaten, Beitragszeile und
  Yoast-Indexable sind nach dem Lauf **gleich** wie direkt nach dem Veröffentlichen; die Seite zeigt Yoast-Titel und
  -Beschreibung (s1–s5).
- **Classic Editor:** dasselbe mit dem Formular und der Yoast-Metabox; „Aktualisieren“ des veröffentlichten Beitrags
  startet nichts erneut (s6–s10).
- **Privater Beitrag:** kein Ereignis, kein Lauf (nur öffentliches Veröffentlichen zählt) (s11).
- Workflow Captain lädt in keinem der beiden Editoren Skripte oder Styles; keine JS-Fehler des Plugins (s1, s6, s12).

Grenzen: Yoast ist ohne JS-Build installiert. Die Yoast-Seitenleiste und die Bedienelemente der Metabox (Analyse,
Vorschau) laufen deshalb nicht; geprüft ist der gespeicherte Zustand. Beim ersten Speichern legt Yoast selbst zwei leere
Hilfsfelder an (`_yoast_wpseo_estimated-reading-time-minutes`, `_yoast_wpseo_primary_category`), unabhängig vom
Plugin. Neue Notbehelfe der Testseite: Asset-Listen von Yoast (`src/generated/assets/*.php`) und zwei weitere
WooCommerce-Skript-Registries (`command-palette*`) als Platzhalter, sonst Fatal im Beitragseditor; Yoast-Indexables über
den Filter `Yoast\WP\SEO\should_index_indexables` auch auf der „local“-Seite.

### 5. Lineare Schrittansicht, Fokusführung, Zielgröße (WF-SET-15, WF-BUILDER-09) – `bin/e2e/a11y2.js`

- **390 px, nur Tastatur** (y1–y6): Leinwand ausgeblendet, „Schritte der Reihe nach“ mit Tab erreichbar, Enter öffnet;
  jeder Schritt ist ein Knopf in Ablauf-Reihenfolge. Enter auf einem Schritt öffnet ihn im Inspektor, der Fokus steht
  auf dessen Überschrift, die Liste bleibt offen; Betreff ändern und Tab führt zum nächsten Feld; Speichern per Tastatur,
  Wiederöffnen zeigt die Änderung; axe-core (WCAG 2.2 AA) auf dem Builder mit offener Liste ohne Verstoß.
  **Vor dem Fix** landete der Fokus nach dem Wählen eines Schritts und nach jeder Feldänderung auf `<body>`, und die
  Liste klappte zu.
- **1280 px** (y7–y9): „+ Wenn/Sonst“ per Enter → Fokus auf dem neuen Knoten; Entf → Fokus auf dem vorigen Knoten;
  Tab durch den Inspektor mit Änderungen verliert den Fokus nie.
- **Zielgröße** (y10): Der Befund 2 des ersten Laufs wurde nachgemessen: Kästchen 16×16, Beschriftung 174×16, nächster
  Knopf 10 px vom Mittelpunkt – die Abstands-Ausnahme von 2.5.8 greift **nicht**. Behoben: die Beschriftung der
  Bestätigungs-Kästchen ist jetzt 24 px hoch (Kästchen + Beschriftung = ein Ziel von 174×24).
- Offen bleibt der Test mit einem echten Screenreader.

### 6. Statuswechsel und Gegenlesen (Befund 3, R-7.4) – nur dokumentiert

Nicht geändert. Stand: Ein `woocommerce.order.status_changed`-Ereignis wird vor dem Start gegengelesen
(`WooCommerceAdapter::reread()`): es zählt nur, wenn die Bestellung **beim Verarbeiten** noch den Zielstatus hat.
Wechselt sie vorher weiter (processing → completed in derselben Minute, typisch bei virtuellen Produkten, Zahlungen mit
sofortigem „Abgeschlossen“ oder Massenbearbeitung), wird das ältere Ereignis `discarded`/`reread_mismatch` – ein
Workflow auf „processing“ startet dann nicht. Im zweiten Lauf nicht erneut ausgelöst (die Bestellungen der Lastmessung
bleiben auf „processing“, dort wurde nichts verworfen).

Entscheidung des Inhabers nötig (Spezifikation, nicht Code). Möglichkeiten:

1. So lassen (sicher gegen Ereignisse aus zurückgerollten Transaktionen), aber in Builder und Hilfe deutlich sagen, dass
   Statuswechsel-Workflows Zwischenstände verpassen können.
2. Für `status_changed` nur prüfen, dass die Bestellung existiert und den Status **irgendwann** hatte (Bestellnotiz
   bzw. Statusverlauf), nicht den aktuellen Status.
3. Gegenlesen für `status_changed` abschalten, wenn in der Spezifikation belegt ist, dass WooCommerce
   `woocommerce_order_status_changed` erst nach dem dauerhaften Speichern feuert (R-3.10; nicht in diesem Lauf geprüft).

Empfehlung: 1 jetzt (Text), 3 prüfen, wenn der WooCommerce-Hook-Zeitpunkt in der Spezifikation belegt ist; 2 nur mit
einer belastbaren Quelle für den Statusverlauf.

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

## Ergebnisse zweiter Lauf (`bin/e2e/run-second.sh`)

| ID | Prüfung | Ergebnis | Detail |
| --- | --- | --- | --- |
| r1 | before backup: two runs of "BR Warten" wait in their delay step | **PASS** | ["running","running"] |
| r1b | before backup: "BR Sofort" mailed once per order | **PASS** |  |
| r2 | before backup: one event in dead_letter | **PASS** | e2e_forced |
| r2b | worker killed in the middle of wp_mail() (PHP process exits) | **PASS** | worker killed while sending |
| r3 | before backup: one event waits (received) | **PASS** |  |
| r3b | before backup: the killed worker left its mail step running with effect "started" | **PASS** | {"state":"running","effect":"started","lease_until":"2026-11-16 17:55:48"} |
| r4 | mysqldump --single-transaction of the live database | **PASS** | 360K |
| r5 | restored copy is a different database with the same rows, states and schema | **PASS** | {"db":"wp_e2e_restore","counts":{"events":17,"causes":0,"workflows":3,"versions":3,"runs":7,"steps":15,"tags":2,"notes":0},"events":{"dead_letter":4,"done":9... |
| r5b | consistency check on the copy finds nothing | **PASS** | {"runs_without_version":[],"steps_without_run":[],"versions_without_workflow":[],"active_without_version":[],"running_without_open_step":[]} |
| r6 | wp workflow-captain verify on the restored copy | **PASS** | Success: No inconsistencies found. |
| r6b | wp workflow-captain status on the copy shows the states of the backup | **PASS** | Plugin 0.2.0, schema 4 (expected 4), adapter interface 0.1.0 Adapters: workflow-captain, wordpress, woocommerce Events: received=4 processing=0 done=9 retry_... |
| r7 | restored copy: the waiting event is processed (done) | **PASS** |  |
| r7b | restored copy: "BR Sofort" mails only the waiting order, not the two done before the backup | **PASS** |  |
| r7c | restored copy: three "BR Warten" runs wait in their delay (two from the backup, one new) | **PASS** | ["running","running","running"] |
| r7d | restored copy: no "BR nach Warten" mail before the delay is due | **PASS** |  |
| r8 | restored copy: the mail step of the killed worker is not repeated (action_required, unclear_outcome, no mail) | **PASS** | {"id":"7","state":"action_required","last_error":"unclear_outcome"} |
| r9 | requeue the dead-letter event of the backup on the copy | **PASS** | Success: Requeued. |
| r9b | wp workflow-captain process on the copy | **PASS** | Success: 1 events, 4 steps processed. |
| r10 | restored copy: after the delay every order gets exactly one "BR nach Warten" mail (dead letter requeued before) | **PASS** | {"o1":1,"o2":1,"o3":1,"o4":1} |
| r10b | restored copy: every run of "BR Warten" and "BR Sofort" succeeded | **PASS** |  |
| r10c | restored copy: no step exists twice | **PASS** |  |
| r10d | restored copy: "BR Sofort" mailed the requeued order once | **PASS** |  |
| r11 | verify after the copy finished every run | **PASS** | Success: No inconsistencies found. |
| r12 | mails: live site before the backup + copy after the restore = one per workflow step and order | **PASS** | live 2 (BR sofort o1,o2), copy 6 (BR sofort o3,o4; BR nach Warten o1-o4) |
| m1 | migrate on a current schema | **PASS** | Success: Schema 4 in place. |
| m2 | downgrade (stored schema newer than the code): process refuses and processes nothing | **PASS** | Error: The stored schema does not match this plugin version; run "wp workflow-captain migrate" (or install the newer version after a downgrade). Nothing was ... |
| m3 | downgrade: migrate refuses and changes nothing | **PASS** | Error: The stored schema is newer than this plugin (downgrade). Install the newer version; nothing was changed. |
| m4 | downgrade: the tables are unchanged and the option still says the newer version | **PASS** |  |
| m4b | downgrade: diagnostics report schema_state "newer" | **PASS** | newer |
| m4c | downgrade: an order paid meanwhile is not processed (event stays received or is not written) | **PASS** | received |
| m5 | back on the matching version the worker runs again (the order paid during the downgrade is processed) | **PASS** | Success: 3 events, 4 steps processed. |
| m6 | interrupted upgrade: the next WP-CLI call finishes it (index and table back, version current, no row lost) | **PASS** | {"index":2,"version":"4","events":20,"causes":0,"workflows":3,"versions":3,"runs":14,"steps":36,"tags":5} |
| m7 | migrate twice in a row | **PASS** | Success: Schema 4 in place. / Success: Schema 4 in place. |
| m8 | repeated migrate changes nothing (same schema, same rows, consistency clean) | **PASS** | {"events":20,"causes":0,"workflows":3,"versions":3,"runs":14,"steps":36,"tags":5,"notes":0} |
| b1 | canvas: trigger, Wenn/Sonst with a rule from the form, Dann and Sonst connected by clicks | **PASS** | ["action1>end","condition1>action1:else","condition1>mail:then","mail>end","start>condition1"] |
| b1b | linear step view lists both branches with Dann/Sonst | **PASS** | ["Auslöser: woocommerce.order.paid","Wenn/Sonst","Dann: Nachricht an Betreiber","Ende","Sonst: Markierung setzen"] |
| b2 | draft saved; an order paid now starts no run of the draft | **PASS** | {"wf1":1,"draftOrder":"33"} |
| b3 | Wiederöffnen: nodes, edges, rule and canvas positions as saved | **PASS** | ["start","mail","end","condition1","action1"] |
| b4 | nested condition one level too deep: publishing refused with condition:too_deep | **PASS** | Der Workflow kann so nicht veröffentlicht werden: node:condition1:condition:too_deep |
| b5 | nested group within the limit: inspector shows the rules as JSON (no form for groups) | **PASS** | "" |
| b6 | Prüfen und veröffentlichen: published (notice, state Aktiv) | **PASS** | Veröffentlicht. Neue Läufe nutzen diese Version; laufende behalten ihre. |
| b7 | real runs: 99 EUR bacs takes Dann (mail), 99 EUR cheque and 20 EUR bacs take Sonst (tag); all succeeded | **PASS** | [["35","succeeded","condition1>mail>end"],["37","succeeded","condition1>action1>end"],["39","succeeded","condition1>action1>end"]] |
| b7b | mail only for the Dann path, exactly once | **PASS** | "" |
| b7c | run page in the browser: Erfolgreich, condition step with outcome "then" | **PASS** | {"heading":"Lauf 1: Erfolgreich","rows":[["condition1","succeeded","1","2026-11-16 17:51:23","{\"outcome\":\"then\"}","",""],["mail","succeeded","1","2026-11... |
| b8 | Entwurf/Veröffentlichen: saved draft does not change new runs; after publishing they use version 2 | **PASS** | {"keptOld":true,"o5":"41","o6":"43"} |
| b9 | canvas: Warten node with 5 minutes | **PASS** | "" |
| b10 | real runs wait: delay step done, mail step queued for 5 minutes later, run page shows "Läuft" | **PASS** | [["delay1","succeeded","1","2026-11-16 17:51:41","{\"until\":\"2026-11-16T17:56:41Z\"}","",""],["mail","queued","0","2026-11-16 17:56:41","","",""]] |
| b11 | Lauf abbrechen in the browser: run Abgebrochen, waiting step cancelled | **PASS** | [["delay1","succeeded","1","2026-11-16 17:51:41","{\"until\":\"2026-11-16T17:56:41Z\"}","",""],["mail","cancelled","0","2026-11-16 17:56:41","","",""]] |
| b12 | after 5 minutes: the cancelled run sends nothing, the other mails once and succeeds | **PASS** | [["45","cancelled"],["47","succeeded"]] |
| b13 | mail server fails once: step in retry_wait with mail_failed, attempt 1, run still Läuft (browser) | **PASS** | [["mail","retry_wait","1","2026-11-16 17:52:53","","mail_failed",""]] |
| b14 | retry after the wait: mail sent exactly once, run Erfolgreich, attempts 2 | **PASS** | {"id":8,"order":"49","state":"succeeded","reason":null,"steps":[["mail","succeeded",2,null,{"sent":1,"to":"site_admin"}],["end","succeeded",1,null,null]]} |
| b15 | unclear outcome: Aktion erforderlich, "Erneut senden?" box; Fortsetzen without the tick is refused, no mail | **PASS** | {"state":"action_required","reason":"unclear_outcome","hasConfirm":true,"refused":true} |
| b16 | with the tick: the mail goes out once more (exactly one captured), run Erfolgreich | **PASS** | {"id":9,"order":"51","state":"succeeded","reason":null,"steps":[["mail","succeeded",1,null,{"sent":1,"to":"site_admin"}],["end","succeeded",1,null,null]]} |
| b17 | no JavaScript errors in the builder and run pages | **PASS** |  |
| s1 | block editor opens with Yoast active; Workflow Captain loads no script or style in the editor | **PASS** | 32 |
| s2 | block editor: published through the UI, one wordpress.post.published event, Yoast fields kept by the save | **PASS** | {"added_by_yoast":["_yoast_wpseo_estimated-reading-time-minutes","_yoast_wpseo_primary_category"]} |
| s3 | block editor: workflow ran (tag, note, one mail on the post) | **PASS** | {"tags":["seo-gesehen"],"notes":["Beitrag 32 veröffentlicht"]} |
| s4 | block editor: Yoast meta, post row and Yoast indexable unchanged by the workflow run | **PASS** | {"diff":[],"indexable":{"title":"SEO Titel SEO Block %%sep%% %%sitename%%","description":"Beschreibung für SEO Block","canonical":"http://127.0.0.1:8080/kano... |
| s5 | front end of the post shows the Yoast title and description after the run | **PASS** | <title>SEO Titel SEO Block - WC E2E</title> |
| s6 | classic editor (plugin from git) opens with the Yoast metabox; no Workflow Captain script or style | **PASS** | {"classic":true,"yoastBox":1} |
| s7 | classic editor: published with the form, one event, Yoast fields of the metabox saved back | **PASS** | {"added_by_yoast":["_yoast_wpseo_estimated-reading-time-minutes","_yoast_wpseo_primary_category"]} |
| s8 | classic editor: workflow ran (tag, note, one mail) | **PASS** | "" |
| s9 | classic editor: Yoast meta, post row and Yoast indexable unchanged by the workflow run | **PASS** | {"diff":[],"indexable":{"title":"SEO Titel SEO Classic %%sep%% %%sitename%%","description":"Beschreibung für SEO Classic","canonical":"http://127.0.0.1:8080/... |
| s10 | classic editor: "Aktualisieren" of the published post starts nothing again (one event, one note, one mail) | **PASS** | "" |
| s11 | private post: no wordpress.post.published event, no tag, no note (only public publishing counts) | **PASS** | {"status":"private","events":0} |
| s12 | no JS error or failed request of Workflow Captain in either editor | **PASS** |  |
| y1 | 390 px: canvas hidden, linear step view offered (summary visible, closed) | **PASS** | "" |
| y2 | 390 px: the list is reached with Tab and opened with Enter; every step is a button in flow order | **PASS** | ["Auslöser: pass.ticket.checked_in","Nachricht an Betreiber","Ende"] |
| y3 | 390 px: Enter on a step opens it in the inspector, the focus goes to its heading, the list stays open | **PASS** | h3 Nachricht an Betreiber |
| y4 | 390 px: Tab after editing the subject moves on to the next control (not to the page start) | **PASS** | select Platzhalter einfügen |
| y5 | 390 px: saved with the keyboard; re-opened draft has the edited subject | **PASS** | http://127.0.0.1:8080/wp-admin/admin.php?page=workflow-captain-workflows&edit=1 |
| y6 | 390 px: axe-core (WCAG 2.2 AA) on the builder with the open step list: no violation | **PASS** | [] |
| y7 | desktop: Enter on "+ Wenn/Sonst" puts the focus on the new node on the canvas | **PASS** | g#condition1 Wenn/Sonst |
| y8 | desktop: Delete removes it and leaves the focus on a node of the flow | **PASS** | g#start Auslöser: pass.ticket.checked_in |
| y9 | desktop: Tab through the inspector while editing never drops the focus to the page start | **PASS** | ["select Platzhalter einfügen","textarea Ticket {{subject.id}} wurde um {{occurre","select Platzhalter einfügen","input ","button Verbinden mit …","button Lö... |
| y10 | target size (WCAG 2.5.8): the pause confirmation box with its label is at least 24 x 24 px | **PASS** | {"box":[16,16],"label":[174,24],"nearest":13} |
| l-small | load small: every paid order has exactly one run, one LOAD mail and one tag; no step or run twice; nothing left open | **PASS** | {"paid":50,"runs":{"succeeded":50},"tags":50,"dupkey":0,"dupev":0,"open":0,"mails":50,"mails_twice":0,"workflow":1} |
| l-large | load large: every paid order has exactly one run, one LOAD mail and one tag; no step or run twice; nothing left open | **PASS** | {"paid":5050,"runs":{"succeeded":5050},"tags":5050,"dupkey":0,"dupev":0,"open":0,"mails":5050,"mails_twice":0,"workflow":1} |
| l-verify | load: verify after both runs | **PASS** | Success: No inconsistencies found. |
