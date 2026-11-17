#!/bin/bash
# Release gate of the suite (WF-INT-18, not shipped): builds the ZIP of every plugin with its own build script in a
# fresh clone (the repositories are not touched), installs all ZIPs into a fresh site and checks activation, license
# (purchase, activation, entitlements, update, refund, deactivation) and the suite events there.
#
#   bin/e2e/suite/release.sh            (needs the suite site of run-suite.sh as the shop on :8083)
set -uo pipefail
ROOT=${E2E_ROOT:-/home/user/wp-e2e}
HERE=$(cd "$(dirname "$0")" && pwd)
CHECKS="$HERE/checks.php"
export E2E_RESULTS=${E2E_RESULTS:-$ROOT/logs/suite-results.jsonl}
export COMPOSER_ALLOW_SUPERUSER=1
CORE=${E2E_CORE:-$(cd "$HERE/../../.." && pwd)}
BRANCH=claude/plugin-todo-liste-2a83v4
Z="$ROOT/suite-src/zip"
OUT="$Z/out"
SITE="$ROOT/suite-zip"
cd /
q() { grep -v -e '_load_textdomain_just_in_time' -e 'wp_update_plugins()' -e '^$' || true; }
rec() { php -r 'echo json_encode(array("id"=>$argv[1],"name"=>$argv[2],"result"=>$argv[3]==="1"?"PASS":"FAIL","detail"=>$argv[4]), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' "$1" "$2" "$3" "$4" | tee -a "$E2E_RESULTS"; }
WZ="php -d memory_limit=1024M $ROOT/wp-cli/vendor/wp-cli/wp-cli/php/boot-fs.php --allow-root --path=$SITE"
wz() { E2E_MAIL_FILE="$ROOT/logs/mail-suite-zip.jsonl" $WZ "$@" 2>&1 | q; }

rm -rf "$Z" && mkdir -p "$OUT"
df=$(df --output=avail -BG /home/user | tail -1 | tr -dc 0-9)
[ "$df" -ge 3 ] || { rec g0 'enough disk space for the release builds (>= 3 GB)' 0 "${df} GB"; exit 1; }

# 1. ZIPs, each with the build script of its repository, in a clone of the branch.
clone() { git clone -q --depth 1 -b "${3:-$BRANCH}" "file://$1" "$Z/$2"; }
git -C "$CORE" archive --format=zip --prefix=workflow-captain/ -o "$OUT/workflow-captain.zip" HEAD
rec g1 'Workflow Captain: release ZIP (git archive, export-ignore)' $([ -s "$OUT/workflow-captain.zip" ] && echo 1 || echo 0) "$(git -C "$CORE" log --oneline -1)"

PRO_BRANCH=$(git -C /home/user/captain-pro rev-parse -q --verify wt/suite2 >/dev/null && echo wt/suite2 || echo "$BRANCH")
clone /home/user/captain-pro captain-pro "$PRO_BRANCH"
for a in workflow-captain-pro pass-captain-pro voucher-captain-pro affiliate-captain-pro; do
	(cd "$Z/captain-pro" && php build/build.php --addon=$a > "$Z/build-$a.log" 2>&1)
	f=$(ls "$Z/captain-pro/dist/$a"-*.zip 2>/dev/null | head -1)
	[ -n "$f" ] && cp "$f" "$OUT/$a.zip"
	rec "g2/$a" "$a: ZIP from build/build.php (development build: license placeholders not checked)" $([ -s "$OUT/$a.zip" ] && echo 1 || echo 0) "$(tail -1 "$Z/build-$a.log")"
done

clone /home/user/wp-passmaster pass
(cd "$Z/pass" && bash build/release.sh > "$Z/build-pass.log" 2>&1); cp "$Z/pass/build/dist/pass-captain.zip" "$OUT/" 2>/dev/null
rec g3 'Pass Captain: ZIP from build/release.sh (composer --no-dev in the export)' $([ -s "$OUT/pass-captain.zip" ] && echo 1 || echo 0) "$(tail -2 "$Z/build-pass.log" | tr '\n' ' ')"

clone /home/user/wp-couponmaster voucher
(cd "$Z/voucher" && bash bin/build.sh > "$Z/build-voucher.log" 2>&1); cp "$Z/voucher/build/voucher-captain.zip" "$OUT/" 2>/dev/null
rec g4 'Voucher Captain: ZIP from bin/build.sh (vendor scoped)' $([ -s "$OUT/voucher-captain.zip" ] && echo 1 || echo 0) "$(tail -2 "$Z/build-voucher.log" | tr '\n' ' ')"

clone /home/user/hastla007/wpaffiliatemaster affiliate
(cd "$Z/affiliate" && bash bin/build-zip.sh > "$Z/build-affiliate.log" 2>&1); f=$(ls "$Z/affiliate/dist/"affiliate-captain-*.zip 2>/dev/null | head -1); [ -n "$f" ] && cp "$f" "$OUT/affiliate-captain.zip"
rec g5 'Affiliate Captain: ZIP from bin/build-zip.sh' $([ -s "$OUT/affiliate-captain.zip" ] && echo 1 || echo 0) "$(tail -2 "$Z/build-affiliate.log" | tr '\n' ' ')"

