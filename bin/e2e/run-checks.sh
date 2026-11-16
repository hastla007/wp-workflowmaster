#!/bin/bash
# Full E2E run of Workflow Captain on a fresh WordPress + WooCommerce site (not shipped: /bin is export-ignore).
# Resets the database wp_e2e (and wp_e2e_ms), installs, runs every check and writes logs/results.jsonl.
#
#   bin/e2e/setup.sh && bin/e2e/run-checks.sh [screenshot-dir]
#
# Exit code 1 when a check failed. Summary: logs/results.jsonl, one JSON line per check.
set -uo pipefail

ROOT=${E2E_ROOT:-/home/user/wp-e2e}
PLUGIN=${E2E_PLUGIN:-$(cd "$(dirname "$0")/../.." && pwd)}
SHOTS=${1:-$ROOT/shots}
CHECKS="$PLUGIN/bin/e2e/checks.php"
export E2E_RESULTS="$ROOT/logs/results.jsonl" E2E_MAIL_FILE="$ROOT/logs/mail.jsonl"
cd /

rec() { # <id> <name> <ok:0|1> <detail>
	php -r 'echo json_encode(array("id"=>$argv[1],"name"=>$argv[2],"result"=>$argv[3]==="1"?"PASS":"FAIL","detail"=>$argv[4]), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' "$1" "$2" "$3" "$4" | tee -a "$E2E_RESULTS"
}
ev() { wp eval-file "$CHECKS" "$@"; }

: > "$E2E_RESULTS"; : > "$E2E_MAIL_FILE"; : > "$ROOT/logs/debug.log"; : > "$ROOT/logs/debug-ms.log"

# ----- fresh single site with WooCommerce (HPOS, EUR) ------------------------------------------------------------
mysql -e "DROP DATABASE IF EXISTS wp_e2e; CREATE DATABASE wp_e2e; DROP DATABASE IF EXISTS wp_e2e_ms; CREATE DATABASE wp_e2e_ms;"
wp core install --url=http://127.0.0.1:8080 --title="WC E2E" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email >/dev/null
wp option update timezone_string Europe/Berlin >/dev/null
wp plugin activate woocommerce >/dev/null
wp option update woocommerce_currency EUR >/dev/null
wp option update woocommerce_default_country 'DE:BE' >/dev/null
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null
wp option update woocommerce_coming_soon no >/dev/null
wp option update woocommerce_allow_tracking no >/dev/null
wp eval '$s = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class ); if ( ! $s->check_orders_table_exists() ) { $s->create_database_tables(); } update_option( "woocommerce_custom_orders_table_enabled", "yes" ); update_option( "woocommerce_feature_custom_order_tables_enabled", "yes" );'
[ -L "$ROOT/www/wp-content/plugins/workflow-captain" ] || { rm -rf "$ROOT/www/wp-content/plugins/workflow-captain"; ln -s "$PLUGIN" "$ROOT/www/wp-content/plugins/workflow-captain"; }
curl -s "http://127.0.0.1:8080/" > "$ROOT/logs/home-without-plugin.html"
wp plugin activate workflow-captain >/dev/null

# ----- a, b: activation and providers ----------------------------------------------------------------------------
ev activation
ev providers

# ----- c, e: order flow with HPOS on and off ---------------------------------------------------------------------
E2E_LABEL=hpos ev order on
E2E_LABEL=posts ev order off
E2E_RESULTS=/dev/null ev order on >/dev/null # back to HPOS for the rest of the run, not recorded again

# ----- d: more WooCommerce and WordPress events ------------------------------------------------------------------
ev wc_more

# ----- a10-12: the real Action Scheduler path (hook -> run_worker) -----------------------------------------------
ev as_prep
mysql wp_e2e -e "UPDATE wp_actionscheduler_actions SET scheduled_date_gmt = UTC_TIMESTAMP() - INTERVAL 1 SECOND, scheduled_date_local = NOW() - INTERVAL 1 SECOND WHERE hook = 'workflow_captain_process_events' AND status = 'pending';"
wp action-scheduler run --hooks=workflow_captain_process_events >/dev/null 2>&1
ev as_check

