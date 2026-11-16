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

// ----- Run list: time range (docs/UI-ENTWUERFE.md, section 1) ---------------------------------------------------

$e = engine_site();
publish( $e, sample_graph(), 'Zeitraum' );
for ( $i = 1; $i <= 3; $i++ ) {
	$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'range-' . $i, array( 'checkin_source' => 'scanner' ) ) );
}
drain( $e );
$ids = array_column( $e->store->find_runs( array(), 10, 0 ), 'id' );
sort( $ids );
foreach ( array( '2026-10-01 08:00:00', '2026-11-10 23:59:59', '2026-11-16 09:00:00' ) as $k => $at ) {
	$e->pdo->exec( "UPDATE wp_workflow_captain_runs SET created_at = '{$at}' WHERE id = " . (int) $ids[ $k ] );
}
check( 'range: since counts runs from that time on', 2 === $e->store->count_runs( array( 'since' => '2026-11-10 00:00:00' ) ) && 1 === $e->store->count_runs( array( 'since' => '2026-11-11 00:00:00' ) ) );
check( 'range: until is inclusive, since and until combine', 2 === $e->store->count_runs( array( 'until' => '2026-11-10 23:59:59' ) ) && 1 === $e->store->count_runs( array( 'since' => '2026-11-01 00:00:00', 'until' => '2026-11-10 23:59:59' ) ) );
check( 'range: find_runs uses the same bounds', array( $ids[1] ) === array_column( $e->store->find_runs( array( 'since' => '2026-11-01 00:00:00', 'until' => '2026-11-10 23:59:59' ), 10, 0 ), 'id' ) );
check( 'range: a malformed bound is ignored, not injected', 3 === $e->store->count_runs( array( 'since' => "2026-11-11' OR '1'='1" ) ) && 3 === $e->store->count_runs( array( 'until' => '16.11.2026' ) ) );
$now = strtotime( '2026-11-16 10:00:00 UTC' );
check( 'range: preset of 7 days', array( 'filter' => array( 'since' => '2026-11-09 10:00:00' ), 'args' => array( 'period' => '7' ) ) === WorkflowsPage::period_filter( '7', '', '', $now ) );
check( 'range: preset of 24 hours', '2026-11-15 10:00:00' === WorkflowsPage::period_filter( '1', '', '', $now )['filter']['since'] );
check( 'range: unknown preset means no range', array( 'filter' => array(), 'args' => array() ) === WorkflowsPage::period_filter( '5', '', '', $now ) && array() === WorkflowsPage::period_filter( '07', '', '', $now )['filter'] );
check( 'range: own dates win over the preset and cover whole days in UTC', array( 'filter' => array( 'since' => '2026-11-01 00:00:00', 'until' => '2026-11-10 23:59:59' ), 'args' => array( 'from' => '2026-11-01', 'to' => '2026-11-10' ) ) === WorkflowsPage::period_filter( '30', '2026-11-01', '2026-11-10', $now ) );
check( 'range: reversed dates are swapped, a single date is open-ended', '2026-11-01 00:00:00' === WorkflowsPage::period_filter( '', '2026-11-10', '2026-11-01', $now )['filter']['since'] && array( 'since' => '2026-11-10 00:00:00' ) === WorkflowsPage::period_filter( '', '2026-11-10', '', $now )['filter'] );
check( 'range: invalid dates are ignored', array() === WorkflowsPage::period_filter( '', '2026-02-30', '10.11.2026', $now )['filter'] );

// ----- Overview: runs in 30 days and provider status (docs/UI-ENTWUERFE.md, section 1) -------------------------

