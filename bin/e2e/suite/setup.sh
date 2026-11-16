#!/bin/bash
# Builds the suite test sites for Workflow Captain, Workflow Captain Pro and the Captain plugins (not shipped: /bin is
# export-ignore). Reuses the builds of bin/e2e/setup.sh (WordPress tag, WooCommerce from the monorepo, WP-CLI); nothing
# is downloaded. Idempotent: re-running skips what exists and only (re)starts the servers.
#
#   bin/e2e/suite/setup.sh              # environment only
#   bin/e2e/suite/run-suite.sh          # fresh databases + all suite checks
#
# Layout (E2E_ROOT, default /home/user/wp-e2e):
#   suite/      single site with WooCommerce (HPOS), port 8083, database wp_e2e_suite. It is also the vendor shop:
#               WP License Captain runs here and Workflow Captain Pro licenses itself against it as https://shop.test
#               (the mu-plugin routes that host to 127.0.0.1:8083; the license client accepts only https).
#   suite-ms/   multisite (subdirectories), port 8084, database wp_e2e_suite_ms.
#   suite-src/  copy (git clone) of captain-pro, built with build/build.php --in-place (the repo itself is not touched).
#   hooks/      tiny PHP webhook receiver, port 8086, writes logs/suite-hooks.jsonl.
set -euo pipefail

ROOT=${E2E_ROOT:-/home/user/wp-e2e}
HERE=$(cd "$(dirname "$0")" && pwd)
CORE=${E2E_CORE:-$( [ -d /home/user/wt-suite-fix ] && echo /home/user/wt-suite-fix || echo /home/user/wp-workflowmaster )}
PRO_REPO=${E2E_PRO_REPO:-/home/user/captain-pro}
PASS=${E2E_PASS:-/home/user/wp-passmaster}
VOUCHER=${E2E_VOUCHER:-/home/user/wp-couponmaster}
AFFILIATE=${E2E_AFFILIATE:-/home/user/hastla007/wpaffiliatemaster}
LICENSE=${E2E_LICENSE:-/home/user/wp-license}
BRANCH=claude/plugin-todo-liste-2a83v4
mkdir -p "$ROOT/logs"

[ -d "$ROOT/ms/wp-includes" ] && [ -f "$ROOT/www/wp-content/plugins/woocommerce/woocommerce.php" ] && [ -x "$ROOT/wp-cli/vendor/bin/wp" ] || {
	echo "Run bin/e2e/setup.sh first (WordPress, WooCommerce and WP-CLI builds are reused)." >&2; exit 1; }
mysqladmin ping >/dev/null 2>&1 || { echo "MariaDB is not running (bin/e2e/setup.sh starts it)." >&2; exit 1; }

# 1. Databases (MariaDB root over the unix socket, like bin/e2e/setup.sh).
mysql -e "CREATE DATABASE IF NOT EXISTS wp_e2e_suite; CREATE DATABASE IF NOT EXISTS wp_e2e_suite_ms;
	CREATE USER IF NOT EXISTS 'wp'@'localhost' IDENTIFIED BY 'wp';
	GRANT ALL ON wp_e2e_suite.* TO 'wp'@'localhost'; GRANT ALL ON wp_e2e_suite_ms.* TO 'wp'@'localhost'; FLUSH PRIVILEGES;"

# 2. WordPress core copied from the clean tag checkout of the multisite build, WooCommerce copied from the built copy.
for site in suite suite-ms; do
	if [ ! -f "$ROOT/$site/wp-includes/version.php" ]; then
		mkdir -p "$ROOT/$site"
		rsync -a --exclude .git --exclude wp-config.php --exclude 'wp-content/plugins/*' --exclude 'wp-content/mu-plugins' \
			--exclude 'wp-content/uploads' --exclude 'wp-content/upgrade' --exclude 'wp-content/debug.log' "$ROOT/ms/" "$ROOT/$site/"
		cp "$ROOT/ms/wp-content/plugins/index.php" "$ROOT/$site/wp-content/plugins/" 2>/dev/null || true
	fi
	[ -f "$ROOT/$site/wp-content/plugins/woocommerce/woocommerce.php" ] || rsync -a "$ROOT/www/wp-content/plugins/woocommerce/" "$ROOT/$site/wp-content/plugins/woocommerce/"
