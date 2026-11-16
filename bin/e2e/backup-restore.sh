#!/bin/bash
# Backup/restore and schema migration run (R12, WF-CORE-07) on the real test site (not shipped: /bin is export-ignore).
#
#   bin/e2e/backup-restore.sh
#
# 1. On $E2E_MAIN_DB (www): workflows with runs in different states – waiting in a delay, an event not yet processed
#    (received), a dead-letter event, a mail step whose worker was killed mid-send.
# 2. mysqldump of the whole database, restored into the copy ${E2E_MAIN_DB}_restore; WP-CLI reaches it with E2E_DB (the
#    www/wp-config.php of the test site reads DB_NAME from it).
# 3. On the copy: verify, worker continues, requeue, delay due – every mail exactly once; then downgrade protection,
#    an interrupted upgrade and repeated migrations.
# Results: logs/results-2.jsonl (one JSON line per check). Leaves $E2E_MAIN_DB as it was before (baseline restored).
set -uo pipefail

ROOT=${E2E_ROOT:-/home/user/wp-e2e}
PLUGIN=${E2E_PLUGIN:-$(cd "$(dirname "$0")/../.." && pwd)}
. "$PLUGIN/bin/e2e/env.sh"
OPS="$PLUGIN/bin/e2e/ops.php"
COPY=${E2E_MAIN_DB}_restore
export E2E_RESULTS=${E2E_RESULTS:-$E2E_LOGS/results-2.jsonl} E2E_ROOT="$ROOT"
cd /

rec() { # <id> <name> <ok:0|1> <detail>
	php -r 'echo json_encode(array("id"=>$argv[1],"name"=>$argv[2],"result"=>$argv[3]==="1"?"PASS":"FAIL","detail"=>$argv[4]), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' "$1" "$2" "$3" "$4" | tee -a "$E2E_RESULTS"
}
# Quiet WP-CLI: the offline site prints a wp_update_plugins() warning on some commands.
q() { grep -v "wp_update_plugins\|^$" || true; }

mkdir -p "$E2E_BACKUPS"
[ -f "$E2E_BACKUPS/baseline.sql" ] || mysqldump --single-transaction $E2E_MAIN_DB > "$E2E_BACKUPS/baseline.sql"
rm -f "$E2E_LOGS/ops-state.json"

# ----- 1. live site with runs in different states -------------------------------------------------------------------
export E2E_MAIL_FILE="$E2E_LOGS/mail-br-live.jsonl"; : > "$E2E_MAIL_FILE"
wp eval-file "$OPS" br_prep 2>&1 | q
E2E_FAIL_DISPATCH=1 wp eval-file "$OPS" br_dead 2>&1 | q
out=$(wp eval-file "$OPS" br_crash 2>&1 | q)
rec r2b 'worker killed in the middle of wp_mail() (PHP process exits)' $([[ "$out" == *"worker killed while sending"* ]] && echo 1 || echo 0) "$out"
wp eval-file "$OPS" br_waiting 2>&1 | q

# ----- 2. backup, restore into a copy -------------------------------------------------------------------------------
mysqldump --single-transaction --routines $E2E_MAIN_DB > "$E2E_BACKUPS/br.sql"
rec r4 'mysqldump --single-transaction of the live database' $([ -s "$E2E_BACKUPS/br.sql" ] && echo 1 || echo 0) "$(du -h "$E2E_BACKUPS/br.sql" | cut -f1)"
mysql -e "DROP DATABASE IF EXISTS $COPY; CREATE DATABASE $COPY;" && mysql "$COPY" < "$E2E_BACKUPS/br.sql"
export E2E_DB=$COPY E2E_MAIL_FILE="$E2E_LOGS/mail-br-copy.jsonl"; : > "$E2E_MAIL_FILE"
wp eval-file "$OPS" br_restored 2>&1 | q
out=$(wp workflow-captain verify 2>&1 | q); rec r6 'wp workflow-captain verify on the restored copy' $([[ "$out" == *"No inconsistencies"* ]] && echo 1 || echo 0) "$out"
out=$(wp workflow-captain status 2>&1 | q); rec r6b 'wp workflow-captain status on the copy shows the states of the backup' $([[ "$out" =~ received=[1-9] && "$out" =~ dead_letter=[1-9] ]] && echo 1 || echo 0) "$(echo "$out" | tr '\n' ' ')"

