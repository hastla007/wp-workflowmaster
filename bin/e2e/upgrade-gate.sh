#!/bin/bash
# Release gate of the free core, part "update and downgrade" (docs/RELEASE-CHECKLIST.md; not shipped: /bin is
# export-ignore). On a fresh WordPress + WooCommerce site: install the previous version from a release ZIP, create a
# workflow, a run and a tag, update to the code under test by replacing the plugin from its ZIP, check that the schema
# migrates in the admin and not in the frontend, that nothing is lost and a new order still runs; then go back to the
# previous version (downgrade), check the notice, that nothing is processed and nothing deleted, and update again.
#
#   bin/e2e/upgrade-gate.sh
#
# Settings like bin/e2e/ci-db.sh (E2E_WWW, E2E_URL, E2E_LOGS, E2E_MAIN_DB, E2E_DB_*, E2E_WP_CLI, CI_WP_VERSION,
# CI_WC_VERSION, CI_HTTP_PROXY); the database must exist and be empty. UPG_FROM: Git revision of the previous version
# (default f36da2d, plugin 0.2.0 with schema 3, the last state before schema 4). Results: $E2E_LOGS/results-upgrade.jsonl.
set -uo pipefail

PLUGIN=$(cd "$(dirname "$0")/../.." && pwd)
export E2E_PLUGIN="$PLUGIN" E2E_PLUGIN_MODE=zip
export E2E_WP_CLI=${E2E_WP_CLI:-php -d memory_limit=1024M $PWD/wp-cli.phar}
: "${E2E_WWW:?E2E_WWW (WordPress directory) is required}" "${E2E_URL:?E2E_URL is required}" "${E2E_LOGS:?E2E_LOGS is required}"
export E2E_ROOT=${E2E_ROOT:-$(dirname "$E2E_WWW")} E2E_BACKUPS=${E2E_BACKUPS:-$E2E_LOGS/backups}
. "$PLUGIN/bin/e2e/env.sh"
export E2E_RESULTS="$E2E_LOGS/results-upgrade.jsonl"
WP_VERSION=${CI_WP_VERSION:-7.1.4}
WC_VERSION=${CI_WC_VERSION:-11.3.0}
FROM=${UPG_FROM:-f36da2d}
OLD_ZIP="$E2E_LOGS/workflow-captain-old.zip"
NEW_ZIP="$E2E_LOGS/workflow-captain-new.zip"
UPG="$PLUGIN/bin/e2e/upgrade.php"
JAR="$E2E_LOGS/upgrade-cookies.txt"
cd /

rec() { # <id> <name> <ok:0|1> <detail>
	php -r 'echo json_encode(array("id"=>$argv[1],"name"=>$argv[2],"result"=>$argv[3]==="1"?"PASS":"FAIL","detail"=>$argv[4]), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' "$1" "$2" "$3" "$4" | tee -a "$E2E_RESULTS"
}
upg() { wp eval-file "$UPG" "$@" 2>/dev/null | tail -1; }
# Stored schema without loading plugins (any WP-CLI call with the plugin loaded may migrate).
stored() { wp --skip-plugins --skip-themes option get workflow_captain_db_version 2>/dev/null; }
json() { php -r '$d=json_decode($argv[1],true); echo $d[$argv[2]] ?? "";' "$1" "$2"; }
login() {
	rm -f "$JAR"
	curl -s -o /dev/null -c "$JAR" "$E2E_URL/wp-login.php"
	curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode log=admin --data-urlencode pwd=admin-e2e-pass \
		--data-urlencode testcookie=1 --data-urlencode "redirect_to=$E2E_URL/wp-admin/" "$E2E_URL/wp-login.php"
}

