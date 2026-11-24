#!/bin/bash
# T103 per provider (not shipped): Pass Captain, Affiliate Captain and the license server (WP License Captain) each
# active on ONE subsite of a multisite. Checks: the adapter registers only there, its events are stored and processed
# only in the tables of that site, deactivating the plugin pauses its waiting events only there, requeue after
# reactivation. Own sites, databases and ports, so it runs beside the other suite sites:
#
#   msprov/shop  single site with WooCommerce and WP License Captain (vendor shop), port 8093, database wp_e2e_msprov
#   msprov/net   multisite (subdirectories), port 8094, database wp_e2e_msprov_ms
#                sites: 1 main (no activation), 2 quelle (Pass), 3 ziel (Affiliate), 4 drei (license server), 5 vier (none)
#
#   E2E_MSP_SRC=/path/to/sources bin/e2e/suite/ms-providers.sh
#
# E2E_MSP_SRC holds checkouts of captain-pro (built with "php build/build.php --in-place --addon=<add-on>" for
# workflow-captain-pro, pass-captain-pro and affiliate-captain-pro), wp-passmaster, wpaffiliatemaster and wp-license.
# WordPress and WooCommerce are copied from the staging site (E2E_MSP_WP, default $ROOT/staging: WordPress 7.1.4,
# WooCommerce 11.3.0). The core is copied from this checkout. Every plugin is a copy under msprov/plugins, the
# checkouts stay untouched.
#
# E2E_IGNORE_PHP_MIN=1: on PHP below 8.5 (MINVER-1) the copies get their PHP minimum lowered to the running PHP (version
# checks and "Requires PHP" headers only, nothing else), otherwise nothing activates. Results of such a run are on the
# wrong PHP and must say so (docs/E2E-SUITE.md).
#
# Writes $E2E_RESULTS (default logs/msprov-results.jsonl). Exit code 1 when a check failed.
set -uo pipefail
ROOT=${E2E_ROOT:-/home/user/wp-e2e}
HERE=$(cd "$(dirname "$0")" && pwd)
CORE=${E2E_CORE:-$(cd "$HERE/../../.." && pwd)}
CHECKS="$HERE/checks.php"
SRC=${E2E_MSP_SRC:?E2E_MSP_SRC: directory with captain-pro, wp-passmaster, wpaffiliatemaster, wp-license}
WPSRC=${E2E_MSP_WP:-$ROOT/staging}
D=${E2E_MSP_DIR:-$ROOT/msprov}
SHOP_PORT=8093 NET_PORT=8094 DB=wp_e2e_msprov DBMS=wp_e2e_msprov_ms
export E2E_RESULTS=${E2E_RESULTS:-$ROOT/logs/msprov-results.jsonl}
export E2E_STATE="$D/state.json" E2E_SHOP_PORT=$SHOP_PORT
mkdir -p "$D/plugins" "$ROOT/logs"
cd /

q() { grep -v -e '_load_textdomain_just_in_time' -e 'wp_update_plugins()' -e '^$' -e 'Deprecated:' -e 'deprecated' || true; }
cli() { local path=$1; shift; E2E_MAIL_FILE="$D/mail.jsonl" php -d memory_limit=1024M "$ROOT/wp-cli/vendor/wp-cli/wp-cli/php/boot-fs.php" --allow-root --path="$path" "$@" 2>&1 | q; }
shop() { cli "$D/shop" "$@"; }
net() { cli "$D/net" "$@"; }
at() { local site=$1; shift; if [ "$site" = main ]; then net --url="127.0.0.1:$NET_PORT/" "$@"; else net --url="127.0.0.1:$NET_PORT/$site/" "$@"; fi; }
chk() { local site=$1; shift; at "$site" eval-file "$CHECKS" "$@"; }
rec() { php -r 'echo json_encode(array("id"=>$argv[1],"name"=>$argv[2],"result"=>$argv[3]==="1"?"PASS":"FAIL","detail"=>$argv[4]), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' "$1" "$2" "$3" "$4" | tee -a "$E2E_RESULTS"; }

# 1. Plugins: copies of the core and of the sources.
copy() { rsync -a --delete --exclude .git --exclude node_modules --exclude tests "$1/" "$D/plugins/$2/"; }
copy "$CORE" workflow-captain
for a in workflow-captain-pro pass-captain-pro affiliate-captain-pro; do copy "$SRC/captain-pro/addons/$a" "$a"; done
copy "$SRC/wp-passmaster" pass-captain
copy "$SRC/wpaffiliatemaster" affiliate-captain
copy "$SRC/wp-license" wp-license
PHPV=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
if [ -n "${E2E_IGNORE_PHP_MIN:-}" ] && php -r 'exit( version_compare( PHP_VERSION, "8.5", "<" ) ? 0 : 1 );'; then
	for f in workflow-captain/workflow-captain.php workflow-captain/src/Requirements.php workflow-captain-pro/workflow-captain-pro.php \
		workflow-captain-pro/src/Requirements.php pass-captain-pro/pass-captain-pro.php affiliate-captain-pro/affiliate-captain-pro.php \
		pass-captain/pass-captain.php affiliate-captain/affiliate-captain.php wp-license/wp-license.php; do
		sed -i -e "s/^\( \* Requires PHP: *\)8\.5/\1$PHPV/" -e "s/const PHP = '8\.5'/const PHP = '$PHPV'/" \
			-e "s/version_compare( PHP_VERSION, '8\.5'/version_compare( PHP_VERSION, '$PHPV'/" "$D/plugins/$f"
	done
	echo "PHP minimum lowered to $PHPV in the copies (E2E_IGNORE_PHP_MIN)."