$diag  = array( 'environment' => array( 'next_worker_run' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 30 ), 'secret_key_own' => true ), 'workflows' => array( 'active' => 3 ), 'runs' => array(), 'events' => array(), 'stuck' => array() );
$cards = \WorkflowCaptain\Admin\OverviewPage::cards( $diag, 1284 );
check( 'overview: card "Läufe · 30 Tage" second, linked to the run list for 30 days', 'Läufe · 30 Tage' === $cards[1]['label'] && 1284 === $cards[1]['value'] && WorkflowsPage::SLUG_RUNS === $cards[1]['page'] && array( 'period' => '30' ) === $cards[1]['args'] && '2026-10-17 10:00:00' === WorkflowsPage::period_filter( $cards[1]['args']['period'], '', '', $now )['filter']['since'] );
check( 'overview: without the count the card is left out', 6 === count( \WorkflowCaptain\Admin\OverviewPage::cards( $diag ) ) && 7 === count( $cards ) );
$prov_rows = array(
	array( 'product' => 'pass-captain', 'status' => 'registered', 'types' => array( 'pass.ticket.checked_in', 'pass.order.paid' ) ),
	array( 'product' => 'voucher-captain', 'status' => 'missing', 'types' => array() ),
	array( 'product' => 'review-captain', 'status' => 'refused', 'types' => array() ),
);
$prov_src  = array(
	'products' => array( 'pass-captain' => array( 'label' => 'Pass Captain' ) ),
	'actions'  => array( 'voucher.code.issue' => 'voucher-captain', 'voucher.code.void' => 'voucher-captain', 'notify.admin' => 'workflow-captain' ),
);
$status    = \WorkflowCaptain\Admin\OverviewPage::provider_status( $prov_rows, $prov_src );
check( 'provider status: label, state text, tone and counts per product', array( 'Pass Captain', 'angemeldet', 'ok', 2, 0 ) === array( $status[0]['label'], $status[0]['state'], $status[0]['tone'], $status[0]['events'], $status[0]['actions'] ) && array( 'kein Adapter', 'warn', 2 ) === array( $status[1]['state'], $status[1]['tone'], $status[1]['actions'] ) && 'bad' === $status[2]['tone'] );
$ptable = \WorkflowCaptain\Admin\OverviewPage::provider_table( $status );
check( 'provider status table: caption, row headers, state as text (colour only repeats it)', str_contains( $ptable, '<caption class="screen-reader-text">Providerstatus</caption>' ) && str_contains( $ptable, '<th scope="row">Pass Captain</th>' ) && str_contains( $ptable, '</span>abgelehnt</td>' ) && str_contains( $ptable, '2 Ereignistypen · 0 Aktionen' ) && str_contains( $ptable, 'aria-hidden="true"' ) );

// ----- Overview of all schedules (docs/UI-ENTWUERFE.md, section 4) ---------------------------------------------