: > "$E2E_RESULTS"; : > "$E2E_MAIL_FILE"; : > "$E2E_LOG"
mkdir -p "$E2E_WWW"
wp core download --version="$WP_VERSION" --force >/dev/null || exit 1
wp config create --dbname="$E2E_MAIN_DB" --dbuser="$E2E_DB_USER" --dbpass="$E2E_DB_PASS" --dbhost="$E2E_DB_HOST" --skip-check --force >/dev/null || exit 1
wp config set WP_DEBUG true --raw >/dev/null
wp config set WP_DEBUG_LOG "$E2E_LOG" >/dev/null
wp config set WP_DEBUG_DISPLAY false --raw >/dev/null
wp config set DISABLE_WP_CRON true --raw >/dev/null
wp config set WP_HOME "$E2E_URL" >/dev/null
wp config set WP_SITEURL "$E2E_URL" >/dev/null
wp config set E2E_MAIL_FILE "$E2E_MAIL_FILE" >/dev/null
mkdir -p "$E2E_WWW/wp-content/mu-plugins"
cp "$PLUGIN/bin/e2e/mu-plugins/e2e-test-site.php" "$E2E_WWW/wp-content/mu-plugins/"
if [ -n "${CI_HTTP_PROXY:-}" ]; then
	wp config set WP_PROXY_HOST "$(echo "$CI_HTTP_PROXY" | sed -E 's#^[a-z]+://##; s#:[0-9]+/?$##')" >/dev/null
	wp config set WP_PROXY_PORT "${CI_HTTP_PROXY##*:}" --raw >/dev/null
	wp config set WP_PROXY_BYPASS_HOSTS 'localhost,127.0.0.1' >/dev/null
	cp "$PLUGIN/bin/e2e/staging/mu-plugins/e2e-staging-proxy.php" "$E2E_WWW/wp-content/mu-plugins/"
fi
wp core install --url="$E2E_URL" --title="Workflow Captain upgrade gate" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email >/dev/null || exit 1
wp option update timezone_string Europe/Berlin >/dev/null
wp plugin install woocommerce --version="$WC_VERSION" --activate >/dev/null || exit 1
wp option update woocommerce_currency EUR >/dev/null
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null
wp option update woocommerce_coming_soon no >/dev/null

git -C "$PLUGIN" archive --format=zip --prefix=workflow-captain/ -o "$OLD_ZIP" "$FROM"
git -C "$PLUGIN" archive --format=zip --prefix=workflow-captain/ -o "$NEW_ZIP" HEAD
old_db=$(git -C "$PLUGIN" show "$FROM:workflow-captain.php" | sed -nE "s/.*WORKFLOW_CAPTAIN_DB_VERSION', '([0-9]+)'.*/\1/p")
new_db=$(sed -nE "s/.*WORKFLOW_CAPTAIN_DB_VERSION', '([0-9]+)'.*/\1/p" "$PLUGIN/workflow-captain.php")

