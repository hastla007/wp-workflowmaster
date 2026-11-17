# Release-Checkliste Workflow Captain (kostenloser Kern)

Stand: Plugin 0.2.x. Aufgaben WF-INT-18 (Teil Kern) und WF-SEO-10. Abgehakt wird je Release; eine offene Zeile verhindert das Release.

## Automatisch (CI muss grün sein)

- [ ] `php tests/run.php` auf PHP 8.5 (8.6 experimentell) grün (`.github/workflows/php.yml`); `e2e-db.yml` gegen MySQL 9.7 und MariaDB 12.3 grün
- [ ] `php tests/license-guard.php`: kein Lizenzcode, kein Produktschlüssel, kein `wp_set_current_user`, kein Mail-Filter
- [ ] Test „keine Frontend-Ausgabe, keine öffentlichen Datenflächen“ in `tests/security-tests.php` (WF-SEO-03, -10)
- [ ] `php bin/make-pot.php --check`: Übersetzungsvorlage aktuell
- [ ] `node tests/builder.test.js` (`.github/workflows/builder.yml`)
- [ ] `python3 bin/validate-spec-examples.py`: Schema, Beispiele, Katalog, n8n-Vorlagen (`.github/workflows/validate-spec.yml`)

## Von Hand in einer echten Installation

- [ ] Aktivieren, Deaktivieren und Deinstallieren auf einer Einzelwebsite und in Multisite (netzwerkweit und je Website); Tabellen und Optionen entstehen und verschwinden
- [ ] Update von der vorigen Version: Schema migriert im Admin, nicht im Frontend; `wp workflow-captain verify` ohne Befund
- [ ] Downgrade auf die vorige Version: Hinweis erscheint, nichts wird verarbeitet, nichts gelöscht
- [ ] Workflow im Builder anlegen, Testlauf, veröffentlichen, Lauf mit echtem Ereignis eines Adapters, Nachricht kommt an
- [ ] WP-Cron abgeschaltet: Website-Zustand meldet es, `wp workflow-captain process` per System-Cron verarbeitet
- [ ] Mit WooCommerce: Worker läuft über Action Scheduler, nicht doppelt über WP-Cron
- [ ] Export und Löschung persönlicher Daten über „Werkzeuge“ erfassen Ereignisse und Läufe
- [ ] Frontend mit und ohne Yoast SEO: keine zusätzlichen oder doppelten Meta-, Canonical- oder Schema-Ausgaben, keine Sitemap-Einträge von Workflow Captain (WF-SEO-10)
- [ ] Plugin Check und PHPCS mit WordPress-Regeln ohne Fehler
- [ ] Release-ZIP enthält nur `workflow-captain.php`, `uninstall.php`, `readme.txt`, `src/`, `assets/`, `languages/` (`.gitattributes`)

## Inhalt

- [ ] `readme.txt`: Version, „Tested up to“, Changelog, Datenschutzabschnitt
- [ ] Bilder für WordPress.org in `.wordpress-org/` (nicht im ZIP, `export-ignore`): `screenshot-N.png` passend zu „== Screenshots ==“ in `readme.txt`, 1280 × 800 bei Pixelfaktor 1; Banner `banner-772x250.png` und `banner-1544x500.png`, Icon `icon-128x128.png` und `icon-256x256.png` (fehlen noch, brauchen ein Motiv des Inhabers). Die Bilder lädt man ins `assets/`-Verzeichnis des SVN von WordPress.org, nicht in `trunk/`.
- [ ] `docs/TESTMATRIX.md` und `docs/KOMPATIBILITAET.md` auf den Stand gebracht
