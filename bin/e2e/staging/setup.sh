#!/bin/bash
# Staging site for Workflow Captain, built with the steps and versions of the GitHub workflows (not shipped: /bin is
# export-ignore). Template: wp-couponmaster .github/workflows/plugin-smoke.yml (job wordpress-integration: WordPress
# 7.1.4, "wp plugin install wordpress-seo --version=28.8", release ZIP installed with --activate and again with
# --force); WooCommerce 11.3.0 as in wp-passmaster ci.yml (browser job, "WC tested up to"). Deviations, all documented
# in docs/E2E-RESULTS.md ("Staging"): PHP 8.3 instead of 8.4, MariaDB 10.11 over the socket instead of a MySQL/MariaDB
# service container, WP-CLI built from source (2.12.0) instead of the downloaded wp-cli.phar, a proxy mu-plugin.
#
#   E2E_FRESH=1 bin/e2e/staging/setup.sh     # drop and rebuild both staging sites
#   bin/e2e/staging/setup.sh                 # after a container restart: start MariaDB and the two servers
#
# Sites: /home/user/wp-e2e/staging (http://127.0.0.1:8090, database wp_staging), /home/user/wp-e2e/staging-ms
# (http://127.0.0.1:8091, wp_staging_ms, multisite installed by run-checks.sh). Admin: admin / admin-e2e-pass.
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/../../.." && pwd)
. "$HERE/env-staging.sh"
. "$PLUGIN/bin/e2e/env.sh"
WP_VERSION=${STAGING_WP:-7.1.4}
WC_VERSION=${STAGING_WC:-11.3.0}
YOAST_VERSION=${STAGING_YOAST:-28.8}
HPOS=${E2E_HPOS:-yes}
mkdir -p "$E2E_LOGS" "$ROOT/bin-staging" "$(dirname "$E2E_ZIP")"
avail=$(df -BG --output=avail /home/user | tail -1 | tr -dc 0-9)
[ "$avail" -ge 3 ] || { echo "Less than 3 GB free on /home/user ($avail GB): stopping."; exit 1; }
rec() { php -r 'echo json_encode(array("id"=>$argv[1],"name"=>$argv[2],"result"=>$argv[3]==="1"?"PASS":"FAIL","detail"=>$argv[4]), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' "$1" "$2" "$3" "$4" | tee -a "$E2E_LOGS/results-staging-setup.jsonl"; }

# MariaDB (local, root over the socket; the workflow uses a service container with root/root on 127.0.0.1).
if ! mysqladmin ping >/dev/null 2>&1; then
	mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
	(setsid nohup mysqld_safe --user=mysql < /dev/null > "$ROOT/logs/mysqld.log" 2>&1 &)
	for _ in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
fi
for db in wp_staging wp_staging_ms wp_staging_restore wp_staging_load wp_staging_seo; do
	mysql -e "GRANT ALL ON $db.* TO 'wp'@'localhost';"
done

# WP-CLI: the workflow downloads wp-cli.phar; here the bundle built from source (bin/e2e/setup.sh) with --path.
for n in wp:staging wpms:staging-ms; do
	printf '#!/bin/sh\nexec php -d memory_limit=1024M %s/wp-cli/vendor/wp-cli/wp-cli/php/boot-fs.php --allow-root --path=%s/%s "$@"\n' "$ROOT" "$ROOT" "${n#*:}" > "$ROOT/bin-staging/${n%%:*}"
	chmod +x "$ROOT/bin-staging/${n%%:*}"
done

config() { # <dir> <db> <url> <debug-log>
	local cli=wp; [ "$1" = "$E2E_MSDIR" ] && cli=wpms
	$cli config create --dbname="$2" --dbuser=wp --dbpass=wp --dbhost=localhost --skip-check --force >/dev/null
	$cli config set WP_DEBUG true --raw >/dev/null
	$cli config set WP_DEBUG_LOG "$4" >/dev/null
	$cli config set WP_DEBUG_DISPLAY true --raw >/dev/null
	$cli config set DISABLE_WP_CRON true --raw >/dev/null
	$cli config set WP_ENVIRONMENT_TYPE staging >/dev/null
	$cli config set FS_METHOD direct >/dev/null
	$cli config set WP_HOME "$3" >/dev/null
	$cli config set WP_SITEURL "$3" >/dev/null
	$cli config set E2E_MAIL_FILE "$E2E_MAIL_FILE" >/dev/null
	# The container reaches wordpress.org only through its egress proxy (the GitHub runner has direct access).
	$cli config set WP_PROXY_HOST 127.0.0.1 >/dev/null
	$cli config set WP_PROXY_PORT "${HTTPS_PROXY##*:}" --raw >/dev/null
	$cli config set WP_PROXY_BYPASS_HOSTS 'localhost,127.0.0.1' >/dev/null
	mkdir -p "$1/wp-content/mu-plugins"
	cp "$HERE/mu-plugins/e2e-staging-proxy.php" "$PLUGIN/bin/e2e/mu-plugins/e2e-test-site.php" "$1/wp-content/mu-plugins/"
}

