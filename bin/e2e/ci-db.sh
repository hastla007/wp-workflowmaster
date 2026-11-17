#!/bin/bash
# E2E checks of Workflow Captain against a real database server (not shipped: /bin is export-ignore). Used by the
# GitHub workflow .github/workflows/e2e-db.yml (MySQL 9.7 / MariaDB 12.3 service container, PHP 8.5, PHP 8.6 experimental;
# WordPress 7.1.4 and the minimum 7.0.8 / WooCommerce 11.2.2). It no longer runs locally as is: the plugin refuses PHP
# below 8.5 (MINVER-1) and the test container has PHP 8.3, so earlier local runs are historical. No browser.
#
#   bin/e2e/ci-db.sh install    # WordPress, WooCommerce from wordpress.org, Workflow Captain from the release ZIP
#   bin/e2e/ci-db.sh checks     # php -S on $E2E_URL, then a, c, d, f, g, migrations, counts, j; exit 1 on a failure
#
# Settings (environment, see bin/e2e/env.sh): E2E_WWW (empty directory for WordPress), E2E_URL (http://127.0.0.1:<port>),
# E2E_MAIN_DB, E2E_DB_HOST, E2E_DB_USER, E2E_DB_PASS (the database must exist and be empty), E2E_LOGS (results and
# logs), E2E_WP_CLI (WP-CLI without --path, default "php -d memory_limit=1024M wp-cli.phar" in the current directory),
# E2E_HPOS (yes|no), CI_WP_VERSION, CI_WC_VERSION, CI_DB_EXPECT (version prefix the server must report, e.g. "9.7"),
# CI_HTTP_PROXY (only behind an egress proxy: WordPress downloads go through it).
# Results: $E2E_LOGS/results-ci.jsonl, one JSON line per check.
set -uo pipefail

PLUGIN=$(cd "$(dirname "$0")/../.." && pwd)
export E2E_PLUGIN="$PLUGIN" E2E_PLUGIN_MODE=zip
export E2E_WP_CLI=${E2E_WP_CLI:-php -d memory_limit=1024M $PWD/wp-cli.phar}
: "${E2E_WWW:?E2E_WWW (WordPress directory) is required}" "${E2E_URL:?E2E_URL is required}" "${E2E_LOGS:?E2E_LOGS is required}"
export E2E_ROOT=${E2E_ROOT:-$(dirname "$E2E_WWW")} E2E_BACKUPS=${E2E_BACKUPS:-$E2E_LOGS/backups}
. "$PLUGIN/bin/e2e/env.sh"
export E2E_RESULTS="$E2E_LOGS/results-ci.jsonl" E2E_ZIP="$E2E_LOGS/workflow-captain.zip"
WP_VERSION=${CI_WP_VERSION:-7.1.4}
WC_VERSION=${CI_WC_VERSION:-11.3.0}
CHECKS="$PLUGIN/bin/e2e/checks.php"
OPS="$PLUGIN/bin/e2e/ops.php"
cd /

rec() { # <id> <name> <ok:0|1> <detail>
	php -r 'echo json_encode(array("id"=>$argv[1],"name"=>$argv[2],"result"=>$argv[3]==="1"?"PASS":"FAIL","detail"=>$argv[4]), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' "$1" "$2" "$3" "$4" | tee -a "$E2E_RESULTS"
}
ev() { wp eval-file "$CHECKS" "$@"; }
ops() { wp eval-file "$OPS" "$@"; }
q() { grep -v '^$' || true; }
# Stored schema version read without loading plugins (a normal WP-CLI call would finish an interrupted upgrade).
schema() { wp --skip-plugins --skip-themes option get workflow_captain_db_version 2>/dev/null; }

