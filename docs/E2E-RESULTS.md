# E2E-Ergebnisse: Workflow Captain auf einer echten WordPress- und WooCommerce-Installation

> **Hinweis (17.11.2026, MINVER-1):** Seit der Entscheidung des Inhabers verlangt Workflow Captain PHP 8.5, WordPress 7.0
> und für den WooCommerce-Adapter WooCommerce 11.2; MySQL 9.7 bzw. MariaDB 12.3 sind empfohlen (älterer Server nur
> Warnung). Alle Läufe auf dieser Seite liefen mit PHP 8.3.6 und MariaDB 10.11, zum Teil mit WordPress 6.9.11/6.4.5 und
> WooCommerce 9.9.5/8.9.0. Sie sind ab jetzt **nur noch historisch**: mit dem aktuellen Stand bleibt das Plugin dort
> inaktiv. Im Prüfcontainer steht kein PHP 8.5 zur Verfügung; die echten Läufe auf PHP 8.5 (8.6 experimentell) mit
> MySQL 9.7 und MariaDB 12.3 macht die CI (`.github/workflows/e2e-db.yml`, `bin/e2e/ci-db.sh`, siehe
> [`KOMPATIBILITAET.md`](KOMPATIBILITAET.md)). Ein Lauf mit Browser auf einer echten Installation mit PHP 8.5 steht aus. **Nachtrag 23.11.2026:** Lauf ohne Browser auf PHP 8.5 lokal, siehe Abschnitt „PHP 8.5 (23.11.2026)“ am Ende.

Stand: 16.11.2026, Branch `wt/e2e` (Basis `claude/plugin-todo-liste-2a83v4`, `f8ab812`, Schema 4). Das Ergebnis jeder Prüfung
steht unten in den Tabellen; erzeugt mit `bin/e2e/run-checks.sh` und `bin/e2e/release-zip.sh` (nicht ausgeliefert,
`/bin` ist `export-ignore`). 189 von 199 Prüfungen des Hauptlaufs und alle 28 des ZIP-Laufs bestehen; die 10 offenen sind
unten unter „Offene Befunde" erklärt (8 Barrierefreiheit, 2 Umgebung bzw. Pro-Add-on, keiner im Kern-Code als Fehler).

**Zweiter Lauf** (gleicher Tag, Abschnitt „Zweiter Lauf“ unten): Sicherung/Wiederherstellung und Migrationen,
Last mit 50 und 5000 Bestellungen, Builder-Pfade im Browser mit echten Läufen, Beitragsaktionen mit Yoast im Block- und
Classic-Editor, lineare Ansicht und Fokus; 79 von 79 Prüfungen bestehen, zwei Fehler im Kern behoben.

**Dritter Lauf** (gleicher Tag, Abschnitt „Dritter Lauf (neue Oberfläche)“ unten): Upgrade mit Upgrade-Sperre auf der
bestehenden Seite, neue Listen, Filter, Laufansicht, Zeitpläne und Builder-Teile im echten Browser mit wp-admin-CSS;
101 von 101 Prüfungen bestehen, acht Fehler im Kern behoben (fünf Commits); Wiederholung des ersten Laufs 197 von 199
(zwei bekannte Punkte, Umgebung und Pro), des zweiten 79 von 79.

**Staging** (gleicher Tag, Abschnitt „Staging (WordPress 7.1.4, WooCommerce 11.3, Yoast 28.8)“ unten): frische Installation
nach dem GitHub-Workflow mit den echten Release-Paketen von wordpress.org, Workflow Captain aus dem Release-ZIP; alle
drei Läufe mit HPOS und klassisch, Checkout-Block mit echten Bestellungen, Yoast-Seitenleiste und -Metabox, Multisite,
Mindestversionen 6.4.5/8.9.0; 908 von 910 Prüfungen bestehen (zweimal `p-network-license`, Pro), kein Fehler im Kern.

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
  sendet nur mit Lizenz (Funktion `workflow.webhooks` ohne Lizenz aus); ein Lizenzserver (wp-license) lief hier nicht. **Nachtrag 24.11.2026:** Mit n8n 1.123.92 (npm, nur 127.0.0.1) nachgeholt: `bin/e2e/n8n.sh`, Ergebnisse in [`n8n/MATRIX.md`](n8n/MATRIX.md), Abschnitt 4.
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
  **Nachgeholt** auf Staging (Abschnitt „Staging“, Teil 2): echte Bestellungen über den Checkout-Block.
- **WordPress 7.x:** nicht geprüft (Testseite auf 6.9.11, wie verlangt die neueste 6.x). **Nachgeholt** auf Staging mit
  WordPress 7.1.4, WooCommerce 11.3.0 und Yoast 28.8.

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

## Dritter Lauf (neue Oberfläche, 16.11.2026)

Geprüft wurde alles, was seit dem zweiten Lauf im Kern neu gebaut und bis dahin nur mit nachgebauten
WordPress-Funktionen und ohne die Styles von wp-admin getestet war: Upgrade mit `src/Migrator.php`, Spalten und Blättern
der Listen, Filter, Laufansicht mit Export, Übersicht, Seite „Zeitpläne“, Status-Tab und die neuen Teile des Builders.
Gleiche Testseite und Umgebung wie oben, Branch `wt/e2e` ab `68bf79d` mit den Commits dieses Laufs; Workflow Captain Pro
war auf `www` nicht aktiv (Eingabefelder von Add-on-Aktionen deshalb mit einer Test-Aktion, siehe unten).

    AXE=<pfad>/axe.min.js bin/e2e/run-third.sh [screenshot-ordner]

Der Lauf beginnt auf `backups/baseline.sql` von `www`; danach bleiben die Daten dieses Laufs auf der Seite (für die
Screenshots). **101 von 101 Prüfungen bestehen** – Tabelle am Ende der Datei. Die Läufe der ersten beiden Runden wurden
danach erneut ausgeführt (Regression, Teil 6).

### Gefundene und behobene Fehler (dritter Lauf)

| Commit | Fehler | Folge | Regressionstest |
| --- | --- | --- | --- |
| `13d4219` | Jeder WP-CLI-Aufruf führt das Upgrade schon beim Laden des Plugins aus; `wp workflow-captain migrate` fand danach immer „aktuell“ und schrieb nur „Schema 4 in place“ | Ein wiederholtes (abgebrochenes) Upgrade und das Upgrade von einem älteren Schema liefen unbemerkt; die Ausgaben „earlier upgrade … repeated“ und „Data steps run“ kamen nie | `tests/run.php` (WF-CORE-07 migrate reports the upgrade that boot() ran …, eigener PHP-Prozess) |
| `bdcabd4` | Audit-Eintrag `run_export` ohne Beschriftung | Status-Tab zeigte den Schlüssel „run_export“ | `tests/run.php` (WF-SET-13: jeder vom Kern geschriebene Audit-Eintrag hat eine Beschriftung) |
| `bdcabd4` | Unter 782 px scrollen Tabellen im eigenen Kasten; Status- und Protokolltabelle hatten nichts Fokussierbares (axe `scrollable-region-focusable`, serious) | Mit der Tastatur nicht seitlich scrollbar (WCAG 2.1.1) | `tests/run.php` (alle `widefat`-Tabellen der Admin-Seiten mit `tabindex="0"`) |
| `bdcabd4` | Seite „Zeitpläne“: Link „Läufe“ im Zellentext nur durch die Farbe erkennbar (axe `link-in-text-block`, 1,41:1) | WCAG 1.4.1 | `tests/admin-list-tests.php` (Link unterstrichen) |
| `bdcabd4` | Builder: „×“ einer Regel 9 × 16 px (axe `target-size`) | WCAG 2.5.8 | `tests/run.php` (kleine Textknöpfe des Builders mindestens 24 × 24 px) |
| `b19846e` | Namensfeld des Editors bei 390 px 1–2 px breiter als der Inhalt (Rand der Eingabefelder von wp-admin; schon im ersten Lauf `x-mobile-builder-overflow`) | Seitliches Scrollen im Inhaltsbereich | `tests/admin-list-tests.php` |
| `b7192c2` | Texte auf den Knoten liefen mit den Schriften von wp-admin über den Rand („WORKFLOW CAPTAIN · AUSLÖSER“); die Kürzung nach Zeichenzahl reichte nicht | Text ragte aus dem Knoten | `tests/builder.test.js` (Texte höchstens 166 px, Kürzung mit „…“, voller Text im Tooltip) |
| `0a570ff` | Providerstatus der Übersicht: „1 Ereignistypen“ | Grammatik | `tests/admin-list-tests.php` |

Kernprüfungen nach dem letzten Commit grün: `php tests/run.php` (752), `php tests/license-guard.php` (91 Dateien),
`php bin/make-pot.php --check` (462 Texte), `python3 bin/validate-spec-examples.py` (69 Beispiele, 22 Gegenbeispiele),
`node tests/builder.test.js` (98), `node tests/settings.test.js` (7).

### 1. Upgrade der bestehenden Seite (WF-CORE-07) – `bin/e2e/upgrade.sh`

