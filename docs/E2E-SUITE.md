# E2E-Suite: Workflow Captain mit Pro, den Captain-Plugins und dem Lizenzserver

Stand: 16.11.2026, Branch `wt/suite` (Basis `claude/plugin-todo-liste-2a83v4`, `7e04e16`). Erzeugt mit
`bin/e2e/suite/setup.sh` und `bin/e2e/suite/run-suite.sh` (nicht ausgeliefert, `/bin` ist `export-ignore`).
**206 von 207 Prüfungen bestehen**; die eine offene (r4b) ist ein Befund in Affiliate Captain, unten erklärt.
Ein Lauf auf frischen Datenbanken dauert etwa 2,5 Minuten.

Die Suite ergänzt [`E2E-RESULTS.md`](E2E-RESULTS.md) (nur Kern, Seiten :8080 bis :8082). Sie läuft auf eigenen
Seiten und Datenbanken, damit die Kern-Testseiten ungestört bleiben.

## Aufbau

| Teil | Wo | Herkunft |
| --- | --- | --- |
| Einzelseite mit WooCommerce (HPOS), zugleich **Herstellershop** | `/home/user/wp-e2e/suite`, http://127.0.0.1:8083, Datenbank `wp_e2e_suite` | WordPress 6.9.11 aus dem Git-Tag-Checkout von `ms/` kopiert, WooCommerce 9.9.5 aus dem Build in `www/` kopiert |
| Multisite (Unterverzeichnisse, fünf Websites) | `/home/user/wp-e2e/suite-ms`, http://127.0.0.1:8084, `wp_e2e_suite_ms` | wie oben |
| Frische Seite für das Release-Gate | `/home/user/wp-e2e/suite-zip`, `wp_e2e_suite_zip` (nur WP-CLI) | wie oben, Plugins nur aus den ZIPs |
| Webhook-Empfänger | `/home/user/wp-e2e/hooks`, http://127.0.0.1:8086 | `bin/e2e/suite/receiver.php`, schreibt `logs/suite-hooks.jsonl` |
| WP-CLI | `wpsuite`, `wpsuitems` in `/usr/local/bin` | das vorhandene WP-CLI-Bundle |

Datenbanken mit dem MariaDB-Root über den Unix-Socket angelegt (wie `bin/e2e/setup.sh`), Benutzer `wp`/`wp`.
Nichts wurde heruntergeladen außer den Composer-Abhängigkeiten der Release-Builds (Quell-Klone, weil GitHub-Archive
gesperrt sind). Admin: `admin` / `admin-e2e-pass`.

Plugins (Symlinks auf die Checkouts, Branch `claude/plugin-todo-liste-2a83v4`, in den Repos nichts geändert außer den
Fehlerbehebungen unten):

| Plugin | Quelle im Lauf |
| --- | --- |
| Workflow Captain | `/home/user/wt-suite-fix` (Worktree `wt/suite-fix` = Branch + Fix `81068ea`) |
| Workflow, Pass, Voucher, Affiliate Captain Pro | Klon von `captain-pro` in `/home/user/wp-e2e/suite-src/captain-pro` (Branch `wt/suite-fix` = `2c13cad` + drei Fixes), `lib/` mit `php build/build.php --in-place --addon=…` im Klon |
| Pass Captain | `/home/user/wp-passmaster` |
| Voucher Captain | Kopie in `suite-src/voucher-captain` mit gescoptem `vendor/` (siehe Befund 1) |
| Affiliate Captain | `/home/user/hastla007/wpaffiliatemaster` |
| WP License Captain | `/home/user/wp-license` (nur auf der Einzelseite) |
| Starter-Connector | `examples/starter-connector` des Kerns |

**Lizenzserver lokal:** WP License Captain läuft auf der Suite-Seite selbst. Die Pro-Add-ons nutzen ihn als
`https://shop.test` (Konstanten `*_CAPTAIN_PRO_LICENSE_SERVER` und `*_LICENSE_PUBLIC_KEYS` in der `wp-config.php`;
der öffentliche Schlüssel kommt aus `wp wplc signing-key`). Der Lizenz-Client nimmt nur https-Adressen; das
mu-plugin `bin/e2e/suite/mu-plugins/e2e-suite.php` leitet `https://shop.test/…` an `http://127.0.0.1:8083/…` um. Die
Anfragen sind echt signiert, die Antworten mit dem gepinnten Ed25519-Schlüssel geprüft. `*_LICENSE_DEV` war nicht
nötig. Pläne werden über `Catalog::save_mapping()` angelegt und über echte, bezahlte WooCommerce-Bestellungen gekauft:
Agentur (alle acht `workflow.*`-Rechte, 3 Plätze), Basis (`workflow.suite_integrations`, `workflow.webhooks`),
Einzelplatz, dazu je ein Plan für Pass, Voucher und Affiliate Captain Pro.

## Ablauf (`run-suite.sh`)

| Phase | Inhalt | TODO |
| --- | --- | --- |
| install | frische Seite, Pläne, sechs Lizenzen gekauft (s0–s3) | – |
| matrix | Free → Pro ohne Lizenz → Basislizenz → Produkt-Add-ons ohne Lizenz → mit Lizenz → Agenturlizenz (f, n, b, u, o, k) | T96, T88, WF-CONNECTOR-12 |
| events | echte Ereignisse aller vier Suite-Adapter aus Lizenzserver, Voucher, Pass (über WooCommerce) und Affiliate; die vier Gutschein-Befehle (e, c) | WF-INT-02, WF-INT-03 |
| refund | eine volle WooCommerce-Erstattung einer Bestellung mit Ticket, Gutschein und Partner (r) | T87 |
| providers | Plugin fehlt (Pass deaktiviert), Fachplugin-Pro fehlt (Voucher Captain Pro deaktiviert), Vertrags-MAJOR (Affiliate meldet 2.0.0) (p, q, m) | T88 |
| webhooks | zwei n8n-Verbindungen gegen den lokalen Empfänger: Test, Aktivierung, Outbox, Workflow-Schritt, eingehend (h) | WF-CONNECTOR-12/-13 |
| expiry | Lizenzablauf im Shop, Pro aktualisiert, Verlängerung durch den Kunden (x, y) | WF-CONNECTOR-12, T96 |
| ms | Netzwerklizenz, Site A → Site B, Fan-out, Freigaben, Rechte, Plätze, Ablauf und Wechsel der Lizenz, Provider je Website (ms, t103) | WF-MS-22, WF-MS-23, T103 |
| release | ZIPs aller Plugins mit ihren eigenen Build-Skripten, Installation in frische Seite, Kauf, Aktivierung, Update-Prüfung, Erstattung, Deaktivierung (g) | WF-INT-18 |
| log | kein Fatal/Warning/Deprecated der Suite-Plugins im Fehlerprotokoll (z1, ms-log, g20) | – |

