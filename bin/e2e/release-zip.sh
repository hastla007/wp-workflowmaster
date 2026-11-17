#!/bin/bash
# Release ZIP check (part of WF-INT-18; not shipped): builds the free ZIP with "git archive" (honours .gitattributes
# export-ignore), installs it with WP-CLI into a fresh single site (port 8082, database wp_e2e_zip, WooCommerce copied
# from the main E2E site), activates it and runs activation, providers and the order flow once.
#
#   bin/e2e/release-zip.sh [ref]     # default HEAD
#
# Outdated since MINVER-1: WordPress 6.9.11 and the local PHP 8.3 are below the minimum; the release ZIP is checked in CI
# by bin/e2e/ci-db.sh (checks ci1, ci2).
set -euo pipefail
ROOT=${E2E_ROOT:-/home/user/wp-e2e}
PLUGIN=${E2E_PLUGIN:-$(cd "$(dirname "$0")/../.." && pwd)}
REF=${1:-HEAD}
export E2E_RESULTS="$ROOT/logs/zip.jsonl" E2E_LABEL=zip E2E_MAIL_FILE="$ROOT/logs/mail.jsonl"
: > "$E2E_RESULTS"
git -C "$PLUGIN" archive --format=zip --prefix=workflow-captain/ -o "$ROOT/workflow-captain.zip" "$REF"
leaked=$(unzip -Z1 "$ROOT/workflow-captain.zip" | grep -E '^workflow-captain/(tests|bin|docs|examples|konzept.md|TODO.md|README.md|composer.json|\.git|\.wordpress-org)' || true)
php -r 'echo json_encode(array("id"=>"z1","name"=>"release ZIP contains only shipped files (export-ignore honoured)","result"=>$argv[1]===""?"PASS":"FAIL","detail"=>$argv[1]===""?$argv[2]:$argv[1])), "\n";' "$leaked" "$(unzip -Z1 "$ROOT/workflow-captain.zip" | cut -d/ -f2 | sort -u | tr '\n' ' ')" | tee -a "$E2E_RESULTS"
mysql -e "DROP DATABASE IF EXISTS wp_e2e_zip; CREATE DATABASE wp_e2e_zip; GRANT ALL ON wp_e2e_zip.* TO 'wp'@'localhost';"
[ -d "$ROOT/zip/wp-includes" ] || git clone -q --depth 1 --branch "${E2E_WP_TAG:-6.9.11}" https://github.com/WordPress/WordPress "$ROOT/zip"
sed -e "s/'wp_e2e'/'wp_e2e_zip'/; s#127.0.0.1:8080#127.0.0.1:8082#g; s#logs/debug.log#logs/debug-zip.log#" "$ROOT/www/wp-config.php" > "$ROOT/zip/wp-config.php"
mkdir -p "$ROOT/zip/wp-content/mu-plugins" && cp "$PLUGIN/bin/e2e/mu-plugins/e2e-test-site.php" "$ROOT/zip/wp-content/mu-plugins/"
rsync -a --delete "$ROOT/www/wp-content/plugins/woocommerce/" "$ROOT/zip/wp-content/plugins/woocommerce/"
rm -rf "$ROOT/zip/wp-content/plugins/workflow-captain"
W="php -d memory_limit=1024M $ROOT/wp-cli/vendor/wp-cli/wp-cli/php/boot-fs.php --allow-root --path=$ROOT/zip"
$W core install --url=http://127.0.0.1:8082 --title="ZIP E2E" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email >/dev/null
$W plugin activate woocommerce >/dev/null 2>&1
$W option update woocommerce_currency EUR >/dev/null
$W plugin install "$ROOT/workflow-captain.zip" --activate
[ -L "$ROOT/zip/wp-content/plugins/workflow-captain" ] && { echo "installed as symlink?"; exit 1; }
$W eval-file "$PLUGIN/bin/e2e/checks.php" activation
$W eval-file "$PLUGIN/bin/e2e/checks.php" providers
$W eval-file "$PLUGIN/bin/e2e/checks.php" order on
$W workflow-captain status
! grep -q '"result":"FAIL"' "$E2E_RESULTS"
