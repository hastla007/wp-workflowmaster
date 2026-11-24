#!/bin/bash
# Security matrix of the n8n connection against a real, self-hosted n8n (WF-N8N-12, docs/n8n/MATRIX.md). Not shipped
# (/bin is export-ignore). n8n is installed from npm into a scratch directory and listens on 127.0.0.1 only; the test
# site (Workflow Captain + Workflow Captain Pro + WP License Captain as the local shop) runs on 127.0.0.1:8087.
#
#   bin/e2e/n8n.sh                 # setup + all checks + stop
#   bin/e2e/n8n.sh setup           # n8n and the test site only (left running)
#   bin/e2e/n8n.sh checks          # the checks (needs setup)
#   bin/e2e/n8n.sh stop            # stops n8n and the PHP server of the test site
#
# Needs bin/e2e/setup.sh and bin/e2e/suite/setup.sh first (WordPress + WooCommerce copies, WP-CLI, MariaDB running).
# Variables: N8N_DIR (npm directory, default /tmp/n8n-e2e), N8N_VERSION (default 1.123.92), E2E_CORE (Workflow Captain
# directory, default this checkout), E2E_PRO (built add-on directory addons/workflow-captain-pro of captain-pro).
#
# MINVER-1: the core and Pro need PHP 8.5 / WordPress 7.0. With E2E_LOWER_MINVER=1 (local container with PHP 8.3 and
# WordPress 6.9) the script lowers the version gate in COPIES of both plugins (src/Requirements.php) and nothing else.
#
# Results: $E2E_ROOT/logs/n8n-results.jsonl (one JSON line per check). Exit code 1 when a check failed.
set -uo pipefail

ROOT=${E2E_ROOT:-/home/user/wp-e2e}
HERE=$(cd "$(dirname "$0")" && pwd)
CORE=${E2E_CORE:-$(cd "$HERE/../.." && pwd)}
PRO=${E2E_PRO:-}
N8N_DIR=${N8N_DIR:-/tmp/n8n-e2e}
N8N_VERSION=${N8N_VERSION:-1.123.92}
SITE=$ROOT/n8n
PORT=8087
export E2E_ROOT=$ROOT E2E_SHOP_PORT=$PORT N8N_URL=http://127.0.0.1:5678 N8N_STATE=$ROOT/logs/n8n-api.json
export E2E_RESULTS=$ROOT/logs/n8n-results.jsonl E2E_DEBUG_LOG=$ROOT/logs/debug-n8n.log
export NO_PROXY="127.0.0.1,localhost${NO_PROXY:+,$NO_PROXY}" no_proxy="127.0.0.1,localhost${no_proxy:+,$no_proxy}"
API="python3 -I $HERE/n8n/api.py"
mkdir -p "$ROOT/logs"

wpn() { E2E_MAIL_FILE=$ROOT/logs/mail-n8n.jsonl php -d memory_limit=1024M "$ROOT/wp-cli/vendor/wp-cli/wp-cli/php/boot-fs.php" --allow-root --path="$SITE" "$@"; }
q() { grep -v -e '_load_textdomain_just_in_time' -e '^$' -e 'wp_update_plugins()' || true; }
ev() { wpn eval-file "$HERE/n8n-checks.php" "$@" 2>&1 | q; }
rec() { # <id> <name> <ok:0|1> <detail>
	php -r 'echo json_encode(array("id"=>$argv[1],"name"=>$argv[2],"result"=>$argv[3]==="1"?"PASS":"FAIL","detail"=>$argv[4]), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' "$1" "$2" "$3" "$4" | tee -a "$E2E_RESULTS"
}
state() { php -r '$s = json_decode( (string) @file_get_contents( $argv[1] ), true ); echo is_array( $s ) ? ( $s[ $argv[2] ] ?? "" ) : "";' "$ROOT/logs/n8n-state.json" "$2" 2>/dev/null; }
setstate() { php -r '$f = $argv[1]; $s = json_decode( (string) @file_get_contents( $f ), true ) ?: array(); $s[ $argv[2] ] = $argv[3]; file_put_contents( $f, json_encode( $s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );' "$ROOT/logs/n8n-state.json" "$1" "$2"; }