## Gefundene und behobene Fehler

| Commit | Repo, Branch | Fehler | Folge | Regressionstest |
| --- | --- | --- | --- | --- |
| `81068ea` | Kern, `wt/suite-fix` (lokal) | Der Starter-Connector lud seine Klassen beim Laden der Datei; Plugins laden alphabetisch, `starter-connector` vor `workflow-captain` | Fatal „Interface WorkflowCaptain\Adapter\Adapter not found“ auf jeder Anfrage einer echten Seite | `tests/connector-tests.php` (Datei lädt ohne Kern in eigenem PHP-Prozess) |
| `0388cde` | wp-couponmaster, `claude/plugin-todo-liste-2a83v4` (gepusht) | `Issuer::void( …, true )` schrieb den Verlauf „voided“ ohne Bestellung | `voucher.voucher.voided` nach einer Erstattung ohne `order_id`, nicht mit der Erstattung verknüpfbar (T87) | `tests/refund-lifecycle-integration.php` (T87) |
| `0ce26a7` | captain-pro, `wt/suite-fix` (lokal) | Netzwerkweit aktiviertes Workflow Captain Pro hielt die Lizenz je Website | Netzwerk-Workflows liefen nur auf der Website mit der Aktivierung; Quelle und Ziele `license_inactive`; jede Website hätte einen Platz verbraucht; keine Lizenzseite im Network Admin (Befund 5 aus `E2E-RESULTS.md`) | `tests/Addons/WorkflowNetworkLicenseTest.php` |
| `a22b127` | captain-pro, `wt/suite-fix` (lokal) | `MultisiteNetwork::maybe_migrate()` rief `Schema::migrate()` ohne die gespeicherte Version auf | Fatal (ArgumentCountError) beim ersten Aufruf der Netzwerkseiten und in der täglichen Wartung; Netzwerktabellen wurden nie angelegt | `WorkflowMultisiteNetworkTest::test_feature_migrates_the_network_tables_from_the_stored_version` |
| `4223dea` | captain-pro, `wt/suite-fix` (lokal) | Nach Lizenzablauf pausieren die Ziel-Websites ihre Läufe (`foreign_unavailable`), aber der Beobachter von Pro ist ohne Lizenz aus; die Kindschritte bleiben `waiting_site_worker` | „Lauf fortsetzen“ nach der Verlängerung setzte nichts fort; die Läufe blieben auf allen Zielen pausiert | `test_resume_after_renewal_continues_runs_the_targets_paused_without_license` |

Prüfungen nach den Commits: Kern `php tests/run.php` 627 bestanden, `license-guard`, `make-pot --check` (295),
`validate-spec-examples.py`, `builder.test.js`, `settings.test.js` grün. captain-pro: PHPUnit 661 Tests (8
übersprungen), `bin/lint.sh`, `make-pot` (POT neu), `build.php --addon=workflow-captain-pro`. wp-couponmaster:
`php tests/run.php` 633 bestanden, `refund-lifecycle-integration.php` auf der Suite-Seite bestanden.

## Befunde (nicht behoben)

1. **Voucher Captain aus dem Checkout bricht WooCommerce:** Das `vendor/` im Repo ist ungescopt (dompdf zieht
   `sabberworm/php-css-parser` 9.5, WooCommerce bringt 8.6): Fatal „Declaration of Sabberworm\CSS\CSSList\CSSList::
   addComments() must be compatible“ beim ersten Laden von WooCommerce-Code. Das Release-ZIP (`bin/build.sh` mit
   `bin/scope-vendor.php`) ist korrekt; nur wer den Checkout mit Entwicklungs-`vendor/` als Plugin nutzt, trifft es.
   Die Suite nutzt deshalb eine gescopte Kopie. Kein Fehler im Produkt, aber ein Stolperstein für Testseiten.
2. **T87, eine Erstattung, mehrere Ereignisse:** Eine volle WooCommerce-Erstattung ergibt genau eine
   WooCommerce-Erstattung (r6) und `woocommerce.order.refunded` mit `refund_id` (r1). Die Produkte verknüpfen so:
   Voucher über `order_id` (nach `0388cde`, r2), Affiliate über `subject` = Bestellung (r4), Pass nur über
   `reason_class` = `woocommerce` (die Pass-Ereignisse tragen die Pass-Bestellung, keine WooCommerce-ID; die
   Spezifikation 4.2 sieht kein Feld vor). Jedes Ereignis beginnt eine eigene `correlation_id` (r4c).
   **r4b (FAIL):** `affiliate.commission.adjusted` hat bei voller Erstattung keine `refund_id`: Affiliate Captain bucht
   die volle Erstattung über den Bestellstatus mit dem synthetischen Schlüssel `status-refund`, den der Adapter (nur
   Zahlen) verwirft; die Spezifikation 4.4 nennt `refund_id` als Feld. Entscheidung nötig (siehe Bericht).
3. **Lizenzablauf im Netzwerk:** Während die Lizenz fehlt, zeigt die Netzwerkansicht die Kindschritte weiter als
   „wartet“ (ms23b), obwohl die Ziele pausiert haben; erst „Lauf fortsetzen“ (nach `4223dea`) setzt sie fort.
   Ein Abgleich ohne Lizenz (Beobachter im Nur-Lesen-Modus) wäre genauer; offen in captain-pro (WFC-PRO-17).
4. **Updates im Release-Gate nur bis zur Prüfung:** Der Shop liefert Pakete nur über https-Adressen seines eigenen
   Hosts; die Testseite läuft auf `http://127.0.0.1:8083`. Geprüft: Release 0.1.1 mit `wp wplc release create`
   angelegt und veröffentlicht, der Updater von Pro sieht 0.1.1 und bietet das Update mit seinem Paket-Platzhalter an
   (g14, g15, g15b; die Paketadresse wird dafür im mu-plugin auf `https://shop.test` umgeschrieben). Nicht geprüft: der
   Download über den Einmal-Link und die Installation (die Release-Registry vergleicht die gespeicherte Adresse). Das
   deckt wp-license in seinen eigenen E2E-Abläufen 05 und 12 mit `https://shop.test` ab. Außerdem fragt WordPress die
   Update-URI-Hosts erst nach einer erfolgreichen Anfrage an wordpress.org (`wp_update_plugins()` bricht sonst ab); die
   Suite legt die Antwort des Updaters deshalb selbst in den Transient.
