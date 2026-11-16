#!/bin/bash
# Suite run: Workflow Captain + Pro + Pass, Voucher, Affiliate Captain + WP License Captain on real WordPress sites
# (not shipped: /bin is export-ignore). Resets the databases wp_e2e_suite and wp_e2e_suite_ms, installs, runs every
# phase and writes logs/suite-results.jsonl (one JSON line per check). docs/E2E-SUITE.md explains the results.
#
#   bin/e2e/suite/setup.sh && bin/e2e/suite/run-suite.sh          # all phases
#   bin/e2e/suite/run-suite.sh ms release                          # only some phases (they need the earlier state)
#
# Exit code 1 when a check failed.
set -uo pipefail

ROOT=${E2E_ROOT:-/home/user/wp-e2e}
HERE=$(cd "$(dirname "$0")" && pwd)
CHECKS="$HERE/checks.php"
export E2E_RESULTS="$ROOT/logs/suite-results.jsonl"
CORE=${E2E_CORE:-$(cd "$HERE/../../.." && pwd)}
KEY_A=suite-e2e-master-key-A-0123456789abcdef0123456789abcdef
KEY_B=suite-e2e-master-key-B-fedcba9876543210fedcba9876543210
cd /

rec() { # <id> <name> <ok:0|1> <detail>
	php -r 'echo json_encode(array("id"=>$argv[1],"name"=>$argv[2],"result"=>$argv[3]==="1"?"PASS":"FAIL","detail"=>$argv[4]), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' "$1" "$2" "$3" "$4" | tee -a "$E2E_RESULTS"
}
q() { grep -v -e '_load_textdomain_just_in_time' -e 'wp_update_plugins()' -e '^$' || true; }
ev() { wpsuite eval-file "$CHECKS" "$@" 2>&1 | q; }
evms() { wpsuitems eval-file "$CHECKS" "$@" 2>&1 | q; }
act() { wpsuite plugin activate "$@" 2>&1 | grep -E 'Error|Fatal' || true; }
deact() { wpsuite plugin deactivate "$@" 2>&1 | grep -E 'Error|Fatal' || true; }

phase_install() {
	: > "$E2E_RESULTS"; : > "$ROOT/logs/debug-suite.log"; : > "$ROOT/logs/debug-suite-ms.log"; : > "$ROOT/logs/suite-hooks.jsonl"
	rm -f "$ROOT/logs/suite-state.json" "$ROOT/logs/suite-keys.php" "$ROOT/logs/mail-suite.jsonl" "$ROOT/logs/mail-suite-ms.jsonl"
	mysql -e "DROP DATABASE IF EXISTS wp_e2e_suite; CREATE DATABASE wp_e2e_suite; DROP DATABASE IF EXISTS wp_e2e_suite_ms; CREATE DATABASE wp_e2e_suite_ms;"
	wpsuite core install --url=http://127.0.0.1:8083 --title="Suite E2E" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email 2>&1 | q
	for o in "timezone_string Europe/Berlin" "woocommerce_currency EUR" "woocommerce_default_country DE:BE" "woocommerce_coming_soon no" "woocommerce_allow_tracking no"; do
		wpsuite option update $o >/dev/null 2>&1
	done
	wpsuite option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null 2>&1
	wpsuite rewrite structure '/%postname%/' >/dev/null 2>&1
	act woocommerce
	wpsuite eval '$s = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class ); if ( ! $s->check_orders_table_exists() ) { $s->create_database_tables(); } update_option( "woocommerce_custom_orders_table_enabled", "yes" ); update_option( "woocommerce_feature_custom_order_tables_enabled", "yes" );' 2>&1 | q
	# The starter connector of the core as an own plugin (custom connector runtime, WF-CONNECTOR-12/-13).
	act wp-license workflow-captain pass-captain voucher-captain affiliate-captain starter-connector
	# Pin the response signing key of this shop for the four Pro add-ons (read by wp-config.php).
	key=$(wpsuite wplc signing-key --field=public_key 2>/dev/null | tail -1)
	{
		echo "<?php"
		for c in WORKFLOW PASS VOUCHER AFFILIATE; do echo "define( '${c}_CAPTAIN_PRO_LICENSE_PUBLIC_KEYS', '$key' );"; done
		# Webhook secrets of the test connection from wp-config (documented override), so the receiver can verify them.
		echo "define( 'WORKFLOW_CAPTAIN_PRO_HOOK_SUITE_N8N_HMAC', 'suite-e2e-hmac-secret-0123456789abcdef0123456789' );"
		echo "define( 'WORKFLOW_CAPTAIN_PRO_HOOK_SUITE_STEP_HMAC', 'suite-e2e-hmac-secret-0123456789abcdef0123456789' );"
		echo "define( 'WORKFLOW_CAPTAIN_PRO_HOOK_SUITE_N8N_INBOUND', 'suite-e2e-inbound-secret-0123456789abcdef01234567' );"
		# Own master key of the vault of Workflow Captain (WF-SEC-02); phase "rotation" changes it.
		echo "define( 'WORKFLOW_CAPTAIN_SECRET_KEY', '$KEY_A' );"
	} > "$ROOT/logs/suite-keys.php"
	rec i1 'shop signing key pinned for the Pro add-ons' $([ -n "$key" ] && echo 1 || echo 0) "$key"
	ev shop
}

