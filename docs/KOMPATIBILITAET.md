# Kompatibilitätsmatrix der Provider

Stand: 23.11.2026 (Provider-Matrix nach Prüfung aller Suite-Repositories; Mindestversionen MINVER-1 vom 17.11.2026), zu [`SPEC-suite-events.md`](SPEC-suite-events.md) Version 1.0.0-draft.8, Workflow Captain 0.2.x und Workflow Captain Pro 0.1.0 (Entwicklungsstand `captain-pro` mit Gutschein-Befehlen und Form-Adapter). Aufgabe in [`../TODO.md`](../TODO.md): „Kompatible Versionen der einzelnen Captain-Provider je Plugin in einer Matrix veröffentlichen“. Verlinkt aus `readme.txt` (FAQ „Which versions of the Captain plugins work together?“).

Ein Adapter meldet sich nur an, wenn die Vertragskonstante des Kerns mindestens die Mindestversion hat und dieselbe MAJOR-Version trägt (R-3.3, R-13.4). Die Plugin-Version allein entscheidet nicht. Alle Angaben sind am Code gelesen; „Branch“ heißt: noch nicht nach `main` gemergt.

## Aktuelle Matrix

Prüfung am 23.11.2026 an allen Suite-Repositories (nur gelesen). Spalte „`main`“ ist der veröffentlichte Stand, Spalte „Branch“ der weiteste ungemergte Stand.