- **Alte Fassung:** Export von `ecd352e` (Stand des zweiten Laufs, ohne `Migrator`) per `git archive` nach
  `/home/user/wp-e2e/old-plugin`, Symlink darauf; ein Workflow, drei bezahlte Bestellungen, drei erfolgreiche Läufe (u0).
- **Update** (Symlink wieder auf den Checkout, wie das Ersetzen der Plugin-Dateien): erster `status` zeigt Schema 4/4
  (u1); Zeilen, Tabellendefinitionen und Schema-Option unverändert, keine Sperre übrig (u2); der alte Workflow läuft
  weiter (u3); `verify` sauber (u4); Diagnose `upgrade_lock` „free“ (u5); `migrate` auf aktuellem Schema ohne Datenschritt
  und ohne Warnung (u6). Das Schema bleibt 4; `Install::migrations()` hat noch keinen Datenschritt.
- **Abgebrochenes Upgrade** (Schema-Option 3, Index `state_updated` und Tabelle `notes` gelöscht): eine Anfrage im
  Frontend migriert nicht, die nächste wp-admin-Anfrage schon (u7a); Index und Tabelle zurück, keine Zeile verloren,
  Sperre frei (u7).
- **Sperre einer laufenden Aktualisierung** (30 s alt): `migrate` lehnt ab („Another request is upgrading“, u9), Diagnose
  „upgrade“/„held“, eine in der Zeit bezahlte Bestellung wird gespeichert, aber nicht verarbeitet (u8), `process` lehnt
  ab (u10); Status-Tab im Browser: „Aktualisierung der Datenbank: läuft gerade“, Schema 3 / 4 (u8b).
- **Verwaiste Sperre** (11 Minuten alt): `migrate` übernimmt sie und meldet jetzt „Upgraded from schema 3 to 4.“ und
  „An earlier upgrade had stopped half-way; it was repeated.“ (u10b, erst nach `13d4219`); Tabellen vollständig (u10c);
  Protokoll „Abgebrochene Datenbank-Aktualisierung wiederholt“ mit von/bis (u11); die wartende Bestellung läuft danach
  (u12); `verify` sauber (u13).
- **Multisite** (`ms`, netzwerkweit aktiv): Website „zwei“ mit Schema 3, fehlender Tabelle und verwaister Sperre wird von
  `wpms --url=… workflow-captain migrate` aktualisiert, die Hauptseite bleibt unberührt (u14); Fehlerprotokoll sauber (u15).

### 2. Daten – `bin/e2e/third.php`

Auf der aktualisierten Seite (mit den Daten aus Teil 1) über die Workflows-API veröffentlicht: 13 Workflows, darunter
Bedingung mit Gruppe (`total_minor ≥ 5000 UND (bacs ODER cod)`), drei Zeitpläne (wöchentlich Montag 07:30, täglich – Zeit
gerade vorbei, monatlich am 1. – pausiert), ein Entwurf und eine Aktion eines Add-ons. 70 bezahlte Bestellungen
(verschiedene Beträge und Zahlarten), Erstattungen, Stornos, Statuswechsel, neue Benutzer, Beiträge; Worker wie geplant.
Ergebnis: 242 Läufe, 331 Ereignisse (woocommerce, wordpress, workflow-captain). Alle Zustände (d3-1):

- `running` (Wartezeit 1 Tag), `succeeded`, `stopped` (Bedingung „fehlendes Feld beendet“), `action_required` aus drei
  echten Engine-Pfaden: `condition_field_missing` (Modus „Fehler“), `overdue` (Zeitreise: Fälligkeit 3 Tage zurück),
  `unclear_outcome` (Absturz nachgestellt: Wirkung „started“ gesetzt), `paused`/`action_unavailable` (Add-on entfernt,
  während Erstattungen kommen), `cancelled` (`cancel_run`) (d3-2).
- `failed` setzt die Engine des Kerns nie (der Zustand ist nur aufgeführt); zwei Läufe wurden für Filter, Zählung und die
  Fehlerspalte direkt auf `failed` gesetzt (Testdaten, Befund unten).
- Zeitraum: 30 Läufe per Zeitreise 35–64 Tage zurückdatiert. Korrelation: eine bezahlte Bestellung startet drei Läufe
  (drei Workflows auf `order.paid`) mit derselben `correlation_id` (d3-4). Keine Personendaten der Bestellungen in den
  Tabellen des Plugins (d3-6).
- Add-on-Aktion: mu-plugin `bin/e2e/mu-plugins/e2e-addon-action.php` (Option `e2e_addon_action`) meldet
  `woocommerce.e2e.mark` mit `builder_fields()` (Auswahl, Zahl, Textfeld).

### 3. Neue Seiten im Browser – `bin/e2e/third.js`

Chromium 153 (Playwright 1.63), `deviceScaleFactor` 1, echtes wp-admin-CSS, 1280 und 390 px, axe-core 4.10.3
(WCAG 2.2 AA, nur Markup des Plugins), Tastatur, JavaScript-Fehler und fehlgeschlagene Anfragen des Plugins.

| Bereich | Geprüft |
| --- | --- |
| Workflowliste | Spalten „Letzter Lauf“ (Zeit, Zustand, Link) und „Fehler (30 Tage)“ (Zahl wie in der Datenbank, Link auf die Läufe, Zustand in Warnfarbe), Entwurf ohne Läufe „–“; Blättern ab 50 (45 zusätzliche Entwürfe, danach gelöscht) (v1–v4) |
| Läufe | 242 Läufe, 50 je Seite, „1 von 5“, nächste Seite mit Tab + Enter, letzte Seite; jeder Zustandsfilter zählt wie die Datenbank; Workflow-Filter mit Hinweis, auch zusammen mit Zustand, Blättern behält Filter; Korrelation (3 Läufe), ungültige Korrelation ignoriert; Zeitraum „letzte 30 Tage“ per Tastatur (212), eigene Daten vor Voreinstellung, vertauschte Daten getauscht (v5–v11) |
| Laufansicht | Korrelation mit Links, Zeitleiste mit `time`-Elementen, redigierte Daten ohne Personendaten; „Lauf exportieren“: Datei mit Lauf, Zeitleiste und redigiertem Ereignis, keine Rechnungsdaten, keine Admin-Mail, kein Login; „Im Editor zeigen“ markiert und wählt den Knoten, Hinweis im Inspektor, Ansage; unklarer Ausgang mit „Erneut senden?“ (24 px) (v12–v15) |
| Ereignisse | 331, Blättern; Filter Anbieter (Hinweis, Zählung der gefilterten Liste), Anbieter + Zustand (Blättern behält beide), Korrelation (v16–v19) |
| Übersicht | Kachel „Läufe · 30 Tage“ = 212 mit Link auf die Laufliste für 30 Tage; Providerstatus WooCommerce, WordPress, Workflow Captain „angemeldet“ mit Zahlen (v20, v21) |
| Zeitpläne | drei Zeilen nach Name, „wöchentlich, Montag, 07:30“, nächster Termin Montag 07:30 Ortszeit (UTC im `datetime`), täglicher mit „zuletzt ausgelöst“ und Link, monatlicher „keiner (pausiert)“ (v22, v23) |
| Status | „Aktualisierung der Datenbank: keine offen“, Schema 4 / 4; Protokoll mit lesbaren Einträgen (wiederholtes Upgrade, Lauf exportiert – erst nach `bdcabd4`) (v24, v25) |
| Builder | Kopfzeile (Name, „Aktiv · Version 1 veröffentlicht“, Auslöser mit Provider und Versionen, letzter Lauf, Testlauf/Veröffentlichen oben); Herkunft am Knoten und im Inspektor; gespeicherte Gruppe als Formular, Feldliste gruppiert; Beispielwerte aus dem Testereignis („Beispiel: 9900“, „bacs“); „+ Gruppe“ mit Enter, Fokus im neuen Feld, keine Gruppe in der Gruppe; Fokus bleibt nach Feldwechsel; Ziehen aus der Palette setzt den Knoten an die Ablegestelle; Warnzeichen am unverbundenen Knoten; Add-on-Aktion ohne Add-on „Aktion nicht verfügbar“, mit Add-on Felder „Farbe“ (Auswahl), „Priorität“ (Zahl), „Kommentar“ (Textfeld) mit Beschriftung und gespeicherten Werten, Änderung + Tab behält den Fokus; Terminvorschau: drei Montage 07:30 Ortszeit, „Zuletzt ausgelöst: noch nie.“ bzw. mit Zeit und Link (v26–v38) |
| 390 px | alle sieben Seiten ohne seitliches Scrollen und ohne axe-Verstoß; Zeitraum und Blättern sichtbar; lineare Ansicht mit Dann/Sonst, Bedingung mit Gruppe im Inspektor, Fokus auf der Überschrift; „Im Editor zeigen“ öffnet die Liste mit markiertem Schritt (v39–v42) |
| Allgemein | keine JavaScript-Fehler, keine fehlgeschlagene Anfrage des Plugins (v43); PHP-Fehlerprotokoll ohne Eintrag des Plugins (h3-20) |

### 4. Frontend und SEO mit Yoast (Bereich i)

