#!/bin/bash
# Third E2E run (docs/E2E-RESULTS.md, "Dritter Lauf (neue Oberfläche)"; not shipped: /bin is export-ignore).
# Needs the site of the first run (bin/e2e/setup.sh with E2E_YOAST=1) and backups/baseline.sql (run-second.sh makes it).
#
#   AXE=<path>/axe.min.js bin/e2e/run-third.sh [screenshot-dir]
#
# 1. upgrade of the existing site from the code of the second run (upgrade.sh), 2. data (third.php), 3. the new admin
# screens and the builder in Chromium with wp-admin CSS (third.js), 4. front end with Yoast, 5. PHP error log.
# The site keeps the data of step 2 afterwards (used for the WordPress.org screenshots, bin/e2e/screenshots.js).
set -uo pipefail

ROOT=${E2E_ROOT:-/home/user/wp-e2e}
PLUGIN=${E2E_PLUGIN:-$(cd "$(dirname "$0")/../.." && pwd)}
SHOTS=${1:-$ROOT/shots-3}
T="$PLUGIN/bin/e2e/third.php"
export E2E_ROOT="$ROOT" E2E_RESULTS="$ROOT/logs/results-3.jsonl" E2E_SHOTS="$SHOTS" NODE_PATH=${NODE_PATH:-$(npm root -g)}
cd /
: > "$E2E_RESULTS"; : > "$ROOT/logs/debug.log"; : > "$ROOT/logs/debug-ms.log"; : > "$ROOT/logs/mail.jsonl"
mkdir -p "$SHOTS"
rec() {
	php -r 'echo json_encode(array("id"=>$argv[1],"name"=>$argv[2],"result"=>$argv[3]==="1"?"PASS":"FAIL","detail"=>$argv[4]), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' "$1" "$2" "$3" "$4" | tee -a "$E2E_RESULTS"
}
q() { grep -v '^Warning: wp_update_plugins\|^Warning: wp_update_themes\|plugins_api()' || true; }

# ----- 1. upgrade -------------------------------------------------------------------------------------------------
E2E_LOCK_SHOT=1 "$PLUGIN/bin/e2e/upgrade.sh" >/dev/null 2>&1

# ----- 2. data ----------------------------------------------------------------------------------------------------
cp "$PLUGIN/bin/e2e/mu-plugins/e2e-addon-action.php" "$ROOT/www/wp-content/mu-plugins/"
wp option update e2e_addon_action 1 >/dev/null 2>&1
for s in data data_states data_paused data_check; do wp eval-file "$T" "$s" 2>&1 | q; done

# ----- 3. browser -------------------------------------------------------------------------------------------------
node "$PLUGIN/bin/e2e/third.js" >/dev/null

# ----- 4. front end and SEO with Yoast active (area i of the first run) -------------------------------------------
out=$("$PLUGIN/bin/e2e/frontend-diff.sh" http://127.0.0.1:8080 "$ROOT/logs/frontend-3" 2>&1); rc=$?
rec i3-1 'front-end HTML identical active vs. deactivated (home, post, product, shop, feed, REST index, sitemap), with the data of this run' $([ $rc -eq 0 ] && echo 1 || echo 0) "$(echo "$out" | tr '\n' ' ' | cut -c1-600)"
if [ -f "$ROOT/www/wp-content/plugins/wordpress-seo/wp-seo.php" ]; then
	wp plugin activate wordpress-seo >/dev/null 2>&1
	curl -s -o /dev/null http://127.0.0.1:8080/; curl -s -o /dev/null http://127.0.0.1:8080/sitemap_index.xml
	out=$(E2E_URLS='/ /?p={POST} /?page_id={PAGE} /?post_type=product&p={PRODUCT} /sitemap_index.xml /post-sitemap.xml /page-sitemap.xml /product-sitemap.xml /wp-json/ /wp-json/wp/v2/posts/{POST} /wp-json/wp/v2/pages/{PAGE} /wp-json/wp/v2/product/{PRODUCT} /wp-json/yoast/v1/get_head?url=http://127.0.0.1:8080/?p={POST} /?feed=rss2' "$PLUGIN/bin/e2e/frontend-diff.sh" http://127.0.0.1:8080 "$ROOT/logs/frontend-yoast-3" 2>/dev/null); rc=$?
	rec i3-2 'with Yoast SEO active: front end, XML sitemaps, REST and get_head identical with Workflow Captain active vs. deactivated, no Workflow Captain data in public responses' $([ $rc -eq 0 ] && echo 1 || echo 0) "$(echo "$out" | grep -E '^(SAME|DIFF|LEAK)' | tr '\n' ' ' | cut -c1-900)"
	wp plugin deactivate wordpress-seo >/dev/null 2>&1
fi

# ----- 5. PHP error log -------------------------------------------------------------------------------------------
hits=$(grep -n "workflow-captain\|workflow_captain\|WorkflowCaptain\|wt-e2e" "$ROOT/logs/debug.log" | head -5)
rec h3-20 'PHP error log has no notice, warning or error from Workflow Captain during the whole run (WP_DEBUG)' $([ -z "$hits" ] && echo 1 || echo 0) "${hits:-$(wc -l < "$ROOT/logs/debug.log") lines in debug.log, none from the plugin}"

pass=$(grep -c '"result":"PASS"' "$E2E_RESULTS"); fail=$(grep -c '"result":"FAIL"' "$E2E_RESULTS")
echo "E2E third run: $pass passed, $fail failed ($E2E_RESULTS)"
[ "$fail" -eq 0 ]