# ----- 3. the copy continues ----------------------------------------------------------------------------------------
wp eval-file "$OPS" br_continue 2>&1 | q
dead=$(php -r '$s=json_decode(file_get_contents($argv[1]),true); echo $s["dead"];' "$E2E_LOGS/ops-state.json")
out=$(wp workflow-captain requeue "$dead" 2>&1 | q); rec r9 'requeue the dead-letter event of the backup on the copy' $([[ "$out" == *Requeued* ]] && echo 1 || echo 0) "$out"
out=$(wp workflow-captain process 2>&1 | q); rec r9b 'wp workflow-captain process on the copy' $([[ "$out" == Success* ]] && echo 1 || echo 0) "$out"
wp eval-file "$OPS" br_due 2>&1 | q
out=$(wp workflow-captain verify 2>&1 | q); rec r11 'verify after the copy finished every run' $([[ "$out" == *"No inconsistencies"* ]] && echo 1 || echo 0) "$out"
# Only the mails of the BR workflows (WooCommerce sends its own order mails into the same log).
live=$(grep -c '"subject":"BR \(sofort\|nach Warten\)' "$E2E_LOGS/mail-br-live.jsonl"); copy=$(grep -c '"subject":"BR \(sofort\|nach Warten\)' "$E2E_LOGS/mail-br-copy.jsonl")
rec r12 'mails: live site before the backup + copy after the restore = one per workflow step and order' $([ "$live" = 2 ] && [ "$copy" = 6 ] && echo 1 || echo 0) "live $live (BR sofort o1,o2), copy $copy (BR sofort o3,o4; BR nach Warten o1-o4)"

# ----- 4. schema migrations on the copy -----------------------------------------------------------------------------
out=$(wp workflow-captain migrate 2>&1 | q); rec m1 'migrate on a current schema' $([[ "$out" == *"Schema 4 in place"* ]] && echo 1 || echo 0) "$out"
wp eval-file "$OPS" mig_newer 2>&1 | q
out=$(wp workflow-captain process 2>&1 | q); rc=$?
rec m2 'downgrade (stored schema newer than the code): process refuses and processes nothing' $([[ "$out" == *"does not match"* ]] && echo 1 || echo 0) "$out"
out=$(wp workflow-captain migrate 2>&1 | q)
rec m3 'downgrade: migrate refuses and changes nothing' $([[ "$out" == *"newer than this plugin"* ]] && echo 1 || echo 0) "$out"
order=$(wp eval '$o = wc_create_order(); $o->set_total(5); $o->save(); $o->payment_complete(); echo "ORDER=" . $o->get_id();' 2>/dev/null | grep -o 'ORDER=[0-9]*' | cut -d= -f2)
wp workflow-captain process >/dev/null 2>&1
wp eval-file "$OPS" mig_newer_check "$order" 2>&1 | q
wp option update workflow_captain_db_version 4 >/dev/null 2>&1
out=$(wp workflow-captain process 2>&1 | q); rec m5 'back on the matching version the worker runs again (the order paid during the downgrade is processed)' $([[ "$out" == Success* && "$out" != "Success: 0 events"* ]] && echo 1 || echo 0) "$out"
wp eval-file "$OPS" mig_interrupt 2>&1 | q
wp eval-file "$OPS" mig_interrupt_check 2>&1 | q
out1=$(wp workflow-captain migrate 2>&1 | q); out2=$(wp workflow-captain migrate 2>&1 | q)
rec m7 'migrate twice in a row' $([[ "$out1" == Success* && "$out2" == Success* ]] && echo 1 || echo 0) "$out1 / $out2"
wp eval-file "$OPS" mig_repeat_check 2>&1 | q

# ----- the live site continues as well; then back to the baseline for the other runs -------------------------------
unset E2E_DB
mysql -e "DROP DATABASE IF EXISTS $COPY;"
mysql $E2E_MAIN_DB < "$E2E_BACKUPS/baseline.sql"
pass=$(grep -c '"id":"[rm][0-9]' "$E2E_RESULTS" | head -1); fail=$(grep '"id":"[rm][0-9]' "$E2E_RESULTS" | grep -c FAIL)
echo "backup/restore: $fail failed ($E2E_RESULTS)"
[ "$fail" -eq 0 ]
