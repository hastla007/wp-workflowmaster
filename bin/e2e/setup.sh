#!/bin/bash
# Builds a real WordPress + WooCommerce test site for Workflow Captain from git sources (not shipped: /bin is
# export-ignore). Idempotent: re-running skips what exists. Needs root (apt), PHP 8.1+ with mysqli, composer, git.
#
#   bin/e2e/setup.sh                 # environment only
#   bin/e2e/run-checks.sh            # fresh site + all checks
#
# Layout (E2E_ROOT, default /home/user/wp-e2e):
#   www/      WordPress (git tag), served by "php -S 127.0.0.1:8080 -t www"
#   ms/       second WordPress for the multisite checks (port 8081)
#   wc-src/   sparse clone of woocommerce/woocommerce, plugins/woocommerce built with composer --prefer-source
#   wp-cli/   WP-CLI installed with composer --prefer-source; /usr/local/bin/wp wraps it for www
#   logs/     debug.log, mail.jsonl, results.jsonl, server logs
#
# Downloads from wordpress.org and GitHub release archives are blocked in the build container, so everything comes
# from git clones and composer source installs. WooCommerce JS is not built (no pnpm build): a feature-config.php is
# generated with WooCommerce's own generator and empty asset registries are stubbed so wp-admin renders.
set -euo pipefail

ROOT=${E2E_ROOT:-/home/user/wp-e2e}
PLUGIN=${E2E_PLUGIN:-$(cd "$(dirname "$0")/../.." && pwd)}
WP_TAG=${E2E_WP_TAG:-6.9.11}
WC_TAG=${E2E_WC_TAG:-9.9.5}
export COMPOSER_ALLOW_SUPERUSER=1
mkdir -p "$ROOT/logs"

# 1. Database: MariaDB from apt, started without systemd.
if ! command -v mariadbd >/dev/null; then
	apt-get update -q && apt-get install -y -q mariadb-server
fi
if ! mysqladmin ping >/dev/null 2>&1; then
	mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
	(setsid nohup mysqld_safe --user=mysql < /dev/null > "$ROOT/logs/mysqld.log" 2>&1 &)
	for _ in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
fi
mysql -e "CREATE DATABASE IF NOT EXISTS wp_e2e; CREATE DATABASE IF NOT EXISTS wp_e2e_ms;
	CREATE USER IF NOT EXISTS 'wp'@'localhost' IDENTIFIED BY 'wp';
	GRANT ALL ON wp_e2e.* TO 'wp'@'localhost'; GRANT ALL ON wp_e2e_ms.* TO 'wp'@'localhost'; FLUSH PRIVILEGES;"

# 2. WordPress core (built core repository, one tag).
[ -d "$ROOT/www/wp-includes" ] || git clone -q --depth 1 --branch "$WP_TAG" https://github.com/WordPress/WordPress "$ROOT/www"
[ -d "$ROOT/ms/wp-includes" ] || git clone -q --depth 1 --branch "$WP_TAG" https://github.com/WordPress/WordPress "$ROOT/ms"

# 3. WP-CLI from source.
if [ ! -x "$ROOT/wp-cli/vendor/bin/wp" ]; then
	mkdir -p "$ROOT/wp-cli" && cd "$ROOT/wp-cli" && { [ -f composer.json ] || echo '{}' > composer.json; }
	composer require -q wp-cli/wp-cli-bundle:^2.11 --prefer-source --no-interaction
fi
for site in www ms; do
	name=wp; [ "$site" = ms ] && name=wpms
	printf '#!/bin/sh\nexec php -d memory_limit=1024M %s/wp-cli/vendor/wp-cli/wp-cli/php/boot-fs.php --allow-root --path=%s/%s "$@"\n' "$ROOT" "$ROOT" "$site" > /usr/local/bin/$name
	chmod +x /usr/local/bin/$name
done

# 4. WooCommerce from the monorepo tag: composer from git sources, no JS build.
if [ ! -f "$ROOT/wc-src/plugins/woocommerce/vendor/autoload_packages.php" ]; then
	[ -d "$ROOT/wc-src" ] || git clone -q --depth 1 --branch "$WC_TAG" --filter=blob:none --sparse https://github.com/woocommerce/woocommerce "$ROOT/wc-src"
	git -C "$ROOT/wc-src" sparse-checkout set plugins/woocommerce packages/php
	cd "$ROOT/wc-src/plugins/woocommerce"
	composer install -q --no-dev --prefer-source --no-scripts --no-interaction
	mkdir -p includes/react-admin && WC_ADMIN_PHASE=core php bin/generate-feature-config.php
