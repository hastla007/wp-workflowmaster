# Kompatibilitätsmatrix der Provider

Stand: 16.11.2026 (Abend), zu [`SPEC-suite-events.md`](SPEC-suite-events.md) Version 1.0.0-draft.7, Workflow Captain 0.2.x und Workflow Captain Pro 0.1.0 (Entwicklungsstand `captain-pro` mit Gutschein-Befehlen und Form-Adapter). Aufgabe in [`../TODO.md`](../TODO.md): „Kompatible Versionen der einzelnen Captain-Provider je Plugin in einer Matrix veröffentlichen“. Verlinkt aus `readme.txt` (FAQ „Welche Versionen der Captain-Plugins passen?“).

Ein Adapter meldet sich nur an, wenn die Vertragskonstante des Kerns mindestens die Mindestversion hat und dieselbe MAJOR-Version trägt (R-3.3, R-13.4). Die Plugin-Version allein entscheidet nicht. Alle Angaben sind am Code gelesen; „Branch“ heißt: noch nicht nach `main` gemergt.

## Aktuelle Matrix

| Provider | Gelesener Stand | Plugin | Vertragskonstante | Adapter verlangt (Workflow Captain Pro) | Was der Stand bringt | Adapter |
|---|---|---|---|---|---|---|
| WordPress | – | ab 6.4 | `$wp_version` steht für den Vertrag (4.7) | WordPress ≥ 6.4 | `wordpress.user.registered`, `wordpress.post.published` | im kostenlosen Kern |
| WooCommerce | – | ab 8.2 | `WC_VERSION` steht für den Vertrag (4.7) | WooCommerce ≥ 8.2, HPOS und klassische Bestelltabellen | `woocommerce.order.*` (5 Typen) | im kostenlosen Kern |
| Workflow Captain (Zeitplan, eingehende Auslöser) | Kern 0.2.x | 0.2.0 | `WORKFLOW_CAPTAIN_CONTRACT_VERSION` = `0.1.0` (Adapter-Schnittstelle) | – | `workflow.schedule.due`; `workflow.inbound.<auslöser>` nur, wenn ein Add-on Auslöser anmeldet | im kostenlosen Kern |
| Pass Captain | `wp-passmaster` Branch `claude/plugin-todo-liste-2a83v4` `51da413` | 1.6.0 | `WPCAP_CONTRACT_VERSION` = `1.2.0` | `1.1.0` (Rückfall, veraltet); Lesefassade `wpcap_get_*_state()` ab `1.2.0` | 13 Ereignistypen, Lesefassade | Pro, `PassAdapter` |
| Voucher Captain | `wp-couponmaster` Branch `claude/plugin-todo-liste-2a83v4` `689ab4a` | 1.3.0 | `VCAP_CONTRACT_VERSION` = `1.3.0` | `1.1.0` (Rückfall, veraltet); Lesefassade `vcap_read_*()` ab `1.2.0`; Gutschein-Befehle ab `1.3.0` | 16 Ereignistypen, Lesefassade, Einstiegspunkte für die Befehle Ausstellen, Sperren, Reaktivieren, Gutschreiben (R-8.3) | Pro, `VoucherAdapter` (Ereignisse); die Befehle als Workflow-Aktionen fehlen in Pro noch (WF-INT-03) |
| Affiliate Captain | `wpaffiliatemaster` Branch `claude/plugin-todo-liste-2a83v4` `a3f20cc` | 0.15.0 | `WPAC_CONTRACT_VERSION` = `1.4.0` | `1.4.0` | 21 Ereignistypen | Pro, `AffiliateAdapter` |
| Lizenzserver (nur im Shop des Anbieters) | `wp-license` Branch `claude/zen-babbage-7q4nac` `caebdcc` | 0.2.0 | `WPLC_CONTRACT_VERSION` = `1.0.0` | `1.0.0` mit Lesefunktion `WPLC\Events\LicenseReader::get()`; ohne Konstante Rückfall über Plugin 0.2.0 und `PlanChange::get_license()` (veraltet) | 3 Ereignistypen | Pro, `LicenseAdapter` |
| Form Captain | `wp-formmaster` `fc1f206` | 1.0.0-dev | `FORM_CAPTAIN_CONTRACT_VERSION` = `1.0.0` | Major 1 | Katalog `form.*` seit Spezifikation 1.0.0-draft.7 (4.6.1, 4.6.2); eigener Workflow-Vertrag zurückgezogen (F-12) | Workflow Captain Pro (`FormAdapter`), bleibt aus bis Form Captain Pro |
| Review Captain | `wp-reviewmaster` `main` `33d1362` | 0.1.0-dev | fehlt auf `main` | – | kein Katalog in Version 1 (4.6) | keiner |
| Course Captain | – | kein Code | – | – | – | keiner |
| Eigene Connectoren | eigenes Plugin | eigene | `contract` im Manifest (`CONNECTOR-CONTRACT.md`) | Workflow Captain Pro als Laufzeit (Filter `workflow_captain_connector_runtime_allowed_v1`, F-18a); die eigene Lizenz prüft der Connector selbst | eigene Typen `x<präfix>.*` | im Kern, freigeschaltet durch Pro |

**Workflow Captain Pro** verlangt Workflow Captain ≥ 0.2.0 (für netzwerkweite Abläufe Schema 4 mit `ForeignRuns`) und für jedes Captain-Produkt zusätzlich dessen gültiges Pro-Add-on (Spezifikation 10.1; der Lizenzserver hat keines).

**Ungeprüft:** ein Lauf der Adapter gegen genau diese Stände in einer echten WordPress-Installation (laut [`E2E-RESULTS.md`](E2E-RESULTS.md) liefen die Suite-Adapter von Pro dort nicht, weil Pro ohne Lizenz keine Funktion freigibt; Prüfung der Adapter bisher nur mit PHPUnit in `captain-pro`). Ältere Stände stehen unten als Verlauf.

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

## Stände auf `main`, die **nicht** genügen

| Provider | `main` | Warum nicht |
|---|---|---|
| Pass Captain | `b2b88ea`, Plugin 1.4.0 | keine Vertragskonstante, kein `wpcap_event_cancelled`, kein `wp_pass_captain_invoice_created_v1`, `wpcap_order_cancelled` nur mit `$order_id`; 416 Commits hinter dem gelesenen Branch |
| Voucher Captain | `2d84943`, Plugin 1.1.0 | keine Vertragskonstante, kein `issue_standalone`, keine Hooks für Rückläufer, Aufgabe der Zustellung und Neuausstellung |

Ein Adapter, der gegen `main` dieser Kerne läuft, meldet sich nach R-3.3 und R-13.4 nicht an.

## Add-on-Kernel

| Paket | Gelesener Stand | Für Adapter nötig | Stand |
|---|---|---|---|
| `captain-pro` `packages/addon-kernel` | `07b5365` und `main` `2f23da7` | Prüfung der Lizenz eines anderen Add-ons und mehrere Kerne je Add-on (F-06, CP-1, CP-2 in [`ANFORDERUNGEN-andere-repos.md`](ANFORDERUNGEN-andere-repos.md)) | fehlt |

## Laufzeitumgebung

Die Matrix für WordPress, PHP, WooCommerce (HPOS, Classic, Checkout Blocks), Action Scheduler und Multisite ist WF-INT-16 und folgt, sobald es Code gibt.