phase_matrix() {
	# T96: Free -> Pro without license -> basic license -> product add-ons -> licensed -> full license.
	ev free
	act workflow-captain-pro
	ev pro_nolicense
	ev activate WorkflowCaptainPro:wcp_basic
	ev basic
	act pass-captain-pro voucher-captain-pro affiliate-captain-pro
	ev addons_unlicensed
	ev activate PassCaptainPro:pass
	ev activate VoucherCaptainPro:voucher
	ev activate AffiliateCaptainPro:affiliate
	ev adapters_on
	ev connector basic-license
	ev activate WorkflowCaptainPro:wcp_full
	ev connector accepted
}

phase_events() {
	ev events
	ev events_check
	ev commands
}

phase_refund() {
	ev refund
	ev refund_check
}

phase_providers() {
	# T88: plugin missing, license missing (product add-on), contract major.
	ev missing_prep
	deact pass-captain
	ev missing_check
	act pass-captain
	ev missing_resume
	deact voucher-captain-pro
	ev addon_missing_check
	act voucher-captain-pro
	wpsuite option update e2e_suite_contract_major 1 >/dev/null 2>&1
	ev major_check
	wpsuite option delete e2e_suite_contract_major >/dev/null 2>&1
	ev major_back
}

phase_webhooks() {
	ev hooks_setup
	ev hooks_send
	ev hooks_inbound
}

phase_expiry() {
	ev expire
	ev expired_check
	ev renew
	ev renewed_check
}

phase_connectors() {
	# WF-CONNECTOR-13: the starter connector of the core as an own connector, with the Pro license.
	ev conn_setup
	ev conn_run
	ev conn_timeout
	ev conn_rights
	ev conn_app_prep
	deact starter-connector
	ev conn_app_off
	act starter-connector
	ev conn_app_on
	# The connector is updated to a new MAJOR of its contract: a copy of the example with contract 2.0.0.
	local v2="$ROOT/suite-src/starter-connector-v2" link="$ROOT/suite/wp-content/plugins/starter-connector"
	rsync -a --delete "$CORE/examples/starter-connector/" "$v2/"
	sed -i "s/'1\.0\.0'/'2.0.0'/g" "$v2/src/Manifest.php" "$v2/src/TicketAdapter.php"
	rm -f "$link" && ln -s "$v2" "$link"
	ev conn_major
	rm -f "$link" && ln -s "$CORE/examples/starter-connector" "$link"
	ev conn_major_back
}

phase_resilience() {
	# T88, WF-INT-17, WF-CONNECTOR-13: timeout after the target accepted the call; restore from a backup.
	ev slow_send
	ev slow_retry
	ev restore_prep
	local dump="$ROOT/logs/suite-restore.sql"
	mysqldump wp_e2e_suite $(mysql -N -e "SHOW TABLES FROM wp_e2e_suite LIKE 'wp\_workflow\_captain%'" | tr '\n' ' ') > "$dump"
	ev restore_run 1
	mysql wp_e2e_suite < "$dump"
	ev restore_run 2
}

phase_rotation() {
	# WF-CONNECTOR-13, WF-SEC-02: rotation of a connection secret and of the master key of the vault.
	ev rotate_conn
	sed -i "s/'WORKFLOW_CAPTAIN_SECRET_KEY', '$KEY_A'/'WORKFLOW_CAPTAIN_SECRET_KEY', '$KEY_B' ); define( 'WORKFLOW_CAPTAIN_SECRET_KEY_PREVIOUS', '$KEY_A'/" "$ROOT/logs/suite-keys.php"
	ev rotate_key before
	dry=$(wpsuite workflow-captain secrets --dry-run 2>&1 | q | grep 'suite-rot' | head -1)
	rec sr3b 'dry run counts the connection secret as "to re-encrypt" (old key)' $(echo "$dry" | grep -q '1 to re-encrypt' && echo 1 || echo 0) "$dry"
	out=$(wpsuite workflow-captain secrets 2>&1 | q | grep 'suite-rot' | head -1)
	rec sr3c 'wp workflow-captain secrets re-encrypts it with the new key (counts only, no values)' $(echo "$out" | grep -q '1 re-encrypted' && echo 1 || echo 0) "$out"
	sed -i "s/ define( 'WORKFLOW_CAPTAIN_SECRET_KEY_PREVIOUS', '$KEY_A' );//" "$ROOT/logs/suite-keys.php"
	ev rotate_key rekeyed
}

phase_ms() {
	bash "$HERE/ms.sh"
}

phase_release() {
	bash "$HERE/release.sh"
}

phase_log() {
	hits=$(grep -E 'PHP (Fatal|Warning|Parse|Deprecated)' "$ROOT/logs/debug-suite.log" | grep -E 'plugins/(workflow-captain|pass-captain|voucher-captain|affiliate-captain|wp-license|starter-connector)' | grep -v 'wp_update_plugins' | sort | uniq -c | head -20)
	rec z1 'suite PHP error log has no fatal/warning/deprecation from the suite plugins' $([ -z "$hits" ] && echo 1 || echo 0) "${hits:-clean}"
}

phases=${*:-install matrix events refund providers webhooks expiry connectors resilience rotation ms release log}
for p in $phases; do
	echo "== phase $p"
	"phase_$p"
done
fails=$(grep -c '"result":"FAIL"' "$E2E_RESULTS" || true)
total=$(wc -l < "$E2E_RESULTS")
echo "Suite: $((total - fails)) of $total checks passed."
[ "$fails" = 0 ]