fi
WC="$ROOT/www/wp-content/plugins/woocommerce"
if [ ! -f "$WC/woocommerce.php" ]; then
	mkdir -p "$WC"
	rsync -a --exclude tests --exclude client --exclude node_modules --exclude .git "$ROOT/wc-src/plugins/woocommerce/" "$WC/"
	# Empty asset registries for the unbuilt wc-admin bundles (otherwise exceptions, and a fatal on plugins.php).
	cd "$WC"
	for d in admin-layout app block-templates components csv-export currency customer-effort-score data date embed \
		experimental explat navigation notices number product-editor remote-logging settings-editor tracks; do
		mkdir -p "assets/client/admin/$d"
		echo "<?php return array( 'dependencies' => array(), 'version' => 'e2e-stub' );" > "assets/client/admin/$d/index.asset.php"
		echo '/* e2e stub: WooCommerce JS not built */' > "assets/client/admin/$d/index.js"
	done
	mkdir -p assets/client/admin/wp-admin-scripts
	for f in $(grep -rhoE "register_script\( *'wp-admin-scripts', *'[a-z-]+'" src includes | sed -E "s/.*'([a-z-]+)'$/\1/" | sort -u); do
		echo "<?php return array( 'dependencies' => array(), 'version' => 'e2e-stub' );" > "assets/client/admin/wp-admin-scripts/$f.asset.php"
		echo '/* e2e stub */' > "assets/client/admin/wp-admin-scripts/$f.js"
	done
fi

# 4b. Optional: Yoast SEO from git (E2E_YOAST=1) for WF-SEO-07. Its runtime needs the scoped vendor_prefixed/ and the
# compiled DI container, which composer builds only with the dev tools; three wpackagist dev packages (test stubs) are
# removed first because wpackagist/wordpress.org downloads are blocked. 28.7 is the newest tag for WordPress 6.9.
if [ "${E2E_YOAST:-0}" = 1 ] && [ ! -f "$ROOT/www/wp-content/plugins/wordpress-seo/src/generated/container.php" ]; then
	[ -d "$ROOT/yoast-src" ] || git clone -q --depth 1 --branch "${E2E_YOAST_TAG:-28.7}" https://github.com/Yoast/wordpress-seo "$ROOT/yoast-src"
	cd "$ROOT/yoast-src"
	composer config preferred-install source
	composer remove --dev -q wpackagist-plugin/easy-digital-downloads wpackagist-plugin/google-site-kit wpackagist-plugin/woocommerce --no-interaction
	rsync -a --delete --exclude .git --exclude node_modules --exclude tests --exclude packages --exclude apps ./ "$ROOT/www/wp-content/plugins/wordpress-seo/"
	(cd "$ROOT/www/wp-content/plugins/wordpress-seo" && composer dump-autoload -q --no-dev --no-scripts)
fi

# 5. wp-config.php for both sites, test mu-plugin, plugin symlink.
write_config() { # <dir> <db> <url>
	[ -f "$1/wp-config.php" ] && return
	{
		echo "<?php"
		echo "define( 'DB_NAME', '$2' ); define( 'DB_USER', 'wp' ); define( 'DB_PASSWORD', 'wp' ); define( 'DB_HOST', 'localhost' );"
		echo "define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );"
		for k in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
			echo "define( '$k', '$(php -r 'echo bin2hex(random_bytes(32));')' );"
		done
		echo "\$table_prefix = 'wp_';"
		echo "define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', '$ROOT/logs/debug$4.log' ); define( 'WP_DEBUG_DISPLAY', true );"
		echo "define( 'WP_HOME', '$3' ); define( 'WP_SITEURL', '$3' );"
		echo "define( 'DISABLE_WP_CRON', true ); define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'AUTOMATIC_UPDATER_DISABLED', true );"
		echo "define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'FS_METHOD', 'direct' );"
		echo "if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }"
		echo "require_once ABSPATH . 'wp-settings.php';"
	} > "$1/wp-config.php"
}
write_config "$ROOT/www" wp_e2e http://127.0.0.1:8080 ""
write_config "$ROOT/ms" wp_e2e_ms http://127.0.0.1:8081 "-ms"
for site in www ms; do
	mkdir -p "$ROOT/$site/wp-content/mu-plugins"
	cp "$PLUGIN/bin/e2e/mu-plugins/e2e-test-site.php" "$ROOT/$site/wp-content/mu-plugins/"
	[ -e "$ROOT/$site/wp-content/plugins/workflow-captain" ] || ln -s "$PLUGIN" "$ROOT/$site/wp-content/plugins/workflow-captain"
done

# 6. Web servers (php -S, several workers so loopback requests do not block).
start_server() { # <port> <dir>
	curl -s -o /dev/null "http://127.0.0.1:$1/" && return
	(cd "$ROOT" && PHP_CLI_SERVER_WORKERS=4 setsid nohup php -d memory_limit=512M -S "127.0.0.1:$1" -t "$2" ${3:-} < /dev/null > "logs/php-server-$1.log" 2>&1 &)
	for _ in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:$1/" && break; sleep 0.5; done
}
start_server 8080 www "$PLUGIN/bin/e2e/router.php"
start_server 8081 ms "$PLUGIN/bin/e2e/router.php"
echo "Environment ready under $ROOT (plugin: $PLUGIN)."
