#!/bin/bash
# All E2E runs on the staging site (WordPress 7.1.4, WooCommerce 11.3.0, Yoast SEO 28.8, release ZIP), for one order
# storage. Not shipped (/bin is export-ignore). Needs bin/e2e/staging/setup.sh first.
#
#   AXE=<path>/axe.min.js bin/e2e/staging/run-staging.sh yes|no [large-orders]
#
# yes = HPOS, no = classic posts storage (the matrix of the GitHub workflow). Order: first run (run-checks.sh, fresh
# database), second run (run-second.sh; its baseline is the state right after the first run), third run
# (run-third.sh), then the browser checkout (checkout.js). Results per storage under logs-staging/hpos-<yes|no>/.
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/../../.." && pwd)
. "$HERE/env-staging.sh"
export E2E_HPOS=${1:-yes}
LARGE=${2:-500}
. "$PLUGIN/bin/e2e/env.sh"
OUT="$E2E_LOGS/hpos-$E2E_HPOS"
SHOTS="$ROOT/shots-staging/hpos-$E2E_HPOS"
mkdir -p "$OUT" "$SHOTS"
cd /
free() { df -BG --output=avail /home/user | tail -1 | tr -dc 0-9; }
guard() { [ "$(free)" -ge 3 ] || { echo "Less than 3 GB free: stopping."; exit 1; }; }

guard
"$PLUGIN/bin/e2e/run-checks.sh" "$SHOTS" > "$OUT/run-checks.out" 2>&1
cp "$E2E_LOGS/results.jsonl" "$OUT/results-1.jsonl"
rm -f "$E2E_BACKUPS/baseline.sql" # the second run takes its baseline from this first run
guard
"$PLUGIN/bin/e2e/run-second.sh" "$SHOTS" "$LARGE" > "$OUT/run-second.out" 2>&1
cp "$E2E_LOGS/results-2.jsonl" "$OUT/results-2.jsonl"
guard
"$PLUGIN/bin/e2e/run-third.sh" "$SHOTS" > "$OUT/run-third.out" 2>&1
cp "$E2E_LOGS/results-3.jsonl" "$OUT/results-3.jsonl"
guard
: > "$OUT/results-checkout.jsonl"
E2E_RESULTS="$OUT/results-checkout.jsonl" E2E_SHOTS="$SHOTS" NODE_PATH=${NODE_PATH:-$(npm root -g)} node "$HERE/checkout.js" > "$OUT/checkout.out" 2>&1 || true
touch "$OUT/results-checkout.jsonl"
for f in results-1 results-2 results-3 results-checkout; do
	printf '%-18s %4s passed, %3s failed\n' "$f" "$(grep -c '"result":"PASS"' "$OUT/$f.jsonl")" "$(grep -c '"result":"FAIL"' "$OUT/$f.jsonl")"
done | tee "$OUT/summary.txt"