install() {
	: > "$E2E_RESULTS"; : > "$E2E_MAIL_FILE"; : > "$E2E_LOG"
	mkdir -p "$E2E_WWW"
	wp core download --version="$WP_VERSION" --force >/dev/null || return 1
	wp config create --dbname="$E2E_MAIN_DB" --dbuser="$E2E_DB_USER" --dbpass="$E2E_DB_PASS" --dbhost="$E2E_DB_HOST" --skip-check --force >/dev/null || return 1
	wp config set WP_DEBUG true --raw >/dev/null
	wp config set WP_DEBUG_LOG "$E2E_LOG" >/dev/null
	wp config set WP_DEBUG_DISPLAY false --raw >/dev/null
	wp config set DISABLE_WP_CRON true --raw >/dev/null
	wp config set WP_ENVIRONMENT_TYPE staging >/dev/null
	wp config set FS_METHOD direct >/dev/null
	wp config set WP_HOME "$E2E_URL" >/dev/null
	wp config set WP_SITEURL "$E2E_URL" >/dev/null
	wp config set E2E_MAIL_FILE "$E2E_MAIL_FILE" >/dev/null
	mkdir -p "$E2E_WWW/wp-content/mu-plugins"
	cp "$PLUGIN/bin/e2e/mu-plugins/e2e-test-site.php" "$E2E_WWW/wp-content/mu-plugins/"
	if [ -n "${CI_HTTP_PROXY:-}" ]; then
		wp config set WP_PROXY_HOST "$(echo "$CI_HTTP_PROXY" | sed -E 's#^[a-z]+://##; s#:[0-9]+/?$##')" >/dev/null
		wp config set WP_PROXY_PORT "${CI_HTTP_PROXY##*:}" --raw >/dev/null
		wp config set WP_PROXY_BYPASS_HOSTS 'localhost,127.0.0.1' >/dev/null
		cp "$PLUGIN/bin/e2e/staging/mu-plugins/e2e-staging-proxy.php" "$E2E_WWW/wp-content/mu-plugins/"
	fi
	wp core install --url="$E2E_URL" --title="Workflow Captain CI" --admin_user=admin --admin_password=admin-e2e-pass --admin_email=admin@example.test --skip-email >/dev/null || return 1
	wp option update timezone_string Europe/Berlin >/dev/null
	wp plugin install woocommerce --version="$WC_VERSION" --activate >/dev/null || return 1
	wp option update woocommerce_currency EUR >/dev/null
	wp option update woocommerce_default_country 'DE:BE' >/dev/null
	wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null
	wp option update woocommerce_coming_soon no >/dev/null
	wp option update woocommerce_allow_tracking no >/dev/null
	wp eval '$s = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class ); if ( ! $s->check_orders_table_exists() ) { $s->create_database_tables(); } update_option( "woocommerce_custom_orders_table_enabled", getenv( "E2E_HPOS" ) ?: "yes" ); update_option( "woocommerce_feature_custom_order_tables_enabled", "yes" );'

	git -C "$PLUGIN" archive --format=zip --prefix=workflow-captain/ -o "$E2E_ZIP" HEAD
	out=$(wp plugin install "$E2E_ZIP" --activate 2>&1 | grep -E "^(Success|Error|Warning):" | tail -1)
	rec ci1 'release ZIP (git archive) installed and activated' $([[ "$out" == *"Success:"* ]] && wp plugin is-active workflow-captain && [ ! -L "$E2E_WWW/wp-content/plugins/workflow-captain" ] && echo 1 || echo 0) "$out"
	leaked=$(unzip -Z1 "$E2E_ZIP" | grep -E '^workflow-captain/(tests|bin|docs|examples|konzept.md|TODO.md|README.md|composer.json|\.git|\.wordpress-org)' || true)
	rec ci2 'release ZIP contains only shipped files (export-ignore)' $([ -z "$leaked" ] && echo 1 || echo 0) "${leaked:-$(unzip -Z1 "$E2E_ZIP" | cut -d/ -f2 | sort -u | tr '\n' ' ')}"
	vers=$(wp eval 'global $wp_version, $wpdb; echo $wp_version, " / ", WC_VERSION, " / PHP ", PHP_VERSION, " / DB ", $wpdb->db_server_info();')
	ok=0; [[ "$vers" == "$WP_VERSION / $WC_VERSION / PHP "* ]] && ok=1
	if [ -n "${CI_DB_EXPECT:-}" ]; then [[ "$vers" == *"DB $CI_DB_EXPECT"* || "$vers" == *"DB 5.5.5-$CI_DB_EXPECT"* ]] || ok=0; fi
	rec ci3 "versions: WordPress / WooCommerce / PHP / database server${CI_DB_EXPECT:+ (expected $CI_DB_EXPECT)}" $ok "$vers"
	db=$(wp eval 'echo wp_json_encode( \WorkflowCaptain\Requirements::database( $GLOBALS["wpdb"]->db_server_info() ) );' 2>&1)
	rec ci3-db 'MINVER-1 database server meets MySQL 9.7 / MariaDB 12.3 (Site Health "good")' $([[ "$db" == *'"status":"good","code":"ok"'* ]] && echo 1 || echo 0) "$db"
	[ "$ok" = 1 ]
}