clone /home/user/wp-license license
(cd "$Z/license" && bash bin/build-zip.sh > "$Z/build-license.log" 2>&1); f=$(ls "$Z/license/dist/"wp-license-captain-*.zip 2>/dev/null | head -1); [ -n "$f" ] && cp "$f" "$OUT/wp-license.zip"
rec g6 'WP License Captain: ZIP from bin/build-zip.sh' $([ -s "$OUT/wp-license.zip" ] && echo 1 || echo 0) "$(tail -2 "$Z/build-license.log" | tr '\n' ' ')"

# The development build keeps the placeholder host license.example.com in "Update URI" (a release build has the shop's
# host). The updater only takes packages from the host of its Update URI, so the test ZIP names the test shop.
php -r '$z = new ZipArchive(); $z->open( $argv[1] ); foreach ( array( "workflow-captain-pro/workflow-captain-pro.php", "workflow-captain-pro/config.php" ) as $f ) { $z->addFromString( $f, str_replace( "https://license.example.com/workflow-captain-pro", "https://shop.test/workflow-captain-pro", (string) $z->getFromName( $f ) ) ); } $z->close();' "$OUT/workflow-captain-pro.zip"

# 2. Fresh site from the WordPress and WooCommerce builds, plugins only from the ZIPs (no symlinks).
mysql -e "DROP DATABASE IF EXISTS wp_e2e_suite_zip; CREATE DATABASE wp_e2e_suite_zip; GRANT ALL ON wp_e2e_suite_zip.* TO 'wp'@'localhost';"
rm -rf "$SITE" && mkdir -p "$SITE"
rsync -a --exclude .git --exclude wp-config.php --exclude 'wp-content/plugins/*' --exclude 'wp-content/mu-plugins' --exclude 'wp-content/uploads' "$ROOT/ms/" "$SITE/"
rsync -a "$ROOT/www/wp-content/plugins/woocommerce/" "$SITE/wp-content/plugins/woocommerce/"
sed -e "s/'wp_e2e_suite'/'wp_e2e_suite_zip'/" -e "s#127.0.0.1:8083#127.0.0.1:8087#g" -e "s#debug-suite.log#debug-suite-zip.log#" "$ROOT/suite/wp-config.php" > "$SITE/wp-config.php"
mkdir -p "$SITE/wp-content/mu-plugins" && cp "$HERE/mu-plugins/e2e-suite.php" "$CORE/bin/e2e/mu-plugins/e2e-test-site.php" "$SITE/wp-content/mu-plugins/"
: > "$ROOT/logs/debug-suite-zip.log"
wz core install --url=http://127.0.0.1:8087 --title="Suite ZIP" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email
for o in "woocommerce_currency EUR" "woocommerce_default_country DE:BE" "woocommerce_coming_soon no"; do wz option update $o >/dev/null; done
wz plugin activate woocommerce >/dev/null
for p in wp-license workflow-captain pass-captain voucher-captain affiliate-captain workflow-captain-pro pass-captain-pro voucher-captain-pro affiliate-captain-pro; do
	out=$(wz plugin install "$OUT/$p.zip" --activate 2>&1 | tr '\n' ' ')
	ok=0; [ -d "$SITE/wp-content/plugins/$p" ] && [ ! -L "$SITE/wp-content/plugins/$p" ] && [[ "$out" == *"activated"* ]] && [[ "$out" != *Fatal* ]] && ok=1
	rec "g7/$p" "$p: installed from its ZIP into a fresh site and activated (no symlink)" "$ok" "${out:0:300}"
done
rec g8 'no symlink in the plugins folder of the ZIP site' $([ -z "$(find "$SITE/wp-content/plugins" -maxdepth 1 -type l)" ] && echo 1 || echo 0) "$(find "$SITE/wp-content/plugins" -maxdepth 1 -type l | tr '\n' ' ')"

# 3. License flow against the shop of the suite site, events from the ZIP builds.
wpsuite eval-file "$CHECKS" zip_shop_buy 2>&1 | q
E2E_MAIL_FILE="$ROOT/logs/mail-suite-zip.jsonl" $WZ eval-file "$CHECKS" zip_licenses 2>&1 | q
E2E_MAIL_FILE="$ROOT/logs/mail-suite-zip.jsonl" $WZ eval-file "$CHECKS" zip_events 2>&1 | q
wpsuite eval-file "$CHECKS" zip_shop_release "$OUT/workflow-captain-pro.zip" 2>&1 | q
E2E_MAIL_FILE="$ROOT/logs/mail-suite-zip.jsonl" $WZ eval-file "$CHECKS" zip_update 2>&1 | q
# Not checked: installing the update. The shop delivers packages only from https URLs of its own host (one-time link,
# then the release registry); this test shop runs on plain http://127.0.0.1:8083. wp-license covers the download in
# its own e2e flows 05 and 12 (https://shop.test). See docs/E2E-SUITE.md.
wpsuite eval-file "$CHECKS" zip_shop_refund 2>&1 | q
E2E_MAIL_FILE="$ROOT/logs/mail-suite-zip.jsonl" $WZ eval-file "$CHECKS" zip_refunded 2>&1 | q
hits=$(grep -E 'PHP (Fatal|Warning|Parse|Deprecated)' "$ROOT/logs/debug-suite-zip.log" | grep -E 'plugins/(workflow-captain|pass-captain|voucher-captain|affiliate-captain|wp-license)' | sort | uniq -c | head -10)
rec g20 'ZIP site PHP error log has no fatal/warning/deprecation from the suite plugins' $([ -z "$hits" ] && echo 1 || echo 0) "${hits:-clean}"
ls -la "$OUT" > "$ROOT/logs/suite-zips.txt"
