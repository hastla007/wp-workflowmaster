#!/bin/bash
# Multisite phase of run-suite.sh (not shipped): network-wide workflows of Workflow Captain Pro with one activation per
# participating subsite at the shop of the suite site (B2, WF-MS-22, WF-MS-23) and the providers per site (T103).
# Database wp_e2e_suite_ms is reset by phase "install" of run-suite.sh. Sites: 1 main (Network Admin, no activation),
# 2 quelle (source), 3 ziel, 4 drei, 5 vier (targets).
set -uo pipefail
ROOT=${E2E_ROOT:-/home/user/wp-e2e}
HERE=$(cd "$(dirname "$0")" && pwd)
CHECKS="$HERE/checks.php"
export E2E_RESULTS=${E2E_RESULTS:-$ROOT/logs/suite-results.jsonl}
cd /
q() { grep -v -e '_load_textdomain_just_in_time' -e 'wp_update_plugins()' -e '^$' || true; }
U=127.0.0.1:8084
ms() { wpsuitems eval-file "$CHECKS" "$@" 2>&1 | q; }
at() { local site=$1; shift; if [ "$site" = main ]; then wpsuitems --url="$U/" "$@" 2>&1 | q; else wpsuitems --url="$U/$site/" "$@" 2>&1 | q; fi; }
lic() { at "$1" eval-file "$CHECKS" ms_site_license "$2"; }
post() { at quelle post create --post_title="Netz-Beitrag $1" --post_status=publish --porcelain >/dev/null; }
work() { for s in "$@"; do at "$s" workflow-captain process >/dev/null; done; }
shop() { wpsuite eval-file "$CHECKS" "$@" 2>&1 | q; }
TARGETS="ziel drei vier"
SITES="quelle ziel drei vier"

wpsuitems core multisite-install --url=http://$U --title="Suite MS" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email --skip-config 2>&1 | q
for s in quelle ziel drei vier; do wpsuitems site create --slug=$s --title=$s --email=admin@example.test --porcelain >/dev/null 2>&1; done
wpsuitems plugin activate workflow-captain workflow-captain-pro --network 2>&1 | grep -E 'Error|Fatal'

# Licenses: every participating subsite activates the network plan (4 live sites) itself; the main site does not.
ms ms_prepare
for s in $SITES; do lic $s wcp_net; done
for s in $SITES; do at $s eval-file "$CHECKS" ms_site_refresh; done
ms ms_reports
lic main wcp_net:production:refused

# Consents, permissions, runs (WF-MS-23 base matrix), all managed from the main site.
ms ms_setup
post 1; work quelle
ms ms_source_check
post 2; work quelle $TARGETS
ms ms_run_check
post 3; work quelle $TARGETS
ms ms_consent_check
work vier
ms ms_consent_resume

# Seats from the license contract (WF-MS-22): the shop refused a fifth live site, Pro refuses five blog IDs.
ms ms_seats_contract
lic main wcp_net:development
ms ms_seats_dev
at main eval-file "$CHECKS" ms_site_unlicense

# License change per site: target 4 switches to the basic plan (no workflow.multisite_network) and back.
post 4; work quelle
ms ms_wait_prep switch_run
lic drei wcp_basic
ms ms_switch_target_check
work $TARGETS
ms ms_switch_target_run
post 5; work quelle ziel vier
ms ms_switch_new_run
lic drei wcp_net
work drei
ms ms_switch_back
work drei
ms ms_switch_done

# License change of the source site.
ms ms_runs_before runs_before_source
lic quelle wcp_basic
post 6; work quelle
ms ms_source_unlicensed
lic quelle wcp_net

# Expiry of the license of all four sites in the shop, renewal, "resume run".
post 7; work quelle
ms ms_wait_prep expire_run
shop shop_expire_ms
for s in $SITES; do at $s eval-file "$CHECKS" ms_site_refresh expired; done
work $TARGETS
ms ms_expired_check
ms ms_runs_before runs_before_expired_post
post 8; work quelle
ms ms_expired_source
shop shop_renew_ms
for s in $SITES; do at $s eval-file "$CHECKS" ms_site_refresh active; done
work $TARGETS
ms ms_renewed_check
work $TARGETS
ms ms_resumed_check
ms ms_pause_net

# WF-MS-23: capability removal (publisher loses super admin, site admin loses his site).
ms ms_cap_setup
post 9; work quelle ziel
ms ms_cap_ran
post 10; work quelle ziel
ms ms_cap_check

