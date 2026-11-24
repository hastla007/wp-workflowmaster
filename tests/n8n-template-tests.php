<?php
/**
 * The n8n templates in docs/n8n (WF-N8N-10, WF-N8N-12). Found with a real n8n 1.123 (bin/e2e/n8n.sh, 24.11.2026):
 * without a webhookId n8n registers an imported webhook under "<workflow id>/<node name>/<path>" instead of the
 * documented production URL; two templates shared one path, so only one of them could be active; and the step
 * "Doppelte verwerfen" named an operation n8n does not know, so it let every duplicate through.
 *
 * @package WorkflowCaptain
 */

$n8n_templates = glob( dirname( __DIR__ ) . '/docs/n8n/*.json' );
$n8n_paths     = array();
$n8n_ok        = array( 'json' => true, 'webhook' => true, 'dedupe' => true );
foreach ( $n8n_templates as $n8n_file ) {
	$n8n_wf = json_decode( (string) file_get_contents( $n8n_file ), true );
	if ( ! is_array( $n8n_wf ) || empty( $n8n_wf['nodes'] ) ) {
		$n8n_ok['json'] = false;
		continue;
	}
	$n8n_hooks = array_values( array_filter( $n8n_wf['nodes'], static fn( $n ) => 'n8n-nodes-base.webhook' === ( $n['type'] ?? '' ) ) );
	$n8n_dedup = array_values( array_filter( $n8n_wf['nodes'], static fn( $n ) => 'n8n-nodes-base.removeDuplicates' === ( $n['type'] ?? '' ) ) );
	if ( 1 !== count( $n8n_hooks ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) ( $n8n_hooks[0]['webhookId'] ?? '' ) )
		|| 'POST' !== ( $n8n_hooks[0]['parameters']['httpMethod'] ?? '' ) || 'headerAuth' !== ( $n8n_hooks[0]['parameters']['authentication'] ?? '' ) ) {
		$n8n_ok['webhook'] = false;
	}
	$n8n_paths[] = (string) ( $n8n_hooks[0]['parameters']['path'] ?? '' );
	$n8n_p       = (array) ( $n8n_dedup[0]['parameters'] ?? array() );
	if ( 1 !== count( $n8n_dedup ) || 'removeItemsSeenInPreviousExecutions' !== ( $n8n_p['operation'] ?? '' ) || 'removeItemsWithAlreadySeenKeyValues' !== ( $n8n_p['logic'] ?? '' ) || '={{ $json.body.id }}' !== ( $n8n_p['dedupeValue'] ?? '' ) ) {
		$n8n_ok['dedupe'] = false;
	}
}
check( 'N8N templates: four files, valid JSON with nodes', 4 === count( $n8n_templates ) && $n8n_ok['json'] );
check( 'N8N templates: one POST webhook with header auth and a fixed webhookId each (production URL /webhook/<path>)', $n8n_ok['webhook'] );
check( 'N8N templates: webhook paths are unique, so all templates can be active at once', count( $n8n_paths ) === count( array_unique( $n8n_paths ) ) && ! in_array( '', $n8n_paths, true ) );
check( 'N8N templates: "Doppelte verwerfen" uses the n8n operation removeItemsSeenInPreviousExecutions on the event id', $n8n_ok['dedupe'] );