$e = engine_site( 'Europe/Berlin' );
$e->registry->add_core( new \WorkflowCaptain\Providers\ScheduleAdapter() );
$sched_graph = static function ( array $schedule ): array {
	return array(
		'nodes' => array(
			array( 'id' => 't', 'type' => 'trigger', 'event' => 'workflow.schedule.due', 'schedule' => $schedule ),
			array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Bericht', 'message' => 'x' ) ),
			array( 'id' => 'e', 'type' => 'end' ),
		),
		'edges' => array( array( 'from' => 't', 'to' => 'mail' ), array( 'from' => 'mail', 'to' => 'e' ) ),
	);
};
$weekly  = array( 'frequency' => 'weekly', 'time' => '07:30', 'weekday' => 1 );
$w_week  = publish( $e, $sched_graph( $weekly ), 'Wochenbericht' );
$w_day   = publish( $e, $sched_graph( array( 'frequency' => 'daily', 'time' => '02:30' ) ), 'Aufräumen nachts' );
$w_pause = publish( $e, $sched_graph( array( 'frequency' => 'monthly', 'time' => '08:00', 'day' => 1 ) ), 'Monatsabschluss' );
$w_arch  = publish( $e, $sched_graph( $weekly ), 'Archiviert' );
publish( $e, sample_graph(), 'Kein Zeitplan' );
list( $w_draft ) = $e->workflows->save( null, 'Nur Entwurf', $sched_graph( $weekly ) );
$e->store->set_workflow_status( $w_pause, EngineStore::WF_PAUSED, '2026-11-16 00:00:00' );
$e->store->set_workflow_status( $w_arch, EngineStore::WF_ARCHIVED, '2026-11-16 00:00:00' );
$e->workflows->save( $w_day, 'Aufräumen nachts', $sched_graph( array( 'frequency' => 'daily', 'time' => '03:00' ) ) );
$e->clock->time = strtotime( '2026-10-24 12:00:00 UTC' );
$sched = array_column( $e->engine->schedules( array( $w_week => strtotime( '2026-10-19 05:30:00 UTC' ) ) ), null, 'workflow_id' );
check( 'schedules: active and paused workflows with a published schedule, by name; archived, drafts and other triggers left out', array( $w_day, $w_pause, $w_week ) === array_keys( $sched ) && ! isset( $sched[ $w_draft ] ) );
check( 'schedules: next due time from Schedule::upcoming() in the site time zone (repeated hour: first occurrence)', strtotime( '2026-10-25 00:30:00 UTC' ) === $sched[ $w_day ]['next'] && \WorkflowCaptain\Engine\Schedule::upcoming( $weekly, $e->clock->time, 'Europe/Berlin', 1 ) === array( $sched[ $w_week ]['next'] ) && strtotime( '2026-10-26 06:30:00 UTC' ) === $sched[ $w_week ]['next'] );
check( 'schedules: paused workflow has no next due time', EngineStore::WF_PAUSED === $sched[ $w_pause ]['status'] && null === $sched[ $w_pause ]['next'] );
check( 'schedules: last fired time from the option, none when never fired', strtotime( '2026-10-19 05:30:00 UTC' ) === $sched[ $w_week ]['last_fired'] && null === $sched[ $w_day ]['last_fired'] );
check( 'schedules: a draft with another schedule is flagged, the published schedule counts', $sched[ $w_day ]['draft_differs'] && '02:30' === $sched[ $w_day ]['schedule']['time'] && ! $sched[ $w_week ]['draft_differs'] );
check( 'schedules: readable rule', 'wöchentlich, Montag, 07:30' === \WorkflowCaptain\Admin\SchedulesPage::describe( $weekly ) && 'monatlich, am 1., 08:00' === \WorkflowCaptain\Admin\SchedulesPage::describe( $sched[ $w_pause ]['schedule'] ) && 'täglich, 02:30' === \WorkflowCaptain\Admin\SchedulesPage::describe( $sched[ $w_day ]['schedule'] ) );
$fmt   = static fn( string $f, int $ts ): string => ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( 'Europe/Berlin' ) )->format( $f );
$lines = \WorkflowCaptain\Admin\SchedulesPage::table_rows( array_values( $sched ), $fmt, true );
check( 'schedules table: row header links to the editor, times as time elements in the site time zone', 3 === count( $lines ) && str_contains( $lines[2], '<th scope="row"><a href="https://example.test/wp-admin/admin.php?page=workflow-captain-workflows&amp;edit=' . $w_week . '">Wochenbericht</a></th>' ) && str_contains( $lines[2], '<time datetime="2026-10-26T06:30:00Z">Mon, 26.10.2026 07:30</time>' ) && str_contains( $lines[2], 'workflow=' . $w_week . '">Läufe</a>' ) );
check( 'schedules table: paused says so, never fired is a dash for screen readers, differing draft is noted', str_contains( $lines[1], 'keiner (pausiert)' ) && str_contains( $lines[0], 'keine Angabe' ) && str_contains( $lines[0], 'Der Entwurf weicht ab' ) );
check( 'schedules table: the link to the runs inside the cell text is underlined (WCAG 1.4.1, axe link-in-text-block in the E2E third run)', str_contains( $lines[2], '(<a class="wfc-text-link" href=' ) && str_contains( (string) file_get_contents( __DIR__ . '/../src/Plugin.php' ), 'a.wfc-text-link{text-decoration:underline}' ) );
check( 'schedules table: without editor right no link', ! str_contains( \WorkflowCaptain\Admin\SchedulesPage::table_rows( array_values( $sched ), $fmt, false )[2], 'edit=' ) );

// ----- Builder: codes of the last check per node (docs/UI-ENTWUERFE.md, section 3) -----------------------------

check( 'node codes: grouped by node, graph codes and malformed entries left out', array( 'mail' => array( 'invalid_to', 'needs_exactly_one_outgoing_edge' ), 'c' => array( 'condition:too_deep' ) ) === WorkflowsPage::node_violations( array( 'do' => 'publish', 'errors' => array( 'node:mail:invalid_to', 'graph:cycle', 'node:mail:needs_exactly_one_outgoing_edge', 'node:c:condition:too_deep', 'node:bad id:x', 7 ) ) ) );
check( 'node codes: no notice gives an empty object (JSON {})', '{}' === json_encode( WorkflowsPage::node_violations( false ) ) && '{}' === json_encode( WorkflowsPage::node_violations( array( 'errors' => array() ) ) ) );
check( 'last fired: milliseconds and link to the runs of the workflow, none when never fired', array( 'at' => 1763274600000, 'runs' => 'https://example.test/wp-admin/admin.php?page=workflow-captain-runs&workflow=3' ) === WorkflowsPage::last_fired( array( 3 => 1763274600 ), 3 ) && null === WorkflowsPage::last_fired( array( 3 => 1763274600 ), 4 ) && null === WorkflowsPage::last_fired( array(), 0 ) );