| Provider | `main` | Weitester Branch | Adapter verlangt (Workflow Captain Pro) | Was der Stand bringt | Adapter |
|---|---|---|---|---|---|
| WordPress | – | – | WordPress ≥ 7.0 (MINVER-1) | `wordpress.user.registered`, `wordpress.post.published` | im kostenlosen Kern |
| WooCommerce | – | – | WooCommerce ≥ 11.2.2 (MINVER-1, `src/Requirements.php`; darunter auf der Seite „Provider“ „abgelehnt – provider_too_old“), HPOS und klassische Bestelltabellen | `woocommerce.order.*` (5 Typen) | im kostenlosen Kern |
| Workflow Captain (Zeitplan, eingehende Auslöser) | Kern 0.2.0, `WORKFLOW_CAPTAIN_CONTRACT_VERSION` = `0.1.0` | – | – | `workflow.schedule.due`; `workflow.inbound.<auslöser>` nur, wenn ein Add-on Auslöser anmeldet | im kostenlosen Kern |
| Pass Captain | `b2b88ea`, Plugin 1.4.0, **keine** Vertragskonstante; `wpcap_event_cancelled` und `wp_pass_captain_invoice_created_v1` fehlen – **genügt nicht** | zwei Linien, die sich widersprechen: `claude/plugin-todo-liste-2a83v4` `453f9cc` (Plugin 1.6.0, `WPCAP_CONTRACT_VERSION` = `1.2.0` = Lesefassade) und `claude/woocommerce-ticket-plugin-8q4m8q` `0efe8a4` (Plugin 1.5.0, `1.2.0` = `service_date`, `1.3.0` = Lesefassade) | `1.1.0` (Rückfall, veraltet); Lesefassade `wpcap_get_*_state()` ab `1.2.0` – der `PassAdapter` prüft zusätzlich, ob die Fassaden-Funktionen existieren, und fängt so die doppelte Bedeutung von `1.2.0` ab | 13 Ereignistypen, Lesefassade | Pro, `PassAdapter` |
| Voucher Captain | `151786c`, Plugin 1.2.0, **keine** Vertragskonstante (nur `VCAP_VERSION`); enthält noch den vorläufigen Workflow-Provider (D13, VC-5) – **genügt nicht** | `claude/wp-plugin-analyse-todo-hvollo-refactors` `fa42cf5` (Pull Request #19, offen): Plugin 1.3.0, `VCAP_CONTRACT_VERSION` = `1.4.0` (1.2.0 Lesefassade, 1.3.0 Befehle, 1.4.0 Connector-Zugriff auf `vcap()->api_v1`), Provider entfernt | `1.1.0` (Rückfall, veraltet); Lesefassade `vcap_read_*()` ab `1.2.0`; Gutschein-Befehle ab `1.3.0` | 16 Ereignistypen, Lesefassade, Einstiegspunkte für die Befehle (R-8.3) | Pro, `VoucherAdapter` (Ereignisse); die Befehle als Workflow-Aktionen fehlen in Pro noch (WF-INT-03) |
| Affiliate Captain | `930b6b1`, Plugin 0.17.0, `WPAC_CONTRACT_VERSION` = `1.6.0` (1.5.0 Partnerprogramme, 1.6.0 „Seit“-Tabelle und Lese-Aggregate; additiv) | – (Branch `claude/plugin-todo-liste-2a83v4` mit 0.15.0/1.4.0 ist überholt) | `1.4.0` | 21 Ereignistypen; `wpac_affiliate_program_changed` (seit 1.5.0) ist nicht im Katalog | Pro, `AffiliateAdapter` |
| Lizenzserver (nur im Shop des Anbieters) | `ec9220d` (Pull Request #48), Plugin 0.2.0, `WPLC_CONTRACT_VERSION` = `1.0.0`, `WPLC\Events\LicenseReader`, vier neue Actions (reserviert, nicht im Katalog) | – | `1.0.0` mit Lesefunktion `LicenseReader::get()`; der Rückfall ohne Konstante über `PlanChange::get_license()` ist für `main` überholt | 3 Ereignistypen | Pro, `LicenseAdapter` |
| Form Captain | `59580d5`, Plugin 1.0.0-dev, `FORM_CAPTAIN_CONTRACT_VERSION` = `1.1.0` (`entries_imported_v1`, `consent_withdrawn_v1`); Mail-Übergabe entfernt, eigener Vertrag zurückgezogen | – | Major 1, mindestens `1.0.0`; der ganze Katalog `form.*` braucht `1.1.0` | Katalog `form.*` (8 Typen, 4.6.1, 4.6.2) | Workflow Captain Pro (`FormAdapter`), bleibt aus bis Form Captain Pro |
| Review Captain | `0818df0`, Plugin 0.1.0-dev, **keine** Vertragskonstante; Code und alle 3 Hooks vorhanden; `workflow-provider-v1.md` ohne Hinweis „nicht normativ“ | `claude/sleepy-gates-6mm217` `35af5e0` (6 Commits vor `main`, ohne Konflikt): `REVIEW_CAPTAIN_CONTRACT_VERSION` = `1.1.0`, Sterne (`rating_value`, `rating_scale_min`, `rating_scale_max`) im Payload ab `1.1.0`, Hinweis „Nicht normativ (F-12)“ | Major 1, mindestens `1.0.0`; ohne Konstante Rückfall über `REVIEW_CAPTAIN_VERSION` und `Events::SCHEMA` = `review_captain.review.v1` (R-3.3). Sterne ab `1.1.0` aus dem Payload, darunter über `reviews()->find_by_uuid()` ohne Vertrag (R-4.27) | Katalog `review.*` (3 Typen, 4.6.3); seit draft.9 Befehle `review.request.plan`/`.cancel` über `workflow()` und den Planer (R-4.30, F-23 = ja), `WorkflowService::CONTRACT` Major 1 | Workflow Captain Pro (`ReviewAdapter`), bleibt aus bis Review Captain Pro (captain-pro Pull Request 10) |
| Course Captain | `5af78c3`, nur Dokumente | `claude/blissful-curie-tuuf7q` `c45e891`: Code, `COURSE_CAPTAIN_CONTRACT_VERSION` = `0.1.0`; feuert bisher nur `course_captain_loaded_v1` | – (kein Katalog `course.*`, 4.6.4) | – | keiner |
| Eigene Connectoren | eigenes Plugin | BoatingSchool und LearnBoating: Branch `claude/plugin-todo-liste-2a83v4` (nicht auf `main`) | `contract` im Manifest (`CONNECTOR-CONTRACT.md`); Workflow Captain Pro als Laufzeit (Filter `workflow_captain_connector_runtime_allowed_v1`, F-18a); die eigene Lizenz prüft der Connector selbst | eigene Typen `x<präfix>.*` | im Kern, freigeschaltet durch Pro |

**Nachtrag 23.11.2026, 13:45 (nach Merges anderer Sitzungen):**

| Repository | `main` jetzt | Stand |
|---|---|---|
| `wp-couponmaster` | `b801e2e` (Pull Request #19 gemergt) | Plugin 1.3.0, `VCAP_CONTRACT_VERSION` = `1.4.0`, vorläufiger Provider entfernt – **kennt Workflow Captain** |
| `wp-reviewmaster` | `8c60c6f` (Pull Request #6 gemergt) | `REVIEW_CAPTAIN_CONTRACT_VERSION` = `1.1.0` mit Sternen im Payload, RC-1 bis RC-4 – **kennt Workflow Captain** |
| `captain-pro` | `a7cad7b` (Pull Request #14 gemergt) | Workflow Captain Pro 0.1.0 (zurückgehalten) mit allen Suite-Adaptern (Pass, Voucher, Affiliate, Lizenzserver, Form, Review), Netzwerk-Code und Tests – CP-3 erledigt; CP-1/CP-2 im Kernel weiter offen |
| `boatingschool` | `e27a7de` (Pull Request #128 gemergt) | `integrations/boatingschool-workflow-connector` – KC-1 erledigt |
| `wp-passmaster` | `b2b88ea` (unverändert) | offen: Pull Request #30 (`claude/woocommerce-ticket-plugin-8q4m8q`, Plugin 1.6.0, Vertrag 1.3.0, beide fehlenden Hooks, Lesefassade) wird von einer anderen Sitzung bearbeitet; enthält aber den eigenen Workflow-Provider mit Mail-Übergabe (`src/Workflow/*`, PC-7) |
| `wp-coursemaster` | `5af78c3` (unverändert) | offen: Pull Request #3 wird von einer anderen Sitzung bearbeitet; kein Katalog `course.*` (CC-1 bis CC-4) |
| `learnboating` | `9f37c77` (unverändert) | offen: Zusammenführungs-Branch `claude/loving-euler-c7h553` (450 Commits vor `main`, mit `learnboating-workflow-connector`) wird von einer anderen Sitzung bearbeitet, noch ohne Pull Request (KC-2) |

**Workflow Captain Pro** ist seit dem 23.11.2026 auf captain-pro `main` (`a7cad7b`, zurückgehalten); vorher nur auf dem Branch `claude/plugin-todo-liste-2a83v4`. Es verlangt Workflow Captain ≥ 0.2.0 (für netzwerkweite Abläufe Schema 4 mit `ForeignRuns`) und für jedes Captain-Produkt zusätzlich dessen gültiges Pro-Add-on (Spezifikation 10.1; der Lizenzserver hat keines).

**Ungeprüft:** ein Lauf der Adapter gegen genau diese Stände in einer echten WordPress-Installation (laut [`E2E-RESULTS.md`](E2E-RESULTS.md) liefen die Suite-Adapter von Pro dort nicht, weil Pro ohne Lizenz keine Funktion freigibt; Prüfung der Adapter bisher nur mit PHPUnit in `captain-pro`). Ältere Stände stehen unten als Verlauf.

## Verlauf: Stand 17.11.2026

| Provider | Gelesener Stand | Plugin | Vertragskonstante | Adapter verlangt (Workflow Captain Pro) | Was der Stand bringt | Adapter |
|---|---|---|---|---|---|---|
| WordPress | – | ab 7.0 | `$wp_version` steht für den Vertrag (4.7) | WordPress ≥ 7.0 (MINVER-1) | `wordpress.user.registered`, `wordpress.post.published` | im kostenlosen Kern |
| WooCommerce | – | ab 11.2 | `WC_VERSION` steht für den Vertrag (4.7) | WooCommerce ≥ 11.2 (MINVER-1; darunter auf der Seite „Provider“ „abgelehnt – provider_too_old“), HPOS und klassische Bestelltabellen | `woocommerce.order.*` (5 Typen) | im kostenlosen Kern |
| Workflow Captain (Zeitplan, eingehende Auslöser) | Kern 0.2.x | 0.2.0 | `WORKFLOW_CAPTAIN_CONTRACT_VERSION` = `0.1.0` (Adapter-Schnittstelle) | – | `workflow.schedule.due`; `workflow.inbound.<auslöser>` nur, wenn ein Add-on Auslöser anmeldet | im kostenlosen Kern |
| Pass Captain | `wp-passmaster` Branch `claude/plugin-todo-liste-2a83v4` `51da413` | 1.6.0 | `WPCAP_CONTRACT_VERSION` = `1.2.0` | `1.1.0` (Rückfall, veraltet); Lesefassade `wpcap_get_*_state()` ab `1.2.0` | 13 Ereignistypen, Lesefassade | Pro, `PassAdapter` |
| Voucher Captain | `wp-couponmaster` Branch `claude/plugin-todo-liste-2a83v4` `689ab4a` | 1.3.0 | `VCAP_CONTRACT_VERSION` = `1.3.0` | `1.1.0` (Rückfall, veraltet); Lesefassade `vcap_read_*()` ab `1.2.0`; Gutschein-Befehle ab `1.3.0` | 16 Ereignistypen, Lesefassade, Einstiegspunkte für die Befehle Ausstellen, Sperren, Reaktivieren, Gutschreiben (R-8.3) | Pro, `VoucherAdapter` (Ereignisse); die Befehle als Workflow-Aktionen fehlen in Pro noch (WF-INT-03) |
| Affiliate Captain | `wpaffiliatemaster` Branch `claude/plugin-todo-liste-2a83v4` `a3f20cc` | 0.15.0 | `WPAC_CONTRACT_VERSION` = `1.4.0` | `1.4.0` | 21 Ereignistypen | Pro, `AffiliateAdapter` |
| Lizenzserver (nur im Shop des Anbieters) | `wp-license` Branch `claude/zen-babbage-7q4nac` `caebdcc` | 0.2.0 | `WPLC_CONTRACT_VERSION` = `1.0.0` | `1.0.0` mit Lesefunktion `WPLC\Events\LicenseReader::get()`; ohne Konstante Rückfall über Plugin 0.2.0 und `PlanChange::get_license()` (veraltet) | 3 Ereignistypen | Pro, `LicenseAdapter` |
| Form Captain | `wp-formmaster` `fc1f206` | 1.0.0-dev | `FORM_CAPTAIN_CONTRACT_VERSION` = `1.0.0` | Major 1 | Katalog `form.*` seit Spezifikation 1.0.0-draft.7 (4.6.1, 4.6.2); eigener Workflow-Vertrag zurückgezogen (F-12) | Workflow Captain Pro (`FormAdapter`), bleibt aus bis Form Captain Pro |
| Review Captain | `wp-reviewmaster` `main` `863b92b`, Branch `claude/plugin-todo-liste-2a83v4` (Vertragskonstante) | 0.1.0-dev | `REVIEW_CAPTAIN_CONTRACT_VERSION` = `1.0.0` (nur Branch; auf `main` fehlt sie) | Major 1, mindestens `1.0.0`; ohne Konstante Rückfall über `REVIEW_CAPTAIN_VERSION` und `Events::SCHEMA` = `review_captain.review.v1` (R-3.3, veraltet, sobald die Konstante auf `main` ist). Sterne über `reviews()->find_by_uuid()` ohne Vertrag (R-4.27) | Katalog `review.*` (3 Typen) seit Spezifikation 1.0.0-draft.8 (4.6.3); keine Befehle (R-4.30, F-23); eigener Workflow-Vertrag nicht normativ (F-12) | Workflow Captain Pro (`ReviewAdapter`), bleibt aus bis Review Captain Pro (captain-pro Pull Request 10) |
| Course Captain | – | kein Code | – | – | – | keiner |
| Eigene Connectoren | eigenes Plugin | eigene | `contract` im Manifest (`CONNECTOR-CONTRACT.md`) | Workflow Captain Pro als Laufzeit (Filter `workflow_captain_connector_runtime_allowed_v1`, F-18a); die eigene Lizenz prüft der Connector selbst | eigene Typen `x<präfix>.*` | im Kern, freigeschaltet durch Pro |

## Verlauf: Stand 16.11.2026 (Vormittag)


| Provider | Gelesener Stand | Plugin-Version | Vertragskonstante | Mindestvertrag für den ganzen Katalog | Bekannte MAJOR | Belegt |
|---|---|---|---|---|---|---|
| Pass Captain | `wp-passmaster` `claude/sharp-mendel-xiiwf3` `dd32087` | 1.5.0 | `WPCAP_CONTRACT_VERSION` = `1.1.0` | `1.1.0` (`wpcap_event_cancelled`, `wp_pass_captain_invoice_created_v1` und die drei Argumente von `wpcap_order_cancelled` gibt es erst seit Plugin 1.5.0) | 1 | ja, am 16.11.2026 am Code und Git-Verlauf |
| Voucher Captain | `wp-couponmaster` `claude/sharp-mendel-xiiwf3` `ed7b791` | 1.2.0 | `VCAP_CONTRACT_VERSION` = `1.1.0` | `1.1.0`; die vier Gutschein-Befehle brauchen eine neue MINOR-Version (R-8.1), die es noch nicht gibt | 1 | ja, am 16.11.2026 am Code |
| Affiliate Captain | `wpaffiliatemaster` `claude/sharp-mendel-xiiwf3` `6600392`, `main` `2a77ed3` (gleiches `src/`) | 0.15.0 | `WPAC_CONTRACT_VERSION` = `1.4.0` | `1.4.0` (`creative.*`, `code_request.*`, `affiliate.recruited` seit 1.4.0) | 1 | ja, am 16.11.2026 am Code, an Tags und Git-Verlauf |
| Lizenzserver | `wp-license` `241ae7a`, `a113568`, `main` `c269e07` (gleiches `src/License/`) | 0.2.0 | fehlt auf allen Branches (F-03) | – („Vertrag unbekannt“, R-3.3; Ersatz über Plugin-Version 0.2.0, weil `plan_changed` erst dort kommt) | – | ja, am 16.11.2026 am Code |
| Form Captain | `wp-formmaster` `main` `6f4785f` | 1.0.0-dev | `FORM_CAPTAIN_CONTRACT_VERSION` = `1.0.0` (trotz zwei neuer Actions) | `1.0.0` (Katalog 4.6.1 seit 1.0.0-draft.7; alle acht Actions gibt es in 1.0.0) | 1 | ja, am 16.11.2026 am Code |
| Review Captain | `wp-reviewmaster` `claude/sleepy-gates-6mm217` `b35fb95` (`main` ohne PHP-Code) | 0.1.0-dev | fehlt | kein Katalog (4.6.3: vorgesehen, wartet auf RC-2 und F-08; `main` `33d1362` ohne Code) | – | ja, am 16.11.2026 am Code |
| Course Captain | – | kein Code | fehlt | kein Katalog in v1 (4.6) | – | – |

## Verlauf: Nachtrag 16.11.2026 (Nachmittag)

| Provider | Neuer Stand (Branch `claude/plugin-todo-liste-2a83v4`) | Vertrag | Was neu ist | Adapter verlangt |
|---|---|---|---|---|
| Pass Captain | `wp-passmaster` `8293d65` | `1.2.0` | Lesefassade `wpcap_get_*_state()` | ab `1.2.0` Lesefassade; `1.1.0` nur Rückfall (veraltet) |
| Voucher Captain | `wp-couponmaster` `fa1a66f` | `1.2.0` | Lesefassade `vcap_read_*()` | ab `1.2.0` Lesefassade; `1.1.0` nur Rückfall (veraltet) |
| Lizenzserver | `wp-license` `57f5b7b` | – | `WPLC\License\ReadFacade`, Rechte `workflow.custom_connectors` und `workflow.multisite_network` vorgemerkt | Fassade, wenn vorhanden; sonst `PlanChange::get_license()` |
| WordPress | im Kern | `$wp_version` | Adapter im kostenlosen Kern (Spezifikation 4.7) | WordPress ≥ 6.4 |
| WooCommerce | im Kern | `WC_VERSION` | Adapter im kostenlosen Kern, HPOS und klassisch | WooCommerce ≥ 8.2 |
| Workflow Captain Pro | `captain-pro` `1a49c97` | – | Suite-Adapter, Webhooks/n8n, netzwerkweite Abläufe; zurückgehalten bis zur Freigabe | Workflow Captain ≥ 0.2.0 mit Schema 4 (`ForeignRuns`) für Netzwerkabläufe |

Keiner dieser Branches ist gemergt. Die Plugin-Versionen von Pass Captain (1.5.0) und Voucher Captain (1.2.0) wurden nicht erhöht; Add-ons prüfen deshalb die Vertragsversion, nicht die Plugin-Version.

## Stände auf `main`, die **nicht** genügen (23.11.2026)

| Provider | `main` | Warum nicht |
|---|---|---|
| Pass Captain | `b2b88ea`, Plugin 1.4.0 | keine Vertragskonstante, kein `wpcap_event_cancelled`, kein `wp_pass_captain_invoice_created_v1`, `wpcap_order_cancelled` nur mit `$order_id` |
| Voucher Captain | `151786c`, Plugin 1.2.0 | keine Vertragskonstante; enthält den vorläufigen Workflow-Provider (`src/Workflow/Provider.php`, D13) |
| Review Captain | `0818df0`, Plugin 0.1.0-dev | keine Vertragskonstante (Rückfall nach R-3.3 möglich, aber veraltet), keine Sterne im Payload |

Ein Adapter, der gegen `main` von Pass oder Voucher Captain läuft, meldet sich nach R-3.3 und R-13.4 nicht an.


## Add-on-Kernel

| Paket | Gelesener Stand | Für Adapter nötig | Stand |
|---|---|---|---|
| `captain-pro` `packages/addon-kernel` | `07b5365` und `main` `2f23da7` | Prüfung der Lizenz eines anderen Add-ons und mehrere Kerne je Add-on (F-06, CP-1, CP-2 in [`ANFORDERUNGEN-andere-repos.md`](ANFORDERUNGEN-andere-repos.md)) | fehlt |

## Laufzeitumgebung

**Mindestversionen ab 17.11.2026 (Entscheidung des Inhabers MINVER-1, einheitlich für alle Captain-Plugins, Pro-Add-ons und Connectoren):**

| Komponente | Mindestens | Was darunter passiert |
|---|---|---|
| PHP | 8.5 (8.6 läuft in der CI als experimentelle Zelle mit, darf fehlschlagen) | Plugin bleibt inaktiv: Hinweis im (Netzwerk-)Admin, Aktivierung wird abgelehnt (`src/Requirements.php`); gespeicherte Daten bleiben |
| WordPress | 7.0 (WordPress 6.8 wirft unter PHP 8.5 Deprecations im Core) | wie bei PHP |
| WooCommerce | 11.2 (nur der eingebaute WooCommerce-Adapter) | Adapter meldet sich nicht an; Seite „Provider“ zeigt „abgelehnt – provider_too_old“; der Rest von Workflow Captain läuft |
| MySQL / MariaDB | MySQL 9.7 oder MariaDB 12.3 (jeweils LTS) | keine Sperre; Website-Zustand zeigt die Empfehlung „Der Datenbankserver ist älter …“ (Status „recommended“) |

`workflow-captain.php`: „Requires at least: 7.0“, „Requires PHP: 8.5“; `readme.txt`: „Requires at least: 7.0“, „Tested up to: 7.1“, „Requires PHP: 8.5“. Die Diagnose (`environment.database`) und die Debug-Informationen nennen die Version des Datenbankservers.

Der Code in `src/` und `tests/` bleibt auf PHP 8.3 lauffähig (keine Syntax, die erst ab 8.4 geht), weil der Prüfcontainer nur PHP 8.3 hat; die Tests rufen die Prüfung mit eingesetzten Versionen auf (`Requirements::unmet()`, `Requirements::database()`).

### Verlauf: geprüfte Installationen bis 16.11.2026 (vor MINVER-1)

Die folgenden Läufe liefen auf PHP 8.3 und zum Teil unter WordPress 7.0 bzw. WooCommerce 11.2. Sie sind ab MINVER-1 nur noch historisch: mit diesem Stand würde das Plugin dort nicht mehr aktiviert. Geprüft auf echten Installationen (WF-INT-16; Einzelheiten in [`E2E-RESULTS.md`](E2E-RESULTS.md)). Damals: „Requires at least: 6.4“, „Requires PHP: 8.1“.

| WordPress | WooCommerce | Yoast SEO | PHP | Datenbank | Bestellungen | Checkout | Multisite | Herkunft der Pakete | Ergebnis |
|---|---|---|---|---|---|---|---|---|---|
| 7.1.4 | 11.3.0 | 28.8 (mit JS) | 8.3.6 | MariaDB 10.11 | HPOS und klassisch | Checkout-Block (Überweisung, Nachnahme, Gratisbestellung) und Shortcode | Unterverzeichnisse, Netzwerkaktivierung, neue und gelöschte Website, Pro netzwerkweit | wordpress.org, Workflow Captain als Release-ZIP | alle Läufe bestanden (908 von 910; offen nur die Lizenzseite von Pro im Netzwerk-Admin) |
| 6.9.11 | 9.9.5 | 28.7 (ohne JS-Build) | 8.3.6 | MariaDB 10.11 | HPOS und klassisch | nur CRUD-API (kein JS-Build) | wie oben | Git-Quellen | bestanden (erster bis dritter Lauf) |
| 6.4.5 | 8.9.0 | – | 8.3.6 | MariaDB 10.11 | HPOS und klassisch | nur CRUD-API | – | wordpress.org, Release-ZIP | Aktivierung, Provider, Bestellabläufe, Admin-Seiten bestanden (Mindestversionen) |

Action Scheduler (aus WooCommerce) und System-Cron (`wp workflow-captain process`, `DISABLE_WP_CRON`) sind auf allen drei Ständen geprüft. Nicht geprüft: Subdomain-Multisite.

### PHP und Datenbankserver

Im Prüfcontainer gibt es nur PHP 8.3 und MariaDB 10.11 – beides unter der Mindestversion. Die gültigen Läufe macht ab MINVER-1 nur die CI auf GitHub. Die Spalte „in CI“ nennt, was der Workflow prüft; ob der Lauf grün war, steht in der letzten Zeile.

| Umgebung | in CI | lokal geprüft |
|---|---|---|
| PHP 8.5 ohne WordPress; 8.6 (Nightly von `setup-php`) experimentell | `php.yml`: Syntax, Lizenzwächter, `tests/run.php` (SQLite), POT; Deprecations, Warnungen und Notices gelten als Fehler; die 8.6-Zelle hat `continue-on-error` | `tests/run.php` weiter auf 8.3.6 (der Code bleibt dort lauffähig) |
| PHP 8.5 mit WordPress 7.1.4, WooCommerce 11.3.0 (HPOS) | `e2e-db.yml`, je gegen MySQL 9.7 und MariaDB 12.3 | – |
| PHP 8.5 mit den Mindestversionen WordPress 7.0.8, WooCommerce 11.2.2 | `e2e-db.yml`, gegen MariaDB 12.3 | – |
| PHP 8.6 mit WordPress 7.1.4 | `e2e-db.yml`, gegen MySQL 9.7, experimentell (darf fehlschlagen) | – |
| PHP 8.3 mit WordPress | – | nur historisch (Tabelle oben); das Plugin lehnt PHP 8.3 jetzt ab |
| MySQL 9.7 | `e2e-db.yml` (Service-Container `mysql:9.7`) | – |
| MariaDB 12.3 | `e2e-db.yml` (Service-Container `mariadb:12.3`) | – |
| MySQL 8.4, MariaDB 10.11/11.4 | nicht mehr (unter der Mindestversion; Website-Zustand warnt) | MariaDB 10.11 nur historisch |

`e2e-db.yml` ruft `bin/e2e/ci-db.sh` auf: WordPress 7.1.4 und WooCommerce 11.3.0 (in einer Zelle 7.0.8 und 11.2.2) von wordpress.org, Workflow Captain als Release-ZIP aus `git archive`, `php -S` als Webserver, kein Browser. Geprüft werden Aktivierung, Tabellen und Schema (ci1–ci4, a1–a12, b1–b5), der Bestellablauf mit HPOS (c0–c11), weitere Ereignisse (d0–d10), Zeitplan (f0–f3), Betrieb (g0–g11), Migrationen mit `migrate` und `verify` (m1–m9, auch ein abgebrochenes Upgrade, das eine wp-admin-Anfrage zu Ende führt), Blättern und Zählungen gegen SQL (`EventStore::count`/`find`, `EngineStore::count_runs`/`find_runs`, n1–n8), das PHP-Fehlerprotokoll (h20) sowie Deaktivieren und Löschen (j1–j9). Neu ist `ci3-db`: der Datenbankserver erfüllt die Mindestversion (Website-Zustand „good“). Ergebnisse als Artefakt `results-ci.jsonl`; eine fehlgeschlagene Prüfung lässt den Job scheitern. Nicht in dieser CI: klassische Bestellablage, Multisite, Admin-Seiten im Browser, Checkout-Block, Yoast SEO und das Upgrade von einer älteren Plugin-Version (diese nur lokal, siehe Tabelle oben).

Lokal am 16.11.2026 mit denselben Schritten gegen MariaDB 10.11 und PHP 8.3.6 (frische Datenbank, `http://127.0.0.1:8095`): 96 von 96 bestanden (historisch, vor MINVER-1).

**Stand der CI (17.11.2026):** Matrix auf MINVER-1 umgestellt (PHP 8.5, 8.6 experimentell; MySQL 9.7, MariaDB 12.3; eine Zelle mit WordPress 7.0.8/WooCommerce 11.2.2). **Ergebnis (nachgetragen am 23.11.2026):** Der Lauf auf `main` (`197c8e8`, Lauf 44035430887 vom 17.11.2026) ist in allen vier Zellen grün: MySQL 9.7/PHP 8.5, MariaDB 12.3/PHP 8.5, MySQL 9.7/PHP 8.6 (experimentell) und MariaDB 12.3 mit WordPress 7.0.8/WooCommerce 11.2.2 auf PHP 8.5.

Hinweise aus dem Checkout-Test: `woocommerce.order.paid` hängt an `woocommerce_payment_complete`; Überweisung und Nachnahme lösen es in WooCommerce nie aus (auch nicht beim manuellen Wechsel auf „In Bearbeitung“), Workflows für diese Zahlarten nutzen den Statuswechsel. Der Block-Checkout erzeugt zusätzlich einen Statuswechsel `checkout-draft` → `pending`, der beim Gegenlesen verworfen wird (R-7.4).

**Nachtrag MINVER-1 (17.11.2026):** WooCommerce-Minimum ist **11.2.2** (11.2.0/11.2.1 übernehmen Bestell-/Zahlungsdaten eines früheren Store-API-Checkouts im selben PHP-Prozess, behoben in 11.2.2). MariaDB ab 11.6.2 schaltet `innodb_snapshot_isolation` standardmäßig ein (Fehler 1020 bei Lesen und späterem `SELECT … FOR UPDATE`); Workflow Captain und das Workflow-Add-on von Pro nutzen keine Transaktionen mit sperrenden Lesezugriffen (Claims über bedingtes `UPDATE`), sind also nicht betroffen – geprüft per Codesuche, in CI gegen MariaDB 12.3 grün.
