#!/bin/bash
# Third E2E run, part 1 (docs/E2E-RESULTS.md, "Dritter Lauf"): update of an existing site from the code of the second
# run to the current checkout (Migrator with upgrade lock). Starts on backups/baseline.sql of www.
#
#   bin/e2e/upgrade.sh [old-revision]     (default ecd352e, the state of the second run)
set -uo pipefail

ROOT=${E2E_ROOT:-/home/user/wp-e2e}
PLUGIN=${E2E_PLUGIN:-$(cd "$(dirname "$0")/../.." && pwd)}
. "$PLUGIN/bin/e2e/env.sh"
OLD=${1:-ecd352e}
T="$PLUGIN/bin/e2e/third.php"
LINK="$E2E_WWW/wp-content/plugins/workflow-captain"
OLDDIR="$ROOT/old-plugin"
export E2E_RESULTS=${E2E_RESULTS:-$E2E_LOGS/results-3.jsonl}
cd /

rec() {
	php -r 'echo json_encode(array("id"=>$argv[1],"name"=>$argv[2],"result"=>$argv[3]==="1"?"PASS":"FAIL","detail"=>$argv[4]), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' "$1" "$2" "$3" "$4" | tee -a "$E2E_RESULTS"
}
q() { grep -v '^Warning: wp_update_plugins\|^Warning: wp_update_themes\|plugins_api()' || true; }

mysql $E2E_MAIN_DB < "$E2E_BACKUPS/baseline.sql"
# The old version: an export of that commit (no checkout of the repository is touched).
rm -rf "$OLDDIR"; mkdir -p "$OLDDIR"
git -C "$PLUGIN" archive "$OLD" | tar -x -C "$OLDDIR"
if [ "$E2E_PLUGIN_MODE" = zip ]; then
	# Release-ZIP site: install the old version from its own ZIP, then update with the current ZIP (wp plugin install --force).
	git -C "$PLUGIN" archive --format=zip --prefix=workflow-captain/ -o "$E2E_LOGS/workflow-captain-old.zip" "$OLD"
	wp plugin install "$E2E_LOGS/workflow-captain-old.zip" --force >/dev/null 2>&1
else
	rm -f "$LINK"; ln -s "$OLDDIR" "$LINK"
fi
wp eval-file "$T" old_data 2>&1 | q
wp eval-file "$T" snapshot 2>&1 | q

# ----- the update: the symlink points to the current code again (like replacing the plugin files), or the ZIP -----
e2e_plugin_current
out=$(wp workflow-captain status 2>&1 | q)
rec u1 'first WP-CLI call after the update: status shows schema 4 (expected 4)' $([[ "$out" == *"schema 4 (expected 4)"* ]] && echo 1 || echo 0) "$(echo "$out" | tr '\n' ' ')"
wp eval-file "$T" upgrade_check 2>&1 | q
out=$(wp workflow-captain verify 2>&1 | q); rec u4 'verify after the update' $([[ "$out" == *"No inconsistencies"* ]] && echo 1 || echo 0) "$out"
out=$(wp workflow-captain migrate 2>&1 | q)
rec u6 'migrate on the current schema: success, no data step, no warning' $([[ "$out" == *"Schema 4 in place"* && "$out" != *"Data steps"* && "$out" != *Warning* ]] && echo 1 || echo 0) "$out"

# ----- interrupted upgrade (schema 3, index and table missing), no lock: the next request completes it -----------
wp eval-file "$T" interrupt 2>&1 | q
curl -s -o /dev/null $E2E_URL/
v0=$(mysql -N $E2E_MAIN_DB -e "SELECT option_value FROM wp_options WHERE option_name='workflow_captain_db_version'")
curl -s -o /dev/null $E2E_URL/wp-admin/
v1=$(mysql -N $E2E_MAIN_DB -e "SELECT option_value FROM wp_options WHERE option_name='workflow_captain_db_version'")
rec u7a 'interrupted upgrade: a front-end request does not migrate, the next wp-admin request does' $([ "$v0" = 3 ] && [ "$v1" = 4 ] && echo 1 || echo 0) "front end: $v0, after wp-admin: $v1"
wp eval-file "$T" interrupt_check u7 2>&1 | q