port=${E2E_URL##*:}
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$port" -t "$E2E_WWW" "$PLUGIN/bin/e2e/router.php" > "$E2E_LOGS/php-server-upgrade.log" 2>&1 &
server=$!
trap 'kill $server 2>/dev/null' EXIT
for _ in $(seq 1 40); do curl -s -o /dev/null "$E2E_URL/" && break; sleep 0.25; done

# ----- previous version ------------------------------------------------------------------------------------------
out=$(wp plugin install "$OLD_ZIP" --activate 2>&1 | grep -E '^(Success|Error)' | tail -1)
rec u1 "previous version ($FROM, schema $old_db) installed from its ZIP and activated" $([[ "$out" == Success* ]] && [ "$(stored)" = "$old_db" ] && echo 1 || echo 0) "$out; stored schema $(stored)"
seed=$(upg seed)
oid=$(json "$seed" order)
rec u2 'previous version: workflow "order paid -> tag" published, paid order tagged' $([ "$(upg tagged "$oid")" = 1 ] && echo 1 || echo 0) "$seed"
before=$(upg counts)

# ----- update to the code under test -----------------------------------------------------------------------------
out=$(wp plugin install "$NEW_ZIP" --force 2>&1 | grep -E '^(Success|Error)' | tail -1)
rec u3 'update: plugin replaced from the new ZIP (like an upload in the admin), schema not yet migrated' $([[ "$out" == Success* ]] && [ "$(stored)" = "$old_db" ] && echo 1 || echo 0) "$out; stored schema $(stored)"
home=$(curl -s -o /dev/null -w '%{http_code}' "$E2E_URL/")
rec u4 'update: a frontend request does not migrate' $([ "$home" = 200 ] && [ "$(stored)" = "$old_db" ] && echo 1 || echo 0) "front page $home, stored schema $(stored)"
login
admin=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$JAR" "$E2E_URL/wp-admin/")
# 302 is fine as long as it is not the login (WooCommerce sends the first admin visit to its own page).
rec u5 "update: the first admin request (logged in) migrates to schema $new_db" $([[ "$admin" == 200* || ( "$admin" == 302* && "$admin" != *wp-login* ) ]] && [ "$(stored)" = "$new_db" ] && echo 1 || echo 0) "wp-admin $admin, stored schema $(stored)"
out=$(wp workflow-captain verify 2>&1 | tail -1)
rec u6 'update: wp workflow-captain verify without findings' $([[ "$out" == *"No inconsistencies"* ]] && echo 1 || echo 0) "$out"
after=$(upg counts)
ok=1
for k in events workflows versions runs steps tags runs_succeeded; do [ "$(json "$before" $k)" = "$(json "$after" $k)" ] || ok=0; done
rec u7 'update: events, workflows, versions, runs, steps and tags kept' $ok "before $before, after $after"
oid2=$(upg order)
rec u8 'update: a new paid order runs through the workflow published before the update' $([ "$(upg tagged "$oid2")" = 1 ] && echo 1 || echo 0) "order $oid2"
updated=$(upg counts)

# ----- downgrade to the previous version -------------------------------------------------------------------------
out=$(wp plugin install "$OLD_ZIP" --force 2>&1 | grep -E '^(Success|Error)' | tail -1)
oid3=$(upg order)
rec d1 'downgrade: previous version installed again, stored schema stays newer' $([[ "$out" == Success* ]] && [ "$(stored)" = "$new_db" ] && echo 1 || echo 0) "$out; stored schema $(stored)"
rec d2 'downgrade: the worker processes nothing (paid order not tagged)' $([ "$(upg tagged "$oid3")" = 0 ] && echo 1 || echo 0) "order $oid3"
login
notice=$(curl -s -b "$JAR" "$E2E_URL/wp-admin/" | grep -o 'Datenbank stammt von einer neueren Version[^<]*' | head -1)
rec d3 'downgrade: the admin shows the notice' $([ -n "$notice" ] && echo 1 || echo 0) "$notice"
down=$(upg counts)
ok=1
for k in workflows versions runs steps tags runs_succeeded; do [ "$(json "$updated" $k)" = "$(json "$down" $k)" ] || ok=0; done
[ "$(json "$down" events)" -ge "$(json "$updated" events)" ] || ok=0
rec d4 'downgrade: nothing deleted' $ok "updated $updated, downgraded $down"

# ----- update again ----------------------------------------------------------------------------------------------
wp plugin install "$NEW_ZIP" --force >/dev/null 2>&1
login
curl -s -o /dev/null -b "$JAR" "$E2E_URL/wp-admin/"
wp eval 'for ( $i = 0; $i < 12; $i++ ) { \WorkflowCaptain\Plugin::instance()->run_worker(); }' >/dev/null 2>&1
rec r1 'update again: the order captured during the downgrade is processed now' $([ "$(upg tagged "$oid3")" = 1 ] && [ "$(stored)" = "$new_db" ] && echo 1 || echo 0) "order $oid3, stored schema $(stored)"
out=$(wp workflow-captain verify 2>&1 | tail -1)
rec r2 'update again: verify without findings' $([[ "$out" == *"No inconsistencies"* ]] && echo 1 || echo 0) "$out"

plugin_log=$(grep -E 'PHP (Fatal|Warning|Deprecated|Notice)' "$E2E_LOG" | grep -c 'plugins/workflow-captain/' || true)
rec r3 'no PHP error, warning or deprecation from the plugin in debug.log' $([ "$plugin_log" = 0 ] && echo 1 || echo 0) "$plugin_log lines"
echo "$(grep -c '"PASS"' "$E2E_RESULTS") passed, $(grep -c '"FAIL"' "$E2E_RESULTS") failed"
! grep -q '"FAIL"' "$E2E_RESULTS"