checks() {
	# Web server for the requests that need one (php -S with the router of bin/e2e, several workers).
	port=${E2E_URL##*:}
	PHP_CLI_SERVER_WORKERS=4 php -d memory_limit=512M -S "127.0.0.1:$port" -t "$E2E_WWW" "$PLUGIN/bin/e2e/router.php" > "$E2E_LOGS/php-server.log" 2>&1 &
	server=$!
	trap 'kill $server 2>/dev/null' EXIT
	for _ in $(seq 1 40); do curl -s -o /dev/null "$E2E_URL/" && break; sleep 0.25; done
	home=$(curl -s -o /dev/null -w '%{http_code}' "$E2E_URL/"); login=$(curl -s -o /dev/null -w '%{http_code}' "$E2E_URL/wp-login.php")
	rec ci4 'php -S serves the site (front page and login 200)' $([ "$home" = 200 ] && [ "$login" = 200 ] && echo 1 || echo 0) "front page $home, wp-login.php $login"

	# ----- a, b: activation, tables, schema, providers --------------------------------------------------------------
	ev activation
	ev providers

	# ----- c: order flow with HPOS --------------------------------------------------------------------------------
	E2E_LABEL=hpos ev order on

	# ----- d: more WooCommerce and WordPress events ---------------------------------------------------------------
	ev wc_more

	# ----- a10-12: the real Action Scheduler path ------------------------------------------------------------------
	ev as_prep
	wp eval 'global $wpdb; $wpdb->query( "UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = UTC_TIMESTAMP() - INTERVAL 1 SECOND, scheduled_date_local = NOW() - INTERVAL 1 SECOND WHERE hook = \"workflow_captain_process_events\" AND status = \"pending\"" );'
	wp action-scheduler run --hooks=workflow_captain_process_events >/dev/null 2>&1
	ev as_check

	# ----- f: schedule trigger, scheduled worker, system-cron CLI path ---------------------------------------------
	ev schedule
	E2E_LABEL=cli ev schedule cli
	out=$(wp workflow-captain process 2>&1); rec f3a 'wp workflow-captain process succeeds' $([[ "$out" == *"Success:"* ]] && echo 1 || echo 0) "$out"
	ev schedule_check

	# ----- g: emergency pause, dead letter, requeue, CLI -----------------------------------------------------------
	ev pause && ev pause_check && ev pause_resume
	E2E_FAIL_DISPATCH=1 ev deadletter_make
	id=$(cat "$E2E_LOGS/dlq-event.txt")
	out=$(wp workflow-captain requeue "$id" 2>&1); rec g5 'wp workflow-captain requeue <dead-letter id>' $([[ "$out" == *Requeued* ]] && echo 1 || echo 0) "$out"
	wp workflow-captain process >/dev/null
	ev deadletter_check
	out=$(wp workflow-captain requeue "$id" 2>&1); rc=$?; rec g7 'requeue of a done event is refused' $([ $rc -ne 0 ] && echo 1 || echo 0) "$out"
	out=$(wp workflow-captain status 2>&1); rec g8 'wp workflow-captain status' $([[ "$out" == *"Adapters: workflow-captain, wordpress, woocommerce"* && "$out" == *"dead_letter=0"* ]] && echo 1 || echo 0) "$(echo "$out" | tr '\n' ' ')"
	out=$(wp workflow-captain verify 2>&1); rec g9 'wp workflow-captain verify' $([[ "$out" == *"No inconsistencies"* ]] && echo 1 || echo 0) "$out"
	out=$(wp workflow-captain diagnostics 2>/dev/null); ok=$(echo "$out" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo (int)(is_array($d) && "action_scheduler"===$d["environment"]["scheduler"] && "current"===$d["environment"]["schema_state"] && in_array("woocommerce",$d["adapters"]["registered"],true));')
	rec g10 'wp workflow-captain diagnostics (valid JSON, scheduler, schema, adapters)' "$ok" "$(echo "$out" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo json_encode(array("env"=>$d["environment"]??null,"events"=>$d["events"]??null,"runs"=>$d["runs"]??null));')"
	out=$(wp workflow-captain pause on 2>&1); wp workflow-captain pause off >/dev/null 2>&1
	rec g11 'wp workflow-captain pause on|off' $([[ "$out" == *"Success:"* ]] && echo 1 || echo 0) "$out"

	# ----- m: schema migrations (upgrade, migrate, verify) on this database server ---------------------------------
	cur=$(wp eval 'echo WORKFLOW_CAPTAIN_DB_VERSION;')
	out=$(wp workflow-captain migrate 2>&1 | q); rec m1 'migrate on a current schema' $([[ "$out" == *"Schema $cur in place"* ]] && echo 1 || echo 0) "$out"
	ops mig_newer 2>&1 | q
	out=$(wp workflow-captain process 2>&1 | q)
	rec m2 'downgrade (stored schema newer than the code): process refuses and processes nothing' $([[ "$out" == *"does not match"* ]] && echo 1 || echo 0) "$out"
	out=$(wp workflow-captain migrate 2>&1 | q)
	rec m3 'downgrade: migrate refuses and changes nothing' $([[ "$out" == *"newer than this plugin"* ]] && echo 1 || echo 0) "$out"
	order=$(wp eval '$o = wc_create_order(); $o->set_total(5); $o->save(); $o->payment_complete(); echo "ORDER=" . $o->get_id();' 2>/dev/null | grep -o 'ORDER=[0-9]*' | cut -d= -f2)
	wp workflow-captain process >/dev/null 2>&1
	ops mig_newer_check "$order" 2>&1 | q
	wp --skip-plugins option update workflow_captain_db_version "$cur" >/dev/null 2>&1
	out=$(wp workflow-captain process 2>&1 | q); rec m5 'back on the matching version the worker runs again (the order paid during the downgrade is processed)' $([[ "$out" == *"Success:"* && "$out" != *"Success: 0 events"* ]] && echo 1 || echo 0) "$out"
	ops mig_interrupt 2>&1 | q
	ops mig_interrupt_check 2>&1 | q
	# The same interrupted upgrade finished by a web request: the front end does not migrate, wp-admin does.
	ops mig_interrupt 2>&1 | q
	curl -s -o /dev/null "$E2E_URL/"; v0=$(schema)
	curl -s -o /dev/null "$E2E_URL/wp-admin/"; v1=$(schema)
	rec m6c 'interrupted upgrade: a front-end request does not migrate, the next wp-admin request does' $([ "$v0" = $((cur - 1)) ] && [ "$v1" = "$cur" ] && echo 1 || echo 0) "front end: $v0, after wp-admin: $v1"
	E2E_LABEL=http ops mig_interrupt_check 2>&1 | q
	out1=$(wp workflow-captain migrate 2>&1 | q); out2=$(wp workflow-captain migrate 2>&1 | q)
	rec m7 'migrate twice in a row' $([[ "$out1" == *"Success:"* && "$out2" == *"Success:"* ]] && echo 1 || echo 0) "$out1 / $out2"
	ops mig_repeat_check 2>&1 | q
	out=$(wp workflow-captain verify 2>&1); rec m9 'verify after the migrations' $([[ "$out" == *"No inconsistencies"* ]] && echo 1 || echo 0) "$out"

	# ----- n: paging and counts -------------------------------------------------------------------------------------
	ev counts

	# ----- PHP error log: nothing from Workflow Captain -------------------------------------------------------------
	hits=$(grep -n "workflow-captain\|workflow_captain\|WorkflowCaptain" "$E2E_LOG" 2>/dev/null | head -5)
	rec h20 'PHP error log has no entry from Workflow Captain (WP_DEBUG, WP_DEBUG_LOG)' $([ -z "$hits" ] && echo 1 || echo 0) "${hits:-$(wc -l < "$E2E_LOG" 2>/dev/null || echo 0) lines in debug.log, none from the plugin}"

	# ----- j: deactivate keeps data, delete removes everything ------------------------------------------------------
	wp plugin deactivate workflow-captain >/dev/null
	ev deactivated
	wp plugin activate workflow-captain >/dev/null
	n=$(wp eval 'echo count( as_get_scheduled_actions( array( "hook" => "workflow_captain_process_events", "status" => ActionScheduler_Store::STATUS_PENDING ), "ids" ) );')
	c=$(wp cron event list --fields=hook --format=csv | grep -c workflow_captain_process_events)
	rec j5 'reactivation schedules the worker once (AS), no WP-Cron duplicate' $([ "$n" = 1 ] && [ "$c" = 0 ] && echo 1 || echo 0) "AS pending=$n wp-cron=$c"
	wp plugin deactivate workflow-captain >/dev/null
	out=$(wp eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; require_once ABSPATH . "wp-admin/includes/file.php"; $r = delete_plugins( array( "workflow-captain/workflow-captain.php" ) ); echo is_wp_error( $r ) ? $r->get_error_message() : var_export( $r, true );' 2>"$E2E_LOGS/delete.err")
	rec j5b 'delete_plugins() runs uninstall.php and deletes the files' $([ "$out" = true ] && [ ! -e "$E2E_WWW/wp-content/plugins/workflow-captain" ] && echo 1 || echo 0) "$(echo "$out" | head -3 | tr '\n' ' ') $(grep -v "^$" "$E2E_LOGS/delete.err" | head -2 | tr '\n' ' ')"
	ev uninstalled

	pass=$(grep -c '"result":"PASS"' "$E2E_RESULTS"); fail=$(grep -c '"result":"FAIL"' "$E2E_RESULTS")
	echo "E2E (database server): $pass passed, $fail failed ($E2E_RESULTS)"
	[ "$fail" -eq 0 ] && [ "$pass" -gt 0 ]
}

case "${1:-}" in
	install) install ;;
	checks) checks ;;
	*) echo "usage: $0 install|checks"; exit 2 ;;
esac