# ----- the same while another request holds the lock (30 s old): nothing happens, the worker waits ----------------
wp eval-file "$T" interrupt busy 2>&1 | q
out=$(wp workflow-captain migrate 2>&1 | q)
rec u9 'lock held: migrate refuses with "Another request is upgrading", nothing changed' $([[ "$out" == *"Another request is upgrading"* ]] && [ "$(wp option get workflow_captain_db_version 2>/dev/null)" = 3 ] && echo 1 || echo 0) "$out"
wp eval-file "$T" busy_check 2>&1 | q
out=$(wp workflow-captain process 2>&1 | q)
rec u10 'lock held: process refuses (schema does not match), nothing processed' $([[ "$out" == *"does not match"* ]] && echo 1 || echo 0) "$out"
# Settings > Status in the browser shows "läuft gerade" (third.js, when E2E_LOCK_SHOT=1 as in run-third.sh).
if [ -n "${E2E_LOCK_SHOT:-}" ]; then E2E_ONLY=lock node "$PLUGIN/bin/e2e/third.js" >/dev/null 2>&1; fi

# ----- the lock turns stale (upgrade stopped half-way 11 minutes ago): taken over and repeated -------------------
mysql $E2E_MAIN_DB -e "UPDATE wp_options SET option_value = UNIX_TIMESTAMP() - 660 WHERE option_name = 'workflow_captain_upgrade_lock'"
out=$(wp workflow-captain migrate 2>&1 | q)
rec u10b 'stale lock: migrate takes it over, warns that an earlier upgrade was repeated, success' $([[ "$out" == *"stopped half-way"* && "$out" == *"Schema 4 in place"* ]] && echo 1 || echo 0) "$(echo "$out" | tr '\n' ' ')"
wp eval-file "$T" interrupt_check u10c 2>&1 | q
wp eval-file "$T" resumed_check 2>&1 | q
out=$(wp workflow-captain verify 2>&1 | q); rec u13 'verify after the resumed upgrade' $([[ "$out" == *"No inconsistencies"* ]] && echo 1 || echo 0) "$out"

# ----- multisite: network-activated, one site with an interrupted upgrade and a stale lock ------------------------
if wpms core is-installed --network >/dev/null 2>&1; then
	wpms plugin activate workflow-captain --network >/dev/null 2>&1
	site=$(wpms site list --field=url 2>/dev/null | grep zwei | head -1)
	pre=$(wpms db prefix --url="$site" 2>/dev/null)
	mysql $E2E_MS_DB -e "DROP TABLE IF EXISTS ${pre}workflow_captain_notes; UPDATE ${pre}options SET option_value='3' WHERE option_name='workflow_captain_db_version'; INSERT INTO ${pre}options (option_name, option_value, autoload) VALUES ('workflow_captain_upgrade_lock', UNIX_TIMESTAMP() - 660, 'off') ON DUPLICATE KEY UPDATE option_value = UNIX_TIMESTAMP() - 660;"
	out=$(wpms --url="$site" workflow-captain migrate 2>&1 | q)
	v=$(wpms --url="$site" option get workflow_captain_db_version 2>/dev/null); n=$(mysql -N $E2E_MS_DB -e "SHOW TABLES LIKE '${pre}workflow_captain_notes'" | wc -l)
	v1=$(wpms option get workflow_captain_db_version 2>/dev/null)
	rec u14 'multisite: site "zwei" with schema 3 and a stale lock is upgraded by migrate --url (table back, version 4), main site untouched' $([[ "$out" == *"stopped half-way"* ]] && [ "$v" = 4 ] && [ "$n" = 1 ] && [ "$v1" = 4 ] && echo 1 || echo 0) "$(echo "$out" | tr '\n' ' ') version=$v notes=$n main=$v1"
	hits=$(grep -n "workflow-captain\|workflow_captain\|WorkflowCaptain\|wt-e2e" "$E2E_MS_LOG" | head -3)
	rec u15 'multisite PHP error log has no entry from Workflow Captain' $([ -z "$hits" ] && echo 1 || echo 0) "${hits:-clean}"
fi
rm -rf "$OLDDIR"