# ----- f: schedule trigger, scheduled worker and system-cron CLI path --------------------------------------------
ev schedule
ev schedule cli
out=$(wp workflow-captain process 2>&1); rec f3a 'wp workflow-captain process succeeds' $([[ "$out" == Success* ]] && echo 1 || echo 0) "$out"
ev schedule_check

# ----- g: emergency pause, dead letter, requeue, CLI ---------------------------------------------------------------
ev pause && ev pause_check && ev pause_resume
E2E_FAIL_DISPATCH=1 ev deadletter_make
id=$(cat "$ROOT/logs/dlq-event.txt")
out=$(wp workflow-captain requeue "$id" 2>&1); rec g5 'wp workflow-captain requeue <dead-letter id>' $([[ "$out" == *Requeued* ]] && echo 1 || echo 0) "$out"
wp workflow-captain process >/dev/null
ev deadletter_check
out=$(wp workflow-captain requeue "$id" 2>&1); rc=$?; rec g7 'requeue of a done event is refused' $([ $rc -ne 0 ] && echo 1 || echo 0) "$out"
out=$(wp workflow-captain status 2>&1); rec g8 'wp workflow-captain status' $([[ "$out" == *"Adapters: workflow-captain, wordpress, woocommerce"* && "$out" == *"dead_letter=0"* ]] && echo 1 || echo 0) "$(echo "$out" | tr '\n' ' ')"
out=$(wp workflow-captain verify 2>&1); rec g9 'wp workflow-captain verify' $([[ "$out" == *"No inconsistencies"* ]] && echo 1 || echo 0) "$out"
out=$(wp workflow-captain diagnostics 2>&1); ok=$(echo "$out" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo (int)(is_array($d) && "action_scheduler"===$d["environment"]["scheduler"] && "current"===$d["environment"]["schema_state"] && in_array("woocommerce",$d["adapters"]["registered"],true));')
rec g10 'wp workflow-captain diagnostics (valid JSON, scheduler, schema, adapters)' "$ok" "$(echo "$out" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo json_encode(array("env"=>$d["environment"]??null,"events"=>$d["events"]??null,"runs"=>$d["runs"]??null));')"
out=$(wp workflow-captain pause on 2>&1); out2=$(wp workflow-captain status 2>&1); wp workflow-captain pause off >/dev/null 2>&1
rec g11 'wp workflow-captain pause on|off' $([[ "$out" == Success* ]] && echo 1 || echo 0) "$out"

# ----- t: provider missing / refused (T88, WF-INT-17 core side) -------------------------------------------------
ev wcoff_prep
wp plugin deactivate woocommerce >/dev/null
ev wcoff_check
wp plugin activate woocommerce >/dev/null
ev wcoff_resume
cp "$PLUGIN/bin/e2e/mu-plugins/e2e-fake-providers.php" "$ROOT/www/wp-content/mu-plugins/"
wp option update e2e_fake_providers 1 >/dev/null
ev refusals
wp option delete e2e_fake_providers >/dev/null

# ----- h: admin pages with Playwright ----------------------------------------------------------------------------
E2E_SHOTS="$SHOTS" NODE_PATH=$(npm root -g) node "$PLUGIN/bin/e2e/admin.js" >/dev/null

# ----- x: accessibility (axe-core + keyboard), when AXE points to axe.min.js (npm install axe-core) ------------------
if [ -n "${AXE:-}" ]; then
	AXE="$AXE" E2E_SHOTS="$SHOTS" NODE_PATH=$(npm root -g) node "$PLUGIN/bin/e2e/a11y.js" >/dev/null
fi

# ----- i: front end identical with and without the plugin --------------------------------------------------------
out=$("$PLUGIN/bin/e2e/frontend-diff.sh" http://127.0.0.1:8080 "$ROOT/logs/frontend" 2>&1); rc=$?
rec i1 'front-end HTML identical active vs. deactivated (home, post, product, shop, feed, REST index, sitemap)' $([ $rc -eq 0 ] && echo 1 || echo 0) "$(echo "$out" | tr '\n' ' ' | cut -c1-600)"

# ----- i2: the same with Yoast SEO active (WF-SEO-07), when setup.sh built it (E2E_YOAST=1) ------------------------
if [ -f "$ROOT/www/wp-content/plugins/wordpress-seo/wp-seo.php" ]; then
	wp plugin activate wordpress-seo >/dev/null 2>&1
	curl -s -o /dev/null http://127.0.0.1:8080/; curl -s -o /dev/null http://127.0.0.1:8080/sitemap_index.xml # warm caches
	out=$(E2E_URLS='/ /?p={POST} /?page_id={PAGE} /?post_type=product&p={PRODUCT} /sitemap_index.xml /post-sitemap.xml /page-sitemap.xml /product-sitemap.xml /wp-json/ /wp-json/wp/v2/posts/{POST} /wp-json/wp/v2/pages/{PAGE} /wp-json/wp/v2/product/{PRODUCT} /wp-json/yoast/v1/get_head?url=http://127.0.0.1:8080/?p={POST} /?feed=rss2' "$PLUGIN/bin/e2e/frontend-diff.sh" http://127.0.0.1:8080 "$ROOT/logs/frontend-yoast" 2>/dev/null); rc=$?
	rec i2 'with Yoast SEO active: front end, XML sitemaps and REST identical with Workflow Captain active vs. deactivated, no Workflow Captain data in public responses' $([ $rc -eq 0 ] && echo 1 || echo 0) "$(echo "$out" | grep -E '^(SAME|DIFF|LEAK)' | tr '\n' ' ' | cut -c1-900)"
	wp plugin deactivate wordpress-seo >/dev/null 2>&1
fi

# ----- PHP error log: nothing from Workflow Captain ---------------------------------------------------------------
hits=$(grep -n "workflow-captain\|workflow_captain\|WorkflowCaptain\|wt-e2e" "$ROOT/logs/debug.log" | head -5)
rec h20 'PHP error log has no entry from Workflow Captain (WP_DEBUG, WP_DEBUG_LOG)' $([ -z "$hits" ] && echo 1 || echo 0) "${hits:-$(wc -l < "$ROOT/logs/debug.log") lines in debug.log, none from the plugin}"

# ----- j: deactivate keeps data, delete removes everything ---------------------------------------------------------
wp plugin deactivate workflow-captain >/dev/null
ev deactivated
wp plugin activate workflow-captain >/dev/null
n=$(mysql -N wp_e2e -e "SELECT COUNT(*) FROM wp_actionscheduler_actions WHERE hook='workflow_captain_process_events' AND status='pending'")
c=$(wp cron event list --fields=hook --format=csv | grep -c workflow_captain_process_events)
rec j5 'reactivation schedules the worker once (AS), no WP-Cron duplicate' $([ "$n" = 1 ] && [ "$c" = 0 ] && echo 1 || echo 0) "AS pending=$n wp-cron=$c"
wp plugin deactivate workflow-captain >/dev/null
# Delete through the WordPress API on a real copy: deleting through the symlink would delete the checkout.
rm "$ROOT/www/wp-content/plugins/workflow-captain"
rsync -a --exclude .git "$PLUGIN/" "$ROOT/www/wp-content/plugins/workflow-captain/"
out=$(wp eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; require_once ABSPATH . "wp-admin/includes/file.php"; $r = delete_plugins( array( "workflow-captain/workflow-captain.php" ) ); echo is_wp_error( $r ) ? $r->get_error_message() : var_export( $r, true );' 2>&1)
rec j5b 'delete_plugins() runs uninstall.php and deletes the files' $([ "$out" = true ] && [ ! -e "$ROOT/www/wp-content/plugins/workflow-captain" ] && echo 1 || echo 0) "$(echo "$out" | head -3 | tr '\n' ' ')"
ev uninstalled
rm -rf "$ROOT/www/wp-content/plugins/workflow-captain"; ln -s "$PLUGIN" "$ROOT/www/wp-content/plugins/workflow-captain"
wp plugin activate workflow-captain >/dev/null

# ----- k: multisite ------------------------------------------------------------------------------------------------
sed -i '/MULTISITE/d' "$ROOT/ms/wp-config.php"
wpms core multisite-install --url=http://127.0.0.1:8081 --title="MS E2E" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email --skip-config >/dev/null 2>&1
if ! grep -q MULTISITE "$ROOT/ms/wp-config.php"; then
	sed -i "s#^if ( ! defined( 'ABSPATH' ) )#define( 'WP_ALLOW_MULTISITE', true ); define( 'MULTISITE', true ); define( 'SUBDOMAIN_INSTALL', false ); define( 'DOMAIN_CURRENT_SITE', '127.0.0.1:8081' ); define( 'PATH_CURRENT_SITE', '/' ); define( 'SITE_ID_CURRENT_SITE', 1 ); define( 'BLOG_ID_CURRENT_SITE', 1 );\nif ( ! defined( 'ABSPATH' ) )#" "$ROOT/ms/wp-config.php"
fi
wpms site create --slug=zwei --title=Zwei --email=admin@example.test >/dev/null
wpms plugin activate workflow-captain --network >/dev/null
# Workflow Captain Pro (captain-pro, lib/ built with "php build/build.php --in-place --addon=workflow-captain-pro").
PRO=${E2E_PRO:-/home/user/captain-pro/addons/workflow-captain-pro}
if [ -f "$PRO/lib/kernel/load.php" ]; then
	rsync -a --delete --exclude .git "$PRO/" "$ROOT/ms/wp-content/plugins/workflow-captain-pro/"
	wpms plugin activate workflow-captain-pro --network >/dev/null 2>&1
	E2E_SHOTS="$SHOTS" E2E_URL=http://127.0.0.1:8081 NODE_PATH=$(npm root -g) node "$PLUGIN/bin/e2e/ms-pro.js" >/dev/null
fi
wpms eval-file "$CHECKS" ms_network
new=$(wpms site create --slug=drei --title=Drei --email=admin@example.test --porcelain)
wpms eval-file "$CHECKS" ms_new_site "$new"
# Any admin, cron or WP-CLI request on the new site runs Install::maybe_upgrade() there.
wpms --url="127.0.0.1:8081/drei/" option get blogname >/dev/null 2>&1
wpms eval-file "$CHECKS" ms_new_site_after_admin "$new"
wpms site delete "$new" --yes >/dev/null
wpms eval-file "$CHECKS" ms_dropped "$new"
wpms plugin deactivate workflow-captain-pro --network >/dev/null 2>&1
wpms plugin deactivate workflow-captain --network >/dev/null
wpms plugin uninstall workflow-captain --skip-delete >/dev/null 2>&1
wpms eval-file "$CHECKS" ms_uninstalled
hits=$(grep -n "workflow-captain\|workflow_captain\|WorkflowCaptain\|wt-e2e" "$ROOT/logs/debug-ms.log" | head -5)
rec k6 'multisite PHP error log has no entry from Workflow Captain' $([ -z "$hits" ] && echo 1 || echo 0) "${hits:-clean}"

# ----- summary -----------------------------------------------------------------------------------------------------
pass=$(grep -c '"result":"PASS"' "$E2E_RESULTS"); fail=$(grep -c '"result":"FAIL"' "$E2E_RESULTS")
echo "E2E: $pass passed, $fail failed ($E2E_RESULTS)"
[ "$fail" -eq 0 ]