done

# 3. Workflow Captain Pro: a clone of captain-pro (branch above), libraries written in place by its own build.
SRC="$ROOT/suite-src/captain-pro"
# Fixes found by the suite are committed locally on wt/suite-fix of captain-pro; use that branch while it exists.
PRO_BRANCH=${E2E_PRO_BRANCH:-$(git -C "$PRO_REPO" rev-parse -q --verify wt/suite-fix >/dev/null && echo wt/suite-fix || echo "$BRANCH")}
if [ ! -d "$SRC/.git" ]; then
	mkdir -p "$ROOT/suite-src" && git clone -q "$PRO_REPO" "$SRC"
fi
if [ -n "${E2E_REFRESH:-}" ] || [ "$(git -C "$SRC" rev-parse HEAD)" != "$(git -C "$PRO_REPO" rev-parse "$PRO_BRANCH")" ]; then
	git -C "$SRC" fetch -q origin "$PRO_BRANCH" && git -C "$SRC" checkout -q -B suite FETCH_HEAD
	for addon in workflow-captain-pro pass-captain-pro voucher-captain-pro affiliate-captain-pro; do rm -rf "$SRC/addons/$addon/lib"; done
fi
ADDONS="workflow-captain-pro pass-captain-pro voucher-captain-pro affiliate-captain-pro"
for addon in $ADDONS; do
	[ -f "$SRC/addons/$addon/lib/kernel/load.php" ] || (cd "$SRC" && php build/build.php --in-place --addon="$addon" >/dev/null)
done

# 3b. Voucher Captain: its checkout has an unscoped development vendor/ (dompdf pulls sabberworm/php-css-parser 9.x,
#     WooCommerce bundles 8.x: fatal "Declaration of Sabberworm\CSS\CSSList\CSSList::addComments() must be compatible").
#     The release build scopes it (bin/build.sh -> bin/scope-vendor.php); the suite does the same in a copy.
VSRC="$ROOT/suite-src/voucher-captain"
rsync -a --delete --exclude .git --exclude node_modules --exclude build --exclude vendor "$VOUCHER/" "$VSRC/"
if [ ! -f "$VSRC/vendor/autoload.php" ] || [ -n "${E2E_REFRESH:-}" ]; then
	rsync -a --delete "$VOUCHER/vendor/" "$VSRC/vendor/"
	php "$VSRC/bin/scope-vendor.php" "$VSRC/vendor" >/dev/null
	(cd "$VSRC" && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize --no-interaction --quiet)
fi

# 4. Plugins as symlinks to the checkouts (read only; fixes go to the branches of the repositories).
link() { # <site> <slug> <target>
	local dst="$ROOT/$1/wp-content/plugins/$2"
	if [ -L "$dst" ] && [ "$(readlink "$dst")" = "$3" ]; then return; fi
	rm -rf "$dst"; ln -s "$3" "$dst"
}
for site in suite suite-ms; do
	link "$site" workflow-captain "$CORE"
	for addon in $ADDONS; do link "$site" "$addon" "$SRC/addons/$addon"; done
	link "$site" pass-captain "$PASS"
	link "$site" voucher-captain "$VSRC"
	link "$site" affiliate-captain "$AFFILIATE"
done
link suite wp-license "$LICENSE"

