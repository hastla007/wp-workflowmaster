# E2E-Suite: Workflow Captain mit Pro, den Captain-Plugins und dem Lizenzserver

Stand: 16.11.2026, Branch `wt/suite` (Basis `claude/plugin-todo-liste-2a83v4`, `7e04e16`). Erzeugt mit
`bin/e2e/suite/setup.sh` und `bin/e2e/suite/run-suite.sh` (nicht ausgeliefert, `/bin` ist `export-ignore`).
**206 von 207 Prüfungen bestehen**; die eine offene (r4b) ist ein Befund in Affiliate Captain, unten erklärt.
Ein Lauf auf frischen Datenbanken dauert etwa 2,5 Minuten. **Zweiter Lauf** (Aktivierung je Unterwebsite, neue Fälle):
283 von 284, siehe Abschnitt „Zweiter Lauf“ am Ende; er ersetzt die Multisite-Ergebnisse (ms*) des ersten Laufs.

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
bin/e2e/suite/setup.sh           # Suite-Seiten, Klon von captain-pro (wt/suite2, sonst Branch), Server :8083 :8084 :8086
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

## Zweiter Lauf (16.11.2026): Aktivierung je Unterwebsite

**283 von 284 Prüfungen bestehen** (frische Datenbanken, alle Phasen einschließlich Release-Gate, 3:40 Minuten). Die eine
offene ist weiter r4b (Befund 2, Affiliate Captain, Entscheidung offen).

Der erste Lauf prüfte die Multisite mit „eine Lizenz für das ganze Netzwerk“ (`0ce26a7`), der nicht übernommen wurde.
Dieser Lauf prüft das übernommene Modell: Jede beteiligte Unterwebsite aktiviert selbst am Lizenzserver und belegt
einen Live-Platz (Entscheidung B2), jede Website meldet Lizenz, `site_limit` und `site_count` in die Netzwerktabelle
`sites`, Veröffentlichen verlangt eine Aktivierung je beteiligter Blog-ID. Die Hauptwebsite (Network Admin) hat im
Lauf **keine** eigene Aktivierung, wie es `WORKFLOW-NETZWERK-BETRIEB.md` (Abschnitt 2) beschreibt.

### Stand der Plugins

| Plugin | Quelle |
| --- | --- |
| Workflow Captain | `/home/user/wt-suite` (Branch `wt/suite`, Basis `68bf79d`), Skripte nehmen jetzt den eigenen Checkout |
| Workflow Captain Pro und die Pro-Add-ons | Klon in `suite-src/captain-pro`, Branch `wt/suite2` von captain-pro = `claude/plugin-todo-liste-2a83v4` (`a275156`) + Fix `176e339`; `wt/suite-fix` des ersten Laufs wird nicht mehr genommen |
| übrige | wie im ersten Lauf (WP License Captain `1fda4bc`) |

Neuer Plan im Shop: „Workflow Captain Pro Netzwerk“ (alle acht `workflow.*`-Rechte, **4 Live-Plätze**, `wcp_net`).
Websites: 1 Hauptwebsite, 2 `quelle`, 3 `ziel`, 4 `drei`, 5 `vier`. Websites 2 bis 5 aktivieren `wcp_net` als
`production`.

### Was neu oder anders ist

