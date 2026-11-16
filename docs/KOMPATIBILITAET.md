# Kompatibilitätsmatrix der Provider (Entwurf)

Stand: 16.11.2026, zu [`SPEC-suite-events.md`](SPEC-suite-events.md) Version 1.0.0-draft.6. Aufgabe in [`../TODO.md`](../TODO.md): „Kompatible Versionen der einzelnen Captain-Provider je Plugin in einer Matrix veröffentlichen“.

**Entwurf.** Veröffentlicht wird die Matrix erst mit dem ersten Release von Workflow Captain Pro. Bis dahin hält sie fest, welche Stände der Kerne die Spezifikation voraussetzt und was davon am Code belegt ist. Adapter gibt es seit dem 16.11.2026 (siehe Nachtrag); „Mindestvertrag“ ist das, was ein Adapter nach R-13.4 verlangen müsste, um den Katalog der Spezifikation vollständig abzudecken.

## Je Provider

| Provider | Gelesener Stand | Plugin-Version | Vertragskonstante | Mindestvertrag für den ganzen Katalog | Bekannte MAJOR | Belegt |
|---|---|---|---|---|---|---|
| Pass Captain | `wp-passmaster` `claude/sharp-mendel-xiiwf3` `dd32087` | 1.5.0 | `WPCAP_CONTRACT_VERSION` = `1.1.0` | `1.1.0` (`wpcap_event_cancelled`, `wp_pass_captain_invoice_created_v1` und die drei Argumente von `wpcap_order_cancelled` gibt es erst seit Plugin 1.5.0) | 1 | ja, am 16.11.2026 am Code und Git-Verlauf |
| Voucher Captain | `wp-couponmaster` `claude/sharp-mendel-xiiwf3` `ed7b791` | 1.2.0 | `VCAP_CONTRACT_VERSION` = `1.1.0` | `1.1.0`; die vier Gutschein-Befehle brauchen eine neue MINOR-Version (R-8.1), die es noch nicht gibt | 1 | ja, am 16.11.2026 am Code |
| Affiliate Captain | `wpaffiliatemaster` `claude/sharp-mendel-xiiwf3` `6600392`, `main` `2a77ed3` (gleiches `src/`) | 0.15.0 | `WPAC_CONTRACT_VERSION` = `1.4.0` | `1.4.0` (`creative.*`, `code_request.*`, `affiliate.recruited` seit 1.4.0) | 1 | ja, am 16.11.2026 am Code, an Tags und Git-Verlauf |
| Lizenzserver | `wp-license` `241ae7a`, `a113568`, `main` `c269e07` (gleiches `src/License/`) | 0.2.0 | fehlt auf allen Branches (F-03) | – („Vertrag unbekannt“, R-3.3; Ersatz über Plugin-Version 0.2.0, weil `plan_changed` erst dort kommt) | – | ja, am 16.11.2026 am Code |
| Form Captain | `wp-formmaster` `main` `6f4785f` | 1.0.0-dev | `FORM_CAPTAIN_CONTRACT_VERSION` = `1.0.0` (trotz zwei neuer Actions) | `1.0.0` (Katalog 4.6.1 seit 1.0.0-draft.7; alle acht Actions gibt es in 1.0.0) | 1 | ja, am 16.11.2026 am Code |
| Review Captain | `wp-reviewmaster` `claude/sleepy-gates-6mm217` `b35fb95` (`main` ohne PHP-Code) | 0.1.0-dev | fehlt | kein Katalog (4.6.3: vorgesehen, wartet auf RC-2 und F-08; `main` `33d1362` ohne Code) | – | ja, am 16.11.2026 am Code |
| Course Captain | – | kein Code | fehlt | kein Katalog in v1 (4.6) | – | – |

## Nachtrag 16.11.2026 (Nachmittag): Lesefassaden, WordPress/WooCommerce, Pro-Add-on

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