Mit den Daten dieses Laufs: Startseite, Beitrag, Seite, Produkt, Shop, Feed, REST-Index und Sitemap mit Plugin aktiv und
deaktiviert gleich (i3-1); mit Yoast SEO 28.7 aktiv zusätzlich Yoast-Sitemaps, REST für Beitrag/Seite/Produkt und
`yoast/v1/get_head` gleich, keine Erwähnung von Workflow Captain (i3-2).

Befund am Messverfahren (kein Fehler des Plugins): Action Scheduler löscht bei **jeder** Plugin-Deaktivierung seine
Option `action_scheduler_migration_status` (Hook `deactivate_plugin`) und meldet ohne sie zwei Beitragsstatus
(`in-progress`, `failed`), die im REST-Index erscheinen. Im ersten Durchgang unterschied sich deshalb `/wp-json/`; das
Skript `frontend-diff.sh` löscht die Option jetzt vor beiden Abrufen.

### 5. Screenshots für WordPress.org – `bin/e2e/screenshots.js`

Neun Bilder in `.wordpress-org/` (1280 × 800, `deviceScaleFactor` 1) von der echten Seite mit den Daten dieses Laufs; nur
im Browser die Entwicklerhinweise von WooCommerce ausgeblendet und der Seitenname in der Werkzeugleiste als
„Beispielshop“ gezeigt, nichts gespeichert. Beschriftungen in `readme.txt` angepasst (neu: Workflowliste, Läufe,
Laufansicht, Zeitpläne; entfallen: Provider, Vorlagen, Einstellungen „Ausführung“).

### 6. Regression: erster und zweiter Lauf erneut

- `bin/e2e/run-checks.sh`: **197 von 199**. Offen nur die zwei bekannten Punkte 5 und 6 oben (`p-network-plugins`:
  Warnung aus `plugins_api()`, Umgebung; `p-network-license`: 403, Pro). Gegenüber dem ersten Lauf jetzt bestanden:
  Zielgröße der Notfallpause, alle Tabellen bei 390 px, `x-mobile-builder-overflow` (nach `b19846e`). Anpassungen der
  Prüfskripte an die neue Oberfläche: „Testlauf“ steht jetzt zweimal im Editor (`admin.js`); `k5` zählt die Tabellen von
  Workflow Captain Pro (`workflow_captain_pro_*`, Add-on nur deaktiviert) nicht mehr zu denen des Kerns.
- `bin/e2e/run-second.sh` mit 500 statt 5000 Bestellungen im großen Lastteil: **79 von 79** (l-large: 550 bezahlte
  Bestellungen, 550 Läufe, keine doppelt; die Lastzahlen in Teil 2 oben stammen weiter aus dem Lauf mit 5000,
  `logs/load.jsonl` enthält jetzt den kleinen Lauf). `builder-flows.js` an die neue Oberfläche angepasst (Feldliste statt
  Textfeld, Gruppe als Formular statt JSON, Kopfzeile statt „Zustand: …“, Zeitleiste neben der Angabentabelle) und nach
  der Anpassung einzeln erneut gelaufen (20 von 20).

### Befunde (nicht geändert)

- **`failed` kommt im Kern nicht vor:** Die Engine setzt Läufe nie auf `failed` (dauerhafte Fehler enden in
  `action_required`); Filter „Fehlgeschlagen“ und der Anteil in „Fehler (30 Tage)“ bleiben ohne Add-on immer 0. Frage an
  den Inhaber: Zustand so lassen (für Add-ons/Netzwerkläufe) oder in der Oberfläche ausblenden, solange er 0 ist.
  Empfehlung: lassen, aber in der Hilfe sagen, dass der Kern dauerhafte Fehler als „Aktion erforderlich“ zeigt.
- **Protokoll aus WP-CLI:** Einträge ohne angemeldeten Benutzer (Upgrade, `requeue` per WP-CLI) zeigen als Benutzer „#0“;
  besser wäre „System/WP-CLI“ (kleine Textänderung, nicht gemacht).
- **Vertrag von WooCommerce:** Kopfzeile und Herkunft zeigen „WooCommerce 9.9.5, Vertrag 9.9.5“; das ist so festgelegt
  (F-20: `contract_version` = `WC_VERSION`), wirkt aber doppelt.
- **Pro:** Eingabefelder echter Pro-Aktionen nicht im Browser geprüft (Pro auf `www` nicht aktiv, ohne Lizenz ohne
  Aktionen); der gleiche Weg (`builder_fields()`) ist mit der Test-Aktion belegt.
- **Echter Screenreader:** weiterhin nicht verfügbar.

### Zustand der Seite nach dem dritten Lauf

`www` hält die Daten dieses Laufs (Sicherung `backups/third-data.sql`, Zustand der IDs in `logs/third-state.json`,
Korrelation in `logs/third-correlation.txt`), Yoast inaktiv, Option `e2e_addon_action` nicht gesetzt (das mu-plugin
`e2e-addon-action.php` liegt in `www/wp-content/mu-plugins/` und tut ohne die Option nichts). `ms`: Workflow Captain
netzwerkweit aktiv, Schema 4 auf allen Websites (Stand nach `run-checks.sh` und Teil 1). Ergebnisse
`logs/results-3.jsonl`, Wiederholungen `logs/results.jsonl` und `logs/results-2.jsonl`; Screenshots des Laufs in
`/home/user/wp-e2e/shots-3`. Die Seiten der anderen Agenten (8083, 8084, 8086) blieben unberührt.

## Staging (WordPress 7.1.4, WooCommerce 11.3, Yoast 28.8)

Stand 16.11.2026, Branch `wt/staging` (Basis `claude/plugin-todo-liste-2a83v4`, `12b8ace`). Nachdem `wordpress.org`,
`downloads.wordpress.org` und `api.wordpress.org` freigegeben wurden, entstand eine frische Staging-Installation mit
den echten, gebauten Release-Paketen (mit JS), gebaut mit den Schritten der GitHub-Workflows. Damit liefen alle drei
bisherigen Prüfläufe erneut, je einmal mit HPOS und mit klassischer Bestellspeicherung, dazu echte Bestellungen über
den Checkout-Block, Yoast mit Seitenleiste und Metabox und eine Mindestversions-Prüfung.

    E2E_FRESH=1 bin/e2e/staging/setup.sh
    AXE=<pfad>/axe.min.js bin/e2e/staging/run-staging.sh yes 500   # HPOS
    AXE=<pfad>/axe.min.js bin/e2e/staging/run-staging.sh no 500    # klassische Speicherung

**Ergebnis:** 910 Prüfungen, 2 offen – beide `p-network-license` (je einmal je Speicherart; Befund 5 des ersten Laufs,
Workflow Captain Pro, nicht der Kern). Kein Fehler im Kern gefunden; geändert wurden nur die Prüfskripte, die Doku und `readme.txt` (Commits auf `wt/staging`, nicht gepusht).

### Ergebnistabelle

| Lauf | HPOS an | HPOS aus | Was |
| --- | --- | --- | --- |
| Einrichtung wie im Workflow (`results-staging-setup.jsonl`) | 4 / 4 | – | ZIP mit `--activate` und `--force`, nur ausgelieferte Dateien, Versionen, HPOS-Schalter |
| Erster Lauf (`run-checks.sh`) | 198 / 199 | 198 / 199 | Aktivierung, Provider, Bestellablauf HPOS/klassisch, weitere Ereignisse, Action Scheduler, Zeitplan, Betrieb, Provider fehlt, Admin, axe-core/Tastatur 1280 und 390 px, Frontend mit/ohne Yoast, Löschen, Multisite mit Pro |
| Zweiter Lauf (`run-second.sh`, Last mit 500) | 84 / 84 | 84 / 84 | Sicherung/Wiederherstellung, Migrationen, Builder-Pfade, Yoast im Block- und Classic Editor (jetzt mit JS, s13–s17), lineare Ansicht, Last |
| Dritter Lauf (`run-third.sh`) | 101 / 101 | 101 / 101 | Upgrade vom Stand des zweiten Laufs per ZIP (`--force`), Daten, neue Oberfläche, Frontend mit Yoast |
| Checkout (`staging/checkout.js`) | 26 / 26 (erster Durchgang 25 / 26, siehe „Befund 3 in echt“) | 26 / 26 | Block-Checkout Überweisung/Nachnahme/Gratisbestellung, Shortcode-Checkout, abgebrochener Checkout, keine Personendaten |
| Mindestversionen (WordPress 6.4.5, WooCommerce 8.9.0) | 52 / 52 und 34 / 34 | (in den 52) | `checks.php` activation, providers, order on/off, wc_more; `admin.js` |

### Aufbau nach dem Workflow und Abweichungen

