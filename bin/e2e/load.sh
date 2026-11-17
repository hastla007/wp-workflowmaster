#!/bin/bash
# Load measurement of the worker (WF-CORE-05) on a copy of the test site (not shipped: /bin is export-ignore).
#
#   bin/e2e/load.sh [large-orders]      # default 5000
#
# Works on the database ${E2E_MAIN_DB}_load (a copy of backups/baseline.sql, reached with E2E_DB), so the live test site is
# untouched. Workflow as in docs/LAST.md: order paid -> mail to the operator -> tag. Orders through WC CRUD with
# payment_complete(); mails captured by the test mu-plugin (no real mail server). Measurements: logs/load.jsonl,
# checks: logs/results-load.jsonl (run-second.sh adds them to logs/results-2.jsonl).
set -uo pipefail

ROOT=${E2E_ROOT:-/home/user/wp-e2e}
PLUGIN=${E2E_PLUGIN:-$(cd "$(dirname "$0")/../.." && pwd)}
. "$PLUGIN/bin/e2e/env.sh"
OPS="$PLUGIN/bin/e2e/ops.php"
LARGE=${1:-5000}
OUT="$E2E_LOGS/load.jsonl"
export E2E_DB=${E2E_MAIN_DB}_load E2E_ROOT="$ROOT" E2E_MAIL_FILE="$E2E_LOGS/mail-load.jsonl"
export E2E_RESULTS=${E2E_RESULTS:-$E2E_LOGS/results-load.jsonl}
cd /
: > "$OUT"; : > "$E2E_RESULTS"; : > "$E2E_MAIL_FILE"; rm -f "$E2E_LOGS/ops-state.json"

m() { # <label> <json from ops.php>
	php -r '$j=json_decode($argv[2],true); if(!is_array($j)){$j=array("raw"=>$argv[2]);} echo json_encode(array("label"=>$argv[1])+$j), "\n";' "$1" "$2" | tee -a "$OUT"
}
ops() { wp eval-file "$OPS" "$@" 2>/dev/null | grep '^{' | tail -1; }
drain() { # <label> <limit> <workers>: passes until nothing is open; every pass is measured
	local label=$1 limit=$2 workers=$3 pass=0 t0
	t0=$(date +%s.%N)
	while :; do
		b=$(ops load_backlog)
		[[ "$b" == *'"events_open":0,"steps_open":0'* ]] && break
		pass=$((pass + 1))
		if [ "$workers" = 2 ]; then
			a=$(mktemp); c=$(mktemp)
			ops load_pass "$limit" > "$a" & ops load_pass "$limit" > "$c" & wait
			m "$label pass $pass worker A" "$(cat "$a")" >/dev/null; m "$label pass $pass worker B" "$(cat "$c")" >/dev/null
			rm -f "$a" "$c"
		else
			m "$label pass $pass" "$(ops load_pass "$limit")" >/dev/null
		fi
		[ $pass -ge 400 ] && break
	done
	m "$label drained" "{\"passes\":$pass,\"seconds\":$(echo "$(date +%s.%N) - $t0" | bc)}"
}

mysql -e "DROP DATABASE IF EXISTS ${E2E_MAIN_DB}_load; CREATE DATABASE ${E2E_MAIN_DB}_load;"
mysql ${E2E_MAIN_DB}_load < "$E2E_BACKUPS/baseline.sql"
m setup "$(ops load_setup)"

# ----- capture cost: 50 orders without and with Workflow Captain ---------------------------------------------------
wp plugin deactivate workflow-captain >/dev/null 2>&1
m "50 orders, plugin inactive" "$(ops load_orders 50)"
wp plugin activate workflow-captain >/dev/null 2>&1
mysql ${E2E_MAIN_DB}_load -e "TRUNCATE wp_workflow_captain_events"
m "50 orders, plugin active (small shop)" "$(ops load_orders 50)"
m "small backlog" "$(ops load_backlog)"
drain "small, batch 25, one worker" 25 1
wp eval-file "$OPS" load_check small 2>/dev/null | grep -E '^(PASS|FAIL)'

# ----- large shop ---------------------------------------------------------------------------------------------------
left=$LARGE
while [ "$left" -gt 0 ]; do
	n=$(( left > 500 ? 500 : left ))
	m "large: $n orders" "$(ops load_orders "$n")"
	left=$((left - n))
done
m "large backlog" "$(ops load_backlog)"
for limit in 25 100 200; do
	for i in 1 2; do m "large: single pass limit $limit" "$(ops load_pass "$limit")"; done
done
s=$(date +%s.%N); out=$(wp workflow-captain process --limit=100 2>&1 | grep Success); e=$(date +%s.%N)
m "large: wp workflow-captain process --limit=100 (whole CLI process)" "{\"seconds\":$(echo "$e - $s" | bc),\"out\":\"$out\"}"
drain "large, limit 200, two workers at once" 200 2
wp eval-file "$OPS" load_check large 2>/dev/null | grep -E '^(PASS|FAIL)'
out=$(wp workflow-captain verify 2>&1 | grep -v wp_update_plugins)
php -r 'echo json_encode(array("id"=>"l-verify","name"=>"load: verify after both runs","result"=>str_contains($argv[1],"No inconsistencies")?"PASS":"FAIL","detail"=>$argv[1])), "\n";' "$out" | tee -a "$E2E_RESULTS"