if [ "${E2E_FRESH:-0}" = 1 ]; then
	mysql -e "DROP DATABASE IF EXISTS wp_staging; CREATE DATABASE wp_staging; DROP DATABASE IF EXISTS wp_staging_ms; CREATE DATABASE wp_staging_ms;"
	rm -rf "$E2E_WWW" "$E2E_MSDIR"; mkdir -p "$E2E_WWW" "$E2E_MSDIR"
	: > "$E2E_LOGS/results-staging-setup.jsonl"

	# --- Install WordPress 7.1.4 (workflow step) ---
	wp core download --version="$WP_VERSION" >/dev/null
	config "$E2E_WWW" wp_staging "$E2E_URL" "$E2E_LOG"
	# Copies of the database (second run: wp_staging_restore, wp_staging_load) are reached with E2E_DB=<db>.
	sed -i "s/^define( 'DB_NAME', 'wp_staging' );/define( 'DB_NAME', getenv( 'E2E_DB' ) ?: 'wp_staging' );/" "$E2E_WWW/wp-config.php"
	wp core install --url="$E2E_URL" --title="Workflow Captain Staging" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email >/dev/null

	# --- Build, install, and update the release ZIP (workflow step; WooCommerce 11.3.0 instead of 11.2.2) ---
	wp plugin install woocommerce --version="$WC_VERSION" --activate >/dev/null
	wp plugin install wordpress-seo --version="$YOAST_VERSION" --activate >/dev/null
	wp option update woocommerce_custom_orders_table_enabled "$HPOS" >/dev/null
	git -C "$PLUGIN" archive --format=zip --prefix=workflow-captain/ -o "$E2E_ZIP" HEAD
	wp plugin install "$E2E_ZIP" --activate >/dev/null
	out=$(wp plugin install "$E2E_ZIP" --force 2>&1 | tail -1)
	rec st1 'release ZIP installed with --activate and updated with --force (as in the workflow)' $([[ "$out" == Success* ]] && wp plugin is-active workflow-captain && [ ! -L "$E2E_WWW/wp-content/plugins/workflow-captain" ] && echo 1 || echo 0) "$out"
	leaked=$(unzip -Z1 "$E2E_ZIP" | grep -E '^workflow-captain/(tests|bin|docs|examples|konzept.md|TODO.md|README.md|composer.json|\.git|\.wordpress-org)' || true)
	rec st2 'release ZIP contains only shipped files (export-ignore)' $([ -z "$leaked" ] && echo 1 || echo 0) "${leaked:-$(unzip -Z1 "$E2E_ZIP" | cut -d/ -f2 | sort -u | tr '\n' ' ')}"
	# Classic Editor from wordpress.org (for WF-SEO-08), inactive until seo.js needs it.
	wp plugin install classic-editor >/dev/null
	vers=$(wp eval 'global $wp_version; echo $wp_version, " / ", WC_VERSION, " / ", WPSEO_VERSION, " / PHP ", PHP_VERSION;')
	rec st3 "versions: WordPress / WooCommerce / Yoast SEO / PHP" $([[ "$vers" == "$WP_VERSION / $WC_VERSION / $YOAST_VERSION / "* ]] && echo 1 || echo 0) "$vers"
	rec st4 'HPOS setting as in the workflow matrix' $([ "$(wp option get woocommerce_custom_orders_table_enabled)" = "$HPOS" ] && echo 1 || echo 0) "woocommerce_custom_orders_table_enabled=$HPOS"

	# --- second site for the multisite checks (run-checks.sh installs the network on a fresh database) ---
	wpms core download --version="$WP_VERSION" >/dev/null
	config "$E2E_MSDIR" wp_staging_ms "$E2E_MS_URL" "$E2E_MS_LOG"
fi

# Web servers (php -S with the router of bin/e2e, several workers for loopback requests).
start_server() { # <port> <dir>
	curl -s -o /dev/null "http://127.0.0.1:$1/" && return
	PHP_CLI_SERVER_WORKERS=4 setsid nohup php -d memory_limit=512M -S "127.0.0.1:$1" -t "$2" "$PLUGIN/bin/e2e/router.php" < /dev/null > "$E2E_LOGS/php-server-$1.log" 2>&1 &
	disown
	for _ in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:$1/" && break; sleep 0.5; done
}
start_server 8090 "$E2E_WWW"
start_server 8091 "$E2E_MSDIR"
echo "Staging ready: $E2E_URL ($E2E_WWW), $E2E_MS_URL ($E2E_MSDIR); . bin/e2e/staging/env-staging.sh before bin/e2e scripts."