Vorlage: `wp-couponmaster` `.github/workflows/plugin-smoke.yml`, Job `wordpress-integration` (Schritte
„Install WordPress 7.1.4“ und „Build, install, and update the release ZIP“, Matrix HPOS/klassisch), WooCommerce-Version
aus `wp-passmaster` `ci.yml` (Browser-Job, 11.3.0 = „WC tested up to“) und `learnboating` `ci.yml` (Zielsystem WP 7.1.4,
11.3 dort als Beta in der Testphase). Ausgeführt wurde:

    wp core download --version=7.1.4
    wp config create --dbname=wp_staging --dbuser=wp --dbpass=wp --dbhost=localhost --skip-check
    wp core install --url=http://127.0.0.1:8090 --title=… --admin_user=admin …
    wp plugin install woocommerce --version=11.3.0 --activate
    wp plugin install wordpress-seo --version=28.8 --activate
    wp option update woocommerce_custom_orders_table_enabled yes|no
    git archive --format=zip --prefix=workflow-captain/ -o workflow-captain.zip HEAD     # statt bin/build.sh
    wp plugin install workflow-captain.zip --activate
    wp plugin install workflow-captain.zip --force
    wp plugin install classic-editor                                                     # für WF-SEO-08

| Workflow | Staging | Grund |
| --- | --- | --- |
| PHP 8.4 (`setup-php`) | PHP 8.3.6 | einzige PHP-Version im Container; WordPress 7.1.4 verlangt 7.4, WooCommerce 11.3 und Yoast 28.8 ebenfalls 7.4 |
| MySQL 8.4 / MariaDB 11.4 als Dienst, `root`/`root` über 127.0.0.1 | MariaDB 10.11.14 lokal, Benutzer `wp`, Socket (`localhost`) | vorhandene Datenbank der Testseiten; Root nur über den Socket |
| `wp-cli.phar` von `raw.githubusercontent.com` | WP-CLI 2.12.0 aus den Quellen (`/home/user/wp-e2e/wp-cli`, `--path`) | keine heruntergeladenen ausführbaren Archive; 2.12.0 ist auch der neueste Tag (`git ls-remote`), also dieselbe Version wie das Phar |
| WooCommerce 11.2.2 (`plugin-smoke.yml`) | 11.3.0 | Auftrag; wie `wp-passmaster` |
| `bin/build.sh` des Gutschein-Plugins | `git archive` wie `bin/e2e/release-zip.sh` | Workflow Captain hat kein Build-Skript; `.gitattributes` bestimmt den Inhalt |
| Datenbank `wordpress`, `--url=http://localhost` | `wp_staging` (Multisite `wp_staging_ms`), Ports 8090/8091 | neben den bestehenden Seiten :8080–:8086 |
| – | zusätzlich `WP_DEBUG`, `WP_DEBUG_LOG`, `DISABLE_WP_CRON`, `WP_ENVIRONMENT_TYPE=staging`, `E2E_MAIL_FILE` | Prüfskripte (Fehlerprotokoll, System-Cron-Pfad, Mails) |
| – | `WP_PROXY_HOST/PORT` und mu-plugin `bin/e2e/staging/mu-plugins/e2e-staging-proxy.php` | der Container erreicht wordpress.org nur über seinen Proxy und hat kein DNS: WordPress lehnt sonst jeden „sicheren“ Download ab (`wp_http_validate_url()` → „A valid URL was not provided“); das mu-plugin vertraut der CA des Proxys und überspringt die DNS-Prüfung nur für `*.wordpress.org` |
| – | mu-plugin `e2e-test-site.php` (Mails in eine Datei) | keine echten Mails |
| Prüfungen: `tests/run-integration.sh` des Gutschein-Plugins | `bin/e2e/staging/run-staging.sh` | eigene Prüfläufe von Workflow Captain |

Keine Notbehelfe an WooCommerce oder Yoast mehr: keine leeren Asset-Listen, kein erzeugtes `feature-config.php`, kein
Container-Build; der Entwicklerhinweis „requires files to be built“ erscheint nicht.

### Prüfskripte für beliebige Seiten

`bin/e2e/env.sh` (von jedem Skript eingebunden) hält Seite, Datenbank, URL, Log-Ordner und Plugin-Art; die Standardwerte
sind die erste Testseite, `bin/e2e/staging/env-staging.sh` setzt die Staging-Werte und legt `wp`/`wpms` für Staging
vor den Pfad. Mit `E2E_PLUGIN_MODE=zip` kommt das Plugin aus dem Release-ZIP: der Plugin-Tausch in `run-checks.sh`
(Löschen, Wiederherstellen) und das Upgrade in `upgrade.sh` laufen über `wp plugin install <zip> --force` (alte Fassung
`ecd352e` als eigenes ZIP), wie ein echtes Update. `E2E_HPOS=no` lässt den ersten Lauf auf klassischer Speicherung laufen.
Ergebnisse je Speicherart unter `/home/user/wp-e2e/logs-staging/hpos-yes|hpos-no/`.

Anpassung am Messverfahren: WooCommerce 11.3 vergibt den Mengenfeldern der Produktseite `quantity_<uniqid>` mit 13 statt
10 Hex-Zeichen; `frontend-diff.sh` glättet sie jetzt (im ersten Durchgang meldeten i1/i2 deshalb nur diese Zeile als
Unterschied).

### 1. Alle bisherigen Läufe, HPOS an und aus

Erster Lauf 198 / 199 (HPOS) und 198 / 199 (klassisch): offen nur `p-network-license` (Befund 5). Gegenüber 6.9.11 jetzt
bestanden: `p-network-plugins` (die Netzwerk-Pluginseite fragt wordpress.org für „Requires Plugins“ ohne Warnung,
Befund 6 war Umgebung). Zweiter Lauf 84 / 84 / 84 / 84, dritter Lauf 101 / 101 / 101 / 101. Im Fehlerprotokoll (`WP_DEBUG`) kein Eintrag
von Workflow Captain; einziger fremder Eintrag ein `_load_textdomain_just_in_time`-Hinweis von WooCommerce beim
Aktivieren per WP-CLI (tritt ohne Workflow Captain genauso auf).

### 2. Checkout-Block mit echten Bestellungen

Theme Twenty Twenty-Five, die von WooCommerce angelegte Kassenseite mit dem Checkout-Block, ein Produkt (39,90 EUR),
kostenloser Versand nach Deutschland, Überweisung und Nachnahme aktiv, Gast-Bestellung. Drei Workflows:
`order.created` → Markierung, `order.status_changed` → Notiz, `order.paid` → Mail an den Betreiber + Markierung.
Bestellt wurde im Browser (Chromium 153), Name, Adresse, Telefon und E-Mail in die Felder getippt.

| Fall | Ereignisse | Läufe |
| --- | --- | --- |
| Block, Überweisung; danach setzt der Shop-Manager im Bestellbildschirm „In Bearbeitung“ | `created`, `status_changed` on-hold, `status_changed` processing | 1 + 2, alle erfolgreich |
| Block, Nachnahme | `created`, `status_changed` processing | 1 + 1 |
| Block, Gutschein 100 % (nichts zu zahlen) | `created`, `status_changed` processing, `paid` | 1 + 1 + 1, Mail „Checkout 213 bezahlt: 0 EUR ()“ |
| Shortcode `[woocommerce_checkout]`, Überweisung (Vergleich) | `created`, `status_changed` on-hold | 1 + 1 |
| Block, Adresse getippt, nicht bestellt | keine | keine |

`order.created` trägt beim Block-Checkout `created_via` = `store-api`, beim Shortcode `checkout`, Status `pending`,
Betrag und Artikelzahl, keine Kundendaten. In keiner `workflow_captain_*`-Tabelle steht etwas von den getippten Daten
(E-Mail, Vor- und Nachname, Adresse, Adresszusatz, Telefon, Ort, PLZ; `co-pii`). Keine JS-Fehler oder fehlgeschlagenen
Anfragen des Plugins auf Warenkorb, Kasse, Bestätigung und Bestellbildschirm.

Befunde (kein Code-Fehler, Spezifikation):

- **`order.paid` bei Überweisung und Nachnahme:** WooCommerce ruft `payment_complete()` bei diesen Zahlarten nie auf –
  auch nicht, wenn der Shop-Manager die Bestellung auf „In Bearbeitung“ setzt. Ein Workflow auf „Bestellung bezahlt“
  startet bei Überweisung und Nachnahme also nie; er startet bei Gateways, die die Zahlung melden, und bei
  Gratisbestellungen. Das entspricht 4.7 (Hook `woocommerce_payment_complete`), ist für Betreiber aber überraschend.
  Frage an den Inhaber: so lassen und im Builder/der Hilfe sagen („für Überweisung den Statuswechsel auf In Bearbeitung
  nehmen“) oder `order.paid` zusätzlich beim ersten Wechsel auf processing/completed mit gesetztem `date_paid` auslösen
  (Spezifikationsänderung). Empfehlung: Hinweis im Builder jetzt; die Erweiterung nur mit Entscheidung.
- **Statuswechsel aus dem Entwurf:** Der Block-Checkout legt die Bestellung als `checkout-draft` an und wechselt beim
  Absenden über `pending` sofort weiter (on-hold/processing). Das Ereignis „→ pending“ (`from_status` = `checkout-draft`)
  wird beim Gegenlesen verworfen (`reread_mismatch`, R-7.4) – gewollt. Der Shortcode-Checkout erzeugt dieses Ereignis
  nicht (Bestellung entsteht direkt als `pending`).