n8n_start() {
	curl -s -m 2 http://127.0.0.1:5678/healthz >/dev/null && return 0
	(cd "$N8N_DIR" && N8N_USER_FOLDER="$N8N_DIR/home" N8N_HOST=127.0.0.1 N8N_LISTEN_ADDRESS=127.0.0.1 N8N_PORT=5678 \
		N8N_PROTOCOL=http N8N_DIAGNOSTICS_ENABLED=false N8N_VERSION_NOTIFICATIONS_ENABLED=false N8N_TEMPLATES_ENABLED=false \
		N8N_PERSONALIZATION_ENABLED=false N8N_SECURE_COOKIE=false N8N_RUNNERS_ENABLED=false \
		N8N_ENFORCE_SETTINGS_FILE_PERMISSIONS=false EXECUTIONS_DATA_SAVE_ON_SUCCESS=all \
		setsid nohup node node_modules/n8n/bin/n8n start < /dev/null > "$ROOT/logs/n8n.log" 2>&1 &) > /dev/null 2>&1
	for _ in $(seq 1 90); do curl -s -m 2 http://127.0.0.1:5678/healthz >/dev/null && return 0; sleep 2; done
	echo "n8n did not start (see $ROOT/logs/n8n.log)" >&2; return 1
}
n8n_stop() { pkill -f "$N8N_DIR/node_modules/n8n/bin/n8n" 2>/dev/null; pkill -f 'node node_modules/n8n/bin/n8n start' 2>/dev/null; for _ in $(seq 1 20); do curl -s -m 1 http://127.0.0.1:5678/healthz >/dev/null || return 0; sleep 1; done; }

phase_setup() {
	mysqladmin ping >/dev/null 2>&1 || { echo "MariaDB is not running (bin/e2e/setup.sh starts it)." >&2; exit 1; }
	[ -f "$ROOT/suite/wp-includes/version.php" ] || { echo "Run bin/e2e/suite/setup.sh first (WordPress/WooCommerce copies)." >&2; exit 1; }
	[ -n "$PRO" ] && [ -f "$PRO/lib/kernel/load.php" ] || { echo "E2E_PRO must name a built addons/workflow-captain-pro (php build/build.php --in-place --addon=workflow-captain-pro)." >&2; exit 1; }

	# 1. n8n from npm (self-hosted, open source). xlsx is pinned to the npm registry copy: the version n8n asks for is
	#    only on cdn.sheetjs.com, which this container cannot reach.
	if [ ! -x "$N8N_DIR/node_modules/.bin/n8n" ] || [ "$(node -p "require('$N8N_DIR/node_modules/n8n/package.json').version" 2>/dev/null)" != "$N8N_VERSION" ]; then
		mkdir -p "$N8N_DIR"
		printf '{ "name": "n8n-e2e", "private": true, "dependencies": { "n8n": "%s" }, "overrides": { "xlsx": "0.18.5" } }\n' "$N8N_VERSION" > "$N8N_DIR/package.json"
		(cd "$N8N_DIR" && npm install --no-audit --no-fund > "$ROOT/logs/n8n-npm.log" 2>&1) || { echo "npm install failed (logs/n8n-npm.log)" >&2; exit 1; }
	fi
	n8n_stop; rm -rf "$N8N_DIR/home"; mkdir -p "$N8N_DIR/home"
	n8n_start || exit 1

	# 2. Test site: WordPress + WooCommerce copied from the suite site, own database, plugins as copies/symlinks.
	mkdir -p "$SITE"
	rsync -a --exclude wp-config.php --exclude 'wp-content/plugins/*' --exclude 'wp-content/mu-plugins' --exclude 'wp-content/uploads' "$ROOT/suite/" "$SITE/"
	[ -f "$SITE/wp-content/plugins/woocommerce/woocommerce.php" ] || rsync -a "$ROOT/suite/wp-content/plugins/woocommerce/" "$SITE/wp-content/plugins/woocommerce/"
	local core_dir=$CORE pro_dir=$PRO
	if [ "${E2E_LOWER_MINVER:-}" = 1 ]; then
		core_dir=$ROOT/n8n-src/workflow-captain pro_dir=$ROOT/n8n-src/workflow-captain-pro
		mkdir -p "$ROOT/n8n-src"; rsync -a --delete --exclude .git --exclude node_modules "$CORE/" "$core_dir/"; rsync -a --delete "$PRO/" "$pro_dir/"
		sed -i "s/const PHP = '8.5';/const PHP = '8.3';/; s/const WORDPRESS = '7.0';/const WORDPRESS = '6.9';/" "$core_dir/src/Requirements.php" "$pro_dir/src/Requirements.php"
		# WordPress itself refuses activation from the plugin headers ("Requires PHP", "Requires at least").
		sed -i -E 's/^( \* Requires PHP: +)8\.5/\18.3/; s/^( \* Requires at least: +)7\.0/\16.9/' "$core_dir/workflow-captain.php" "$pro_dir/workflow-captain-pro.php"
	fi
	ln -sfn "$core_dir" "$SITE/wp-content/plugins/workflow-captain"
	ln -sfn "$pro_dir" "$SITE/wp-content/plugins/workflow-captain-pro"
	ln -sfn "${E2E_LICENSE:-/home/user/wp-license}" "$SITE/wp-content/plugins/wp-license"
	mkdir -p "$SITE/wp-content/mu-plugins"
	cp "$CORE/bin/e2e/mu-plugins/e2e-test-site.php" "$CORE/bin/e2e/suite/mu-plugins/e2e-suite.php" "$SITE/wp-content/mu-plugins/"
	mysql -e "DROP DATABASE IF EXISTS wp_e2e_n8n; CREATE DATABASE wp_e2e_n8n; GRANT ALL ON wp_e2e_n8n.* TO 'wp'@'localhost'; FLUSH PRIVILEGES;"
	{
		echo "<?php"
		echo "define( 'DB_NAME', 'wp_e2e_n8n' ); define( 'DB_USER', 'wp' ); define( 'DB_PASSWORD', 'wp' ); define( 'DB_HOST', 'localhost' );"
		echo "define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );"
		for k in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
			echo "define( '$k', '$(php -r 'echo bin2hex(random_bytes(32));')' );"
		done
		echo "\$table_prefix = 'wp_';"
		echo "define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', '$E2E_DEBUG_LOG' ); define( 'WP_DEBUG_DISPLAY', false );"
		echo "define( 'WP_HOME', 'http://127.0.0.1:$PORT' ); define( 'WP_SITEURL', 'http://127.0.0.1:$PORT' );"
		echo "define( 'DISABLE_WP_CRON', true ); define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'AUTOMATIC_UPDATER_DISABLED', true ); define( 'FS_METHOD', 'direct' );"
		echo "define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array( 'e2e-2026' => 'e2e-only-installation-encryption-secret-0123456789' ) );"
		echo "define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', 'e2e-2026' );"
		echo "define( 'WPLC_LICENSE_KEY_SECRETS', array( 'e2e-2026' => 'e2e-only-license-key-secret-0123456789abcdef' ) );"
		echo "define( 'WPLC_LICENSE_KEY_ID', 'e2e-2026' );"
		echo "define( 'WORKFLOW_CAPTAIN_PRO_LICENSE_SERVER', 'https://shop.test' );"
		echo "define( 'WORKFLOW_CAPTAIN_SECRET_KEY', 'n8n-e2e-master-key-0123456789abcdef0123456789abcdef' );"
		echo "if ( is_readable( '$ROOT/logs/n8n-keys.php' ) ) { require '$ROOT/logs/n8n-keys.php'; }"
		echo "if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }"
		echo "require_once ABSPATH . 'wp-settings.php';"
	} > "$SITE/wp-config.php"
	rm -f "$ROOT/logs/n8n-keys.php" "$ROOT/logs/n8n-state.json"; : > "$E2E_DEBUG_LOG"
	pkill -f "php.*-S 127.0.0.1:$PORT" 2>/dev/null; sleep 1
	(cd "$ROOT" && E2E_SHOP_PORT=$PORT E2E_MAIL_FILE="$ROOT/logs/mail-n8n.jsonl" PHP_CLI_SERVER_WORKERS=6 setsid nohup php -d memory_limit=512M -S "127.0.0.1:$PORT" -t "$SITE" "$CORE/bin/e2e/router.php" < /dev/null > "$ROOT/logs/php-server-$PORT.log" 2>&1 &) > /dev/null 2>&1
	for _ in $(seq 1 20); do curl -s -m 3 -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 0.5; done
	wpn core install --url=http://127.0.0.1:$PORT --title="n8n E2E" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email 2>&1 | q
	wpn option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null 2>&1
	wpn rewrite structure '/%postname%/' >/dev/null 2>&1
	wpn plugin activate woocommerce wp-license workflow-captain 2>&1 | grep -E 'Error|Fatal' || true
	key=$(wpn wplc signing-key --field=public_key 2>/dev/null | tail -1)
	echo "<?php define( 'WORKFLOW_CAPTAIN_PRO_LICENSE_PUBLIC_KEYS', '$key' );" > "$ROOT/logs/n8n-keys.php"
	wpn plugin activate workflow-captain-pro 2>&1 | grep -E 'Error|Fatal' || true
}

phase_checks() {
	: > "$E2E_RESULTS"
	local v; v=$(node -p "require('$N8N_DIR/node_modules/n8n/package.json').version" 2>/dev/null)
	rec v0 "n8n $v (self-hosted, npm) on 127.0.0.1:5678 only" "$(ss -ltn | grep -q '127.0.0.1:5678 ' && ! ss -ltn | grep -qE '(0.0.0.0|\*|\[::\]):5678 ' && echo 1 || echo 0)" "$v"
	$API setup >/dev/null || { rec v1 'n8n owner and API key' 0 'setup failed'; return; }

	# Templates: import all four, check they load (same nodes, webhook path, webhookId), activate with a credential.
	local ids="" ok=1 detail="" paths=""
	for f in "$CORE"/docs/n8n/*.json; do
		local id; id=$($API import "$f")
		[[ "$id" =~ ^[A-Za-z0-9]{16}$ ]] || { ok=0; detail="$detail $(basename "$f"):$id"; continue; }
		ids="$ids $id"
		local loaded; loaded=$($API get "$id" | python3 -I -c 'import json,sys; w=json.load(sys.stdin); print(len(w["nodes"]), [n.get("webhookId","") != "" for n in w["nodes"] if n["type"].endswith(".webhook")][0])')
		local want; want=$(python3 -I -c 'import json,sys; w=json.load(open(sys.argv[1])); print(len(w["nodes"]), True)' "$f")
		[ "$loaded" = "$want" ] || { ok=0; detail="$detail $(basename "$f"):loaded=$loaded,want=$want"; }
		paths="$paths $(python3 -I -c 'import json,sys; print([n["parameters"]["path"] for n in json.load(open(sys.argv[1]))["nodes"] if n["type"].endswith(".webhook")][0])' "$f")"
		[ "$(basename "$f")" = woocommerce-bezahlt.json ] && setstate wf_paid "$id"
	done
	rec t1 'four templates of docs/n8n imported through the n8n API and loaded unchanged (nodes, webhookId)' $ok "ids:$ids$detail"
	local dup; dup=$(echo "$paths" | tr ' ' '\n' | sed '/^$/d' | sort | uniq -d)
	rec t2 'webhook paths of the templates are unique (all four can be active at the same time)' "$([ -z "$dup" ] && echo 1 || echo 0)" "paths:$paths${dup:+ duplicate: $dup}"
	local hid; hid=$($API import "$HERE/n8n/e2e-antworten.json"); setstate wf_status "$hid"
	local iid; iid=$($API import "$HERE/n8n/e2e-eingehend.json"); setstate wf_inbound "$iid"
	$API activate "$hid" >/dev/null; $API activate "$iid" >/dev/null

	ev license
	ev create
	local cred; cred=$($API cred "Workflow Captain n8n-paid" X-WFC-Token "$(state x header_secret)")
	local act="" wid
	for wid in $ids; do $API use-cred "$wid" "$cred" >/dev/null; act="$act $($API activate "$wid" | cut -d' ' -f1)"; done
	rec t3 'templates activated in n8n with a Header-Auth credential holding the value Workflow Captain showed once' "$([ "$(echo $act | tr ' ' '\n' | sort -u)" = 200 ] && echo 1 || echo 0)" "activate:$act"
	local p404; p404=$(curl -s -o /dev/null -w '%{http_code}' -X POST "http://127.0.0.1:5678/webhook/workflow-captain-woocommerce-order-paid" -H 'Content-Type: application/json' -d '{}')
	local pbad; pbad=$(curl -s -o /dev/null -w '%{http_code}' -X POST "http://127.0.0.1:5678/webhook/workflow-captain-woocommerce-order-paid" -H 'X-WFC-Token: falsch' -H 'Content-Type: application/json' -d '{}')
	rec t4 'n8n Header-Auth: request without the header and with a wrong value refused by n8n' "$([ "$p404" -ge 400 ] && [ "$pbad" -ge 400 ] && echo 1 || echo 0)" "without:$p404 wrong:$pbad"

	ev activate
	ev send
	ev duplicate
	ev codes
	$API deactivate "$(state x wf_paid)" >/dev/null
	ev inactive
	$API activate "$(state x wf_paid)" >/dev/null
	ev rotate_header
	local cred2; cred2=$($API cred "Workflow Captain n8n-paid (neu)" X-WFC-Token "$(state x header_secret)")
	$API use-cred "$(state x wf_paid)" "$cred2" >/dev/null; $API deactivate "$(state x wf_paid)" >/dev/null; $API activate "$(state x wf_paid)" >/dev/null
	ev rotate_header_after
	ev inbound
	ev rotate_inbound
	ev ssrf
	ev license_off
	n8n_stop
	ev down
	ev log
}

phase_stop() {
	n8n_stop
	pkill -f "php.*-S 127.0.0.1:$PORT" 2>/dev/null || true
}

if [ $# -eq 0 ]; then set -- setup checks stop; fi
for p in "$@"; do "phase_$p"; done
if [ -s "$E2E_RESULTS" ]; then
	echo "$(grep -c '"PASS"' "$E2E_RESULTS") passed, $(grep -c '"FAIL"' "$E2E_RESULTS") failed ($E2E_RESULTS)"
	grep -q '"FAIL"' "$E2E_RESULTS" && exit 1
fi
exit 0
