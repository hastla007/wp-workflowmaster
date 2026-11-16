#!/bin/bash
# Multisite phase of run-suite.sh (not shipped): network-wide workflows of Workflow Captain Pro with a real license
# from the shop of the suite site (WF-MS-22, WF-MS-23) and the providers per site (T103). Database wp_e2e_suite_ms
# is reset by phase "install" of run-suite.sh.
set -uo pipefail
ROOT=${E2E_ROOT:-/home/user/wp-e2e}
HERE=$(cd "$(dirname "$0")" && pwd)
CHECKS="$HERE/checks.php"
export E2E_RESULTS=${E2E_RESULTS:-$ROOT/logs/suite-results.jsonl}
cd /
q() { grep -v -e '_load_textdomain_just_in_time' -e 'wp_update_plugins()' -e '^$' || true; }
U=127.0.0.1:8084
ms() { wpsuitems eval-file "$CHECKS" "$@" 2>&1 | q; }
at() { local site=$1; shift; wpsuitems --url="$U/$site/" "$@" 2>&1 | q; }
post() { at quelle post create --post_title="Netz-Beitrag $1" --post_status=publish --porcelain >/dev/null; }
work() { for s in "$@"; do at "$s" workflow-captain process >/dev/null; done; }
shop() { wpsuite eval-file "$CHECKS" "$@" 2>&1 | q; }

wpsuitems core multisite-install --url=http://$U --title="Suite MS" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email --skip-config 2>&1 | q
for s in quelle ziel drei vier; do wpsuitems site create --slug=$s --title=$s --email=admin@example.test --porcelain >/dev/null 2>&1; done
wpsuitems plugin activate workflow-captain workflow-captain-pro --network 2>&1 | grep -E 'Error|Fatal'

# Network license from the shop (the agency plan grants workflow.multisite_network).
ms ms_license wcp_full
ms ms_license_sites
ms ms_setup
post 1; work quelle
ms ms_source_check
post 2; work quelle ziel drei vier
ms ms_run_check
post 3; work quelle ziel drei vier
ms ms_consent_check
work vier
ms ms_consent_resume
ms ms_limit

# License expiry while child steps wait in the target queues (WF-MS-22, R-17.9).
post 4; work quelle
ms ms_expire_prep
shop shop_expire_ms
ms ms_refresh expired
work ziel drei vier
ms ms_expired_check
shop shop_renew_ms
ms ms_refresh active
work ziel drei vier
ms ms_renewed_check
work ziel drei vier
ms ms_resumed_check

# License change to a plan without workflow.multisite_network (WF-MS-22 "Lizenzwechsel").
ms ms_runs_before
wpsuitems eval '\WorkflowCaptainPro\addon()->license()->deactivate();' >/dev/null 2>&1
ms ms_license wcp_basic
post 5; work quelle ziel
ms ms_switch_check
wpsuitems eval '\WorkflowCaptainPro\addon()->license()->deactivate();' >/dev/null 2>&1
ms ms_license wcp_full

ms ms_pause_net

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