# WF-MS-23: provider plugin missing on the target site 5.
wpsuitems site option update e2e_suite_net_contract 1 >/dev/null 2>&1
ms ms_provider_setup
post 11; work quelle ziel vier
ms ms_provider_check
at vier plugin activate woocommerce voucher-captain voucher-captain-pro >/dev/null
at vier eval-file "$CHECKS" activate VoucherCaptainPro:voucher
ms ms_provider_resume
work vier
ms ms_provider_after
at vier eval 'if ( function_exists( "\\VoucherCaptainPro\\addon" ) ) { \VoucherCaptainPro\addon()->license()->deactivate(); }' >/dev/null
at vier plugin deactivate voucher-captain-pro voucher-captain woocommerce >/dev/null
wpsuitems site option delete e2e_suite_net_contract >/dev/null 2>&1

# WF-MS-23: timeout after the side effect of a target step (mail handed over, then the transport fails).
ms ms_timeout_setup
at ziel option update e2e_suite_mail_timeout 1 >/dev/null
post 12; work quelle ziel
ms ms_timeout_check
work ziel
ms ms_timeout_after
at ziel option delete e2e_suite_mail_timeout >/dev/null

# WF-MS-23: cron of the target site 4 does not run.
ms ms_cron_setup
post 13; work quelle
ms ms_cron_check
work drei
ms ms_cron_after
at quelle eval-file "$CHECKS" ms_dup
work drei

# WF-MS-23: site 5 flagged as deleted and restored, then deleted for good and restored from a backup.
ms ms_life_setup
post 14; work quelle
wpsuitems site deactivate 5 2>&1 | grep -E 'Error|Fatal'
ms ms_life_flagged
wpsuitems site activate 5 2>&1 | grep -E 'Error|Fatal'
ms ms_life_restored
work vier
ms ms_life_done
DUMP="$ROOT/logs/suite-ms-site5.sql"
tables=$(mysql -N -e "SHOW TABLES FROM wp_e2e_suite_ms LIKE 'wp\_5\_%'" | tr '\n' ' ')
{ mysqldump wp_e2e_suite_ms $tables; mysqldump --no-create-info --where="blog_id=5" wp_e2e_suite_ms wp_blogs wp_blogmeta; } > "$DUMP"
post 15; work quelle
wpsuitems site delete 5 --yes 2>&1 | grep -E 'Error|Fatal'
ms ms_life_deleted
mysql wp_e2e_suite_ms < "$DUMP"
ms ms_life_backup
post 16; work quelle vier
ms ms_life_backup_done

# WF-MS-23: blog switch and cache isolation.
ms ms_cache

# T103: providers per site. Voucher Captain and its Pro add-on only on site 2 (license per site), WooCommerce on 2 and 3.
for s in quelle ziel; do at $s plugin activate woocommerce >/dev/null; done
at quelle plugin activate voucher-captain voucher-captain-pro >/dev/null
at quelle eval-file "$CHECKS" activate VoucherCaptainPro:voucher
at quelle eval-file "$CHECKS" ms_provider voucher-captain,wordpress,woocommerce
at ziel eval-file "$CHECKS" ms_provider -voucher-captain,woocommerce
at drei eval-file "$CHECKS" ms_provider -voucher-captain,-woocommerce,wordpress
at quelle eval-file "$CHECKS" ms_provider_event
at quelle eval 'vcap()->issuer->issue_standalone( array( "amount" => 8, "source" => "admin", "recipient_email" => "ms2@customer.test", "send" => false ) );' >/dev/null
at quelle plugin deactivate voucher-captain >/dev/null
at quelle eval-file "$CHECKS" ms_provider_paused
at ziel eval-file "$CHECKS" ms_provider woocommerce
at quelle plugin activate voucher-captain >/dev/null

hits=$(grep -E 'PHP (Fatal|Warning|Parse|Deprecated)' "$ROOT/logs/debug-suite-ms.log" | grep -E 'plugins/(workflow-captain|voucher-captain)' | sort | uniq -c | head -10)
php -r 'echo json_encode(array("id"=>"ms-log","name"=>"multisite PHP error log has no fatal/warning/deprecation from the suite plugins","result"=>$argv[1]===""?"PASS":"FAIL","detail"=>$argv[1]===""?"clean":$argv[1]), JSON_UNESCAPED_SLASHES), "\n";' "$hits" | tee -a "$E2E_RESULTS"