fi

# 2. Databases, WordPress, WooCommerce, configuration.
mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB; DROP DATABASE IF EXISTS $DBMS; CREATE DATABASE $DBMS;
	CREATE USER IF NOT EXISTS 'wp'@'localhost' IDENTIFIED BY 'wp'; GRANT ALL ON $DB.* TO 'wp'@'localhost';
	GRANT ALL ON $DBMS.* TO 'wp'@'localhost'; FLUSH PRIVILEGES;"
rm -f "$E2E_STATE" "$D/keys.php" "$D/mail.jsonl" "$D/debug-shop.log" "$D/debug-net.log"; : > "$E2E_RESULTS"
for site in shop net; do
	rsync -a --delete --exclude wp-config.php --exclude 'wp-content/plugins/*' --exclude wp-content/mu-plugins \
		--exclude wp-content/uploads --exclude wp-content/upgrade --exclude 'wp-content/debug*.log' "$WPSRC/" "$D/$site/"
	rsync -a --delete "$WPSRC/wp-content/plugins/woocommerce/" "$D/$site/wp-content/plugins/woocommerce/"
	for p in "$D"/plugins/*; do ln -sfn "$p" "$D/$site/wp-content/plugins/$(basename "$p")"; done
	mkdir -p "$D/$site/wp-content/mu-plugins"
	cp "$CORE/bin/e2e/mu-plugins/e2e-test-site.php" "$HERE/mu-plugins/e2e-suite.php" "$D/$site/wp-content/mu-plugins/"
	port=$SHOP_PORT db=$DB ms=0
	[ "$site" = net ] && port=$NET_PORT db=$DBMS ms=1
	{
		echo "<?php"
		echo "define( 'DB_NAME', '$db' ); define( 'DB_USER', 'wp' ); define( 'DB_PASSWORD', 'wp' ); define( 'DB_HOST', 'localhost' );"
		echo "define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );"
		for k in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
			echo "define( '$k', '$(php -r 'echo bin2hex(random_bytes(32));')' );"
		done
		echo "\$table_prefix = 'wp_';"
		echo "define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', '$D/debug-$site.log' ); define( 'WP_DEBUG_DISPLAY', false );"
		echo "define( 'WP_HOME', 'http://127.0.0.1:$port' ); define( 'WP_SITEURL', 'http://127.0.0.1:$port' );"
		echo "define( 'DISABLE_WP_CRON', true ); define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'AUTOMATIC_UPDATER_DISABLED', true );"
		echo "define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'WP_ACCESSIBLE_HOSTS', 'shop.test' ); define( 'FS_METHOD', 'direct' );"
		echo "define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array( 'e2e-2026' => 'e2e-only-installation-encryption-secret-0123456789' ) );"
		echo "define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', 'e2e-2026' );"
		echo "define( 'WPLC_LICENSE_KEY_SECRETS', array( 'e2e-2026' => 'e2e-only-license-key-secret-0123456789abcdef' ) );"
		echo "define( 'WPLC_LICENSE_KEY_ID', 'e2e-2026' );"
		[ -n "${E2E_IGNORE_PHP_MIN:-}" ] && echo "define( 'WPLC_TESTS_IGNORE_PHP_MINIMUM', true );"
		for c in WORKFLOW PASS VOUCHER AFFILIATE; do echo "define( '${c}_CAPTAIN_PRO_LICENSE_SERVER', 'https://shop.test' );"; done
		echo "if ( is_readable( '$D/keys.php' ) ) { require '$D/keys.php'; }"
		[ "$ms" = 1 ] && echo "define( 'WP_ALLOW_MULTISITE', true ); define( 'MULTISITE', true ); define( 'SUBDOMAIN_INSTALL', false ); define( 'DOMAIN_CURRENT_SITE', '127.0.0.1:$NET_PORT' ); define( 'PATH_CURRENT_SITE', '/' ); define( 'SITE_ID_CURRENT_SITE', 1 ); define( 'BLOG_ID_CURRENT_SITE', 1 );"
		echo "if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }"
		echo "require_once ABSPATH . 'wp-settings.php';"
	} > "$D/$site/wp-config.php"
done

# 3. Server of the shop (the license clients of the subsites call it as https://shop.test, see the suite mu-plugin).
pkill -f "php .*-S 127.0.0.1:$SHOP_PORT" 2>/dev/null
(cd "$D" && E2E_SHOP_PORT=$SHOP_PORT E2E_MAIL_FILE="$D/mail.jsonl" PHP_CLI_SERVER_WORKERS=6 setsid nohup php -d memory_limit=512M -S "127.0.0.1:$SHOP_PORT" -t shop "$CORE/bin/e2e/router.php" < /dev/null > "$D/php-server-$SHOP_PORT.log" 2>&1 &) > /dev/null 2>&1
for _ in $(seq 1 20); do curl -s -m 3 -o /dev/null "http://127.0.0.1:$SHOP_PORT/" && break; sleep 0.5; done

# 4. Shop: WooCommerce (HPOS), WP License Captain, plans and paid licenses (section "shop" of the suite).
shop core install --url=http://127.0.0.1:$SHOP_PORT --title="MS Provider Shop" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email
for o in "timezone_string Europe/Berlin" "woocommerce_currency EUR" "woocommerce_default_country DE:BE" "woocommerce_coming_soon no" "woocommerce_allow_tracking no"; do shop option update $o >/dev/null; done
shop plugin activate woocommerce wp-license | grep -E 'Error|Fatal'
key=$(shop wplc signing-key --field=public_key | tail -1)
{ echo "<?php"; for c in WORKFLOW PASS VOUCHER AFFILIATE; do echo "define( '${c}_CAPTAIN_PRO_LICENSE_PUBLIC_KEYS', '$key' );"; done; } > "$D/keys.php"
rec msp0 'shop signing key pinned for the Pro add-ons' "$([ -n "$key" ] && echo 1 || echo 0)" "$key"
shop eval-file "$CHECKS" shop

# 5. Network: Workflow Captain and Pro network-activated, every subsite activates the network plan itself (B2).
net core multisite-install --url=http://127.0.0.1:$NET_PORT --title="MS Provider" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email --skip-config
for s in quelle ziel drei vier; do net site create --slug=$s --title=$s --email=admin@example.test --porcelain >/dev/null; done
net plugin activate workflow-captain workflow-captain-pro --network | grep -E 'Error|Fatal'
chk main ms_prepare
for s in quelle ziel drei vier; do chk $s ms_site_license wcp_net; done

# 6. One provider per site, each with WooCommerce; the product licenses only on their own site.
at quelle plugin activate woocommerce pass-captain pass-captain-pro | grep -E 'Error|Fatal|Warning'
chk quelle activate PassCaptainPro:pass
at ziel plugin activate woocommerce affiliate-captain affiliate-captain-pro | grep -E 'Error|Fatal|Warning'
chk ziel activate AffiliateCaptainPro:affiliate
at drei plugin activate woocommerce wp-license | grep -E 'Error|Fatal|Warning'
SUITE=pass-captain,affiliate-captain,wp-license
only() { echo "$SUITE" | tr ',' '\n' | while read -r a; do [ "$a" = "$1" ] && printf '%s,' "$a" || printf -- '-%s,' "$a"; done; }
chk main ms_provider "$(only none)"
chk quelle ms_provider "$(only pass-captain)"
chk ziel ms_provider "$(only affiliate-captain)"
chk drei ms_provider "$(only wp-license)"
chk vier ms_provider "$(only none)"

# 7. Events per provider only in the tables of its site.
chk quelle ms_prov_emit pass
chk ziel ms_prov_emit affiliate
chk drei ms_prov_emit license

# 8. Deactivation pauses only on that site; the other sites keep their adapters; requeue after reactivation.
deact_case() { # <site> <provider> <plugin> <label>
	chk "$1" ms_prov_emit "$2:wait"
	at "$1" plugin deactivate "$3" | grep -E 'Error|Fatal'
	chk "$1" ms_prov_paused "$2"
	chk "$1" ms_provider "off-$2|-$3"
	for s in quelle ziel drei; do
		[ "$s" = "$1" ] && continue
		case $s in quelle) a=pass-captain;; ziel) a=affiliate-captain;; drei) a=wp-license;; esac
		chk "$s" ms_provider "off-$2|$a"
	done
	at "$1" plugin activate "$3" | grep -E 'Error|Fatal'
	chk "$1" ms_prov_resume "$2"
}
deact_case quelle pass pass-captain
deact_case ziel affiliate affiliate-captain
deact_case drei license wp-license

hits=$(grep -hE 'PHP (Fatal|Warning|Parse)' "$D/debug-net.log" "$D/debug-shop.log" 2>/dev/null | grep -E 'plugins/(workflow-captain|pass-captain|affiliate-captain|wp-license)' | sort | uniq -c | head -10)
rec msp-log 'no PHP fatal or warning from the suite plugins in the logs of shop and network' "$([ -z "$hits" ] && echo 1 || echo 0)" "${hits:-clean}"
fails=$(grep -c '"result":"FAIL"' "$E2E_RESULTS" || true)
total=$(wc -l < "$E2E_RESULTS")
echo "ms-providers: $((total - fails)) of $total checks passed."
[ "$fails" = 0 ]
