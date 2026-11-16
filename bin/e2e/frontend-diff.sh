#!/bin/sh
# Front-end neutrality (WF-SEO-03/-07): the public HTML must be identical with the plugin active and deactivated,
# apart from nonces and timestamps, and must not mention Workflow Captain. Usage: bin/e2e/frontend-diff.sh <base-url> <out-dir>
# E2E_URLS overrides the list; {POST}, {PAGE}, {PRODUCT} are replaced with IDs of published items.
set -eu
BASE=${1:-http://127.0.0.1:8080}
OUT=${2:-.}
mkdir -p "$OUT"
PRODUCT=$(wp post list --post_type=product --post_status=publish --field=ID --posts_per_page=1)
POST=$(wp post list --post_type=post --post_status=publish --field=ID --posts_per_page=1)
PAGE=$(wp post list --post_type=page --post_status=publish --field=ID --posts_per_page=1)
URLS=${E2E_URLS:-"/ /?p=$POST /?page_id=$PAGE /?post_type=product&p=$PRODUCT /?post_type=product /?feed=rss2 /wp-json/ /wp-sitemap.xml"}
URLS=$(echo "$URLS" | sed "s/{POST}/$POST/g; s/{PAGE}/$PAGE/g; s/{PRODUCT}/$PRODUCT/g")
norm() {
	# Nonces, cache busters and generated times vary per request.
	sed -E 's/(nonce|_wpnonce|wpApiSettings|wcStoreApiNonce)([^,;]{0,40})[0-9a-f]{10}/\1\2NONCE/g; s/[0-9a-f]{10}/HEX10/g; s/<lastBuildDate>[^<]*/<lastBuildDate>/; s/"timestamp":[0-9]+/"timestamp":0/g'
}
fetch() {
	i=0
	for u in $URLS; do
		i=$((i+1))
		curl -sL "$BASE$u" | norm > "$OUT/$1-$i.html"
	done
}
wp plugin is-active workflow-captain
# Action Scheduler deletes its migration flag whenever any plugin is deactivated (deactivate_plugin hook), and without
# the flag it registers its post statuses, which show up in the REST index. Start both fetches without the flag, so the
# deactivation below does not change the front end by itself (E2E third run).
wp option delete action_scheduler_migration_status >/dev/null 2>&1 || true
fetch active
wp plugin deactivate workflow-captain >/dev/null
fetch inactive
wp plugin activate workflow-captain >/dev/null
i=0; rc=0
for u in $URLS; do
	i=$((i+1))
	if grep -qi "workflow.captain" "$OUT/active-$i.html"; then
		echo "LEAK $u: public response mentions Workflow Captain"; grep -oi ".\{40\}workflow.captain.\{40\}" "$OUT/active-$i.html" | head -3; rc=1
	fi
	if cmp -s "$OUT/active-$i.html" "$OUT/inactive-$i.html"; then
		echo "SAME $u ($(wc -c < "$OUT/active-$i.html") bytes)"
	else
		echo "DIFF $u"; diff "$OUT/active-$i.html" "$OUT/inactive-$i.html" | head -20; rc=1
	fi
done
exit $rc