- **Befund 3 in echt:** Im ersten Durchgang setzte das Skript die Überweisung auf „In Bearbeitung“, bevor der Worker
  lief; das ältere „on-hold“-Ereignis wurde verworfen, der Workflow lief dafür nicht. Das ist der in „Zweiter Lauf“,
  Teil 6 beschriebene Fall, hier mit einem echten Admin-Klick. Das Skript lässt den Worker jetzt vorher laufen (wie der
  System-Cron jede Minute; im echten Shop kommt die Überweisung Tage später).
- WooCommerce 11.3 behält für einen abgebrochenen Block-Checkout keine Entwurfs-Bestellung; deshalb entsteht dort kein
  Ereignis.

### 3. Yoast SEO 28.8 mit JS (WF-SEO-08, WF-SEO-07)

`seo.js` mit den gebauten Yoast-Skripten und dem Classic-Editor-Plugin 1.7.0 von wordpress.org:

- Block-Editor: Yoast-Seitenleiste öffnet mit Fokus-Keyphrase „testfokus“, SEO-Analyse und Lesbarkeit; der Yoast-Store
  hält SEO-Titel und Beschreibung (s13). Nach Veröffentlichen und Workflow-Lauf zeigt die Seitenleiste dieselben Werte
  (s14); Yoast-Metadaten, Beitragszeile und Indexable unverändert (s4).
- Classic Editor: Metabox mit Keyphrase, SEO-Analyse und Snippet mit der Beschreibung (s15), nach dem Lauf gleich (s16);
  Metadaten unverändert (s9); „Aktualisieren“ startet nichts erneut (s10); privater Beitrag startet nichts (s11).
- Kein unbehandelter JS-Fehler in beiden Editoren mit Yoast, WooCommerce und Workflow Captain aktiv (s17); Workflow
  Captain lädt dort kein Skript (s1, s6).
- Yoast schreibt beim Speichern im Block-Editor eigene Bewertungsfelder (`_yoast_wpseo_content_score`, `…_linkdex`,
  `…_meta_description_score`, `…_seo_title_score`, Lesezeit, Hauptkategorie) – vom Editor, nicht vom Workflow; der
  Vergleich vor/nach dem Lauf ist gleich.
- Frontend, Feed, REST, Yoast-Sitemaps und `yoast/v1/get_head` mit und ohne Workflow Captain gleich, keine Erwähnung in
  öffentlichen Antworten (i2, i3-2), mit HPOS und klassisch.

### 4. Oberfläche auf WordPress 7.1.4 (WF-SET-15, WF-BUILDER-09)

axe-core 4.10.3 (WCAG 2.2 AA) und Tastatur mit den Admin-Styles von 7.1.4: 15 Seiten bei 1280 und 390 px ohne Verstoß
und ohne seitliches Scrollen, Builder-Tastatur (x-…, 53 Prüfungen), lineare Ansicht und Fokus (y1–y10), neue Seiten des
dritten Laufs bei 1280 und 390 px (v…, x…). Keine Abweichung zu 6.9.11, kein Fix nötig. Ein echter Screenreader war
weiterhin nicht verfügbar.

### 5. Multisite (staging-ms, :8091)

Netzwerkinstallation auf frischer Datenbank, Workflow Captain per `wpms plugin install <zip>` und
`plugin activate --network`: Tabellen, Schema und Rechte je Website (k1), neue Website sofort mit Tabellen (k2, k3),
Löschen der Website entfernt ihre Tabellen (k4), Netzwerk-Deinstallation entfernt alle (k5), Fehlerprotokoll sauber
(k6); Upgrade einer Unterwebsite mit verwaister Sperre (u14, u15); Pro netzwerkweit (p-…, außer `p-network-license`).

### 6. WordPress.org-Slug „workflow-captain“

`https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=workflow-captain` →
`{"error":"Plugin not found."}`; ebenso `workflowcaptain` und `workflow-captain-pro`. Gegenprobe: ein geschlossenes
Plugin (`display-widgets`) liefert `{"error":"closed", … "closed":true}` – der Slug ist also weder vergeben noch
geschlossen. `https://wordpress.org/plugins/workflow-captain/` leitet auf die Suche um; die Suche nach
„workflow captain“ findet kein Plugin dieses Namens (nur „Captain …“-Plugins anderer Anbieter). Nicht öffentlich prüfbar:
Einreichungen in der Prüfwarteschlange; reserviert ist der Slug erst mit der eigenen Einreichung.
(`plugins.svn.wordpress.org` ist im Container nicht freigegeben.)

### 7. readme.txt

`Tested up to: 7.1` (alle Kernprüfungen auf 7.1.4 grün). `Requires at least: 6.4` bleibt: auf WordPress 6.4.5 mit
WooCommerce 8.9.0 (die Mindestversionen des Gutschein-Workflows, Job `minimum-versions`) bestehen Aktivierung,
Provider, Bestellablauf mit HPOS und klassisch und weitere Ereignisse (52 von 52) sowie alle Admin-Seiten (34 von 34);
dazwischen geprüft 6.9.11 (erste drei Läufe). Die Mindestversions-Seite lag unter `/home/user/wp-e2e/staging-min`
(Datenbank `wp_staging_min`, Server nur für die Prüfung auf :8092 gestartet und wieder beendet).

### Neustart

- Nach einem Container-Neustart: `bin/e2e/staging/setup.sh` (startet MariaDB und die Server :8090/:8091, baut nichts
  neu). Frisch: `E2E_FRESH=1 bin/e2e/staging/setup.sh` (löscht `wp_staging`, `wp_staging_ms` und beide Ordner).
- Danach jedes Skript mit `. bin/e2e/staging/env-staging.sh` davor, z. B. `node bin/e2e/staging/checkout.js`.
- Admin `admin` / `admin-e2e-pass`; WP-CLI `/home/user/wp-e2e/bin-staging/wp` und `wpms`; ZIP unter
  `/home/user/wp-e2e/staging-zip/`; Logs und Ergebnisse `/home/user/wp-e2e/logs-staging/`, Screenshots
  `/home/user/wp-e2e/shots-staging/`.
- Zustand: `staging` hält die Daten des letzten Laufs (klassische Speicherung, Checkout-Bestellungen), Yoast und Classic
  Editor installiert, aber inaktiv (die Skripte schalten sie selbst ein); `staging-ms` Netzwerk mit „zwei“, Workflow
  Captain netzwerkweit aktiv, Pro inaktiv. Die Seiten :8080–:8086 blieben unberührt.

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

## Ergebnisse dritter Lauf (`bin/e2e/run-third.sh`)

