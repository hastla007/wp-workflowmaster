# Shared settings of the E2E scripts (not shipped: /bin is export-ignore). Sourced by every shell script in bin/e2e.
# The defaults are the first test site (www :8080, ms :8081, plugin as a symlink to the checkout). A different site
# sets these variables first, for example the staging site built like the GitHub workflow:
#
#   . bin/e2e/staging/env-staging.sh && bin/e2e/run-checks.sh
#
# E2E_PLUGIN_MODE=link: the plugin directory is a symlink to the checkout ($PLUGIN).
# E2E_PLUGIN_MODE=zip:  the plugin is installed from the release ZIP ($E2E_ZIP) with "wp plugin install".
ROOT=${E2E_ROOT:-/home/user/wp-e2e}
PLUGIN=${E2E_PLUGIN:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}
export E2E_ROOT="$ROOT"
export E2E_SITE=${E2E_SITE:-www} E2E_MS_SITE=${E2E_MS_SITE:-ms}
export E2E_WWW="$ROOT/$E2E_SITE" E2E_MSDIR="$ROOT/$E2E_MS_SITE"
export E2E_MAIN_DB=${E2E_MAIN_DB:-wp_e2e} E2E_MS_DB=${E2E_MS_DB:-wp_e2e_ms}
export E2E_URL=${E2E_URL:-http://127.0.0.1:8080} E2E_MS_HOST=${E2E_MS_HOST:-127.0.0.1:8081}
export E2E_MS_URL="http://$E2E_MS_HOST"
export E2E_LOGS=${E2E_LOGS:-$ROOT/logs} E2E_BACKUPS=${E2E_BACKUPS:-$ROOT/backups}
export E2E_LOG=${E2E_LOG:-$E2E_LOGS/debug.log} E2E_MS_LOG=${E2E_MS_LOG:-$E2E_LOGS/debug-ms.log}
export E2E_MAIL_FILE=${E2E_MAIL_FILE:-$E2E_LOGS/mail.jsonl}
export E2E_PLUGIN_MODE=${E2E_PLUGIN_MODE:-link} E2E_ZIP=${E2E_ZIP:-$ROOT/workflow-captain.zip}
export E2E_HPOS=${E2E_HPOS:-yes}
mkdir -p "$E2E_LOGS" "$E2E_BACKUPS"

# Puts the code under test in place on the single site (or with "ms" on the multisite): symlink or release ZIP.
e2e_plugin_current() {
	local base="$E2E_WWW" cli=wp
	[ "${1:-}" = ms ] && base="$E2E_MSDIR" cli=wpms
	local dir="$base/wp-content/plugins/workflow-captain"
	if [ "$E2E_PLUGIN_MODE" = zip ]; then
		[ -L "$dir" ] && rm -f "$dir"
		$cli plugin install "$E2E_ZIP" --force >/dev/null 2>&1
	else
		[ -L "$dir" ] && [ "$(readlink "$dir")" = "$PLUGIN" ] && return 0
		rm -rf "$dir"; ln -s "$PLUGIN" "$dir"
	fi
}
