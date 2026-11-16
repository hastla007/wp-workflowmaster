<?php
/**
 * Tests of the admin lists (included by tests/run.php; uses its check() and helpers): pagination with offset and count
 * in both stores (real SQL on SQLite), the correlation filter, the page navigation markup, the step timeline and the
 * redacted envelope of the run page (docs/UI-ENTWUERFE.md, sections 1 and 5; konzept.md 10).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Admin\Pagination;
use WorkflowCaptain\Admin\WorkflowsPage;
use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Privacy\EnvelopeView;
use WorkflowCaptain\Security\Redact;
use WorkflowCaptain\Store\EventStore;

// Minimal WordPress stand-ins for rendering the navigation; real WordPress provides them.
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = 'default' ) { return esc_html( $t ); }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = 'default' ) { return 1 === (int) $n ? $one : $many; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n ) { return (string) $n; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ) { return $url . '?' . http_build_query( $args ); }
}

// ----- Event store: offset, count, correlation filter ----------------------------------------------------------

$s = site();
for ( $i = 1; $i <= 7; $i++ ) {
	$s->consumer->capture( $s->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'page-' . $i ) );
}
$all = $s->store->find( array(), 100, 0 );
check( 'list: event store counts all rows', 7 === $s->store->count( array() ) && 7 === count( $all ) );
$page2 = $s->store->find( array(), 3, 3 );
check( 'list: event page 2 of 3 rows is rows 4 to 6, newest first', array_column( array_slice( $all, 3, 3 ), 'id' ) === array_column( $page2, 'id' ) );
check( 'list: last event page holds the rest', 1 === count( $s->store->find( array(), 3, 6 ) ) );
check( 'list: event count per state filter', 7 === $s->store->count( array( 'state' => EventStore::RECEIVED ) ) && 0 === $s->store->count( array( 'state' => EventStore::DEAD_LETTER ) ) );
$corr = (string) $all[2]['envelope']['correlation_id'];
$hit  = $s->store->find( array( 'correlation_id' => $corr ), 10, 0 );
check( 'list: correlation filter finds the event of that correlation', 1 === count( $hit ) && $all[2]['id'] === $hit[0]['id'] && 1 === $s->store->count( array( 'correlation_id' => $corr ) ) );
check( 'list: correlation filter combines with state', 1 === $s->store->count( array( 'correlation_id' => $corr, 'state' => EventStore::RECEIVED ) ) && 0 === $s->store->count( array( 'correlation_id' => $corr, 'state' => EventStore::DONE ) ) );
check( 'list: invalid correlation ID matches nothing (no wildcard search)', 0 === $s->store->count( array( 'correlation_id' => '%' ) ) && array() === $s->store->find( array( 'correlation_id' => "x' OR 1=1 --" ), 10, 0 ) );

// ----- Engine store: workflows and runs ------------------------------------------------------------------------

$e = engine_site();
for ( $i = 1; $i <= 5; $i++ ) {
	publish( $e, sample_graph(), 'WF ' . $i );
}
$e->store->ensure_anchor( 'network:2', 'Netzwerk', '2026-11-16 10:00:00' );
check( 'list: workflow count leaves out network anchors', 5 === $e->store->count_workflows() );
$wf_page = $e->store->list_workflows( 2, 2 );
check( 'list: workflow page with offset', array( 'WF 3', 'WF 2' ) === array_column( $wf_page, 'name' ) );
for ( $i = 1; $i <= 4; $i++ ) {
	$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'run-' . $i, array( 'checkin_source' => 1 === $i ? 'web' : 'scanner' ) ) );
}
drain( $e );
$runs = $e->store->find_runs( array(), 100, 0 );
check( 'list: run count equals rows (4 events x 5 workflows)', 20 === count( $runs ) && 20 === $e->store->count_runs( array() ) );
check( 'list: run page with offset', array_column( array_slice( $runs, 15, 5 ), 'id' ) === array_column( $e->store->find_runs( array(), 5, 15 ), 'id' ) );
$wf_id = $runs[0]['workflow_id'];
check( 'list: run count per workflow and state', 4 === $e->store->count_runs( array( 'workflow_id' => $wf_id ) ) && 20 === $e->store->count_runs( array( 'state' => EngineStore::RUN_SUCCEEDED ) ) && 0 === $e->store->count_runs( array( 'state' => EngineStore::RUN_FAILED ) ) );
$corr = (string) $runs[0]['context']['correlation_id'];
$same = $e->store->find_runs( array( 'correlation_id' => $corr ), 100, 0 );
check( 'list: runs with the same correlation (one event, five workflows)', 5 === count( $same ) && 5 === $e->store->count_runs( array( 'correlation_id' => $corr ) ) && array( $corr ) === array_values( array_unique( array_map( fn( $r ) => $r['context']['correlation_id'], $same ) ) ) );
check( 'list: run correlation filter combines with workflow', 1 === $e->store->count_runs( array( 'correlation_id' => $corr, 'workflow_id' => $wf_id ) ) );
check( 'list: invalid run correlation matches nothing', 0 === $e->store->count_runs( array( 'correlation_id' => 'abc' ) ) );

// ----- Page navigation (WP_List_Table markup, `paged`, filters kept) -------------------------------------------

check( 'pagination: pages and offset', array( 'total' => 120, 'per_page' => 50, 'pages' => 3, 'paged' => 2, 'offset' => 50 ) === Pagination::state( 120, 50, 2 ) );
check( 'pagination: page beyond the end is clamped to the last', 3 === Pagination::state( 120, 50, 9 )['paged'] && 100 === Pagination::state( 120, 50, 9 )['offset'] );
check( 'pagination: empty list has one page', 1 === Pagination::state( 0, 50, 4 )['pages'] && 0 === Pagination::state( 0, 50, 4 )['offset'] );
$html = Pagination::render( Pagination::state( 120, 50, 2 ), array( 'page' => 'workflow-captain-runs', 'state' => 'failed', 'correlation' => '', 'workflow' => 0 ), 'Seiten der Laufliste' );
check( 'pagination: WordPress classes and total', str_contains( $html, 'class="tablenav bottom"' ) && str_contains( $html, 'class="tablenav-pages"' ) && str_contains( $html, '120 Einträge' ) && str_contains( $html, '2 von 3' ) );
check( 'pagination: links keep the filter and use paged', str_contains( $html, 'page=workflow-captain-runs&amp;state=failed&amp;paged=3' ) && ! str_contains( $html, 'correlation=' ) && ! str_contains( $html, 'workflow=' ) );
check( 'pagination: page 1 link has no paged parameter', 1 === preg_match( '/class="prev-page button" href="[^"]*state=failed"/', $html ) );
check( 'pagination: labelled navigation with screen reader texts', str_contains( $html, '<nav class="tablenav bottom" aria-label="Seiten der Laufliste">' ) && str_contains( $html, '<span class="screen-reader-text">Nächste Seite</span>' ) );
$first = Pagination::render( Pagination::state( 120, 50, 1 ), array( 'page' => 'x' ), 'L' );
check( 'pagination: first page disables first and previous', 2 === substr_count( $first, 'tablenav-pages-navspan button disabled' ) && str_contains( $first, 'class="next-page button"' ) );
check( 'pagination: one page hides the links (one-page)', str_contains( Pagination::render( Pagination::state( 3, 50, 1 ), array( 'page' => 'x' ), 'L' ), 'tablenav-pages one-page' ) );

// ----- Run page: timeline and redacted envelope ----------------------------------------------------------------

$steps    = $e->store->steps_for_run( $same[0]['id'] );
$timeline = WorkflowsPage::timeline( $steps );
check( 'timeline: one row per step in order', array_column( $steps, 'node_id' ) === array_column( $timeline, 'node' ) );
check( 'timeline: finished steps have start, end and attempts', '' !== $timeline[0]['started'] && null !== $timeline[0]['ended'] && $timeline[0]['attempts'] >= 1 );
$wait = WorkflowsPage::timeline(
	array(
		array( 'node_id' => 'd', 'state' => EngineStore::STEP_RETRY_WAIT, 'created_at' => '2026-11-16 10:00:00', 'due_at' => '2026-11-16 10:05:00', 'updated_at' => '2026-11-16 10:00:01', 'attempts' => 2, 'result' => array( 'to' => 'a@b.example', 'n' => 1 ), 'last_error' => 'mail_failed' ),
		array( 'node_id' => 'h', 'state' => EngineStore::STEP_ACTION_REQUIRED, 'created_at' => '2026-11-16 10:00:00', 'due_at' => '2026-11-16 10:00:00', 'updated_at' => '2026-11-16 10:01:00', 'attempts' => 1, 'result' => null, 'last_error' => '' ),
	)
);
check( 'timeline: retry wait shows until when, no end, attempts and error', '2026-11-16 10:05:00' === $wait[0]['wait_until'] && null === $wait[0]['ended'] && 2 === $wait[0]['attempts'] && 'mail_failed' === $wait[0]['error'] );
check( 'timeline: result is redacted (no address)', array( 'n' => 1 ) === $wait[0]['result'] );
check( 'timeline: step waiting for a human counts as stopped, no wait, no error', null === $wait[1]['wait_until'] && '2026-11-16 10:01:00' === $wait[1]['ended'] && null === $wait[1]['error'] );

$context                           = $same[0]['context'];
$context['data']['note']           = 'x';
$context['data']['partner_user_id'] = 9;
$context['data']['checkin_source'] = 'mail an jemand@example.test';
$allowed                           = EnvelopeView::allowed_fields();
$allowed['pass.ticket.checked_in']['partner_user_id'] = 'integer';
$view               = EnvelopeView::redacted( $context, $allowed );
check( 'redacted: actor left out, routing fields kept', ! array_key_exists( 'actor_user_id', $view['envelope'] ) && $corr === $view['envelope']['correlation_id'] && isset( $view['envelope']['idempotency_key'], $view['envelope']['subject']['id'] ) );
check( 'redacted: only catalogue fields, no user IDs, no addresses', array( 'device_id' => 4, 'area_id' => 0 ) === $view['envelope']['data'] && 3 === $view['hidden'] );
Redact::remember( 'geheim-1234' );
$secret = EnvelopeView::redacted( array( 'type' => 'pass.ticket.checked_in', 'subject' => array( 'type' => 'ticket', 'id' => 'geheim-1234' ), 'data' => array( 'checkin_source' => 'geheim-1234' ) ), $allowed );
check( 'redacted: secret values are scrubbed', '[redacted]' === $secret['envelope']['subject']['id'] && '[redacted]' === $secret['envelope']['data']['checkin_source'] );
Redact::reset();
check( 'redacted: unknown event type shows no data', array() === EnvelopeView::redacted( array( 'type' => 'x.y.z', 'data' => array( 'a' => 1 ) ), $allowed )['envelope']['data'] );

// ----- Workflow list: last run and errors per workflow (docs/UI-ENTWUERFE.md, section 1) -----------------------

$ids     = array_column( $e->store->list_workflows( 10, 0 ), 'id' );
$runs_wf = $e->store->find_runs( array( 'workflow_id' => $ids[0] ), 10, 0 );
$e->store->set_run_state( $runs_wf[0]['id'], EngineStore::RUN_FAILED, 'mail_failed', '2026-11-16 12:00:00' );
$e->store->set_run_state( $runs_wf[1]['id'], EngineStore::RUN_ACTION_REQUIRED, 'unclear_outcome', '2026-11-16 12:00:00' );
$sum = $e->store->workflow_run_summary( array_merge( $ids, array( 999 ) ), '2000-01-01 00:00:00' );
check( 'summary: last run per workflow is the newest run', $runs_wf[0]['id'] === $sum[ $ids[0] ]['last_run_id'] && EngineStore::RUN_FAILED === $sum[ $ids[0] ]['last_state'] && $runs_wf[0]['created_at'] === $sum[ $ids[0] ]['last_at'] );
check( 'summary: errors count failed and action_required runs', 2 === $sum[ $ids[0] ]['errors'] && 0 === $sum[ $ids[1] ]['errors'] );
check( 'summary: workflow without runs has no entry', ! isset( $sum[999] ) && 5 === count( $sum ) );
check( 'summary: errors before the window do not count', 0 === $e->store->workflow_run_summary( array( $ids[0] ), '2999-01-01 00:00:00' )[ $ids[0] ]['errors'] );
check( 'summary: empty list asks nothing', array() === $e->store->workflow_run_summary( array(), '2000-01-01 00:00:00' ) );
check( 'summary: error window is 30 days', '2026-10-17 10:00:00' === WorkflowsPage::error_since( strtotime( '2026-11-16 10:00:00 UTC' ) ) );
$cells = WorkflowsPage::summary_cells( $ids[0], $sum[ $ids[0] ] );
check( 'summary cells: last run as time with link and state, errors linked to the runs of the workflow', str_contains( $cells, '<time datetime="' ) && str_contains( $cells, 'run=' . $runs_wf[0]['id'] ) && str_contains( $cells, 'wfc-run-state-bad' ) && str_contains( $cells, 'workflow=' . $ids[0] . '"' ) && str_contains( $cells, '<strong>2</strong>' ) && str_contains( $cells, 'aria-label="2 Läufe mit Fehler' ) );
check( 'summary cells: no run gives a dash for screen readers and 0', str_contains( WorkflowsPage::summary_cells( 7, null ), 'keine Angabe' ) && str_ends_with( WorkflowsPage::summary_cells( 7, null ), '<td>0</td>' ) );

// ----- Origin of events and actions (docs/UI-ENTWUERFE.md, section 2) -----------------------------------------

$src = \WorkflowCaptain\Admin\Provenance::sources( $e->registry, array( 'notify.admin', 'workflow.tag.set', 'voucher.code.issue', 'xstarterconnector.ping', 'webhook.send' ), array( 'starter-connector' ) );
check( 'origin: event type maps to its adapter with plugin and contract version', 'pass-captain' === $src['events']['pass.ticket.checked_in'] && 'Pass Captain' === $src['products']['pass-captain']['label'] && '1.1.0' === $src['products']['pass-captain']['contract'] && $src['products']['pass-captain']['registered'] );
check( 'origin: core actions belong to Workflow Captain, product actions to their product', 'workflow-captain' === $src['actions']['notify.admin'] && 'workflow-captain' === $src['actions']['workflow.tag.set'] && 'voucher-captain' === $src['actions']['voucher.code.issue'] && 'starter-connector' === $src['actions']['xstarterconnector.ping'] );
check( 'origin: unknown action prefix has no origin; unregistered product is marked', ! isset( $src['actions']['webhook.send'] ) && false === $src['products']['voucher-captain']['registered'] );
check( 'origin: product labels', 'WooCommerce' === \WorkflowCaptain\Admin\Provenance::label( 'woocommerce' ) && 'Starter Connector' === \WorkflowCaptain\Admin\Provenance::label( 'starter-connector' ) );
check( 'origin: editor header line names product, version and contract', str_starts_with( (string) \WorkflowCaptain\Admin\Provenance::describe_event( $src, 'pass.ticket.checked_in' ), 'Pass Captain ' ) && str_ends_with( (string) \WorkflowCaptain\Admin\Provenance::describe_event( $src, 'pass.ticket.checked_in' ), ', Vertrag 1.1.0' ) && null === \WorkflowCaptain\Admin\Provenance::describe_event( $src, 'woocommerce.order.paid' ) );

// ----- Run export (docs/UI-ENTWUERFE.md, section 5) -----------------------------------------------------------

$run_x                              = $same[0];
$run_x['context']['actor_user_id']  = 42;
$run_x['context']['data']['partner_user_id'] = 9;
$run_x['actor_user_id']             = 42;
$run_x['serial_key']                = 'actor:42';
$run_x['last_error']                = 'failed for geheim-5678';
$tl                                 = WorkflowsPage::timeline( $e->store->steps_for_run( $run_x['id'] ) );
$tl[0]['result']                    = array( 'to' => 'jemand@example.test', 'n' => 1, 'note' => 'geheim-5678' );
Redact::remember( 'geheim-5678' );
$x    = EnvelopeView::run_export( $run_x, $tl, 'WF 1', 1, $allowed, '0.2.0', '2026-11-16T12:00:00Z' );
Redact::reset();
$json = json_encode( $x );
check( 'export: format, run fields and steps', 'workflow-captain-run-export' === $x['format'] && $run_x['id'] === $x['run']['id'] && 'WF 1' === $x['run']['workflow_name'] && count( $tl ) === count( $x['steps'] ) && $tl[0]['node'] === $x['steps'][0]['node'] );
check( 'export: no actor, no serialisation key, no user IDs', ! str_contains( $json, 'actor' ) && ! str_contains( $json, 'serial' ) && ! str_contains( $json, '_user_id' ) && ! array_key_exists( 'actor_user_id', $x['event'] ) );
check( 'export: step results redacted and secrets scrubbed', array( 'n' => 1, 'note' => '[redacted]' ) === $x['steps'][0]['result'] && ! str_contains( $json, 'example.test' ) && 'failed for [redacted]' === $x['run']['reason'] );
check( 'export: event data only from the catalogue, hidden fields counted', isset( $x['event']['correlation_id'] ) && ! isset( $x['event']['data']['partner_user_id'] ) && $x['hidden_data_fields'] >= 1 );