# 5. wp-config.php. The license server is this shop; its Ed25519 public key is pinned after the first install
#    (run-suite.sh writes suite-keys.php). WPLC_* secrets are test-only values.
write_config() { # <dir> <db> <url> <log-suffix> <multisite:0|1>
	[ -f "$1/wp-config.php" ] && return
	{
		echo "<?php"
		echo "define( 'DB_NAME', '$2' ); define( 'DB_USER', 'wp' ); define( 'DB_PASSWORD', 'wp' ); define( 'DB_HOST', 'localhost' );"
		echo "define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );"
		for k in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
			echo "define( '$k', '$(php -r 'echo bin2hex(random_bytes(32));')' );"
		done
		echo "\$table_prefix = 'wp_';"
		echo "define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', '$ROOT/logs/debug$4.log' ); define( 'WP_DEBUG_DISPLAY', false );"
		echo "define( 'WP_HOME', '$3' ); define( 'WP_SITEURL', '$3' );"
		echo "define( 'DISABLE_WP_CRON', true ); define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'AUTOMATIC_UPDATER_DISABLED', true );"
		echo "define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'WP_ACCESSIBLE_HOSTS', 'shop.test' ); define( 'FS_METHOD', 'direct' );"
		echo "define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array( 'e2e-2026' => 'e2e-only-installation-encryption-secret-0123456789' ) );"
		echo "define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', 'e2e-2026' );"
		echo "define( 'WPLC_LICENSE_KEY_SECRETS', array( 'e2e-2026' => 'e2e-only-license-key-secret-0123456789abcdef' ) );"
		echo "define( 'WPLC_LICENSE_KEY_ID', 'e2e-2026' );"
		for c in WORKFLOW PASS VOUCHER AFFILIATE; do echo "define( '${c}_CAPTAIN_PRO_LICENSE_SERVER', 'https://shop.test' );"; done
		echo "if ( is_readable( '$ROOT/logs/suite-keys.php' ) ) { require '$ROOT/logs/suite-keys.php'; }"
		[ "$5" = 1 ] && echo "define( 'WP_ALLOW_MULTISITE', true ); define( 'MULTISITE', true ); define( 'SUBDOMAIN_INSTALL', false ); define( 'DOMAIN_CURRENT_SITE', '127.0.0.1:8084' ); define( 'PATH_CURRENT_SITE', '/' ); define( 'SITE_ID_CURRENT_SITE', 1 ); define( 'BLOG_ID_CURRENT_SITE', 1 );"
		echo "if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }"
		echo "require_once ABSPATH . 'wp-settings.php';"
	} > "$1/wp-config.php"
}
write_config "$ROOT/suite" wp_e2e_suite http://127.0.0.1:8083 -suite 0
write_config "$ROOT/suite-ms" wp_e2e_suite_ms http://127.0.0.1:8084 -suite-ms 1
for site in suite suite-ms; do
	mkdir -p "$ROOT/$site/wp-content/mu-plugins"
	cp "$CORE/bin/e2e/mu-plugins/e2e-test-site.php" "$HERE/mu-plugins/e2e-suite.php" "$ROOT/$site/wp-content/mu-plugins/"
done

# 6. WP-CLI wrappers.
for pair in wpsuite:suite wpsuitems:suite-ms; do
	name=${pair%%:*}; site=${pair#*:}
	printf '#!/bin/sh\nexec env E2E_MAIL_FILE=%s/logs/mail-%s.jsonl php -d memory_limit=1024M %s/wp-cli/vendor/wp-cli/wp-cli/php/boot-fs.php --allow-root --path=%s/%s "$@"\n' "$ROOT" "$site" "$ROOT" "$ROOT" "$site" > /usr/local/bin/$name
	chmod +x /usr/local/bin/$name
done

# 7. Servers: both sites (several workers, the shop answers its own license requests) and the webhook receiver.
start_server() { # <port> <docroot> [router]
	curl -s -m 3 -o /dev/null "http://127.0.0.1:$1/" && return
	(cd "$ROOT" && E2E_MAIL_FILE="$ROOT/logs/mail-$2.jsonl" PHP_CLI_SERVER_WORKERS=6 setsid nohup php -d memory_limit=512M -S "127.0.0.1:$1" -t "$2" ${3:-} < /dev/null > "logs/php-server-$1.log" 2>&1 &)
	for _ in $(seq 1 20); do curl -s -m 3 -o /dev/null "http://127.0.0.1:$1/" && break; sleep 0.5; done
}
start_server 8083 suite "$CORE/bin/e2e/router.php"
start_server 8084 suite-ms "$CORE/bin/e2e/router.php"
mkdir -p "$ROOT/hooks" && cp "$HERE/receiver.php" "$ROOT/hooks/index.php"
start_server 8086 hooks
echo "Suite environment ready under $ROOT (core: $CORE, pro: $SRC)."