5. **Release-ZIPs der Pro-Add-ons nur als Entwicklungs-Build:** `build.php --release` verlangt echte Server-Adresse
   und Schlüssel statt der Platzhalter; für den Test wurde in der Kopie des Pro-ZIPs `Update URI` auf `https://shop.test`
   gesetzt.
6. **Warnung beim Vertrags-MAJOR-Test:** Die Simulation definiert `WPAC_CONTRACT_VERSION` = 2.0.0 vor Affiliate
   Captain; dessen eigenes `define()` meldet „Constant already defined“ (nur in diesem Test, erwartet).

## Nicht geprüft und warum

- **Checkout Blocks, WooCommerce-JS:** WooCommerce ohne JS-Build; Bestellungen über die CRUD-API mit den Hooks des
  klassischen Checkouts (`woocommerce_checkout_order_processed`, dort reserviert Pass Captain).
- **Andere WordPress-/PHP-/WooCommerce-Versionen:** nur WP 6.9.11, PHP 8.3.6, WooCommerce 9.9.5 (HPOS an).
- **Timeout nach erfolgreichem Zielaufruf, Restore aus Backup, Site-Löschung/Restore im Netzwerk, Ziel-Site-Cron-Ausfall:**
  nicht gebaut; Netzwerk-Lebenszyklus nur mit den Doppeln in captain-pro.
- **Form und Review Captain:** nicht installiert (nicht im Auftrag; Form-Adapter existiert in Pro seit `19c896b`).
- **Netzwerk-Lizenzseite per Browser:** `network = true` setzt laut Kernel die Lizenzseite unter `settings.php`;
  nicht per HTTP aufgerufen.
- **Affiliate Captain Pro, Pass Captain Pro, Voucher Captain Pro im Netzwerk:** lizenzieren weiter je Website
  (Konfiguration `network` nicht gesetzt); bewusst nicht geändert, siehe offene Frage im Bericht.

## Kompatibilität (WF-INT-16, WF-INT-17)

