# Staging site built like the GitHub workflow (bin/e2e/staging/setup.sh); source it before any bin/e2e script:
#
#   . bin/e2e/staging/env-staging.sh && bin/e2e/run-checks.sh
#
# WordPress 7.1.4, WooCommerce 11.3.0 and Yoast SEO 28.8 from wordpress.org, Workflow Captain from the release ZIP.
export E2E_ROOT=/home/user/wp-e2e
export E2E_SITE=staging E2E_MS_SITE=staging-ms
export E2E_MAIN_DB=wp_staging E2E_MS_DB=wp_staging_ms
export E2E_URL=http://127.0.0.1:8090 E2E_MS_HOST=127.0.0.1:8091
export E2E_LOGS=$E2E_ROOT/logs-staging E2E_BACKUPS=$E2E_ROOT/backups-staging
export E2E_LOG=$E2E_LOGS/debug.log E2E_MS_LOG=$E2E_LOGS/debug-ms.log E2E_MAIL_FILE=$E2E_LOGS/mail.jsonl
export E2E_PLUGIN_MODE=zip E2E_ZIP=$E2E_ROOT/staging-zip/workflow-captain.zip
# "wp" and "wpms" for the staging sites (WP-CLI from source, see setup.sh) come first on the PATH.
export PATH=$E2E_ROOT/bin-staging:$PATH