| Phase | Inhalt | Prüfungen | TODO |
| --- | --- | --- | --- |
| ms (neu gefasst) | Aktivierung je Unterwebsite, Meldung in `sites`, Network Admin ohne eigene Aktivierung | ms1–ms4, ms2/*, ms3b | WF-MS-22, WF-MS-25 |
| ms | Plätze aus dem Lizenzvertrag statt Filter: der Server lehnt die fünfte Live-Aktivierung ab (409 `site_limit`), Pro lehnt fünf Blog-IDs bei `site_limit` 4 ab (`seats:limit_exceeded`, `seats:site_unlicensed:1`), auch wenn Website 1 eine Entwicklungsaktivierung hat | ms-seat-server/1, ms8, ms18–ms18c | WF-MS-22 |
| ms | Lizenzwechsel je Website: Ziel 4 wechselt auf den Basisplan (ohne Netzwerkrecht) und zurück; Quelle 2 wechselt | ms22, ms27–ms32, ms20, ms21 | WF-MS-22 |
| ms | Ablauf der Lizenz aller vier Websites im Shop, Aktualisierung je Website, Verlängerung, „Lauf fortsetzen“ im Network Admin | ms23–ms26 | WF-MS-22 |
| ms | Capability-Entzug: Veröffentlicher verliert Super-Admin (`publisher_not_authorized`), Website-Admin verliert seine Website | ms33–ms35 | WF-MS-23 |
| ms | Fachplugin fehlt auf Site B (Voucher Captain, Netzwerkvertrag für `voucher.issue` aus dem mu-plugin der Suite) | ms36–ms38b | WF-MS-23 |
| ms | Zeitüberschreitung eines Zielschritts nach der Nebenwirkung (Mail übergeben, dann Transportfehler) | ms39–ms40b | WF-MS-23 |
| ms | Cron-Ausfall einer Ziel-Website (`site_worker_late`, Status „verspätet“), doppelte Zustellung desselben Quellereignisses | ms41–ms43b | WF-MS-23 |
| ms | Website als gelöscht markiert und wieder aktiviert; endgültig gelöscht und aus einer Sicherung (Tabellen und `wp_blogs`-Zeile) wiederhergestellt | ms44–ms48b | WF-MS-23 |
| ms | Blog-Wechsel und Cache-Isolation (ohne persistenten Objekt-Cache) | ms49, ms49b | WF-MS-23 |
| connectors (neu) | Starter-Connector mit Pro-Lizenz: Freigabe, Lauf, Duplikat, Zeitüberschreitung nach erfolgreichem Aufruf, Recht des Veröffentlichers fehlt, Connector-Plugin deaktiviert, Update auf Vertrag 2.0.0 | kc0–kc11 | WF-CONNECTOR-13 |
| resilience (neu) | Webhook-Schritt: Empfänger nimmt an, antwortet nach dem Timeout (12 s gegen 10 s); Wiederherstellung der Kern-Tabellen aus einer Sicherung | tm1, tm2, rs0–rs2 | T88, WF-INT-17, WF-CONNECTOR-13 |
| rotation (neu) | Secret einer Verbindung neu erzeugt (alt 401, neu 202); Hauptschlüssel des Tresors gewechselt (`WORKFLOW_CAPTAIN_SECRET_KEY_PREVIOUS`, `wp workflow-captain secrets`) | sr1–sr4 | WF-CONNECTOR-13 |

Hilfen der Suite (nur Testseite, `bin/e2e/suite/mu-plugins/e2e-suite.php`): Option `e2e_suite_mail_timeout` (Mail wird
übergeben, dann Ausnahme), `e2e_suite_note_timeout` (Vermerk des Connectors gespeichert, dann Ausnahme),
Netzwerkoption `e2e_suite_net_contract` (Netzwerkvertrag für `voucher.issue`), `e2e_suite_net_publish` (siehe
Befund 8). Der Empfänger antwortet verspätet, solange `logs/suite-hooks-slow` einen Pfad nennt.

### Gefundene und behobene Fehler

| Commit | Repo, Branch | Fehler | Folge | Regressionstest |
| --- | --- | --- | --- | --- |
| `176e339` | captain-pro, `wt/suite2` (lokal) | `resume_run()`, das Fortsetzen nach neuer Freigabe und „Ziel-Websites neu bestätigen“ fragten die Lizenz der **aktuellen** Website (`plane_problem()`) | Im Network Admin ohne eigene Aktivierung setzte nichts fort: ms17, ms25, ms31b liefen ins Leere, Schritte blieben blockiert | `test_network_admin_without_own_activation_resumes_and_continues` |
| `176e339` | captain-pro, `wt/suite2` (lokal) | Lebenszyklus-Hooks (Archivieren, Löschen, Spam, neu angelegt, Domain) nur registriert, wenn die aktuelle Website lizenziert ist | Löschen einer Website im Network Admin blieb unbemerkt: Workflow blieb aktiv, Schritt „wartet“ (ms45, ms47) | `test_lifecycle_works_without_a_license_on_the_current_site` |
| `176e339` | captain-pro, `wt/suite2` (lokal) | Nach Wiederherstellung einer Website aus einer Sicherung, die älter ist als der Lauf, kennt die Website den lokalen Lauf nicht | Kindschritt „wartet auf den Worker“ für immer | `test_resume_does_not_wait_for_a_run_the_restored_target_does_not_know` (jetzt `blocked_provider:payload_expired`, ms48) |

Neu: `Policy::manage_problem()` – Verwalten, Bestätigen und Fortsetzen gehen, sobald eine beteiligte Website eine Lizenz
meldet; jedes Ziel prüft weiter seinen eigenen Platz (`seat_problem()`), sein Worker die eigene Lizenz (`gate()`).
Doku und TODO in captain-pro: `7e05e59` (Supporttabelle um `action_not_network_safe` und `payload_expired`, WFC-PRO-17).

Prüfungen: captain-pro PHPUnit 672 Tests (8 übersprungen), `bin/lint.sh`, `make-pot` (POT neu, de_DE und de_DE_formal
439 von 439), `build.php --addon=workflow-captain-pro`. Kern: `php tests/run.php` 745 bestanden, `license-guard`,
`make-pot --check` (459), `validate-spec-examples.py`, `builder.test.js`, `settings.test.js` grün.

### Befunde (nicht behoben)

7. **Produktbefehle sind nicht netzwerkfähig.** Ein Netzwerkvertrag für `voucher.issue` lässt sich erklären und
   veröffentlichen, aber auf der Ziel-Website lehnt der Kern den Schritt ab (`action_not_network_safe`, ms38b):
   `VoucherCommand` implementiert `NetworkSafe` nicht, und kein Produkt erklärt einen Netzwerkvertrag (WFC-PRO-17 d).
   Fehlt das Fachplugin auf dem Ziel, pausiert der Schritt mit `action_unavailable` (ms37), nicht mit
   `provider_missing`: Die Aktion ist dann gar nicht registriert, die Vertragsprüfung kommt nicht zum Zug.
   „Inkompatibler Vertrag“ einer Netzwerkaktion ist deshalb auf echter Multisite nicht erreichbar (die fünf Aktionen
   des Kerns haben den Vertrag des Kerns selbst); geprüft nur mit den Doppeln in captain-pro.
8. **Ereignisse des Kerns sind nicht netzwerkfähig erklärt.** `wordpress.post.published` kann keinen
   Netzwerk-Workflow starten (`source:not_publishable`, ms7a); nur die Suite-Adapter von Pro erklären ihre Ereignisse.
   Die Suite erklärt das Ereignis mit dem Feld `post_type` über den dokumentierten Filter
   `workflow_captain_pro_network_publishable_v1`. Ob Pro die Ereignisse des Kerns (`wordpress.*`, `woocommerce.*`)
   selbst erklären soll, entscheidet der Inhaber.
9. **Wiederherstellung aus älterer Sicherung:** Der Schritt kann nicht nachgeholt werden, weil die Daten das Netzwerk
   nach dem Einreihen verlassen haben (R-17.3); er blockiert jetzt sichtbar mit `payload_expired` (ms48). Ein neues
   Ereignis läuft danach normal (ms48b). Auf einer Einzelseite wird ein Ereignis nach der Wiederherstellung erneut
   verarbeitet und der Webhook mit derselben Ereignis-ID wiederholt (rs2): mindestens einmal, der Empfänger muss
   über die ID entdoppeln.
10. **Zeitüberschreitung nach der Mail** bleibt bei „Braucht eine Entscheidung“ (`unclear_outcome`, Netzwerk
    `manual_review`); „Lauf fortsetzen“ wiederholt sie nicht (ms40, ms40b). So gewollt (R-8.12).
11. **Website-Admin verliert seine Website:** Er kann für sie nichts mehr freigeben oder widerrufen; die von ihm
    gegebene Freigabe bleibt bestehen, weil sie der Website gehört (ms35). Bewusst so gebaut; ob ein Rollenentzug
    Freigaben zurücknehmen soll, wäre eine Entscheidung des Inhabers.
12. **Ungültige Signatur und Replay** gibt es beim Starter-Connector nicht (er läuft im selben Prozess). Geprüft sind
    sie am eingehenden Webhook von Pro mit dem `InboundGuard` des Kerns (h13, h14, sr2); Connectoren mit externem
    Eingang (learnboating, boatingschool) nutzen denselben Guard und prüfen es in ihren eigenen E2E-Läufen.

### Abdeckung der TODO-Aufgaben

| Aufgabe | Belegt durch | Nicht in dieser Umgebung prüfbar |
| --- | --- | --- |
| WF-MS-22 | Plätze aus Vertrag und Server (ms-seat-server/1, ms8, ms18–ms18c), Zielausführung ohne Platz gestoppt (ms27, ms28, ms30), Lizenzwechsel je Website (ms20, ms21, ms27–ms32), Ablauf und Verlängerung erst nach „Lauf fortsetzen“ (ms23–ms26, ms31) | – |
| WF-MS-23 | A → B, Fan-out, Freigaben, Rechte (ms5–ms17), Capability-Entzug (ms33–ms35), Plugin fehlt (ms37), Timeout nach Nebenwirkung (ms40), Duplikat (ms43b), Cron-Ausfall (ms42, ms43), Teilfehler und Opt-out im Lauf (ms16, ms28), Löschen und Restore (ms45–ms48b), Lizenzablauf (ms23), PII (ms15), Blog-Wechsel (ms49) | Cache-Isolation mit persistentem Objekt-Cache (kein Redis/Memcached, ms49b); inkompatibler Vertrag einer Netzwerkaktion (Befund 7) |
| WF-CONNECTOR-12 | n4, b5, k1, k2, x2–x9, y1–y5 (erster Lauf), kc0 | – |
| WF-CONNECTOR-13 | App deaktiviert kc7–kc9, Contract-Major kc10/kc11, Rechte kc5/kc6, Signatur und Replay h13/h14/sr2, Duplikat kc2, Timeout nach Zielaufruf kc3/kc4 und tm1/tm2, Secret-Rotation sr1–sr4, Retry kc3/kc4, PII e8/h12, Lizenzablauf x4/y5 | – |
| T88 | p, q, m (erster Lauf), Timeout nach Zielaufruf tm1/tm2, Restore rs0–rs2 | – |
| T103 | t103/* (unverändert grün) | – |
| WF-INT-17 | Plugin fehlt p1–p7, kc7–kc9; Lizenz x, ms23; Contract-Major m1–m6, kc10; Timeout tm1/tm2, ms40; Replay h13; Duplikat kc2, ms43b; Refund r0–r7; Restore rs0–rs2, ms45–ms48b; Isolation t103/iso, ms49; Cross-Site-Rechte ms5, ms6, ms34, ms35 | Checkout Blocks (WooCommerce ohne JS-Build), andere WordPress-/PHP-/WooCommerce-Versionen (nur WP 6.9.11, PHP 8.3.6, WC 9.9.5) |

### Ergebnisse (zweiter Lauf, 16.11.2026)

| ID | Prüfung | Ergebnis | Detail |
| --- | --- | --- | --- |
| i1 | shop signing key pinned for the Pro add-ons | **PASS** | E0cFj5Jx9BxhKQGJExQUWJzIbgYOtwYrZ0Vtyz9iU+c= |
| s1 | WP License Captain active with its tables (vendor shop on the suite site) | **PASS** | 0.2.0 |
| s0/agency | license plan agency (workflow-captain-pro) mapped to a product | **PASS** | workflow.suite_integrations,workflow.webhooks,workflow.event_correlation,workflow.advanced_logic,workflow.recipe_templates,workflow.extended_logs,workflow.cu... |
| s0/basic | license plan basic (workflow-captain-pro) mapped to a product | **PASS** | workflow.suite_integrations,workflow.webhooks limit 3 |
| s0/single | license plan single (workflow-captain-pro) mapped to a product | **PASS** | workflow.suite_integrations,workflow.webhooks,workflow.event_correlation,workflow.advanced_logic,workflow.recipe_templates,workflow.extended_logs,workflow.cu... |
| s0/network4 | license plan network4 (workflow-captain-pro) mapped to a product | **PASS** | workflow.suite_integrations,workflow.webhooks,workflow.event_correlation,workflow.advanced_logic,workflow.recipe_templates,workflow.extended_logs,workflow.cu... |
| s0/pro | license plan pro (pass-captain-pro) mapped to a product | **PASS** | pass.write_api limit 3 |
| s0/pro | license plan pro (voucher-captain-pro) mapped to a product | **PASS** | voucher.white_label limit 3 |
| s0/pro | license plan pro (affiliate-captain-pro) mapped to a product | **PASS** | affiliate.extended_api limit 3 |
| s2/wcp_full | paid order issues a license (wcp_full) | **PASS** | workflow-captain-pro active until 2027-11-16 18:50:33 |
| s2/wcp_basic | paid order issues a license (wcp_basic) | **PASS** | workflow-captain-pro active until 2027-11-16 18:50:33 |
| s2/wcp_one | paid order issues a license (wcp_one) | **PASS** | workflow-captain-pro active until 2027-11-16 18:50:33 |
| s2/wcp_net | paid order issues a license (wcp_net) | **PASS** | workflow-captain-pro active until 2027-11-16 18:50:34 |
| s2/pass | paid order issues a license (pass) | **PASS** | pass-captain-pro active until 2027-11-16 18:50:34 |
| s2/voucher | paid order issues a license (voucher) | **PASS** | voucher-captain-pro active until 2027-11-16 18:50:34 |
| s2/affiliate | paid order issues a license (affiliate) | **PASS** | affiliate-captain-pro active until 2027-11-16 18:50:34 |
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
| e0 | setup: license, voucher, ticket product, affiliate and a paid WooCommerce order | **PASS** | {"license":"8","voucher":"ok","affiliate":1,"order":"30 processing"} |
| e6 | every stored suite envelope is valid (core validator, before processing) | **PASS** | [] |
| e8 | no e-mail address or name of buyer, recipient or customer in any stored envelope (R-5, before processing) | **PASS** | [] |
| e1 | license server events captured (issued, status_changed) | **PASS** | ["license.license.status_changed","license.license.issued"] |
| e2 | Voucher Captain events captured (standalone voucher, vouchers of the order) | **PASS** | ["voucher.voucher.issued_standalone","voucher.order.vouchers_issued","voucher.voucher.delivered"] |
| e3 | Pass Captain events captured from the WooCommerce order (order paid, tickets issued) | **PASS** | ["pass.order.paid","pass.order.tickets_issued"] |
| e4 | Affiliate Captain events captured (commission created) | **PASS** | ["affiliate.affiliate.created","affiliate.sale.attributed","affiliate.commission.created"] |
| e5 | every suite event processed (done), none paused or failed | **PASS** | [] |
| e7 | each provider workflow ran and succeeded | **PASS** | {"license.license.issued":"succeeded,succeeded,succeeded,succeeded,succeeded,succeeded,succeeded","voucher.voucher.issued_standalone":"succeeded","pass.order... |
| c1 | Workflow Captain Pro offers the four voucher commands as actions | **PASS** | notify.admin,notify.user,workflow.tag.set,workflow.tag.remove,workflow.note.add,voucher.issue,voucher.void,voucher.reactivate,voucher.redeem,workflow.webhook... |
| c2 | Voucher Captain core has the four entry points (contract >= 1.3.0) | **PASS** | 1.3.0 |
| c3 | voucher.void switched off in Voucher Captain: the step is refused (command_disabled, final), the run needs a decision, voucher stays active | **PASS** | {"run":{"id":"13","state":"action_required","last_error":"voucher:command_disabled"},"steps":["action_required:voucher:command_disabled"]} |
| c4 | voucher.void switched on: the run voids the voucher; its voided event is captured as caused by the workflow | **PASS** | {"run":"succeeded","status":"void","event":1} |
| c5 | protocol of Voucher Captain lists both calls (refused and done) with workflow, run and step, without code | **PASS** | ["refused:command_disabled:wf6","done:voided:wf6"] |
| c6 | voucher.issue above the ceiling of 100 EUR cannot be published | **PASS** |  |
| c7 | voucher.issue: a new user (by the admin) gets a 5 EUR voucher through the command, run succeeds | **PASS** | {"runs":["succeeded"],"vouchers":1} |
| r0 | full WooCommerce refund of the order with ticket, voucher and affiliate | **PASS** | refund 35, order refunded |
| r1 | exactly one woocommerce.order.refunded with the refund ID (the canonical refund) | **PASS** | [{"subject":"order:30","data":{"refund_id":35,"full_refund":true},"state":"received"}] |
| r2 | Voucher Captain: voucher voided because of the refund (void_reason refund, same order) | **PASS** | [{"subject":"voucher:3","data":{"order_id":30,"void_reason":"refund"},"state":"received"}] |
| r3 | Pass Captain: refund mirrored from WooCommerce (pass.order.refunded with reason_class woocommerce, pass.refund.completed) | **PASS** | {"order_refunded":[{"subject":"order:e133d7912eea89f514b7333eabd9aba1","data":{"reason_class":"woocommerce"},"state":"received"}],"refund_completed":[{"subje... |
| r4 | Affiliate Captain: commission adjusted on the same WooCommerce order (subject order, adjustment_type refund) | **PASS** | [{"subject":"order:30","data":{"source":"woocommerce","adjustment_type":"refund","full":true},"state":"received"}] |
| r4b | Affiliate Captain: the full refund carries the WooCommerce refund_id (optional field; full reversal by order status has none) | **FAIL** | [{"subject":"order:30","data":{"source":"woocommerce","adjustment_type":"refund","full":true},"state":"received"}] |
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
| h3 | test request signed with the HMAC secret from wp-config.php (InboundGuard::sign) | **PASS** | ddeee9e2903770a1fe9d98b040fbeff556b92585b98488d4fc90962fa14c17b9 |
| h4 | both connections activated after the test | **PASS** | [{"wcp_notice":"activated"},{"wcp_notice":"activated"}] |
| h5 | workflow with the step workflow.webhook.send published | **PASS** |  |
| h6 | outbox: one row for pass.order.paid of the forwarding connection | **PASS** | [{"id":"1","connection":"suite-n8n","event_id":"3d494913-498d-4fde-bbf4-24a2b08258c2","state":"pending"}] |
| h7 | outbox sent pass.order.paid to the receiver, signed, with the event ID of Workflow Captain | **PASS** | {"id":"3d494913-498d-4fde-bbf4-24a2b08258c2","signed":true} |
| h8 | forwarded data holds only the selected fields | **PASS** | {"currency":"EUR","total_minor":2500,"ticket_count":0} |
| h9 | workflow step sent woocommerce.order.paid to the receiver, run succeeded | **PASS** | {"requests":1,"runs":["succeeded"]} |
| h10 | outbox row marked sent | **PASS** | ["sent"] |
| h11 | inbound: signed request accepted with 202 after the core stored it | **PASS** | {"code":202,"body":"{\"status\":\"accepted\",\"request_id\":\"0d2573a66e44c8e27c001961\",\"result\":\"captured\"}","nonce":"0d2573a66e44c8e27c001961"} |
| h12 | inbound event workflow.inbound.crm_lead stored with only the declared fields (no email) | **PASS** | {"lead_id":7,"source":"web"} |
| h13 | inbound replay of the same nonce refused (409) | **PASS** | {"code":409,"body":"{\"code\":\"workflow_captain_pro_replay\",\"message\":\"replay\",\"data\":{\"status\":409}}","nonce":"0d2573a66e44c8e27c001961"} |
| h14 | inbound with a wrong signature refused (401) | **PASS** | {"code":401,"body":"{\"code\":\"workflow_captain_pro_bad_signature\",\"message\":\"bad_signature\",\"data\":{\"status\":401}}","nonce":"9d9d26b8acc3fc5d9522b... |
| h15 | inbound with an undeclared trigger refused (403) | **PASS** | {"code":403,"body":"{\"code\":\"workflow_captain_pro_trigger_not_allowed\",\"message\":\"trigger_not_allowed\",\"data\":{\"status\":403}}","nonce":"ffb50ca71... |
| x0 | shop: the agency license of Workflow Captain Pro expired (paid period ended 20 days ago, grace of 14 days over) [time simulated] | **PASS** | expired until 2026-10-27 18:51:17 |
| x1 | Pro refreshes from the shop: no access any more | **PASS** | ok expired expired |
| x2/workflow.suite_integrations | expired license: feature off (workflow.suite_integrations) | **PASS** |  |
| x2/workflow.webhooks | expired license: feature off (workflow.webhooks) | **PASS** |  |
| x2/workflow.custom_connectors | expired license: feature off (workflow.custom_connectors) | **PASS** |  |
| x2/workflow.multisite_network | expired license: feature off (workflow.multisite_network) | **PASS** |  |
| x3 | expired license: no suite adapter registered (Pass, Voucher, Affiliate, license server) | **PASS** | workflow-captain,wordpress,woocommerce |
| x4 | expired license: custom connector refused runtime_not_allowed; its release stays stored | **PASS** | runtime_not_allowed |
| x5 | expired license: the new run with the webhook step pauses (action_unavailable), nothing is sent | **PASS** | {"run":{"id":"18","state":"paused","last_error":"action_unavailable"},"steps":["a0:paused:action_unavailable"]} |
| x6 | expired license: the outbox queues nothing new (no pass events captured) | **PASS** | ["sent"] |
| x7 | expired license: inbound route answers 403 license_inactive | **PASS** | {"code":403,"body":"{\"code\":\"workflow_captain_pro_license_inactive\",\"message\":\"license_inactive\",\"data\":{\"status\":403}}","nonce":"c70fa3043df71a2... |
| x8 | expired license: connections and their settings stay readable (nothing deleted) | **PASS** | ["suite-n8n","suite-step"] |
| x9 | license screen says the license expired | **PASS** | Workflow Captain Pro – LicenseStatusExpiredPaid untilOctober 27, 2026 7:51 pmLive sites in use0 / 3EnvironmentDevelopment site (9874c809-4590-4f07-bfbd-b6f44... |
| y0 | shop: the customer renews the expired license (manual renewal order paid, same key) | **PASS** | active 2027-11-16 18:51:20 |
| y1 | Pro refreshes: access again | **PASS** | active |
| y2 | renewed: suite adapters registered again | **PASS** | workflow-captain,wordpress,woocommerce,pass-captain,voucher-captain,affiliate-captain,wp-license,starter-connector |
| y3 | renewed: the paused run does not continue by itself (needs a human, R-10.8) | **PASS** | [{"state":"paused"},{"state":"paused"}] |
| y4 | renewed: resuming the run sends the webhook with the same event ID, run succeeds | **PASS** | {"resume":true,"run":{"state":"succeeded"}} |
| y5 | renewed: custom connector accepted again with its stored release | **PASS** | not_enabled |
| kc0 | connector released by the operator; an admin publishes "ticket closed -> note" (Pro license with workflow.custom_connectors) | **PASS** | {"reason":null,"violations":[],"publish":[]} |
| kc1 | ticket 42 closed: event captured, run succeeded, one note | **PASS** | {"run":{"id":20,"state":"succeeded","steps":["a0:succeeded","end:succeeded"]},"notes":1} |
| kc2 | duplicate: the same ticket closed twice more stores no new event and starts no run (discriminator "once") | **PASS** | {"events":1,"runs":1} |
| kc3 | timeout after the note was written: the idempotent step waits for a retry (no failure, no decision needed) | **PASS** | {"run":{"id":21,"state":"running","steps":["a0:retry_wait:exception:RuntimeException"]},"notes":1} |
| kc4 | retry with the same idempotency key: the connector sees the key, writes no second note, run succeeded | **PASS** | {"run":{"id":21,"state":"succeeded","steps":["a0:succeeded","end:succeeded"]},"result":{"ticket_id":43,"repeated":true},"notes":1} |
| kc5 | publisher lost manage_options: the connector step pauses (not_authorized), no note | **PASS** | {"id":22,"state":"paused:not_authorized","steps":["a0:paused:not_authorized"]} |
| kc6 | right back: continuing the step writes the note, run succeeded | **PASS** | {"id":22,"state":"succeeded","steps":["a0:succeeded","end:succeeded"]} |
| kc7 | connector plugin deactivated: its waiting event pauses (adapter_unregistered), nothing lost, no run | **PASS** | [{"id":"96","state":"paused","last_error":"adapter_unregistered"}] |
| kc8 | connector deactivated: a new workflow with its trigger cannot be published | **PASS** |  |
| kc9 | connector active again: requeue processes the paused event, note written once | **PASS** | {"requeued":1,"event":[{"id":"96","state":"done","last_error":null}],"notes":1} |
| kc10 | connector updated to contract 2.0.0: the run of the workflow published on 1.x pauses at its step (provider_contract_changed), no note | **PASS** | {"id":24,"state":"paused:provider_contract_changed","steps":["a0:paused:provider_contract_changed"]} |
| kc11 | back on contract 1.0.0: the connector is registered again with its old MAJOR | **PASS** | 14 |
| tm1 | receiver got the webhook but answered after the timeout: the step waits for a retry (transport error), the run did not fail | **PASS** | {"requests":1,"run":{"id":25,"state":"running","steps":["a0:retry_wait:webhook:transport_http_request_failed"]}} |
| tm2 | retry sends again with the same event ID (the receiver can drop the duplicate), run succeeded | **PASS** | {"requests":1,"same_id":true,"run":{"id":25,"state":"succeeded","steps":["a0:succeeded","end:succeeded"]}} |
| rs0 | restore: the event of a new order waits for the worker (backup taken now) | **PASS** | received |
| rs1 | before the restore: the webhook went out once with the event ID of Workflow Captain | **PASS** | {"requests":1,"event":"done"} |
| rs2 | after the restore: the event is processed again (at least once), the repeated webhook carries the same event ID; one event, no second event row | **PASS** | {"requests":1,"same_id":true,"events":1} |
| sr1 | inbound connection with a secret in the vault (not wp-config): signed request accepted | **PASS** | [{"wcp_notice":"created","edit":"suite-rot"},{"wcp_notice":"activated"},202] |
| sr2 | secret rotated: the old secret is refused at once (401), the new one accepted (202) | **PASS** | [{"wcp_notice":"regenerated","edit":"suite-rot"},401,202] |
| sr3 | new master key, old one as WORKFLOW_CAPTAIN_SECRET_KEY_PREVIOUS: the secret is still readable and accepted before re-encryption | **PASS** | ["set",202] |
| sr3b | dry run counts the connection secret as "to re-encrypt" (old key) | **PASS** | workflow-captain-pro:suite-rot:inbound: 0 current, 1 to re-encrypt, 0 unreadable |
| sr3c | wp workflow-captain secrets re-encrypts it with the new key (counts only, no values) | **PASS** | workflow-captain-pro:suite-rot:inbound: 0 current, 1 re-encrypted, 0 unreadable |
| sr4 | "wp workflow-captain secrets" re-encrypted the stores; after removing the previous key the secret works with the new key alone | **PASS** | ["set",202] |
| ms1 | Pro network-activated licenses per site (kernel option network is off; every subsite has its own activation, B2) | **PASS** | false |
| ms4 | network tables created (schema current) | **PASS** | current |
| ms2/2/wcp_net | site 2: own activation of the wcp_net license (production) against the shop | **PASS** | active production 1/4 |
| ms2/3/wcp_net | site 3: own activation of the wcp_net license (production) against the shop | **PASS** | active production 2/4 |
| ms2/4/wcp_net | site 4: own activation of the wcp_net license (production) against the shop | **PASS** | active production 3/4 |
| ms2/5/wcp_net | site 5: own activation of the wcp_net license (production) against the shop | **PASS** | active production 4/4 |
| ms3 | every participating site reports its own seat in the network table (licensed, site_limit 4, site_count 4 of the license) | **PASS** | {"1":{"licensed":false,"state":"unlicensed","limit":0,"count":0},"2":{"licensed":true,"state":"active","limit":4,"count":4},"3":{"licensed":true,"state":"act... |
| ms3b | main site without activation: the feature is off there, the Network Admin still works (a participating site holds a license) | **PASS** | {"main":{"licensed":false,"state":"unlicensed","limit":0,"count":0},"admin_problem":null} |
| ms-seat-server/1 | site 1: a further live activation of the wcp_net license is refused by the license server (live-site limit of the contract) | **PASS** | site_limit: All sites of this license are in use. Release a site in your customer account or upgrade the license. |
| ms5 | site admin cannot define network workflows (permission:super_admin) | **PASS** | {"id":0,"violations":["permission:super_admin"]} |
| ms6 | site admin of site 3 may not release site 4, but its own site 3 | **PASS** | ok |
| ms7a | wordpress.post.published is not declared network-publishable: publishing is refused (source:not_publishable) | **PASS** | ["source:not_publishable"] |
| ms7 | super admin publishes "site A -> site B" and a fan-out to three sites (main site without activation) | **PASS** | [2,[],3,[]] |
| ms8 | preview: source not released yet, 4 sites of 4 (site_limit of the license, no filter), every site with its own activation | **PASS** | {"source":{"blog_id":2,"site_problem":null,"consented":false,"paused":false,"publishable":true,"provider":"wordpress","licensed":true,"cron":"ok"},"seats":{"... |
| ms9 | source not released: no network run starts (source_not_consented), nothing reaches the targets | **PASS** | [] |
| ms10 | site 2 releases the event family wordpress.post with the field post_type | **PASS** |  |
| ms11 | site A -> site B: parent run succeeded, child step on site 3 succeeded, tag on site 3 | **PASS** | {"parent":"succeeded","children":{"3":"succeeded"}} |
| ms12 | fan-out: one child step per target (3, 4, 5), all succeeded, tag on each target | **PASS** | {"parent":"succeeded","children":{"3":"succeeded","4":"succeeded","5":"succeeded"}} |
| ms13 | nothing ran on the source site itself or on the main site (no tag there) | **PASS** |  |
| ms14 | fan-out: three admin mails, one per target site, with the released field | **PASS** | ["Netz netz-fan 4","Netz netz-fan 4","Netz netz-fan 4"] |
| ms15 | no actor_user_id and no post title in the network tables (field allowlist, R-17.3) | **PASS** | 2572 bytes |
| ms16 | target without consent: its child step pauses (paused_site_consent), the others succeed (partial) | **PASS** | {"parent":"paused_site_consent","children":{"3":"succeeded","4":"succeeded","5":"paused_site_consent:target_not_consented"}} |
| ms17 | new consent (given in the Network Admin) resumes the paused child step on site 5 (after its worker ran) | **PASS** | {"3":"succeeded","4":"succeeded","5":"succeeded"} |
| ms18 | five sites with a license for four: publishing is refused (seats:limit_exceeded from the signed site_limit, seats:site_unlicensed:1) | **PASS** | ["seats:site_unlicensed:1","seats:limit_exceeded"] |
| ms18b | preview names the site without its own activation and "5 sites of 4" | **PASS** | {"used":5,"limit":4,"blog_ids":[1,2,3,4,5],"unlicensed":[1],"license_limit":4,"license_count":4} |
| ms2/1/wcp_net | site 1: own activation of the wcp_net license (development) against the shop | **PASS** | active development 4/4 |
| ms18c | site 1 with a development activation: still refused, only seats:limit_exceeded (blog IDs count against site_limit) | **PASS** | ["seats:limit_exceeded"] |
| ms-release/1 | site 1: activation released (seat back to the license) | **PASS** | released |
| ms22/switch_run | fan-out child steps wait for the target workers (switch_run) | **PASS** | {"3":"waiting_site_worker","4":"waiting_site_worker","5":"waiting_site_worker"} |
| ms2/4/wcp_basic | site 4: own activation of the wcp_basic license (production) against the shop | **PASS** | active production 1/3 |
| ms27 | site 4 changed to a license without the network right: it reports no seat, its waiting child step blocks at once (site_license_inactive), sites 3 and 5 keep waiting | **PASS** | {"report4":{"licensed":false,"state":"active","limit":3,"count":1},"children":{"3":"waiting_site_worker","4":"blocked_provider:site_license_inactive","5":"wa... |
| ms28 | after the target workers: sites 3 and 5 succeeded, site 4 ran nothing (its local run paused, no tag) | **PASS** | {"children":{"3":"succeeded","4":"blocked_provider:site_license_inactive","5":"succeeded"},"local":{"3":"succeeded","4":"paused:foreign_unavailable","5":"suc... |
| ms29 | a new network workflow with site 4 cannot be published (seats:site_unlicensed:4) | **PASS** | ["seats:site_unlicensed:4"] |
| ms30 | new run while site 4 has no seat: its child step is blocked before queueing (site_license_inactive), sites 3 and 5 get theirs | **PASS** | {"children":{"3":"succeeded","4":"blocked_provider:site_license_inactive","5":"succeeded"},"local":{"3":"succeeded","4":"none","5":"succeeded"}} |
| ms2/4/wcp_net | site 4: own activation of the wcp_net license (production) against the shop | **PASS** | active production 4/4 |
| ms31 | seat back on site 4: nothing continues by itself (R-17.9, needs "resume run") | **PASS** | [{"3":"succeeded","4":"blocked_provider:site_license_inactive","5":"succeeded"},{"3":"succeeded","4":"blocked_provider:site_license_inactive","5":"succeeded"}] |
| ms31b | "resume run" in the Network Admin (main site without activation) continues the two child steps of site 4 | **PASS** | 2 |
| ms32 | after resume and the worker of site 4: both child steps of site 4 succeeded, sites 3 and 5 ran each run exactly once | **PASS** | {"0":{"3":"succeeded","4":"succeeded","5":"succeeded"},"1":{"3":"succeeded","4":"succeeded","5":"succeeded"},"tags":{"3":4,"4":4},"before":{"3":2,"4":2,"5":2}} |
| ms2/2/wcp_basic | site 2: own activation of the wcp_basic license (production) against the shop | **PASS** | active production 1/3 |
| ms20 | source site without the network right: the new post starts no network run, nothing is deleted | **PASS** | {"runs":8,"report2":{"licensed":false,"state":"active","limit":3,"count":1}} |
| ms21 | source site without the network right: publishing is refused (seats:site_unlicensed:2) | **PASS** | ["seats:site_unlicensed:2"] |
| ms2/2/wcp_net | site 2: own activation of the wcp_net license (production) against the shop | **PASS** | active production 4/4 |
| ms22/expire_run | fan-out child steps wait for the target workers (expire_run) | **PASS** | {"3":"waiting_site_worker","4":"waiting_site_worker","5":"waiting_site_worker"} |
| ms-shop1 | shop: the network license (four subsites) expired [time simulated] | **PASS** |  |
| ms-refresh/expired/2 | site 2 refreshed its own license from the shop (expired) | **PASS** | expired |
| ms-refresh/expired/3 | site 3 refreshed its own license from the shop (expired) | **PASS** | expired |
| ms-refresh/expired/4 | site 4 refreshed its own license from the shop (expired) | **PASS** | expired |
| ms-refresh/expired/5 | site 5 refreshed its own license from the shop (expired) | **PASS** | expired |
| ms23 | license expired on every site: the network view blocks the three child steps at once (site_license_inactive) | **PASS** | {"3":"blocked_provider:site_license_inactive","4":"blocked_provider:site_license_inactive","5":"blocked_provider:site_license_inactive"} |
| ms23b | license expired: the target sites pause their queued runs instead of running them; no tag | **PASS** | {"3":"paused:foreign_unavailable","4":"paused:foreign_unavailable","5":"paused:foreign_unavailable"} |
| ms23c | license expired: a new post on the source site starts no network run | **PASS** | 10 |
| ms-shop2 | shop: the network license renewed by the customer | **PASS** | active |
| ms-refresh/active/2 | site 2 refreshed its own license from the shop (active) | **PASS** | active |
| ms-refresh/active/3 | site 3 refreshed its own license from the shop (active) | **PASS** | active |
| ms-refresh/active/4 | site 4 refreshed its own license from the shop (active) | **PASS** | active |
| ms-refresh/active/5 | site 5 refreshed its own license from the shop (active) | **PASS** | active |
| ms24 | renewed and refreshed on every site: the blocked child steps do not continue by themselves (R-17.9) | **PASS** | {"children":{"3":"blocked_provider:site_license_inactive","4":"blocked_provider:site_license_inactive","5":"blocked_provider:site_license_inactive"},"local":... |
| ms25 | renewed: "resume run" in the Network Admin continues the three child steps | **PASS** | 3 |
| ms26 | after resume and the target workers: all three child steps succeeded | **PASS** | {"3":"succeeded","4":"succeeded","5":"succeeded"} |
| ms33 | a second super admin publishes "site 2 -> site 3" (note) | **PASS** | [6,[]] |
| ms33b | while the publisher is super admin: the run succeeded on site 3 | **PASS** | {"3":"succeeded"} |
| ms34 | publisher lost the super admin right: the new child step is blocked (publisher_not_authorized), nothing queued on site 3, no other user takes its place | **PASS** | {"children":{"3":"blocked_provider:publisher_not_authorized"},"local":{"3":"none"}} |
| ms34b | "resume run" does not continue it while the publisher has no right (a decision: publish again) | **PASS** | 0 |
| ms35 | site admin removed from site 3: he can neither grant nor revoke for site 3 any more; the consent of site 3 stays (it belongs to the site) | **PASS** | ok |
| ms36 | network workflow with the provider action voucher.issue on site 5 published (network contract present, consent given) | **PASS** | {"contract":true,"grant":true,"publish":[]} |
| ms37 | Voucher Captain (and its Pro add-on) missing on site 5: the action is not there, its step pauses (action_unavailable) and blocks in the network view, site 3 succeeds | **PASS** | {"children":{"3":"succeeded","5":"blocked_provider:action_unavailable"},"local":{"3":"succeeded","5":"paused:action_unavailable"}} |
| l/voucher | license voucher activated in VoucherCaptainPro against the local shop (signed answer, pinned key) | **PASS** | ok active voucher.white_label |
| ms38 | Voucher Captain and Voucher Captain Pro with license active on site 5: "resume run" continues only the step of site 5 | **PASS** | 1 |
| ms38b | resumed on site 5: the core refuses the voucher command across sites (action_not_network_safe: product commands are not network-safe yet), nothing issued, site 3 not repeated | **PASS** | {"children":{"3":"succeeded","5":"blocked_provider:action_not_network_safe"},"local":{"3":"succeeded","5":"paused:action_not_network_safe"}} |
| ms39 | network workflow "site 2 -> site 3, admin mail" published | **PASS** | [] |
| ms40 | timeout after the mail went out on site 3: the step is not repeated, it needs a decision (local action_required unclear_outcome, network manual_review) | **PASS** | {"children":{"3":"manual_review:unclear_outcome"},"local":{"3":"action_required:unclear_outcome"},"mails":1} |
| ms40b | "resume run" and the worker of site 3 do not send the mail a second time (started side effect stays a decision on the site) | **PASS** | {"resumed":0,"children":{"3":"manual_review:unclear_outcome"},"local":{"3":"action_required:unclear_outcome"},"mails":1} |
| ms41 | network workflow "site 2 -> site 4, tag" published | **PASS** | [] |
| ms42 | daily maintenance marks the waiting step of site 4 as late (site_worker_late), state stays "waiting for the site worker" | **PASS** | {"flagged":1,"children":{"4":"waiting_site_worker:site_worker_late"}} |
| ms42b | status page: site 4 "late", the other sites are not late, nothing moved to another site | **PASS** | {"4":"late","3":"ok"} |
| ms43 | cron of site 4 runs again: the late step runs there and succeeds | **PASS** | {"4":"succeeded"} |
| ms43b | duplicate delivery of the same source event (twice): no second network run, no second child step, nothing more on site 4 | **PASS** | {"runs":1,"route":[{"9":"repeated"},{"9":"repeated"}]} |
| ms44 | network workflow "site 2 -> site 5, tag" published | **PASS** | [] |
| ms45 | site 5 flagged as deleted: the workflow waits for a confirmation (needs_confirmation) and the open step of site 5 pauses (site_deleted) | **PASS** | {"status":"needs_confirmation","reason":"site_deleted:5","children":{"5":"paused_site_consent:site_deleted"}} |
| ms45b | while the site is flagged deleted the targets cannot be confirmed (target:5:site_deleted) | **PASS** | ["target:5:site_deleted"] |
| ms46 | site restored: the step waited for the confirmation; "confirm target sites" makes the workflow active and continues the step | **PASS** | {"before":{"5":"paused_site_consent:site_deleted"},"confirm":[],"status":"active","after":{"5":"waiting_site_worker"}} |
| ms46b | after the worker of the restored site 5: the step succeeded | **PASS** | {"5":"succeeded"} |
| ms47 | site 5 deleted: workflow needs confirmation, the open step pauses (site_deleted), confirming is refused (target:5:site_missing) | **PASS** | {"status":"needs_confirmation","children":{"5":"paused_site_consent:site_deleted"},"confirm":["target:5:site_missing"]} |
| ms48 | restored from a backup older than the run: the targets can be confirmed again; the step does not wait for a run the site no longer knows, it blocks for a decision (payload_expired: the data left the network when it was queued) | **PASS** | {"confirm":[],"children":{"5":"blocked_provider:payload_expired"}} |
| ms48b | after the restore (its own activation came back with the backup) a new run reaches site 5 and succeeds | **PASS** | {"children":{"5":"succeeded"},"report5":{"licensed":true,"state":"active","limit":4,"count":4}} |
| ms49 | blog switch: each site reads its own options, the current site comes back also after an exception, a transient of site 3 is not visible on site 4 | **PASS** | {"names":{"3":"ziel\|3","4":"drei\|4","5":"vier\|5"},"back":1,"leak":false} |
| ms49b | no persistent object cache on this test site (isolation across requests with Redis/Memcached not testable here) | **PASS** | internal cache only |
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
| t103/off | Voucher Captain deactivated on site 2: its waiting event pauses there (adapter_unregistered) | **PASS** | {"id":"22","type":"voucher.voucher.issued_standalone","state":"paused","last_error":"adapter_unregistered"} |
| t103/3/woocommerce | site 3: adapter woocommerce registered | **PASS** | workflow-captain,wordpress,woocommerce |
| ms-log | multisite PHP error log has no fatal/warning/deprecation from the suite plugins | **PASS** | clean |
| g1 | Workflow Captain: release ZIP (git archive, export-ignore) | **PASS** | 68bf79d TODO counts after UI drafts |
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