| Fall | Ergebnis | Prüfungen |
| --- | --- | --- |
| WordPress 6.9.11, PHP 8.3.6, WooCommerce 9.9.5 HPOS, Action Scheduler, `DISABLE_WP_CRON` | läuft | alle |
| Multisite lokal je Website (Provider je Website, Isolation) | läuft | t103/* |
| Multisite netzwerkweit mit Pro-Lizenz | läuft nach `0ce26a7`, `a22b127`, `4223dea` | ms1–ms26 |
| Free (ohne Pro) | nur Kern-Adapter; Connector-Laufzeit ab Werk frei | f1–f5 |
| Pro ohne Lizenz / Basis / Agentur / abgelaufen / verlängert | je Recht an und aus wie dokumentiert | n, b, k, x, y |
| Fachplugin-Pro fehlt / ohne Lizenz | Adapter aus, Lizenzseite nennt das Add-on, alte Ereignisse pausieren | b3, b4, u1, u2, q1–q4 |
| Plugin fehlt / deaktiviert | Ereignisse pausieren (`adapter_unregistered`), Requeue nach Reaktivierung | p1–p7, t103/off |
| Vertrags-MAJOR eines Providers | Adapter aus (`contract_major`), keine Typen, kein Erfassen | m1–m6 |
| Lizenz/Recht fehlt oder abgelaufen | Pro-Schritte pausieren (`action_unavailable`), eingehend 403, Outbox reiht nichts ein, Connector `runtime_not_allowed` | x2–x9 |
| Replay, falsche Signatur, unbekannter Auslöser (eingehend) | 409, 401, 403 | h13–h15 |
| Duplikat | Zweite Zustellung erzeugt nichts doppelt (Kern-E2E c11, Pro-Tests) | – |
| Refund | eine kanonische WooCommerce-Erstattung, Produkte verknüpft | r0–r7 |
| Release-ZIPs aller Plugins in frischer Seite | installiert, aktiviert, Lizenz, Ereignisse | g1–g20 |

## Neustart und Lauf

```
bin/e2e/setup.sh                 # Kern-Testseiten, MariaDB, WP-CLI (Voraussetzung)
bin/e2e/suite/setup.sh           # Suite-Seiten, Klon von captain-pro (wt/suite-fix, sonst Branch), Server :8083 :8084 :8086
bin/e2e/suite/run-suite.sh       # alles; oder einzelne Phasen: run-suite.sh ms release
```

Ergebnisse: `/home/user/wp-e2e/logs/suite-results.jsonl`, Fehlerprotokolle `debug-suite*.log`, Mails `mail-suite*.jsonl`,
Webhook-Anfragen `suite-hooks.jsonl`, ZIPs in `/home/user/wp-e2e/suite-src/zip/out/`.

## Ergebnisse (Lauf vom 16.11.2026)

| ID | Prüfung | Ergebnis | Detail |
| --- | --- | --- | --- |
| i1 | shop signing key pinned for the Pro add-ons | **PASS** | YWJ19safpMmZcPIUNE0X78LFyorxKo5dvEAALV1vNzg= |
| s1 | WP License Captain active with its tables (vendor shop on the suite site) | **PASS** | 0.2.0 |
| s0/agency | license plan agency (workflow-captain-pro) mapped to a product | **PASS** | workflow.suite_integrations,workflow.webhooks,workflow.event_correlation,workflow.advanced_logic,workflow.recipe_templates,workflow.extended_logs,workflow.cu... |
| s0/basic | license plan basic (workflow-captain-pro) mapped to a product | **PASS** | workflow.suite_integrations,workflow.webhooks limit 3 |
| s0/single | license plan single (workflow-captain-pro) mapped to a product | **PASS** | workflow.suite_integrations,workflow.webhooks,workflow.event_correlation,workflow.advanced_logic,workflow.recipe_templates,workflow.extended_logs,workflow.cu... |
| s0/pro | license plan pro (pass-captain-pro) mapped to a product | **PASS** | pass.write_api limit 3 |
| s0/pro | license plan pro (voucher-captain-pro) mapped to a product | **PASS** | voucher.white_label limit 3 |
| s0/pro | license plan pro (affiliate-captain-pro) mapped to a product | **PASS** | affiliate.extended_api limit 3 |
| s2/wcp_full | paid order issues a license (wcp_full) | **PASS** | workflow-captain-pro active until 2027-11-16 18:06:53 |
| s2/wcp_basic | paid order issues a license (wcp_basic) | **PASS** | workflow-captain-pro active until 2027-11-16 18:06:54 |
| s2/wcp_one | paid order issues a license (wcp_one) | **PASS** | workflow-captain-pro active until 2027-11-16 18:06:54 |
| s2/pass | paid order issues a license (pass) | **PASS** | pass-captain-pro active until 2027-11-16 18:06:54 |
| s2/voucher | paid order issues a license (voucher) | **PASS** | voucher-captain-pro active until 2027-11-16 18:06:54 |
| s2/affiliate | paid order issues a license (affiliate) | **PASS** | affiliate-captain-pro active until 2027-11-16 18:06:54 |
| s3 | agency plan grants all eight workflow.* rights | **PASS** | ["workflow.suite_integrations","workflow.webhooks","workflow.event_correlation","workflow.advanced_logic","workflow.recipe_templates","workflow.extended_logs... |
| f1 | Free: Workflow Captain Pro not active | **PASS** |  |
| f2 | Free: only the core adapters are registered (wordpress, woocommerce, schedules; plus the starter connector) | **PASS** | workflow-captain,wordpress,woocommerce,starter-connector |
| f3 | Free: no event type of Pass, Voucher, Affiliate or the license server is offered | **PASS** | [] |
| f4 | Free: a voucher issued in Voucher Captain stores no event (no adapter, nothing captured) | **PASS** | issued |
| f5 | Free: the custom connector runtime is open by default (core default, no Pro installed) | **PASS** | {"0":"starter-connector","refused":[]} |
| n1 | Pro active, license state is not active (no activation yet) | **PASS** | unlicensed |
| n2/workflow.suite_integrations | Pro without license: feature off (workflow.suite_integrations) | **PASS** |  |
| n2/workflow.webhooks | Pro without license: feature off (workflow.webhooks) | **PASS** |  |
| n2/workflow.custom_connectors | Pro without license: feature off (workflow.custom_connectors) | **PASS** |  |
| n2/workflow.multisite_network | Pro without license: feature off (workflow.multisite_network) | **PASS** |  |
| n3 | Pro without license: no suite adapter registered | **PASS** | workflow-captain,wordpress,woocommerce |
| n4 | Pro without license: custom connector refused with runtime_not_allowed (WF-CONNECTOR-12) | **PASS** | runtime_not_allowed |
| n5 | Pro without license: inbound webhook route answers 403 license_inactive first | **PASS** | 403 {"code":"workflow_captain_pro_license_inactive","message":"license_inactive","data":{"status":403}} |
| n6 | Pro license screen renders the activation form | **PASS** | Workflow Captain Pro – LicenseStatusNot activatedLicense key Activate Premium featuresFeatureStatusSuite integrations: events of Pass, Voucher and Affiliate ... |
| l/wcp_basic | license wcp_basic activated in WorkflowCaptainPro against the local shop (signed answer, pinned key) | **PASS** | ok active workflow.suite_integrations,workflow.webhooks |
| b1 | basic license: suite integrations and webhooks on, custom connectors and network off | **PASS** |  |
| b2 | basic license: license server adapter registered (no product add-on needed) | **PASS** | workflow-captain,wordpress,woocommerce,wp-license |
| b3 | Pro-Recht des Fachplugins fehlt: Pass, Voucher, Affiliate adapters not registered | **PASS** | workflow-captain,wordpress,woocommerce,wp-license |
| b4 | license screen says which product add-on is missing (Pass/Voucher/Affiliate Captain Pro not active) | **PASS** | ["Pass Captain: off. Workflows with this product also need Pass Captain Pro with a valid license, and Pass Captain Pro is not active.","Voucher Captain: off.... |
| b5 | basic license without workflow.custom_connectors: connector refused runtime_not_allowed (WF-CONNECTOR-12) | **PASS** | runtime_not_allowed |
| u1 | product add-ons active without license: Pass, Voucher, Affiliate adapters still not registered | **PASS** | workflow-captain,wordpress,woocommerce,wp-license |
| u2 | license screen names the unlicensed add-ons | **PASS** | ["Pass Captain: off. Workflows with this product also need a valid license of Pass Captain Pro, which this site does not have.","Voucher Captain: off. Workfl... |
| l/pass | license pass activated in PassCaptainPro against the local shop (signed answer, pinned key) | **PASS** | ok active pass.write_api |
| l/voucher | license voucher activated in VoucherCaptainPro against the local shop (signed answer, pinned key) | **PASS** | ok active voucher.white_label |
| l/affiliate | license affiliate activated in AffiliateCaptainPro against the local shop (signed answer, pinned key) | **PASS** | ok active affiliate.extended_api |
| o1 | all four suite adapters registered (pass-captain, voucher-captain, affiliate-captain, wp-license) | **PASS** | workflow-captain,wordpress,woocommerce,pass-captain,voucher-captain,affiliate-captain,wp-license |
| o2 | no refused adapters | **PASS** | [] |
| o3 | event types offered per provider (catalog: pass 13, voucher 16, affiliate 21, license 3) | **PASS** | {"workflow":1,"wordpress":2,"woocommerce":5,"pass":13,"voucher":16,"affiliate":21,"license":3} |
| o4 | license screen: every product "on" | **PASS** | ["Pass Captain: on. Its events reach Workflow Captain.","Voucher Captain: on. Its events reach Workflow Captain.","Voucher Captain: the voucher commands (iss... |
| k2/basic-license | connector refused runtime_not_allowed (basic-license) | **PASS** | runtime_not_allowed |
| l/wcp_full | license wcp_full activated in WorkflowCaptainPro against the local shop (signed answer, pinned key) | **PASS** | ok active workflow.suite_integrations,workflow.webhooks,workflow.event_correlation,workflow.advanced_logic,workflow.recipe_templates,workflow.extended_logs,w... |
| k1 | license with workflow.custom_connectors: connector accepted (only waits for the operator release) | **PASS** | not_enabled |
| e0 | setup: license, voucher, ticket product, affiliate and a paid WooCommerce order | **PASS** | {"license":"7","voucher":"ok","affiliate":1,"order":"28 processing"} |
| e6 | every stored suite envelope is valid (core validator, before processing) | **PASS** | [] |
| e8 | no e-mail address or name of buyer, recipient or customer in any stored envelope (R-5, before processing) | **PASS** | [] |
| e1 | license server events captured (issued, status_changed) | **PASS** | ["license.license.status_changed","license.license.issued"] |
| e2 | Voucher Captain events captured (standalone voucher, vouchers of the order) | **PASS** | ["voucher.voucher.issued_standalone","voucher.order.vouchers_issued","voucher.voucher.delivered"] |
| e3 | Pass Captain events captured from the WooCommerce order (order paid, tickets issued) | **PASS** | ["pass.order.paid","pass.order.tickets_issued"] |
| e4 | Affiliate Captain events captured (commission created) | **PASS** | ["affiliate.affiliate.created","affiliate.sale.attributed","affiliate.commission.created"] |
| e5 | every suite event processed (done), none paused or failed | **PASS** | [] |
| e7 | each provider workflow ran and succeeded | **PASS** | {"license.license.issued":"succeeded,succeeded,succeeded,succeeded,succeeded,succeeded","voucher.voucher.issued_standalone":"succeeded","pass.order.tickets_i... |
| c1 | Workflow Captain Pro offers the four voucher commands as actions | **PASS** | notify.admin,notify.user,workflow.tag.set,workflow.tag.remove,workflow.note.add,voucher.issue,voucher.void,voucher.reactivate,voucher.redeem,workflow.webhook... |
| c2 | Voucher Captain core has the four entry points (contract >= 1.3.0) | **PASS** | 1.3.0 |
| c3 | voucher.void switched off in Voucher Captain: the step is refused (command_disabled, final), the run needs a decision, voucher stays active | **PASS** | {"run":{"id":"12","state":"action_required","last_error":"voucher:command_disabled"},"steps":["action_required:voucher:command_disabled"]} |
| c4 | voucher.void switched on: the run voids the voucher; its voided event is captured as caused by the workflow | **PASS** | {"run":"succeeded","status":"void","event":1} |
| c5 | protocol of Voucher Captain lists both calls (refused and done) with workflow, run and step, without code | **PASS** | ["refused:command_disabled:wf6","done:voided:wf6"] |
| c6 | voucher.issue above the ceiling of 100 EUR cannot be published | **PASS** |  |
| c7 | voucher.issue: a new user (by the admin) gets a 5 EUR voucher through the command, run succeeds | **PASS** | {"runs":["succeeded"],"vouchers":1} |
| r0 | full WooCommerce refund of the order with ticket, voucher and affiliate | **PASS** | refund 33, order refunded |
| r1 | exactly one woocommerce.order.refunded with the refund ID (the canonical refund) | **PASS** | [{"subject":"order:28","data":{"refund_id":33,"full_refund":true},"state":"received"}] |
| r2 | Voucher Captain: voucher voided because of the refund (void_reason refund, same order) | **PASS** | [{"subject":"voucher:3","data":{"order_id":28,"void_reason":"refund"},"state":"received"}] |
| r3 | Pass Captain: refund mirrored from WooCommerce (pass.order.refunded with reason_class woocommerce, pass.refund.completed) | **PASS** | {"order_refunded":[{"subject":"order:7b4a4956b5145459fd7b2d5373127e66","data":{"reason_class":"woocommerce"},"state":"received"}],"refund_completed":[{"subje... |
| r4 | Affiliate Captain: commission adjusted on the same WooCommerce order (subject order, adjustment_type refund) | **PASS** | [{"subject":"order:28","data":{"source":"woocommerce","adjustment_type":"refund","full":true},"state":"received"}] |
| r4b | Affiliate Captain: the full refund carries the WooCommerce refund_id (optional field; full reversal by order status has none) | **FAIL** | [{"subject":"order:28","data":{"source":"woocommerce","adjustment_type":"refund","full":true},"state":"received"}] |
| r4c | one refund, one correlation? Each provider event starts its own correlation (spec 6: correlation_id only along workflow chains) | **PASS** | 8 correlation IDs for 8 events |
| r5 | no refund event of Voucher or Affiliate without a link to the WooCommerce order | **PASS** | [] |
| r6 | WooCommerce holds exactly one refund (no product plugin created a second refund) | **PASS** | 1 |
| r7 | all refund events processed (done) | **PASS** | [] |
| p0 | Pass events of a new order wait for the worker (received) | **PASS** | ["pass.order.paid","pass.order.tickets_issued"] |
| p1 | Pass Captain deactivated: its stored events pause (adapter_unregistered), none lost or failed | **PASS** | ["pass.order.paid:paused:adapter_unregistered","pass.order.tickets_issued:paused:adapter_unregistered"] |
| p2 | Pass Captain deactivated: no run started | **PASS** |  |
| p3 | Pass Captain deactivated: adapter not registered | **PASS** | workflow-captain,wordpress,woocommerce,voucher-captain,affiliate-captain,wp-license,starter-connector |
| p4 | Pro license screen: "Pass Captain: not active on this site" | **PASS** | ["Pass Captain: not active on this site.","Voucher Captain: on. Its events reach Workflow Captain.","Voucher Captain: the voucher commands (issue, void, reac... |
| p5 | Providers page of the core lists pass-captain with its paused events | **PASS** | pass-captainkein Adapter (Add-on fehlt, inaktiv oder ohne Lizenz)– / –Erledigt: 5, Pausiert: 2starter-connectorangemeldet1.0.0 / 1.0.0voucher-captainangemeld... |
| p6 | Pass Captain deactivated: a new workflow with a Pass trigger cannot be published (provider_unavailable) | **PASS** |  |
| p7 | Pass Captain active again: requeue processes the paused events, the run succeeds | **PASS** | {"requeued":2,"left":[],"runs":["succeeded"]} |
| q1 | Voucher Captain Pro deactivated: voucher adapter not registered (Pro right of the product missing) | **PASS** | workflow-captain,wordpress,woocommerce,pass-captain,affiliate-captain,wp-license,starter-connector |
| q2 | license screen: Voucher Captain off, Voucher Captain Pro not active | **PASS** | ["Pass Captain: on. Its events reach Workflow Captain.","Voucher Captain: off. Workflows with this product also need Voucher Captain Pro with a valid license... |
| q3 | a voucher event stored before stays paused (adapter_unregistered), not lost | **PASS** | {"state":"paused","last_error":"adapter_unregistered"} |
| q4 | a voucher issued now stores no event (no license, nothing captured, R-10.1) | **PASS** |  |
| m1 | Affiliate Captain with contract 2.0.0: adapter not registered (contract_major) | **PASS** | 2.0.0 / workflow-captain,wordpress,woocommerce,pass-captain,voucher-captain,wp-license,starter-connector |
| m2 | license screen names the incompatible contract of Affiliate Captain | **PASS** | ["Pass Captain: on. Its events reach Workflow Captain.","Voucher Captain: on. Its events reach Workflow Captain.","Voucher Captain: the voucher commands (iss... |
| m3 | no affiliate.* event type offered while the contract is incompatible | **PASS** |  |
| m4 | an affiliate event during the incompatible contract is not captured | **PASS** |  |
| m5 | contract 1.x again: affiliate adapter registered | **PASS** | workflow-captain,wordpress,woocommerce,pass-captain,voucher-captain,affiliate-captain,wp-license,starter-connector |
| m6 | voucher adapter registered again; the requeued voucher event is processed | **PASS** | done |
| h1 | two n8n connections created (private exception for 127.0.0.1:8086, HTTP) | **PASS** | [{"wcp_notice":"created","edit":"suite-n8n"},{"wcp_notice":"created","edit":"suite-step"}] |
| h2 | connection test reaches the local receiver (ok:200) | **PASS** | [{"wcp_notice":"test_ok","wcp_code":"ok:200"},{"wcp_notice":"test_ok","wcp_code":"ok:200"}] |
| h3 | test request signed with the HMAC secret from wp-config.php (InboundGuard::sign) | **PASS** | 03f706f1bb2f8ff44f0377b21a1eba37965b8758f1598c1e33fc92d12ef600be |
| h4 | both connections activated after the test | **PASS** | [{"wcp_notice":"activated"},{"wcp_notice":"activated"}] |
| h5 | workflow with the step workflow.webhook.send published | **PASS** |  |
| h6 | outbox: one row for pass.order.paid of the forwarding connection | **PASS** | [{"id":"1","connection":"suite-n8n","event_id":"479d0b26-0888-46b9-bee8-3e205f39fd22","state":"pending"}] |
| h7 | outbox sent pass.order.paid to the receiver, signed, with the event ID of Workflow Captain | **PASS** | {"id":"479d0b26-0888-46b9-bee8-3e205f39fd22","signed":true} |
| h8 | forwarded data holds only the selected fields | **PASS** | {"currency":"EUR","total_minor":2500,"ticket_count":0} |
| h9 | workflow step sent woocommerce.order.paid to the receiver, run succeeded | **PASS** | {"requests":1,"runs":["succeeded"]} |
| h10 | outbox row marked sent | **PASS** | ["sent"] |
| h11 | inbound: signed request accepted with 202 after the core stored it | **PASS** | {"code":202,"body":"{\"status\":\"accepted\",\"request_id\":\"782e72a6c83c0c89dbd27abd\",\"result\":\"captured\"}","nonce":"782e72a6c83c0c89dbd27abd"} |
| h12 | inbound event workflow.inbound.crm_lead stored with only the declared fields (no email) | **PASS** | {"lead_id":7,"source":"web"} |
| h13 | inbound replay of the same nonce refused (409) | **PASS** | {"code":409,"body":"{\"code\":\"workflow_captain_pro_replay\",\"message\":\"replay\",\"data\":{\"status\":409}}","nonce":"782e72a6c83c0c89dbd27abd"} |
| h14 | inbound with a wrong signature refused (401) | **PASS** | {"code":401,"body":"{\"code\":\"workflow_captain_pro_bad_signature\",\"message\":\"bad_signature\",\"data\":{\"status\":401}}","nonce":"f6418980b6ac3bad29a16... |
| h15 | inbound with an undeclared trigger refused (403) | **PASS** | {"code":403,"body":"{\"code\":\"workflow_captain_pro_trigger_not_allowed\",\"message\":\"trigger_not_allowed\",\"data\":{\"status\":403}}","nonce":"07d16ee27... |
| x0 | shop: the agency license of Workflow Captain Pro expired (paid period ended 20 days ago, grace of 14 days over) [time simulated] | **PASS** | expired until 2026-10-27 18:07:34 |
| x1 | Pro refreshes from the shop: no access any more | **PASS** | ok expired expired |
| x2/workflow.suite_integrations | expired license: feature off (workflow.suite_integrations) | **PASS** |  |
| x2/workflow.webhooks | expired license: feature off (workflow.webhooks) | **PASS** |  |
| x2/workflow.custom_connectors | expired license: feature off (workflow.custom_connectors) | **PASS** |  |
| x2/workflow.multisite_network | expired license: feature off (workflow.multisite_network) | **PASS** |  |
| x3 | expired license: no suite adapter registered (Pass, Voucher, Affiliate, license server) | **PASS** | workflow-captain,wordpress,woocommerce |
| x4 | expired license: custom connector refused runtime_not_allowed; its release stays stored | **PASS** | runtime_not_allowed |
| x5 | expired license: the new run with the webhook step pauses (action_unavailable), nothing is sent | **PASS** | {"run":{"id":"17","state":"paused","last_error":"action_unavailable"},"steps":["a0:paused:action_unavailable"]} |
| x6 | expired license: the outbox queues nothing new (no pass events captured) | **PASS** | ["sent"] |
| x7 | expired license: inbound route answers 403 license_inactive | **PASS** | {"code":403,"body":"{\"code\":\"workflow_captain_pro_license_inactive\",\"message\":\"license_inactive\",\"data\":{\"status\":403}}","nonce":"b54196fd858d595... |
| x8 | expired license: connections and their settings stay readable (nothing deleted) | **PASS** | ["suite-n8n","suite-step"] |
| x9 | license screen says the license expired | **PASS** | Workflow Captain Pro – LicenseStatusExpiredPaid untilOctober 27, 2026 7:07 pmLive sites in use0 / 3EnvironmentDevelopment site (e3b8dce7-c295-4d9e-91fd-24019... |
| y0 | shop: the customer renews the expired license (manual renewal order paid, same key) | **PASS** | active 2027-11-16 18:07:37 |
| y1 | Pro refreshes: access again | **PASS** | active |
| y2 | renewed: suite adapters registered again | **PASS** | workflow-captain,wordpress,woocommerce,pass-captain,voucher-captain,affiliate-captain,wp-license,starter-connector |
| y3 | renewed: the paused run does not continue by itself (needs a human, R-10.8) | **PASS** | [{"state":"paused"},{"state":"paused"}] |
| y4 | renewed: resuming the run sends the webhook with the same event ID, run succeeds | **PASS** | {"resume":true,"run":{"state":"succeeded"}} |
| y5 | renewed: custom connector accepted again with its stored release | **PASS** | not_enabled |
| ms1 | Pro network-activated keeps its license for the network (kernel option network) | **PASS** | true |
| ms2/wcp_full | license activated once for the network (wcp_full) | **PASS** | ok |
| ms3 | workflow.multisite_network active on every site of the network with one activation | **PASS** | {"1":true,"2":true,"3":true,"4":true,"5":true} |
| ms4 | network tables created (schema current) | **PASS** | current |
| ms5 | site admin cannot define network workflows (permission:super_admin) | **PASS** | {"id":0,"violations":["permission:super_admin"]} |
| ms6 | site admin of site 3 may not release site 4, but its own site 3 | **PASS** | ok |
| ms7 | super admin publishes "site A -> site B" and a fan-out to three sites | **PASS** | [{"id":1,"violations":[]},{"id":2,"violations":[]},[],[]] |
| ms8 | preview: source not released yet, seats 4 without limit, no license problem | **PASS** | {"source":{"blog_id":2,"site_problem":null,"consented":false,"paused":false},"seats":{"used":4,"limit":null,"blog_ids":[2,3,4,5]},"license":null} |
| ms9 | source not released: no network run starts (source_not_consented), nothing reaches the targets | **PASS** | [] |
| ms10 | site 2 releases the event family wordpress.post with the field post_type | **PASS** |  |
| ms11 | site A -> site B: parent run succeeded, child step on site 3 succeeded, tag on site 3 | **PASS** | {"parent":"succeeded","children":{"3":"succeeded"}} |
| ms12 | fan-out: one child step per target (3, 4, 5), all succeeded, tag on each target | **PASS** | {"parent":"succeeded","children":{"3":"succeeded","4":"succeeded","5":"succeeded"}} |
| ms13 | nothing ran on the source site itself or on the main site (no tag there) | **PASS** |  |
| ms14 | fan-out: three admin mails, one per target site, with the released field | **PASS** | ["Netz netz-fan 4","Netz netz-fan 4","Netz netz-fan 4"] |
| ms15 | no actor_user_id and no post title in the network tables (field allowlist, R-17.3) | **PASS** | 1710 bytes |
| ms16 | target without consent: its child step pauses (paused_site_consent), the others succeed (partial) | **PASS** | {"parent":"paused_site_consent","children":{"3":"succeeded","4":"succeeded","5":"paused_site_consent:target_not_consented"}} |
| ms17 | new consent resumes the paused child step on site 5 (after its worker ran) | **PASS** | {"3":"succeeded","4":"succeeded","5":"succeeded"} |
| ms18 | site limit 3 (filter workflow_captain_pro_network_site_limit): a fifth site cannot be published (seats:limit_exceeded) | **PASS** | ["seats:limit_exceeded"] |
| ms22 | before the expiry: the fan-out child steps wait for the target workers | **PASS** | {"3":"waiting_site_worker","4":"waiting_site_worker","5":"waiting_site_worker"} |
| ms-shop1 | shop: agency license expired for the multisite test [time simulated] | **PASS** |  |
| ms-refresh/expired | network license refreshed from the shop (expired) | **PASS** | expired |
| ms23 | license expired: the target sites pause the queued runs (foreign_unavailable) instead of running them; no tag | **PASS** | {"10":"paused:foreign_unavailable","11":"paused:foreign_unavailable","12":"paused:foreign_unavailable"} |
| ms23b | license expired: the network view still shows the child steps as waiting (observer off without license) | **PASS** | {"3":"waiting_site_worker","4":"waiting_site_worker","5":"waiting_site_worker"} |
| ms-shop2 | shop: agency license renewed by the customer | **PASS** | active |
| ms-refresh/active | network license refreshed from the shop (active) | **PASS** | active |
| ms24 | renewed: the paused runs on the targets do not continue by themselves (R-17.9) | **PASS** | {"10":"paused:foreign_unavailable","11":"paused:foreign_unavailable","12":"paused:foreign_unavailable"} |
| ms25 | renewed: "resume run" by the super admin continues the three child steps | **PASS** | 3 |
| ms26 | after resume and the target workers: all three child steps succeeded | **PASS** | {"3":"succeeded","4":"succeeded","5":"succeeded"} |
| ms1 | Pro network-activated keeps its license for the network (kernel option network) | **PASS** | true |
| ms2/wcp_basic | license activated once for the network (wcp_basic) | **PASS** | ok |
| ms19 | license without workflow.multisite_network: the feature is off on the network | **PASS** |  |
| ms20 | license without the right: the new post starts no network run (license_inactive), nothing is deleted | **PASS** | 6 runs |
| ms21 | license without the right: publishing is refused (license_inactive) | **PASS** | ["license_inactive"] |
| ms1 | Pro network-activated keeps its license for the network (kernel option network) | **PASS** | true |
| ms2/wcp_full | license activated once for the network (wcp_full) | **PASS** | ok |
| l/voucher | license voucher activated in VoucherCaptainPro against the local shop (signed answer, pinned key) | **PASS** | ok active voucher.white_label |
| t103/2/voucher-captain | site 2: adapter voucher-captain registered | **PASS** | workflow-captain,wordpress,woocommerce,voucher-captain |
| t103/2/wordpress | site 2: adapter wordpress registered | **PASS** | workflow-captain,wordpress,woocommerce,voucher-captain |
| t103/2/woocommerce | site 2: adapter woocommerce registered | **PASS** | workflow-captain,wordpress,woocommerce,voucher-captain |
| t103/3/-voucher-captain | site 3: adapter voucher-captain not registered | **PASS** | workflow-captain,wordpress,woocommerce |
| t103/3/woocommerce | site 3: adapter woocommerce registered | **PASS** | workflow-captain,wordpress,woocommerce |
| t103/4/-voucher-captain | site 4: adapter voucher-captain not registered | **PASS** | workflow-captain,wordpress |
| t103/4/-woocommerce | site 4: adapter woocommerce not registered | **PASS** | workflow-captain,wordpress |
| t103/4/wordpress | site 4: adapter wordpress registered | **PASS** | workflow-captain,wordpress |
| t103/iso | voucher event of site 2 is stored and processed only in the tables of site 2 (site isolation) | **PASS** | {"site2":["done"],"site3":0} |
| t103/off | Voucher Captain deactivated on site 2: its waiting event pauses there (adapter_unregistered) | **PASS** | {"id":"11","type":"voucher.voucher.issued_standalone","state":"paused","last_error":"adapter_unregistered"} |
| t103/3/woocommerce | site 3: adapter woocommerce registered | **PASS** | workflow-captain,wordpress,woocommerce |
| ms-log | multisite PHP error log has no fatal/warning/deprecation from the suite plugins | **PASS** | clean |
| g1 | Workflow Captain: release ZIP (git archive, export-ignore) | **PASS** | 81068ea Starter connector: load its classes inside the core's hooks (E2E suite) |
| g2/workflow-captain-pro | workflow-captain-pro: ZIP from build/build.php (development build: license placeholders not checked) | **PASS** | Development build: placeholders of the license configuration were not checked. Use --release for a ZIP that goes to customers. |
| g2/pass-captain-pro | pass-captain-pro: ZIP from build/build.php (development build: license placeholders not checked) | **PASS** | Development build: placeholders of the license configuration were not checked. Use --release for a ZIP that goes to customers. |
| g2/voucher-captain-pro | voucher-captain-pro: ZIP from build/build.php (development build: license placeholders not checked) | **PASS** | Development build: placeholders of the license configuration were not checked. Use --release for a ZIP that goes to customers. |
| g2/affiliate-captain-pro | affiliate-captain-pro: ZIP from build/build.php (development build: license placeholders not checked) | **PASS** | Development build: placeholders of the license configuration were not checked. Use --release for a ZIP that goes to customers. |
| g3 | Pass Captain: ZIP from build/release.sh (composer --no-dev in the export) | **PASS** | Created /home/user/wp-e2e/suite-src/zip/pass/build/dist/pass-captain.zip from HEAD (588b696, SOURCE_DATE_EPOCH=1794851045) sha256: 0dfe25e33761f659f374b9d1b9... |
| g4 | Voucher Captain: ZIP from bin/build.sh (vendor scoped) | **PASS** | THIRD-PARTY-NOTICES.txt: 8 packages. Built /home/user/wp-e2e/suite-src/zip/voucher/build/voucher-captain.zip 1.3.0 (5.7M, sha256 0a48ed246dca81e9206a878bc8ee... |
| g5 | Affiliate Captain: ZIP from bin/build-zip.sh | **PASS** | 221 checks, 0 failures Built dist/affiliate-captain-0.15.0.zip (1.1M)  |
| g6 | WP License Captain: ZIP from bin/build-zip.sh | **PASS** | wp-license-captain-0.2.0.zip: OK Built dist/wp-license-captain-0.2.0.zip (236 entries)  |
| g7/wp-license | wp-license: installed from its ZIP into a fresh site and activated (no symlink) | **PASS** | Unpacking the package... Installing the plugin... Plugin installed successfully. Warning: wp_version_check(): An unexpected error occurred. Something may be ... |
| g7/workflow-captain | workflow-captain: installed from its ZIP into a fresh site and activated (no symlink) | **PASS** | Unpacking the package... Installing the plugin... Plugin installed successfully. Warning: wp_update_themes(): An unexpected error occurred. Something may be ... |
| g7/pass-captain | pass-captain: installed from its ZIP into a fresh site and activated (no symlink) | **PASS** | Unpacking the package... Installing the plugin... Plugin installed successfully. Warning: wp_update_themes(): An unexpected error occurred. Something may be ... |
| g7/voucher-captain | voucher-captain: installed from its ZIP into a fresh site and activated (no symlink) | **PASS** | Unpacking the package... Installing the plugin... Plugin installed successfully. Warning: wp_update_themes(): An unexpected error occurred. Something may be ... |
| g7/affiliate-captain | affiliate-captain: installed from its ZIP into a fresh site and activated (no symlink) | **PASS** | Unpacking the package... Installing the plugin... Plugin installed successfully. Warning: wp_update_themes(): An unexpected error occurred. Something may be ... |
| g7/workflow-captain-pro | workflow-captain-pro: installed from its ZIP into a fresh site and activated (no symlink) | **PASS** | Unpacking the package... Installing the plugin... Plugin installed successfully. Warning: wp_update_themes(): An unexpected error occurred. Something may be ... |
| g7/pass-captain-pro | pass-captain-pro: installed from its ZIP into a fresh site and activated (no symlink) | **PASS** | Unpacking the package... Installing the plugin... Plugin installed successfully. Warning: wp_update_themes(): An unexpected error occurred. Something may be ... |
| g7/voucher-captain-pro | voucher-captain-pro: installed from its ZIP into a fresh site and activated (no symlink) | **PASS** | Unpacking the package... Installing the plugin... Plugin installed successfully. Warning: wp_update_themes(): An unexpected error occurred. Something may be ... |
| g7/affiliate-captain-pro | affiliate-captain-pro: installed from its ZIP into a fresh site and activated (no symlink) | **PASS** | Unpacking the package... Installing the plugin... Plugin installed successfully. Warning: wp_update_themes(): An unexpected error occurred. Something may be ... |
| g8 | no symlink in the plugins folder of the ZIP site | **PASS** |  |
| g9 | shop: a new customer buys the agency plan for the ZIP site | **PASS** | active |
| g10 | ZIP site: Workflow Captain Pro activated with the purchased license (all workflow.* rights) | **PASS** | ok |
| g11/pass | ZIP site: PassCaptainPro activated (third seat of its license) | **PASS** | ok |
| g11/voucher | ZIP site: VoucherCaptainPro activated (third seat of its license) | **PASS** | ok |
| g11/affiliate | ZIP site: AffiliateCaptainPro activated (third seat of its license) | **PASS** | ok |
| g12 | ZIP site: all suite adapters registered from the release builds | **PASS** | workflow-captain,wordpress,woocommerce,pass-captain,voucher-captain,affiliate-captain,wp-license |
| g13 | ZIP site: one WooCommerce order produces processed Pass, Voucher and Affiliate events | **PASS** | ["affiliate.affiliate.created","affiliate.sale.attributed","affiliate.commission.created","pass.order.paid","pass.order.tickets_issued","voucher.order.vouche... |
| g14 | shop: release 0.1.1 of Workflow Captain Pro created and published (wp wplc release create) | **PASS** | 1 |
| g15 | ZIP site: the updater of Pro sees version 0.1.1 from the shop | **PASS** | {"update_available":true,"current_version":"0.1.0","product_key":"workflow-captain-pro","channel":"stable","license_active":true,"name":"Workflow Captain Pro... |
| g15b | ZIP site: the updater offers 0.1.1 with its own package placeholder (download only through the license client) | **PASS** | {"id":"https://shop.test/workflow-captain-pro","slug":"workflow-captain-pro","plugin":"workflow-captain-pro/workflow-captain-pro.php","version":"0.1.1","url"... |
| g17 | shop: refund of the purchase revokes the license | **PASS** | revoked |
| g18 | ZIP site: after the refund Pro has no access, its features are off | **PASS** | deactivated |
| g19 | ZIP site: the license can be removed from the site (deactivation) | **PASS** | wplc_not_activated |
| g20 | ZIP site PHP error log has no fatal/warning/deprecation from the suite plugins | **PASS** | clean |
| z1 | suite PHP error log has no fatal/warning/deprecation from the suite plugins | **PASS** | clean |

## Nachtrag beim Zusammenführen (16.11.2026)

- Übernommen: Fix des Starter-Connectors (Kern), `order_id` beim Sperren nach Erstattung (Voucher `0388cde`), „Lauf fortsetzen“ nach Lizenzverlängerung (captain-pro).
- Nicht übernommen: „eine Lizenz für das ganze Netzwerk“ (`0ce26a7`, Branch `wt/suite-fix` in captain-pro, lokal). Er widerspricht der gleichzeitig gebauten Platzzählung je Unterwebsite (WF-MS-02/-25, Entscheidung B2 des Lizenzservers: jede Unterwebsite zählt als eigene Website). Der doppelte Migrationsfix (`a22b127`) war schon enthalten. Die Lizenzseite im Network Admin (Befund 5 in `E2E-RESULTS.md`) bleibt damit offen, bis der Inhaber entscheidet: eine Aktivierung je Netzwerk oder je Unterwebsite.