| ID | Prüfung | Ergebnis | Detail |
| --- | --- | --- | --- |
| u0 | old version (second run, 0.2.0, no Migrator class) stored workflows, events and runs | **PASS** | {"runs":["succeeded","succeeded","succeeded"],"events":13,"causes":0,"workflows":1,"versions":1,"steps":9,"tags":3,"notes":0} |
| u1 | first WP-CLI call after the update: status shows schema 4 (expected 4) | **PASS** | Plugin 0.2.0, schema 4 (expected 4), adapter interface 0.1.0 Adapters: workflow-captain, wordpress, woocommerce Events: received=1 processing=0 done=12 retry... |
| u2 | after the update: rows, table definitions and schema option unchanged, no upgrade lock left | **PASS** | {"events":13,"causes":0,"workflows":1,"versions":1,"runs":3,"steps":9,"tags":3,"notes":0} |
| u3 | the workflow published with the old version keeps working after the update (new order -> run succeeded) | **PASS** | ["succeeded","succeeded","succeeded","succeeded"] |
| u5 | diagnostics: schema 4/4 current, upgrade lock free | **PASS** | {"schema":"4","schema_expected":"4","schema_state":"current","upgrade_lock":"free"} |
| u4 | verify after the update | **PASS** | Success: No inconsistencies found. |
| u6 | migrate on the current schema: success, no data step, no warning | **PASS** | Success: Schema 4 in place. |
| u7a | interrupted upgrade: a front-end request does not migrate, the next wp-admin request does | **PASS** | front end: 3, after wp-admin: 4 |
| u7 | upgrade from schema 3 finished: index and table back, version 4, no row lost, lock released | **PASS** | {"index":2,"version":"4","lock":false,"events":17,"causes":0,"workflows":1,"versions":1,"runs":4,"steps":12,"tags":4} |
| u9 | lock held: migrate refuses with "Another request is upgrading", nothing changed | **PASS** | Error: Another request is upgrading this site right now. Try again in a few minutes; an upgrade that stopped half-way is taken over after 600 seconds. |
| u8 | lock held by another request: schema stays 3, diagnostics say "upgrade"/"held", an order paid meanwhile is stored but not processed | **PASS** | {"schema_state":"upgrade","lock":"held","event":"received"} |
| u10 | lock held: process refuses (schema does not match), nothing processed | **PASS** | Error: The stored schema does not match this plugin version; run "wp workflow-captain migrate" (or install the newer version after a downgrade). Nothing was ... |
| u8b | lock held: Settings > Status shows "Aktualisierung der Datenbank: läuft gerade" and schema 3 / 4 | **PASS** | Aktualisierung der Datenbank	läuft gerade |
| u10b | stale lock: migrate takes it over, warns that an earlier upgrade was repeated, success | **PASS** | Upgraded from schema 3 to 4. Warning: An earlier upgrade had stopped half-way; it was repeated. Success: Schema 4 in place.  |
| u10c | upgrade from schema 3 finished: index and table back, version 4, no row lost, lock released | **PASS** | {"index":2,"version":"4","lock":false,"events":21,"causes":0,"workflows":1,"versions":1,"runs":4,"steps":12,"tags":4} |
| u11 | audit log has the entry "upgrade_resumed" with from/to | **PASS** | {"at":"2026-11-16T19:15:28Z","user":0,"what":"upgrade_resumed","detail":{"from":"3","to":"4"}} |
| u12 | after the upgrade the order paid during the lock is processed | **PASS** | done |
| u13 | verify after the resumed upgrade | **PASS** | Success: No inconsistencies found. |
| u14 | multisite: site "zwei" with schema 3 and a stale lock is upgraded by migrate --url (table back, version 4), main site untouched | **PASS** | Upgraded from schema 3 to 4. Warning: An earlier upgrade had stopped half-way; it was repeated. Success: Schema 4 in place.  version=4 notes=1 main=4 |
| u15 | multisite PHP error log has no entry from Workflow Captain | **PASS** | clean |
| d3-1 | more than 120 runs, every run state present | **PASS** | {"action_required":3,"cancelled":2,"failed":2,"paused":2,"running":2,"stopped":3,"succeeded":228} |
| d3-2 | open states come from the engine: overdue, unclear_outcome, condition_field_missing (action_required), action_unavailable (paused), condition_field_missing (stopped) | **PASS** | ["action_required:condition_field_missing","action_required:overdue","action_required:unclear_outcome","paused:action_unavailable","stopped:condition_field_m... |
| d3-3 | events of WooCommerce, WordPress and the schedule adapter, more than 100 (paging) | **PASS** | {"woocommerce":241,"wordpress":89,"workflow-captain":1} |
| d3-4 | one order.paid event started one run per active order.paid workflow (three), all sharing its correlation ID | **PASS** | cdd9ed8f-95f2-4e39-b5e1-dbef04d2a6bb -> 3 |
| d3-5 | the daily schedule fired once (last fired stored), weekly one has not yet | **PASS** | [9] |
| d3-6 | no personal data of the orders in any workflow_captain_* table | **PASS** | 550263 bytes scanned |
| v1 | workflow list: columns "Letzter Lauf" and "Fehler (30 Tage)"; last run with time, state and link | **PASS** | {"heads":["Name","Zustand","Auslöser","Version","Letzter Lauf (UTC)","Fehler (30 Tage)"],"paidRow":"Bestellung bezahlt: Betreiber informieren Exportieren\tAk... |
| v2 | workflow list: the workflow with action_required runs shows its error count (linked to its runs) and its last state in the warning style | **PASS** | ["Statuswechsel: Feld fehlt (Fehler) Exportieren","Aktiv","woocommerce.order.status_changed","1","2026-11-16 19:16:00 Aktion erforderlich","1"] |
| v3 | workflow list: a draft without runs shows "–" (with screen reader text) and 0 errors | **PASS** | ["Entwurf: Bewertung anfragen Exportieren","Entwurf","woocommerce.order.status_changed","–","–\nkeine Angabe","0"] |
| x3-workflows-1280 | axe (WCAG 2.2 AA) workflow list at 1280 px: no violation in Workflow Captain markup | **PASS** | none |
| v4 | workflow list pages after 50 entries (WP_List_Table markup, "2 von 2") | **PASS** | {"wfTotal":58,"p1":50,"p2":8} |
| v5 | runs: all 242 runs counted, 50 per page, "1 von 5" | **PASS** | {"allLink":"Alle (242)","pages":"1 von 5"} |
| x3-runs-1280 | axe (WCAG 2.2 AA) runs list at 1280 px: no violation in Workflow Captain markup | **PASS** | none |
| v6 | runs: next page with the keyboard; page 2 continues below page 1 (newest first, no overlap) | **PASS** | {"first":"193","second":"192"} |
| v6b | runs: last page holds the rest | **PASS** | 42 |
| v7 | runs: every state filter shows only runs of that state, as many as stored (current link marked aria-current) | **PASS** | {"running":[2,"Läuft",2],"action_required":[3,"Aktion erforderlich",3],"paused":[2,"Pausiert",2],"failed":[2,"Fehlgeschlagen",2],"succeeded":[50,"Erfolgreich... |
| v8 | runs: workflow filter (notice, only that workflow, paging keeps the filter) | **PASS** | {"wfNotice":"Nur Läufe mit: Workflow „Bestellung bezahlt: Betreiber informieren“. Filter entfernen","wfNames":["Bestellung bezahlt: Betreiber informieren"],"... |
| v8b | runs: workflow and state filter together | **PASS** | ["Fehlgeschlagen","Fehlgeschlagen"] |
| v9 | runs: correlation filter shows the three runs of one order.paid event (three workflows) | **PASS** | ["Große Bestellung (Bedingung mit Gruppe)","Bestellung bezahlt: Betreiber informieren","Alt: Bestellung bezahlt"] |
| v9b | runs: a correlation that is no UUID is ignored (all runs, no notice) | **PASS** | "" |
| v10 | runs: "letzte 30 Tage" chosen and sent with the keyboard (Tab to "Filtern", Enter) counts 212 of 242 runs | **PASS** | {"url":"http://127.0.0.1:8080/wp-admin/admin.php?page=workflow-captain-runs&period=30&from&to","shown":212} |
| v11 | runs: own dates win over the preset, reversed dates are swapped, the state filter stays | **PASS** | {"swapped":10,"inRange":10,"oldest":"2026-09-13","oldEnd":"2026-09-22"} |
| v12 | run view: correlation with links to runs and events, timeline with every step and time elements, redacted data (catalog fields, no personal data) | **PASS** | {"meta":"Angaben zum Lauf\nWorkflow\tGroße Bestellung (Bedingung mit Gruppe) (alle Läufe dieses Workflows)\nVersion\t1\nEreignis\twoocommerce.order.paid\nObj... |
| x3-run-1280 | axe (WCAG 2.2 AA) run view at 1280 px: no violation in Workflow Captain markup | **PASS** | none |
| v13 | run export: JSON file with run, timeline and redacted event, no personal data (billing data, admin mail), no user login | **PASS** | {"file":"workflow-captain-run-216.json","keys":["format","format_version","exported_at","plugin","times","run","event","hidden_data_fields","steps"],"bytes":... |
| v14 | "Im Editor zeigen": the editor opens with the step's node marked and selected, the note in the inspector and an announcement | **PASS** | {"jumpNode":"small","focusNode":"small","live":["Knoten aus dem Lauf markiert: Markierung setzen","",""]} |
| v15 | run view: unclear outcome shows the confirmation box "Erneut senden?" (label 24 px high) and "Fortsetzen" | **PASS** | "" |
| v16 | events: 331 events, 50 per page with page navigation | **PASS** | 1 von 7 |
| x3-events-1280 | axe (WCAG 2.2 AA) events list at 1280 px: no violation in Workflow Captain markup | **PASS** | none |
| v16b | events: page 2 | **PASS** | "" |
| v17 | events: provider filter (notice, only wordpress.* events, counts of the filtered list) | **PASS** | {"wpTypes":["wordpress"],"wpCount":89} |
| v18 | events: state and provider filter together, paging keeps both | **PASS** | {"doneStates":["Erledigt"],"nextEv":"http://127.0.0.1:8080/wp-admin/admin.php?page=workflow-captain-events&state=done&product=woocommerce&paged=2"} |
| v19 | events: correlation filter shows the order.paid event of the three runs, link back to its runs | **PASS** | ["woocommerce.order.paid"] |
| v20 | overview: card "Läufe · 30 Tage" = 212, linked to the run list for 30 days | **PASS** | {"cardText":"Läufe · 30 Tage\n212","cardHref":"http://127.0.0.1:8080/wp-admin/admin.php?page=workflow-captain-runs&period=30"} |
| v21 | overview: provider status lists WooCommerce, WordPress and the schedule adapter as "angemeldet" with counts | **PASS** | ["WooCommerce\tangemeldet\t5 Ereignistypen · 0 Aktionen","WordPress\tangemeldet\t2 Ereignistypen · 0 Aktionen","Workflow Captain\tangemeldet\t1 Ereignistyp ·... |
| x3-overview-1280 | axe (WCAG 2.2 AA) overview at 1280 px: no violation in Workflow Captain markup | **PASS** | none |
| v22 | schedules page: three schedule workflows by name; weekly rule, next due a Monday 07:30 site time (UTC in datetime) | **PASS** | {"weekly":"Wochenbericht montags\tAktiv · Version 1\twöchentlich, Montag, 07:30\tMon, 23.11.2026 07:30\t–\nkeine Angabe","nextDt":"2026-11-23T06:30:00Z","nex... |
| v23 | schedules page: daily one with last fired time and link to its runs; paused monthly one says "keiner (pausiert)" | **PASS** | {"daily":"Täglicher Abgleich\tAktiv · Version 1\ttäglich, 20:14\tTue, 17.11.2026 20:14\tMon, 16.11.2026 20:14 (Läufe)","monthly":"Monatsabschluss\tPausiert ·... |
| x3-schedules-1280 | axe (WCAG 2.2 AA) schedules at 1280 px: no violation in Workflow Captain markup | **PASS** | none |
| v24 | status tab: "Aktualisierung der Datenbank: keine offen", schema 4 / 4 | **PASS** | Aktualisierung der Datenbank	keine offen |
| v25 | status tab: audit entries with readable labels (resumed upgrade, run export) | **PASS** | ["Letzte sicherheitsrelevante Änderungen","Zeit (UTC)\tBenutzer\tÄnderung\tEinzelheiten","2026-11-16T19:16:18Z\tadmin\tLauf exportiert\trun: 216","2026-11-16... |
| x3-status-1280 | axe (WCAG 2.2 AA) settings status at 1280 px: no violation in Workflow Captain markup | **PASS** | none |
| v26 | editor header: name, "Aktiv · Version 1 veröffentlicht", trigger with provider and versions, last run linked, test run and publish at the top | **PASS** | Große Bestellung (Bedingung mit Gruppe)  Aktiv · Version 1 veröffentlicht Auslöser woocommerce.order.paid von WooCommerce 9.9.5, Vertrag 9.9.5 Letzter Lauf: ... |
| v27 | origin on the node ("WOOCOMMERCE · AUSLÖSER") and in the inspector ("Herkunft: WooCommerce 9.9.5, Vertrag …") | **PASS** | {"kicker":"WOOCOMMERCE · AUSLÖSER","origin":"Herkunft: WooCommerce 9.9.5, Vertrag 9.9.5 · ID: start"} |
| v28 | condition form: the stored group is shown as a group (fieldset with its own AND/OR), field list grouped (envelope / event data) | **PASS** | ["Ereignis:6","Daten des Ereignisses:5"] |
| v29 | field list with example value from the sample event ("Beispiel: 9900", "Beispiel: bacs") | **PASS** | ["Beispiel: 9900","Beispiel: bacs","Beispiel: bacs"] |
| v30 | "+ Gruppe" with Enter: a second group, the focus is on the field of its first rule; a group offers no further "+ Gruppe" | **PASS** | select.wfc-rule-field Feld |
| v31 | focus kept after changing a field in the new group (no jump to the page start) | **PASS** | select.wfc-rule-field Feld |
| x3-builder-group-1280 | axe (WCAG 2.2 AA) builder with a condition group at 1280 px: no violation in Workflow Captain markup | **PASS** | none |
| v32 | drag from the palette onto the canvas adds the node where it was dropped | **PASS** | {"before":5,"after":6,"added":{"id":"delay1","type":"delay","unit":"hours","amount":1,"x":390,"y":30}} |
| v33 | warning sign on the unconnected node (badge with "!", text in the accessible name) | **PASS** | Warten: 1 Std.. Warnung: Der Ausgang ist nicht verbunden. Verbinden oder mit „Ende“ abschließen. Nicht mit dem Auslöser verbunden. |
| v34 | action of an add-on that is gone: node marked unavailable with the warning "Aktion nicht verfügbar" | **PASS** | woocommerce.e2e.mark. Warnung: Aktion nicht verfügbar: Der Provider fehlt oder ist nicht aktiv. |
| v35 | input fields of an add-on action (builder_fields): select "Farbe" with its options, number "Priorität", textarea "Kommentar", labelled and filled from the stored config | **PASS** | {"color":"red","prio":"2","comment":"Erstattung prüfen","options":["—","Rot","Grün"]} |
| v36 | add-on field changed, Tab moves on (focus kept), the graph holds the new value | **PASS** | textarea.large-text Erstattung prüfen |
| v37 | schedule preview: next three dues, each a Monday 07:30 in the site time zone, a week apart; "Zuletzt ausgelöst: noch nie." | **PASS** | ["Mo., 07:30","Mo., 07:30","Mo., 07:30"] |
| v38 | schedule form of the daily workflow: "Zuletzt ausgelöst:" with time and link to its runs | **PASS** | Zuletzt ausgelöst: Mon, 11/16/2026, 08:14 PM (Läufe dieses Workflows) |
| x3-builder-schedule-1280 | axe (WCAG 2.2 AA) builder with schedule preview at 1280 px: no violation in Workflow Captain markup | **PASS** | none |
| x3-workflows-390 | axe (WCAG 2.2 AA) workflows at 390 px: no violation in Workflow Captain markup | **PASS** | none |
| x3-workflows-390-overflow | workflows at 390 px: no horizontal page scroll | **PASS** | 0px |
| x3-runs-390 | axe (WCAG 2.2 AA) runs at 390 px: no violation in Workflow Captain markup | **PASS** | none |
| x3-runs-390-overflow | runs at 390 px: no horizontal page scroll | **PASS** | 0px |
| x3-run-390 | axe (WCAG 2.2 AA) run at 390 px: no violation in Workflow Captain markup | **PASS** | none |
| x3-run-390-overflow | run at 390 px: no horizontal page scroll | **PASS** | 0px |
| x3-events-390 | axe (WCAG 2.2 AA) events at 390 px: no violation in Workflow Captain markup | **PASS** | none |
| x3-events-390-overflow | events at 390 px: no horizontal page scroll | **PASS** | 0px |
| x3-overview-390 | axe (WCAG 2.2 AA) overview at 390 px: no violation in Workflow Captain markup | **PASS** | none |
| x3-overview-390-overflow | overview at 390 px: no horizontal page scroll | **PASS** | 0px |
| x3-schedules-390 | axe (WCAG 2.2 AA) schedules at 390 px: no violation in Workflow Captain markup | **PASS** | none |
| x3-schedules-390-overflow | schedules at 390 px: no horizontal page scroll | **PASS** | 0px |
| x3-status-390 | axe (WCAG 2.2 AA) status at 390 px: no violation in Workflow Captain markup | **PASS** | none |
| x3-status-390-overflow | status at 390 px: no horizontal page scroll | **PASS** | 0px |
| v39 | 390 px: time range form and page navigation of the runs visible (not hidden like tablenav top) | **PASS** | "" |
| v40 | 390 px: linear step view of the workflow with a group lists Dann/Sonst branches | **PASS** | ["Auslöser: woocommerce.order.paid","Wenn/Sonst","Dann: Nachricht an Betreiber","Ende","Sonst: Markierung setzen"] |
| v41 | 390 px: choosing the condition in the list opens it in the inspector with the focus on its heading; the group form fits the screen | **PASS** | {"mFocus":"h3 Wenn/Sonst","overflow":0} |
| x3-builder-linear-390 | axe (WCAG 2.2 AA) builder (linear view, condition group) at 390 px: no violation in Workflow Captain markup | **PASS** | none |
| x3-builder-linear-390-overflow | builder (linear view, condition group) at 390 px: no horizontal page scroll | **PASS** | 0px |
| v42 | 390 px: "Im Editor zeigen" opens the linear list with the step marked | **PASS** | ["Auslöser: woocommerce.order.paid","Wenn/Sonst","Dann: Nachricht an Betreiber","Ende","Sonst: Markierung setzen (aus dem Lauf)"] |
| v43 | no JavaScript error and no failed request of Workflow Captain on any page of this run | **PASS** | [] |
| i3-1 | front-end HTML identical active vs. deactivated (home, post, product, shop, feed, REST index, sitemap), with the data of this run | **PASS** | SAME / (86858 bytes) SAME /?p=198 (97320 bytes) SAME /?page_id=9 (75906 bytes) SAME /?post_type=product&p=191 (97466 bytes) SAME /?post_type=product (86622 b... |
| i3-2 | with Yoast SEO active: front end, XML sitemaps, REST and get_head identical with Workflow Captain active vs. deactivated, no Workflow Captain data in public responses | **PASS** | SAME / (89810 bytes) SAME /?p=198 (101235 bytes) SAME /?page_id=9 (78962 bytes) SAME /?post_type=product&p=191 (100796 bytes) SAME /sitemap_index.xml (2441 b... |
| h3-20 | PHP error log has no notice, warning or error from Workflow Captain during the whole run (WP_DEBUG) | **PASS** | 142 lines in debug.log, none from the plugin |

## Ergebnisse Staging (`bin/e2e/staging/`)

Vollständige Ergebnisse aller Läufe unter `/home/user/wp-e2e/logs-staging/hpos-yes|hpos-no/` (nicht im Repository). Hier die
neuen Prüfungen: Einrichtung, Checkout (HPOS an), Yoast mit JS (HPOS an), Mindestversionen (Zusammenfassung oben).

| ID | Prüfung | Ergebnis | Detail |
| --- | --- | --- | --- |
| st1 | release ZIP installed with --activate and updated with --force (as in the workflow) | **PASS** | Success: Installed 1 of 1 plugins. |
| st2 | release ZIP contains only shipped files (export-ignore) | **PASS** |  assets languages readme.txt src uninstall.php workflow-captain.php  |
| st3 | versions: WordPress / WooCommerce / Yoast SEO / PHP | **PASS** | 7.1.4 / 11.3.0 / 28.8 / PHP 8.3.6 |
| st4 | HPOS setting as in the workflow matrix | **PASS** | woocommerce_custom_orders_table_enabled=yes |
| co0 | setup: WooCommerce created the checkout page with the Checkout block; bank transfer and cash on delivery enabled; three workflows published | **PASS** | {"workflows":{"created":62,"status":63,"paid":64},"product":208,"blocks_page":8,"classic_page":210,"cart_page":7,"theme":"twentytwentyfive"} |
| co-blocks-bacs-0 | Checkout block, bank transfer: order placed (order-received page), manager sets "processing" on the order screen | **PASS** | {"order":"211","hpos":true,"saved":true} |
| co-blocks-bacs-1 | blocks-bacs: order placed in the browser, payment method bacs, created via store-api | **PASS** | {"status":"processing","method":"bacs","via":"store-api","total":"39.90"} |
| co-blocks-bacs-2 | blocks-bacs: events woocommerce.order.created, woocommerce.order.status_changed:on-hold, woocommerce.order.status_changed:processing (each once) | **PASS** | {"events":["woocommerce.order.created","woocommerce.order.status_changed:pending","woocommerce.order.status_changed:on-hold","woocommerce.order.status_change... |
| co-blocks-bacs-3 | blocks-bacs: order.created envelope valid, with status, items, total and channel of the placed order, no customer data | **PASS** | {"status":"pending","currency":"EUR","total_minor":3990,"item_count":1,"created_via":"store-api","customer_user_id":null} |
| co-blocks-bacs-4 | blocks-bacs: every expected event processed to done | **PASS** | [] |
| co-blocks-bacs-5 | blocks-bacs: runs succeeded (created 1, status 2, paid 0) | **PASS** | {"created":["succeeded"],"status":["succeeded","succeeded"],"paid":[]} |
| co-blocks-cod-1 | blocks-cod: order placed in the browser, payment method cod, created via store-api | **PASS** | {"status":"processing","method":"cod","via":"store-api","total":"39.90"} |
| co-blocks-cod-2 | blocks-cod: events woocommerce.order.created, woocommerce.order.status_changed:processing (each once) | **PASS** | {"events":["woocommerce.order.created","woocommerce.order.status_changed:pending","woocommerce.order.status_changed:processing"],"discarded_on_reread":["wooc... |
| co-blocks-cod-3 | blocks-cod: order.created envelope valid, with status, items, total and channel of the placed order, no customer data | **PASS** | {"status":"pending","currency":"EUR","total_minor":3990,"item_count":1,"created_via":"store-api","customer_user_id":null} |
| co-blocks-cod-4 | blocks-cod: every expected event processed to done | **PASS** | [] |
| co-blocks-cod-5 | blocks-cod: runs succeeded (created 1, status 1, paid 0) | **PASS** | {"created":["succeeded"],"status":["succeeded"],"paid":[]} |
| co-blocks-free-1 | blocks-free: order placed in the browser, payment method , created via store-api | **PASS** | {"status":"processing","method":"","via":"store-api","total":"0.00"} |
| co-blocks-free-2 | blocks-free: events woocommerce.order.created, woocommerce.order.status_changed:processing, woocommerce.order.paid (each once) | **PASS** | {"events":["woocommerce.order.created","woocommerce.order.status_changed:pending","woocommerce.order.status_changed:processing","woocommerce.order.paid"],"di... |
| co-blocks-free-3 | blocks-free: order.created envelope valid, with status, items, total and channel of the placed order, no customer data | **PASS** | {"status":"pending","currency":"EUR","total_minor":0,"item_count":1,"created_via":"store-api","customer_user_id":null} |
| co-blocks-free-4 | blocks-free: every expected event processed to done | **PASS** | [] |
| co-blocks-free-5 | blocks-free: runs succeeded (created 1, status 1, paid 1) | **PASS** | {"created":["succeeded"],"status":["succeeded"],"paid":["succeeded"]} |
| co-blocks-free-6 | blocks-free: paid workflow mail with rendered subject, tag set | **PASS** | Checkout 213 bezahlt: 0 EUR () |
| co-classic-bacs-1 | classic-bacs: order placed in the browser, payment method bacs, created via checkout | **PASS** | {"status":"on-hold","method":"bacs","via":"checkout","total":"39.90"} |
| co-classic-bacs-2 | classic-bacs: events woocommerce.order.created, woocommerce.order.status_changed:on-hold (each once) | **PASS** | {"events":["woocommerce.order.created","woocommerce.order.status_changed:on-hold"],"discarded_on_reread":[]} |
| co-classic-bacs-3 | classic-bacs: order.created envelope valid, with status, items, total and channel of the placed order, no customer data | **PASS** | {"status":"pending","currency":"EUR","total_minor":3990,"item_count":1,"created_via":"checkout","customer_user_id":null} |
| co-classic-bacs-4 | classic-bacs: every expected event processed to done | **PASS** | [] |
| co-classic-bacs-5 | classic-bacs: runs succeeded (created 1, status 1, paid 0) | **PASS** | {"created":["succeeded"],"status":["succeeded"],"paid":[]} |
| co-draft | abandoned block checkout (address typed, order not placed): no WooCommerce event, so no run (WooCommerce 11.3 keeps no draft order for it) | **PASS** | {"new_events":[],"draft_orders":[]} |
| co-pii | no personal data typed into the block and classic checkout (e-mail, names, address, phone, city, postcode) in any workflow_captain_* table | **PASS** | 600955 bytes searched |
| co-js | no JS error or failed request of Workflow Captain on cart, checkout, order-received and the order screen | **PASS** |  |
| s13 | block editor with the built Yoast JS: Yoast sidebar opens with focus keyphrase, SEO analysis and readability; editor store holds the saved Yoast fields | **PASS** | {"sidebar0":{"keyphraseField":"testfokus","seoAnalysis":true,"readability":true},"store0":{"keyphrase":"testfokus","title":"SEO Titel SEO Block %%sep%% %%sit... |
| s14 | block editor after the workflow run: the Yoast sidebar shows the same focus keyphrase, SEO title and description as before | **PASS** | {"sidebar1":{"keyphraseField":"testfokus","seoAnalysis":true,"readability":true},"store1":{"keyphrase":"testfokus","title":"SEO Titel SEO Block %%sep%% %%sit... |
| s15 | classic editor with the built Yoast JS: metabox shows focus keyphrase, SEO analysis and the snippet with the saved description | **PASS** | {"keyphrase":"testfokus","analysis":true,"preview":true} |
| s16 | classic editor after the workflow run: the metabox shows the same focus keyphrase, analysis and snippet | **PASS** | {"keyphrase":"testfokus","analysis":true,"preview":true} |
| s17 | no uncaught JS error at all in the block editor and the classic editor with Yoast, WooCommerce and Workflow Captain active | **PASS** |  |

## PHP 8.5 (23.11.2026)

Umgebung: PHP 8.5.13 aus dem Quellcode gebaut (`/opt/php85`, mysqli, curl, openssl, zip, gd, intl), WordPress 7.1.4 und WooCommerce 11.3.0 von wordpress.org, MariaDB 10.11 (älter als die Empfehlung MySQL 9.7/MariaDB 12.3, daher nur Hinweis), WP-CLI 2.12.0, Workflow Captain aus dem Release-ZIP (`git archive`). Ohne Browser.

| Lauf | Ergebnis |
|---|---|
| `bin/e2e/ci-db.sh install` und `checks` (Aktivierung, Provider, Bestellablauf mit HPOS, weitere WooCommerce-/WordPress-Ereignisse, Action Scheduler, Zeitplan, System-Cron-Pfad, Notfallpause, Dead Letter, CLI, Migrationen) | 96 von 97; offen nur `ci3-db`: Website-Zustand meldet für MariaDB 10.11 erwartungsgemäß „Update empfohlen“ (`old_server`) |
| `bin/e2e/upgrade-gate.sh` (neu): Vorversion `f36da2d` (Schema 3) aus ZIP, Workflow „Bestellung bezahlt → Markierung“, Update per ZIP, Frontend migriert nicht, erster Admin-Aufruf migriert auf Schema 4, `verify` ohne Befund, alle Zeilen erhalten, neue Bestellung läuft durch den alten Workflow; Downgrade: Schema bleibt 4, Worker verarbeitet nichts, Hinweis im Admin, nichts gelöscht; erneutes Update: die während des Downgrades erfasste Bestellung wird nachgeholt | 15 von 15 |
| `debug.log` | keine Meldung aus Workflow Captain, WooCommerce oder WordPress (nur Deprecations von WP-CLI selbst) |
| Plugin Check 2.2.0 | ohne Fehler und Warnungen (vorher 23 Fehler, 34 Warnungen; behoben im selben Commit) |

Befund am Rand: Ein nicht angemeldeter Aufruf von `/wp-admin/` startet die Migration ebenfalls (`is_admin()` ist vor der Anmeldeprüfung wahr). Die Migration ist gesperrt und wiederholbar, deshalb unkritisch; die Zusage „im Admin, nicht im Frontend“ gilt.
