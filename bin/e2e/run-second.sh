#!/bin/bash
# Second E2E run (docs/E2E-RESULTS.md, "Zweiter Lauf"; not shipped: /bin is export-ignore). Needs the site of the first
# run (bin/e2e/setup.sh, then bin/e2e/run-checks.sh once) with Yoast (E2E_YOAST=1) and the Classic Editor (E2E_CLASSIC=1).
#
#   AXE=<path>/axe.min.js bin/e2e/run-second.sh [screenshot-dir] [large-orders]
#
# Order: backup/restore and migrations (copy database), builder paths, Yoast, accessibility – each on the baseline
# database of www (backups/baseline.sql) – and last the load measurement on its own copy (${E2E_MAIN_DB}_load).
# Results: logs/results-2.jsonl; load figures: logs/load.jsonl.
set -uo pipefail

ROOT=${E2E_ROOT:-/home/user/wp-e2e}
PLUGIN=${E2E_PLUGIN:-$(cd "$(dirname "$0")/../.." && pwd)}
. "$PLUGIN/bin/e2e/env.sh"
SHOTS=${1:-$ROOT/shots-2}
LARGE=${2:-5000}
export E2E_ROOT="$ROOT" E2E_RESULTS="$E2E_LOGS/results-2.jsonl" E2E_SHOTS="$SHOTS" NODE_PATH=${NODE_PATH:-$(npm root -g)}
cd /
: > "$E2E_RESULTS"
mkdir -p "$E2E_BACKUPS"
[ -f "$E2E_BACKUPS/baseline.sql" ] || mysqldump --single-transaction $E2E_MAIN_DB > "$E2E_BACKUPS/baseline.sql"
baseline() { mysql $E2E_MAIN_DB < "$E2E_BACKUPS/baseline.sql"; : > "$E2E_LOGS/mail.jsonl"; }

baseline; "$PLUGIN/bin/e2e/backup-restore.sh" >/dev/null 2>&1
baseline; node "$PLUGIN/bin/e2e/builder-flows.js" >/dev/null
baseline; node "$PLUGIN/bin/e2e/seo.js" >/dev/null
baseline; node "$PLUGIN/bin/e2e/a11y2.js" >/dev/null
# E2E_SKIP_LOAD=1 keeps logs/load.jsonl and the load checks of an earlier run (the large shop takes about 25 minutes).
if [ "${E2E_SKIP_LOAD:-0}" = 1 ]; then
	[ -f "$E2E_LOGS/results-load.jsonl" ] && cat "$E2E_LOGS/results-load.jsonl" >> "$E2E_RESULTS"
else
	baseline; E2E_RESULTS="$E2E_LOGS/results-load.jsonl" "$PLUGIN/bin/e2e/load.sh" "$LARGE" >/dev/null 2>&1
	cat "$E2E_LOGS/results-load.jsonl" >> "$E2E_RESULTS"
fi

pass=$(grep -c '"result":"PASS"' "$E2E_RESULTS"); fail=$(grep -c '"result":"FAIL"' "$E2E_RESULTS")
echo "E2E second run: $pass passed, $fail failed ($E2E_RESULTS)"
[ "$fail" -eq 0 ]
