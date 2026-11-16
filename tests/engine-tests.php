<?php
/**
 * Tests of the workflow engine (included by tests/run.php; uses its check() and helpers).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\Consumer\Consumer;
use WorkflowCaptain\Engine\Actions\Note;
use WorkflowCaptain\Engine\Actions\NotifyAdmin;
use WorkflowCaptain\Engine\Actions\NotifyUser;
use WorkflowCaptain\Engine\Actions\Tag;
use WorkflowCaptain\Engine\ActionRegistry;
use WorkflowCaptain\Engine\Conditions;
use WorkflowCaptain\Engine\Engine;
use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Engine\Graph;
use WorkflowCaptain\Engine\Template;
use WorkflowCaptain\Engine\Workflows;
use WorkflowCaptain\Engine\WpdbEngineStore;
use WorkflowCaptain\Store\EventStore;
use WorkflowCaptain\Store\WpdbEventStore;

/** A site with consumer and engine wired like Plugin does. */
function engine_site( string $tz = 'UTC', ?PDO $pdo = null, string $prefix = 'wp_', int $blog_id = 1 ): object {
	$pdo = $pdo ?? FakeWpdb::memory();
	$db  = new FakeWpdb( $pdo, $prefix );
	$db->create_tables();
	$db->create_engine_tables();
	$e            = new stdClass();
	$e->pdo       = $pdo;
	$e->clock     = new FixedClock();
	$e->dir       = new FakeDirectory();
	$e->store     = new WpdbEngineStore( $db );
	$e->events    = new WpdbEventStore( $db );
	$e->actions   = new ActionRegistry();
	$e->actions->add( new NotifyAdmin( $e->dir ) );
	$e->actions->add( new NotifyUser( $e->dir ) );
	$e->actions->add( new Tag( $e->store, $e->clock ) );
	$e->actions->add( new Tag( $e->store, $e->clock, true ) );
	$e->actions->add( new Note( $e->store, $e->clock ) );
	$e->cap       = new CapAction();
	$e->actions->add( $e->cap );
	$e->engine    = new Engine( $e->store, $e->actions, $e->dir, $e->clock, $tz );
	$e->adapter   = new TestAdapter();
	$e->registry  = new AdapterRegistry();
	$e->registry->add( $e->adapter );
	$e->consumer  = new Consumer( $e->events, $e->registry, $e->engine, $e->clock, $blog_id );
	$e->engine->set_consumer( $e->consumer );
	$e->workflows = new Workflows( $e->store, $e->actions, $e->dir, $e->clock );
	return $e;
}

/** Trigger → condition (source scanner) → then: notify admin, tag → end; else: end. */
function sample_graph( string $event = 'pass.ticket.checked_in' ): array {
	return array(
		'nodes' => array(
			array( 'id' => 't', 'type' => 'trigger', 'event' => $event ),
			array( 'id' => 'c', 'type' => 'condition', 'rules' => array( 'op' => 'and', 'items' => array( array( 'field' => 'data.checkin_source', 'operator' => 'eq', 'value' => 'scanner' ) ) ), 'missing' => 'false' ),
			array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Einlass {{subject.id}}', 'message' => 'Quelle {{data.checkin_source}} am {{occurred_at}}' ) ),
			array( 'id' => 'tag', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'eingecheckt' ) ),
			array( 'id' => 'e1', 'type' => 'end' ),
			array( 'id' => 'e2', 'type' => 'end' ),
		),
		'edges' => array(
			array( 'from' => 't', 'to' => 'c' ),
			array( 'from' => 'c', 'to' => 'mail', 'branch' => 'then' ),
			array( 'from' => 'c', 'to' => 'e2', 'branch' => 'else' ),
			array( 'from' => 'mail', 'to' => 'tag' ),
			array( 'from' => 'tag', 'to' => 'e1' ),
		),
	);
}

function publish( object $e, array $graph, string $name = 'Test' ): int {
	list( $id, $v ) = $e->workflows->save( null, $name, $graph );
	$errors         = $e->workflows->publish( $id, 1 );
	if ( array() !== $errors ) {
		echo '  publish errors: ' . implode( ', ', $errors ) . "\n";
	}
	return $id;
}

/** Processes events and steps until nothing is due (at most 50 rounds). */
function drain( object $e ): void {
	for ( $i = 0; $i < 50; $i++ ) {
		if ( 0 === $e->consumer->process( 100 ) + $e->engine->process( 100 ) ) {
			return;
		}
	}
}

function run_of( object $e ): array {
	return $e->store->find_runs( array(), 10, 0 )[0] ?? array();
}

function steps_of( object $e ): array {
	$run = run_of( $e );
	return array() === $run ? array() : $e->store->steps_for_run( $run['id'] );
}

// ----- Graph validation (WF-BUILDER-06) -----------------------------------------------------------------------

$e = engine_site();
check( 'graph: sample graph is valid', array() === Graph::violations( sample_graph(), $e->actions ) );
$bad = sample_graph();
$bad['nodes'][] = array( 'id' => 't2', 'type' => 'trigger', 'event' => 'pass.order.paid' );
check( 'graph: two triggers are refused', in_array( 'graph:needs_exactly_one_trigger', Graph::violations( $bad, $e->actions ), true ) );
$bad = sample_graph();
$bad['edges'][] = array( 'from' => 'tag', 'to' => 'mail' );
$v   = Graph::violations( $bad, $e->actions );
check( 'graph: cycle refused', in_array( 'graph:cycle', $v, true ) );
$bad = sample_graph();
array_pop( $bad['edges'] );
check( 'graph: action without outgoing edge refused', in_array( 'node:tag:needs_exactly_one_outgoing_edge', Graph::violations( $bad, $e->actions ), true ) );
$bad = sample_graph();
$bad['nodes'][] = array( 'id' => 'lost', 'type' => 'end' );
check( 'graph: unreachable node refused', in_array( 'node:lost:unreachable', Graph::violations( $bad, $e->actions ), true ) );
$bad = sample_graph();
$bad['edges'][2]['branch'] = 'then';
check( 'graph: condition needs one then and one else', in_array( 'node:c:needs_one_then_and_one_else', Graph::violations( $bad, $e->actions ), true ) );
$bad = sample_graph();
$bad['nodes'][2]['action'] = 'voucher.refund';
check( 'graph: unknown action refused (no forbidden command, 8.2)', in_array( 'node:mail:unknown_action', Graph::violations( $bad, $e->actions ), true ) );
$bad = sample_graph();
unset( $bad['nodes'][2]['config']['subject'] );
check( 'graph: action configuration checked', in_array( 'node:mail:subject:required', Graph::violations( $bad, $e->actions ), true ) );
$bad = sample_graph( 'shop.order.paid' );
check( 'graph: trigger must be a catalogue type name', in_array( 'node:t:invalid_event', Graph::violations( $bad, $e->actions ), true ) );
check( 'graph: invalid input refused', array( 'graph:invalid' ) === Graph::violations( 'x', $e->actions ) );

// ----- Conditions (WF-BUILDER-05) -----------------------------------------------------------------------------

$env  = array( 'type' => 'pass.order.paid', 'data' => array( 'total_minor' => 4800, 'currency' => 'EUR', 'payment_provider' => 'stripe' ) );
$rule = static fn( $f, $o, $v = null ) => array( 'op' => 'and', 'items' => array( array( 'field' => $f, 'operator' => $o, 'value' => $v ) ) );
check( 'condition: number comparison', Conditions::TRUE === Conditions::evaluate( $rule( 'data.total_minor', 'gte', 4000 ), $env, 'false' ) );
check( 'condition: no casting of strings to numbers', Conditions::FALSE === Conditions::evaluate( $rule( 'data.currency', 'gt', 1 ), $env, 'false' ) );
check( 'condition: in list', Conditions::TRUE === Conditions::evaluate( $rule( 'data.payment_provider', 'in', array( 'paypal', 'stripe' ) ), $env, 'false' ) );
check( 'condition: OR group', Conditions::TRUE === Conditions::evaluate( array( 'op' => 'or', 'items' => array( array( 'field' => 'data.currency', 'operator' => 'eq', 'value' => 'CHF' ), array( 'op' => 'and', 'items' => array( array( 'field' => 'type', 'operator' => 'eq', 'value' => 'pass.order.paid' ) ) ) ) ), $env, 'false' ) );
check( 'condition: missing field, mode false', Conditions::FALSE === Conditions::evaluate( $rule( 'data.ticket_count', 'gt', 1 ), $env, 'false' ) );
check( 'condition: missing field, mode stop', Conditions::STOP === Conditions::evaluate( $rule( 'data.ticket_count', 'gt', 1 ), $env, 'stop' ) );
check( 'condition: missing field, mode error', Conditions::ERROR === Conditions::evaluate( $rule( 'data.ticket_count', 'gt', 1 ), $env, 'error' ) );
check( 'condition: exists does not trip the missing mode', Conditions::FALSE === Conditions::evaluate( $rule( 'data.ticket_count', 'exists' ), $env, 'error' ) );
check( 'condition: too deep refused', in_array( 'condition:too_deep', Conditions::validate( array( 'op' => 'and', 'items' => array( array( 'op' => 'or', 'items' => array( array( 'op' => 'and', 'items' => array( array( 'field' => 'type', 'operator' => 'eq', 'value' => 'x' ) ) ) ) ) ) ) ), true ) );
check( 'condition: unknown field refused', in_array( 'condition:invalid_field', Conditions::validate( $rule( 'customer.email', 'eq', 'x' ) ), true ) );
check( 'template: only envelope fields', 'A pass.order.paid / 4800 /  / -' === Template::render( 'A {{type}} / {{data.total_minor}} / {{customer.email}} / {{data.none}}-', $env ) );

// ----- Publishing (R-8.7) ---------------------------------------------------------------------------------------

$e              = engine_site();
list( $id, $v ) = $e->workflows->save( null, 'Entwurf', array( 'nodes' => array(), 'edges' => array() ) );
check( 'publish: incomplete draft can be saved but not published', $id > 0 && array() !== $v && array() !== $e->workflows->publish( $id, 1 ) );
list( $id ) = $e->workflows->save( null, 'Ohne Recht', sample_graph() );
check( 'publish: user without activate_workflows refused', in_array( 'permission:activate_workflows', $e->workflows->publish( $id, 99 ), true ) );
$g = sample_graph();
$g['nodes'][3] = array( 'id' => 'tag', 'type' => 'action', 'action' => 'test.write', 'config' => array() );
list( $id ) = $e->workflows->save( null, 'Schreibend', $g );
check( 'publish: user needs the capability of every writing action (R-8.7)', in_array( 'permission:vcap_manage_vouchers', $e->workflows->publish( $id, 1 ), true ) );
$e->dir->caps[1][] = 'vcap_manage_vouchers';
check( 'publish: with the capability it publishes', array() === $e->workflows->publish( $id, 1 ) );
$wf = $e->store->get_workflow( $id );
check( 'publish: version is stored with authorized_by_user_id', 'active' === $wf['status'] && 1 === $e->store->get_version( $wf['published_version_id'] )['authorized_by_user_id'] );

// ----- End to end: event → run → steps → actions ---------------------------------------------------------------

$e  = engine_site();
$id = publish( $e, sample_graph() );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
$run = run_of( $e );
check( 'e2e: run started and succeeded', EngineStore::RUN_SUCCEEDED === ( $run['state'] ?? '' ) );
check( 'e2e: admin mail sent with filled template', 1 === count( $e->dir->sent ) && 'Einlass 8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' === $e->dir->sent[0][1] && str_contains( $e->dir->sent[0][2], 'Quelle scanner' ) );
check( 'e2e: tag set on the subject', array( 'eingecheckt' ) === $e->store->tags_for( 'pass-captain', 'ticket', '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' ) );
$states = array_map( fn( $s ) => $s['node_id'] . ':' . $s['state'], steps_of( $e ) );
check( 'e2e: steps c, mail, tag, end succeeded; else branch not created', array( 'c:succeeded', 'mail:succeeded', 'tag:succeeded', 'e1:succeeded' ) === $states );
check( 'e2e: condition outcome logged', 'then' === steps_of( $e )[0]['result']['outcome'] );
check( 'e2e: run log holds no address', ! str_contains( json_encode( array_map( fn( $s ) => $s['result'], steps_of( $e ) ) ), '@' ) );
check( 'e2e: stored event done and minimized (run keeps its snapshot)', array() === $e->events->find( array(), 1, 0 )[0]['envelope']['data'] && 'scanner' === $run['context']['data']['checkin_source'] );

$e = engine_site();
publish( $e, sample_graph() );
$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'k', array( 'checkin_source' => 'web' ) ) );
drain( $e );
check( 'e2e: else branch ends the run without mail', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] && array() === $e->dir->sent && 'else' === steps_of( $e )[0]['result']['outcome'] );

$e = engine_site();
publish( $e, sample_graph( 'pass.order.paid' ) );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'e2e: other trigger type starts nothing', array() === $e->store->find_runs( array(), 10, 0 ) );

$e  = engine_site();
$id = publish( $e, sample_graph() );
$e->workflows->pause( $id );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'e2e: paused workflow starts nothing', array() === $e->store->find_runs( array(), 10, 0 ) );

// C-10 and WF-CORE-02: a repeated dispatch starts no second run; edits do not touch running runs
$e   = engine_site();
$id  = publish( $e, sample_graph() );
$e->consumer->capture( $e->adapter, checkin() );
$env = $e->events->find( array(), 1, 0 )[0]['envelope'];
$e->engine->dispatch( $env, null );
$e->engine->dispatch( $env, null );
check( 'C-10 dispatching the same event twice gives one run and one first step', 1 === count( $e->store->find_runs( array(), 10, 0 ) ) && 1 === count( steps_of( $e ) ) );
$g = sample_graph();
$g['nodes'][2]['config']['subject'] = 'Neue Fassung';
$e->workflows->save( $id, 'Test', $g );
$e->workflows->publish( $id, 1 );
$e->engine->process( 100 );
$e->engine->process( 100 );
check( 'WF-CORE-02 running run keeps its version', 'Einlass 8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' === ( $e->dir->sent[0][1] ?? '' ) );

// R-7.14 through the real engine: an event caused by workflow X never starts X
$e  = engine_site();
$x  = publish( $e, sample_graph(), 'X' );
$y  = publish( $e, sample_graph(), 'Y' );
$e->consumer->capture( $e->adapter, checkin() );
$env = $e->events->find( array(), 1, 0 )[0]['envelope'];
$e->consumer->run_command(
	Consumer::cause_for( $env, $x ),
	function () use ( $e ) {
		$e->consumer->capture( $e->adapter, checkin( 'aaaabbbbccccddddeeeeffff00001111', 'caused' ) );
	}
);
drain( $e );
$by_wf = array_count_values( array_map( fn( $r ) => $r['workflow_id'], $e->store->find_runs( array(), 10, 0 ) ) );
check( 'R-7.14 caused event starts Y but not X', 1 === ( $by_wf[ $x ] ?? 0 ) && 2 === ( $by_wf[ $y ] ?? 0 ) );

// C-9 through the engine: events captured while an action runs carry its causation
$e      = engine_site();
$wf     = publish( $e, sample_graph() );
$probe  = new class( $e ) implements \WorkflowCaptain\Engine\Action {
	public function __construct( private object $e ) {}
	public function key(): string { return 'test.probe'; }
	public function label(): string { return 'probe'; }
	public function capability(): ?string { return null; }
	public function idempotent(): bool { return true; }
	public function validate_config( array $config ): array { return array(); }
	public function execute( \WorkflowCaptain\Engine\ActionContext $c ): array {
		$this->e->consumer->capture( $this->e->adapter, checkin( 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'from-action' ) );
		return array();
	}
};
$e->actions->add( $probe );
$g = sample_graph();
$g['nodes'][3] = array( 'id' => 'tag', 'type' => 'action', 'action' => 'test.probe', 'config' => array() );
$e->workflows->save( $wf, 'Test', $g );
$e->workflows->publish( $wf, 1 );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
$caused = array_values( array_filter( $e->events->find( array(), 10, 0 ), fn( $r ) => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' === $r['subject_id'] ) );
check( 'C-9 event from an action carries causation and depth 1', isset( $caused[0] ) && $wf === $caused[0]['envelope']['caused_by_workflow_id'] && 1 === $caused[0]['envelope']['chain_depth'] );

// R-8.8: right withdrawn after publishing pauses the run
$e = engine_site();
$e->dir->caps[1][] = 'vcap_manage_vouchers';
$g = sample_graph();
$g['nodes'][3] = array( 'id' => 'tag', 'type' => 'action', 'action' => 'test.write', 'config' => array() );
publish( $e, $g );
$e->dir->caps[1] = array( 'activate_workflows', 'manage_options' );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'R-8.8 withdrawn right pauses the run, the write does not happen', EngineStore::RUN_PAUSED === run_of( $e )['state'] && 'not_authorized' === run_of( $e )['last_error'] && 0 === $e->cap->runs );
check( 'C-11 the engine never sets a current user', ! str_contains( file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Engine/Engine.php' ), 'wp_set_current_user' ) );
$e->dir->caps[1][] = 'vcap_manage_vouchers';
$paused = array_values( array_filter( steps_of( $e ), fn( $s ) => EngineStore::STEP_PAUSED === $s['state'] ) )[0];
check( 'continue after the right is back', $e->workflows->continue_step( $paused['id'], false ) );
drain( $e );
check( 'run completes after continuing; result redacted', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] && 1 === $e->cap->runs && ! isset( $e->store->get_step( $paused['id'] )['result']['mail'] ) );

// R-8.11: temporary mail failures follow the plan
$e = engine_site();
publish( $e, sample_graph() );
$e->dir->fail_mails = 2;
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
$mail = array_values( array_filter( steps_of( $e ), fn( $s ) => 'mail' === $s['node_id'] ) )[0];
check( 'R-8.11 failed mail waits for retry', EngineStore::STEP_RETRY_WAIT === $mail['state'] && 'mail_failed' === $mail['last_error'] && 'none' === $mail['effect'] );
$e->clock->advance( 60 );
drain( $e );
$e->clock->advance( 300 );
drain( $e );
check( 'R-8.11 succeeds on the third attempt, one mail', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] && 1 === count( $e->dir->sent ) );

// R-8.12: worker dies after sending → never resent on its own
$e = engine_site();
publish( $e, sample_graph() );
$e->dir->crash_after_send = true;
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
$mail = array_values( array_filter( steps_of( $e ), fn( $s ) => 'mail' === $s['node_id'] ) )[0];
check( 'R-8.12 unclear outcome needs a human, mail not repeated', EngineStore::STEP_ACTION_REQUIRED === $mail['state'] && 'unclear_outcome' === $mail['last_error'] && 1 === count( $e->dir->sent ) );
check( 'R-8.12 continuing without confirmation is refused', false === $e->workflows->continue_step( $mail['id'], false ) );
$e->dir->crash_after_send = false;
check( 'R-8.12 continuing with confirmation sends again', $e->workflows->continue_step( $mail['id'], true ) );
drain( $e );
check( 'R-8.12 run completes after confirmation', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] && 2 === count( $e->dir->sent ) );

// WF-CORE-06: lease expiry after a crash before the side effect
$e = engine_site();
publish( $e, sample_graph() );
$e->consumer->capture( $e->adapter, checkin() );
$e->consumer->process();
$claimed = $e->store->claim_steps( 10, $e->clock->mysql(), 'dead-worker', $e->clock->mysql( $e->clock->now() + 300 ) );
check( 'WF-CORE-06 claimed step is not taken by another worker during the lease', 1 === count( $claimed ) && 0 === $e->engine->process() );
$e->clock->advance( 301 );
drain( $e );
check( 'WF-CORE-06 after the lease the run continues', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] );

// Delay with calendar days and DST (konzept 15.3)
$e  = engine_site( 'Europe/Vienna' );
$e->clock->time = strtotime( '2026-03-28 09:00:00 UTC' ); // 10:00 local, day before the change to summer time
$until          = $e->engine->delay_until( 'days', 1 );
check( 'delay: one day keeps the local time over the DST change', '2026-03-29 08:00:00' === gmdate( 'Y-m-d H:i:s', $until ) );
check( 'delay: hours are absolute', $e->clock->now() + 7200 === $e->engine->delay_until( 'hours', 2 ) );
$g = sample_graph();
$g['nodes'][] = array( 'id' => 'wait', 'type' => 'delay', 'unit' => 'hours', 'amount' => 2 );
$g['edges'][3] = array( 'from' => 'mail', 'to' => 'wait' );
$g['edges'][] = array( 'from' => 'wait', 'to' => 'tag' );
publish( $e, $g );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'delay: run waits, tag not yet set', EngineStore::RUN_RUNNING === run_of( $e )['state'] && array() === $e->store->tags_for( 'pass-captain', 'ticket', '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' ) );
$e->clock->advance( 7200 );
drain( $e );
check( 'delay: after two hours the run completes', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] );

// Catch-up limit: a step more than a day overdue waits for a decision
$e = engine_site();
publish( $e, $g );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
$e->clock->advance( 7200 + DAY_IN_SECONDS + 60 );
drain( $e );
$tag = array_values( array_filter( steps_of( $e ), fn( $s ) => 'tag' === $s['node_id'] ) )[0];
check( 'catch-up: overdue step needs a decision', EngineStore::STEP_ACTION_REQUIRED === $tag['state'] && 'overdue' === $tag['last_error'] );
check( 'catch-up: continuing runs it', $e->workflows->continue_step( $tag['id'], false ) );
drain( $e );
check( 'catch-up: run completes', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] );

// Condition with missing field in mode stop / error
$e = engine_site();
$g = sample_graph();
$g['nodes'][1]['rules']['items'][0]['field'] = 'data.reentry';
$g['nodes'][1]['rules']['items'][0]['value'] = true;
$g['nodes'][1]['missing'] = 'stop';
publish( $e, $g );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'condition stop ends the run as stopped', EngineStore::RUN_STOPPED === run_of( $e )['state'] );

// Action removed after publishing (provider gone) pauses safely
$e = engine_site();
$g = sample_graph();
$g['nodes'][3] = array( 'id' => 'tag', 'type' => 'action', 'action' => 'test.probe', 'config' => array() );
$e->actions->add( new class() implements \WorkflowCaptain\Engine\Action {
	public function key(): string { return 'test.probe'; }
	public function label(): string { return 'probe'; }
	public function capability(): ?string { return null; }
	public function idempotent(): bool { return true; }
	public function validate_config( array $config ): array { return array(); }
	public function execute( \WorkflowCaptain\Engine\ActionContext $c ): array { return array(); }
} );
publish( $e, $g );
$fresh = new ActionRegistry();
$fresh->add( new NotifyAdmin( $e->dir ) );
$e->engine = new Engine( $e->store, $fresh, $e->dir, $e->clock );
$e->consumer = new Consumer( $e->events, $e->registry, $e->engine, $e->clock, 1 );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'WF-CORE-02 vanished action pauses the run', EngineStore::RUN_PAUSED === run_of( $e )['state'] && 'action_unavailable' === run_of( $e )['last_error'] );

// Cancel
$e = engine_site();
publish( $e, $g = ( function () { $x = sample_graph(); $x['nodes'][] = array( 'id' => 'wait', 'type' => 'delay', 'unit' => 'days', 'amount' => 1 ); $x['edges'][3] = array( 'from' => 'mail', 'to' => 'wait' ); $x['edges'][] = array( 'from' => 'wait', 'to' => 'tag' ); return $x; } )() );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'cancel stops waiting steps', $e->workflows->cancel_run( run_of( $e )['id'] ) );
$e->clock->advance( 2 * DAY_IN_SECONDS );
drain( $e );
check( 'cancelled run sets no tag later', EngineStore::RUN_CANCELLED === run_of( $e )['state'] && array() === $e->store->tags_for( 'pass-captain', 'ticket', '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' ) );

// Note once per step key, notify.user resolves the address at runtime
$e = engine_site();
$g = sample_graph();
$g['nodes'][2] = array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.user', 'config' => array( 'subject' => 'Hallo', 'message' => 'Danke für den Besuch' ) );
$g['nodes'][3] = array( 'id' => 'tag', 'type' => 'action', 'action' => 'workflow.note.add', 'config' => array( 'text' => 'Eingecheckt über {{data.checkin_source}}' ) );
publish( $e, $g );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'notify.user mails the actor by user ID', array( 'person@example.com', 'Hallo', 'Danke für den Besuch' ) === ( $e->dir->sent[0] ?? array() ) );
check( 'note added once with rendered text', 1 === count( $e->store->notes_for( 'pass-captain', 'ticket', '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' ) ) && 'Eingecheckt über scanner' === $e->store->notes_for( 'pass-captain', 'ticket', '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' )[0]['note'] );
check( 'run context keeps the actor until the run ends (privacy handles it)', 17 === run_of( $e )['actor_user_id'] );

// ----- Privacy for runs (R-12.5, R-12.4) ------------------------------------------------------------------------

$e = engine_site();
publish( $e, sample_graph() );
$GLOBALS['wfc_test_users'] = array( 17 => 'person@example.com' );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
$privacy = new \WorkflowCaptain\Privacy\Privacy( fn() => $e->events, $e->clock, fn() => $e->store );
$export  = $privacy->export( 'person@example.com' );
check( 'C-13 export includes the runs of the user', 1 === count( array_filter( $export['data'], fn( $i ) => 'workflow-captain-runs' === $i['group_id'] ) ) );
$privacy->erase( 'person@example.com' );
check( 'C-13 erasure removes the user from runs', array() === $e->store->runs_by_actor( 17, 10, 0 ) && ! isset( run_of( $e )['context']['actor_user_id'] ) );
$e->clock->advance( 31 * DAY_IN_SECONDS );
$privacy->apply_retention();
check( 'C-14 finished runs and their steps are deleted after 30 days', array() === $e->store->find_runs( array(), 10, 0 ) );
$e = engine_site();
$g = sample_graph();
$g['nodes'][] = array( 'id' => 'wait', 'type' => 'delay', 'unit' => 'weeks', 'amount' => 10 );
$g['edges'][3] = array( 'from' => 'mail', 'to' => 'wait' );
$g['edges'][] = array( 'from' => 'wait', 'to' => 'tag' );
publish( $e, $g );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
$e->clock->advance( 40 * DAY_IN_SECONDS );
( new \WorkflowCaptain\Privacy\Privacy( fn() => $e->events, $e->clock, fn() => $e->store ) )->apply_retention();
check( 'C-14 an open run is kept', 1 === count( $e->store->find_runs( array(), 10, 0 ) ) );

// ----- Diagnostics without personal data (P2) ------------------------------------------------------------------

$e = engine_site();
publish( $e, sample_graph() );
$e->dir->crash_after_send = true;
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
$report = \WorkflowCaptain\Admin\Diagnostics::report( $e->events, $e->store, $e->registry, array( 'plugin' => '0.2.0' ) );
$json   = json_encode( $report );
check( 'diagnostics: counts and reasons present', 1 === $report['runs']['action_required'] && 1 === $report['action_required_reasons']['unclear_outcome'] && array( 'pass-captain' ) === $report['adapters']['registered'] );
check( 'diagnostics: no address, no subject ID, no user ID', ! str_contains( $json, '@' ) && ! str_contains( $json, '8f3a1c0d9e7b' ) && ! str_contains( $json, '"actor_user_id"' ) );

// ----- WF-CORE-05: concurrency, fairness, serialisation --------------------------------------------------------

/** Workflow whose first step is a tag action; many events give many due steps. */
function tag_graph(): array {
	return array(
		'nodes' => array(
			array( 'id' => 't', 'type' => 'trigger', 'event' => 'pass.ticket.checked_in' ),
			array( 'id' => 'tag', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'x' ) ),
			array( 'id' => 'e', 'type' => 'end' ),
		),
		'edges' => array( array( 'from' => 't', 'to' => 'tag' ), array( 'from' => 'tag', 'to' => 'e' ) ),
	);
}

$e = engine_site();
$x = publish( $e, tag_graph(), 'X' );
$y = publish( $e, tag_graph(), 'Y' );
for ( $i = 0; $i < 6; $i++ ) {
	$e->consumer->capture( $e->adapter, checkin( str_pad( (string) $i, 32, 'a' ), 'k' . $i ) );
}
$e->consumer->process( 100 );
function tag_done( object $e ): array {
	$by = array();
	foreach ( $e->store->find_runs( array(), 100, 0 ) as $r ) {
		foreach ( $e->store->steps_for_run( $r['id'] ) as $s ) {
			if ( 'tag' === $s['node_id'] && EngineStore::STEP_SUCCEEDED === $s['state'] ) {
				$by[ $r['workflow_id'] ] = ( $by[ $r['workflow_id'] ] ?? 0 ) + 1;
			}
		}
	}
	return $by;
}
$e->engine->process( 4 );
$done = tag_done( $e );
check( 'WF-CORE-05 fairness: a batch of 4 gives each of two workflows at most 2', 2 === ( $done[ $x ] ?? 0 ) && 2 === ( $done[ $y ] ?? 0 ) );

$e = engine_site();
$x = publish( $e, tag_graph(), 'X' );
for ( $i = 0; $i < 7; $i++ ) {
	$e->consumer->capture( $e->adapter, checkin( str_pad( (string) $i, 32, 'b' ), 'k' . $i ) );
}
$e->consumer->process( 100 );
$held = $e->store->claim_steps( 5, $e->clock->mysql(), 'other-worker', $e->clock->mysql( $e->clock->now() + 300 ) );
check( 'WF-CORE-05 site limit: with 5 steps held elsewhere nothing more runs', 5 === count( $held ) && 0 === $e->engine->process( 25 ) );

$e = engine_site();
$x = publish( $e, tag_graph(), 'X' );
$y = publish( $e, tag_graph(), 'Y' );
for ( $i = 0; $i < 3; $i++ ) {
	$e->consumer->capture( $e->adapter, checkin( str_pad( (string) $i, 32, 'c' ), 'k' . $i ) );
}
$e->consumer->process( 100 );
// Hold two steps of workflow X in another worker.
$xsteps = array();
foreach ( $e->store->find_runs( array( 'workflow_id' => $x ), 10, 0 ) as $r ) {
	$xsteps[] = $e->store->steps_for_run( $r['id'] )[0]['id'];
}
$e->store->claim_step( $xsteps[0], $e->clock->mysql(), 'other', $e->clock->mysql( $e->clock->now() + 300 ) );
$e->store->claim_step( $xsteps[1], $e->clock->mysql(), 'other', $e->clock->mysql( $e->clock->now() + 300 ) );
$e->engine->process( 25 );
$done = tag_done( $e );
check( 'WF-CORE-05 workflow limit: X with 2 held runs nothing more, Y runs all', 0 === ( $done[ $x ] ?? 0 ) && 3 === ( $done[ $y ] ?? 0 ) );

$e = engine_site();
$g = tag_graph();
$g['nodes'][0]['serialize'] = 'subject';
$g['nodes'][] = array( 'id' => 'wait', 'type' => 'delay', 'unit' => 'hours', 'amount' => 1 );
$g['edges'][0] = array( 'from' => 't', 'to' => 'wait' );
$g['edges'][] = array( 'from' => 'wait', 'to' => 'tag' );
publish( $e, $g );
$e->consumer->capture( $e->adapter, checkin( 'dddddddddddddddddddddddddddddddd', 'first' ) );
$e->consumer->capture( $e->adapter, checkin( 'dddddddddddddddddddddddddddddddd', 'second' ) );
$e->consumer->capture( $e->adapter, checkin( 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 'other' ) );
drain( $e );
$runs  = array_reverse( $e->store->find_runs( array(), 10, 0 ) );
$steps = array_map( fn( $r ) => count( $e->store->steps_for_run( $r['id'] ) ), $runs );
check( 'WF-CORE-05 serialisation: second run on the same object waits, other object runs', array( 2, 1, 2 ) === $steps );
$e->clock->advance( 3600 );
drain( $e );
$e->clock->advance( 3600 );
drain( $e );
check( 'WF-CORE-05 serialisation: after the first run the second follows', 3 === count( array_filter( $e->store->find_runs( array(), 10, 0 ), fn( $r ) => EngineStore::RUN_SUCCEEDED === $r['state'] ) ) );
check( 'graph: invalid serialize mode refused', in_array( 'node:t:invalid_serialize', Graph::violations( array( 'nodes' => array( array( 'id' => 't', 'type' => 'trigger', 'event' => 'pass.order.paid', 'serialize' => 'order' ), array( 'id' => 'e', 'type' => 'end' ) ), 'edges' => array( array( 'from' => 't', 'to' => 'e' ) ) ), $e->actions ), true ) );

// Conflicts of parallel runs (konzept 15.3): tags "last writer wins" without duplicates, notes are only appended.
$e  = engine_site();
$at = $e->clock->mysql();
$e->store->set_tag( 'pass', 'ticket', '77', 'vip', 1, $at );
$e->store->set_tag( 'pass', 'ticket', '77', 'vip', 2, $at );
check( 'WF-CORE-05 conflict: the same tag from two workflows is stored once', array( 'vip' ) === $e->store->tags_for( 'pass', 'ticket', '77' ) );
$e->store->remove_tag( 'pass', 'ticket', '77', 'vip' );
$removed = $e->store->tags_for( 'pass', 'ticket', '77' );
$e->store->set_tag( 'pass', 'ticket', '77', 'vip', 1, $at );
check( 'WF-CORE-05 conflict: remove then set, the last writer wins', array() === $removed && array( 'vip' ) === $e->store->tags_for( 'pass', 'ticket', '77' ) );
$e->store->add_note( str_repeat( 'a', 64 ), 'pass', 'ticket', '77', 'one', 1, 1, $at );
$e->store->add_note( str_repeat( 'b', 64 ), 'pass', 'ticket', '77', 'two', 2, 2, $at );
$e->store->add_note( str_repeat( 'a', 64 ), 'pass', 'ticket', '77', 'one again', 1, 1, $at );
check( 'WF-CORE-05 conflict: notes of two runs are appended, a repeated step adds nothing', array( 'one', 'two' ) === array_column( $e->store->notes_for( 'pass', 'ticket', '77' ), 'note' ) );

// ----- WF-CORE-06: heartbeat and hanging work -------------------------------------------------------------------

$e    = engine_site();
$slow = new class( $e ) implements \WorkflowCaptain\Engine\Action {
	public bool $beat = false;
	public function __construct( private object $e ) {}
	public function key(): string { return 'test.slow'; }
	public function label(): string { return 'slow'; }
	public function capability(): ?string { return null; }
	public function idempotent(): bool { return true; }
	public function validate_config( array $config ): array { return array(); }
	public function execute( \WorkflowCaptain\Engine\ActionContext $c ): array {
		$this->e->clock->advance( 280 );           // almost the whole lease
		$this->beat = $c->heartbeat();
		$this->e->clock->advance( 100 );           // past the original lease
		$this->e->other = $this->e->store->claim_steps( 10, $this->e->clock->mysql(), 'intruder', $this->e->clock->mysql( $this->e->clock->now() + 300 ) );
		return array();
	}
};
$e->actions->add( $slow );
$g = tag_graph();
$g['nodes'][1] = array( 'id' => 'tag', 'type' => 'action', 'action' => 'test.slow', 'config' => array() );
publish( $e, $g );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'WF-CORE-06 heartbeat extends the lease; no second worker takes the step', $slow->beat && array() === $e->other && EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] );

$e = engine_site();
publish( $e, tag_graph() );
$e->consumer->capture( $e->adapter, checkin() );
$e->consumer->process();
$e->store->claim_steps( 10, $e->clock->mysql(), 'dead', $e->clock->mysql( $e->clock->now() + 300 ) );
$e->clock->advance( 2 * DAY_IN_SECONDS );
$stuck = \WorkflowCaptain\Admin\Diagnostics::stuck( $e->store, $e->clock->now() );
check( 'WF-CORE-06 a lease expired for over a day is reported', 1 === $stuck['expired_leases'] );
check( 'WF-CORE-06 reporting changes nothing', EngineStore::STEP_RUNNING === steps_of( $e )[0]['state'] );

// ----- Settings -------------------------------------------------------------------------------------------------

use WorkflowCaptain\Settings;
check( 'settings: defaults', 25 === Settings::defaults()['batch'] && 30 === Settings::defaults()['retention_finished'] );
check( 'settings: values clamped to their range', 200 === Settings::sanitize( array( 'batch' => 9999 ) )['batch'] && 1 === Settings::sanitize( array( 'max_site' => -3 ) )['max_site'] );
check( 'settings: retention cannot exceed the proposal', 90 === Settings::sanitize( array( 'retention_stuck' => 365 ) )['retention_stuck'] );
check( 'settings: unknown keys dropped', ! isset( Settings::sanitize( array( 'evil' => 1 ) )['evil'] ) );
check( 'settings: engine limits mapped', array( 'site' => 5, 'workflow' => 2, 'catch_up' => 86400, 'seconds' => 20 ) === Settings::engine_limits( Settings::defaults() ) );
$e = new Engine( new WpdbEngineStore( new FakeWpdb( FakeWpdb::memory() ) ), new ActionRegistry(), new FakeDirectory(), new FixedClock(), 'UTC', array( 'site' => 9, 'bogus' => 3, 'workflow' => 0 ) );
check( 'engine: limits taken from settings, invalid ignored', array( 'site' => 9, 'workflow' => 2, 'catch_up' => 86400, 'seconds' => 20 ) === $e->limits() );

// ----- Provider overview (WF-INT-14 part) ----------------------------------------------------------------------

$e = engine_site();
publish( $e, sample_graph() );
$e->registry->add( new TestAdapter( 'shop' ) );                       // refused: unknown product
$e->consumer->capture( $e->adapter, checkin() );
$e->events->insert( array( 'id' => \WorkflowCaptain\Envelope\Uuid::v4(), 'site' => array( 'blog_id' => 1 ), 'idempotency_key' => str_repeat( 'f', 64 ), 'type' => 'voucher.voucher.redeemed', 'source' => array( 'product' => 'voucher-captain' ), 'subject' => array( 'type' => 'voucher', 'id' => '1' ), 'data' => array() ), EventStore::PAUSED, $e->clock->mysql() );
$rows = \WorkflowCaptain\Admin\ProvidersPage::rows( $e->registry, $e->events, $e->store );
$by   = array_column( $rows, null, 'product' );
check( 'providers: registered adapter with versions, types and active workflows', 'registered' === $by['pass-captain']['status'] && '1.1.0' === $by['pass-captain']['contract_version'] && 1 === $by['pass-captain']['active_workflows']['pass.ticket.checked_in'] && 1 === $by['pass-captain']['events']['received'] );
check( 'providers: refused adapter shows its reason', 'refused' === $by['shop']['status'] && 'unknown_product' === $by['shop']['reason'] );
check( 'providers: product with events but no adapter is shown as missing', 'missing' === $by['voucher-captain']['status'] && 1 === $by['voucher-captain']['events']['paused'] );

// ----- Dry run and provider availability (WF-BUILDER-10, -11) ---------------------------------------------------

use WorkflowCaptain\Engine\DryRun;
$e   = engine_site();
$dry = DryRun::run( sample_graph(), array( 'data' => array( 'checkin_source' => 'scanner' ), 'subject' => array( 'type' => 'ticket', 'id' => 'T-1', 'id_kind' => 'public' ) ), $e->actions );
check( 'dry run: follows the then branch and ends', 'succeeded' === $dry['outcome'] && array( 't', 'c', 'mail', 'tag', 'e1' ) === array_column( $dry['path'], 'node' ) );
check( 'dry run: renders the message text', 'Einlass T-1' === $dry['path'][2]['would']['subject'] );
check( 'dry run: no side effect', array() === $e->dir->sent && array() === $e->store->find_runs( array(), 10, 0 ) );
$dry = DryRun::run( sample_graph(), array( 'data' => array( 'checkin_source' => 'web' ) ), $e->actions );
check( 'dry run: else branch', array( 't', 'c', 'e2' ) === array_column( $dry['path'], 'node' ) );
$g = sample_graph();
$g['nodes'][1]['missing'] = 'error';
$dry = DryRun::run( $g, array( 'data' => array() ), $e->actions );
check( 'dry run: missing field marks the node', 'condition_field_missing' === $dry['outcome'] && 'c' === $dry['error_node'] );
$g = sample_graph();
$g['nodes'][2]['action'] = 'gone.action';
check( 'dry run: unavailable action marks the node', 'mail' === DryRun::run( $g, array( 'data' => array( 'checkin_source' => 'scanner' ) ), $e->actions )['error_node'] );

$wf = new Workflows( $e->store, $e->actions, $e->dir, $e->clock, fn() => array( 'pass.order.paid' ) );
list( $id ) = $wf->save( null, 'Ohne Provider', sample_graph() );
check( 'WF-BUILDER-11 publishing without a provider for the trigger is blocked', in_array( 'node:t:provider_unavailable', $wf->publish( $id, 1 ), true ) );
$wf = new Workflows( $e->store, $e->actions, $e->dir, $e->clock, fn() => array( 'pass.ticket.checked_in' ) );
check( 'WF-BUILDER-11 with the provider registered it publishes', array() === $wf->publish( $id, 1 ) );


// ----- WF-SEC-04: end-to-end data flow ---------------------------------------------------------------------------

/** Every value of every table, as one string. */
function dump_all( PDO $pdo ): string {
	$out = '';
	foreach ( $pdo->query( "SELECT name FROM sqlite_master WHERE type = 'table'" )->fetchAll( PDO::FETCH_COLUMN ) as $table ) {
		$out .= json_encode( $pdo->query( 'SELECT * FROM ' . $table )->fetchAll( PDO::FETCH_ASSOC ) );
	}
	return $out;
}

$e      = engine_site();
$marker = 'datenfluss.marker@example.invalid';
$e->dir->emails[17] = $marker;
$g = sample_graph();
$g['nodes'][2] = array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.user', 'config' => array( 'subject' => 'Hallo {{data.checkin_source}}', 'message' => 'Text' ) );
publish( $e, $g );
$e->dir->fail_mails = 1;                                     // first attempt fails (retry path)
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
$e->clock->advance( 60 );
drain( $e );
$GLOBALS['wfc_test_users'] = array( 17 => $marker );
$privacy = new \WorkflowCaptain\Privacy\Privacy( fn() => $e->events, $e->clock, fn() => $e->store );
$export  = json_encode( $privacy->export( $marker ) );
$diag    = json_encode( \WorkflowCaptain\Admin\Diagnostics::report( $e->events, $e->store, $e->registry, array() ) );
check( 'SEC-04 the mail reached the user', $marker === ( $e->dir->sent[0][0] ?? '' ) );
check( 'SEC-04 the address is stored in no table (events, runs, steps, tags, notes, causes)', ! str_contains( dump_all( $e->pdo ), $marker ) && ! str_contains( dump_all( $e->pdo ), 'datenfluss.marker' ) );
check( 'SEC-04 the address is in neither diagnostics nor export', ! str_contains( $diag, $marker ) && ! str_contains( $export, $marker ) );

// Forbidden field names never reach storage; a crash after sending leaves no address either.
$e = engine_site();
publish( $e, $g );
$e->dir->emails[17]       = $marker;
$e->dir->crash_after_send = true;
$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'x', array( 'customer_email' => $marker ) ) );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'SEC-04 an event with a forbidden field is rejected, the clean one runs', 1 === count( $e->events->find( array(), 10, 0 ) ) && 1 === count( $e->store->find_runs( array(), 10, 0 ) ) );
check( 'SEC-04 after a crash after sending, no address in any table or error', ! str_contains( dump_all( $e->pdo ), $marker ) );
$privacy = new \WorkflowCaptain\Privacy\Privacy( fn() => $e->events, $e->clock, fn() => $e->store );
$GLOBALS['wfc_test_users'] = array( 17 => 'person@example.com' );
$privacy->erase( 'person@example.com' );
check( 'SEC-04 after erasure no user ID 17 remains in events or runs', ! preg_match( '/"actor_user_id":17\b/', dump_all( $e->pdo ) ) );

// ----- WF-CORE-07: schema state --------------------------------------------------------------------------------

use WorkflowCaptain\Install;
check( 'schema: same version is current', Install::SCHEMA_CURRENT === Install::schema_state( '3', '3' ) );
check( 'schema: missing or older version needs an upgrade', Install::SCHEMA_UPGRADE === Install::schema_state( false, '3' ) && Install::SCHEMA_UPGRADE === Install::schema_state( '2', '3' ) );
check( 'schema: newer stored version is a downgrade', Install::SCHEMA_NEWER === Install::schema_state( '4', '3' ) );

// WF-CORE-07: versioned upgrade with lock, resumption and data steps (Migrator), on an in-memory option table.
function migrator_case( array $opts, array $migrations, int $now, ?callable $schema = null ): array {
	$log   = array();
	$steps = array();
	foreach ( $migrations as $version => $step ) {
		$steps[ $version ] = function () use ( $step, &$log ) { $log[] = 'step' . $step; };
	}
	$m = new \WorkflowCaptain\Migrator(
		array(
			'get'    => function ( $n, $d = false ) use ( &$opts ) { return $opts[ $n ] ?? $d; },
			'add'    => function ( $n, $v ) use ( &$opts ) { if ( isset( $opts[ $n ] ) ) { return false; } $opts[ $n ] = $v; return true; },
			'update' => function ( $n, $v ) use ( &$opts, &$log ) { $opts[ $n ] = $v; $log[] = $n . '=' . $v; return true; },
			'delete' => function ( $n ) use ( &$opts ) { unset( $opts[ $n ] ); return true; },
		),
		$schema ?? function () use ( &$log ) { $log[] = 'schema'; },
		$steps,
		$now
	);
	try {
		$r = $m->run( '6' );
	} catch ( \RuntimeException $e ) {
		$r = array( 'state' => 'exception' );
	}
	return array( $r, $opts, $log );
}
$lock = \WorkflowCaptain\Migrator::LOCK_OPTION;
$db   = Install::DB_OPTION;
$steps = array( 3 => 3, 5 => 5, 6 => 6 );
[ $r, $o, $l ] = migrator_case( array( $db => '4' ), $steps, 1000 );
check( 'WF-CORE-07 upgrade: tables first, then only the data steps above the stored version, version moves step by step', 'done' === $r['state'] && array( 'schema', 'step5', $db . '=5', 'step6', $db . '=6', $db . '=6' ) === $l && array( 5, 6 ) === $r['steps'] );
check( 'WF-CORE-07 upgrade: lock released afterwards', ! isset( $o[ $lock ] ) && '6' === $o[ $db ] );
[ $r, $o, $l ] = migrator_case( array(), $steps, 1000 );
check( 'WF-CORE-07 fresh installation: tables only, no data step', 'done' === $r['state'] && array( 'schema', $db . '=6' ) === $l );
[ $r, $o, $l ] = migrator_case( array( $db => '4', $lock => '900' ), $steps, 1000 );
check( 'WF-CORE-07 a second request while an upgrade runs changes nothing and reports busy', 'busy' === $r['state'] && array() === $l && '900' === $o[ $lock ] && '4' === $o[ $db ] );
[ $r, $o, $l ] = migrator_case( array( $db => '5', $lock => '100' ), $steps, 100 + \WorkflowCaptain\Migrator::STALE_SECONDS );
check( 'WF-CORE-07 an upgrade that stopped half-way is taken over and resumed after the last finished step', 'done' === $r['state'] && $r['resumed'] && array( 'schema' ) === array_slice( $l, 1, 1 ) && in_array( 'step6', $l, true ) && ! in_array( 'step5', $l, true ) && ! isset( $o[ $lock ] ) );
[ $r, $o, $l ] = migrator_case( array( $db => '7' ), $steps, 1000 );
check( 'WF-CORE-07 downgrade: newer stored schema is left alone', 'newer' === $r['state'] && array() === $l && '7' === $o[ $db ] );
[ $r, $o, $l ] = migrator_case( array( $db => '6' ), $steps, 1000 );
check( 'WF-CORE-07 current schema: nothing runs', 'current' === $r['state'] && array() === $l );
[ $r, $o, $l ] = migrator_case( array( $db => '4' ), $steps, 1000, function () { throw new \RuntimeException( 'disk full' ); } );
check( 'WF-CORE-07 a failed upgrade keeps the old version and frees the lock for the next request', 'exception' === $r['state'] && '4' === $o[ $db ] && ! isset( $o[ $lock ] ) );
check( 'WF-CORE-07 pending steps are sorted and bounded', array( 5, 6 ) === \WorkflowCaptain\Migrator::pending_steps( 4, 6, array( 7, 6, 2, 5 ) ) );
check( 'WF-CORE-07 lock state for diagnostics', 'free' === \WorkflowCaptain\Migrator::lock_state( false, 1000 ) && 'held' === \WorkflowCaptain\Migrator::lock_state( '900', 1000 ) && 'stale' === \WorkflowCaptain\Migrator::lock_state( '1', 1000 ) );
check( 'WF-CORE-07 the shipped data steps lead at most to the current schema version', array() === array_filter( array_keys( Install::migrations() ), fn( $v ) => (int) $v > (int) WORKFLOW_CAPTAIN_DB_VERSION ) );

// ----- Site Health ---------------------------------------------------------------------------------------------

use WorkflowCaptain\Admin\SiteHealth;
$now  = 1763287200;
$good = array( 'environment' => array( 'next_worker_run' => gmdate( 'Y-m-d\TH:i:s\Z', $now + 30 ), 'schema_state' => 'current', 'secret_key_own' => true, 'wp_cron_disabled' => false ), 'events' => array(), 'runs' => array(), 'stuck' => array( 'expired_leases' => 0, 'runs_without_steps' => 0 ) );
check( 'site health: all good', array( 'good' ) === array_values( array_unique( array_column( SiteHealth::evaluate( $good, $now ), 'status' ) ) ) );
$bad = $good;
$bad['environment']['next_worker_run'] = gmdate( 'Y-m-d\TH:i:s\Z', $now - 3600 );
$bad['environment']['schema_state']    = 'newer';
$bad['environment']['secret_key_own']  = false;
$bad['runs']['action_required']        = 2;
$r = SiteHealth::evaluate( $bad, $now );
check( 'site health: overdue cron and newer schema are critical', 'critical' === $r['cron']['status'] && 'overdue' === $r['cron']['code'] && 'critical' === $r['schema']['status'] );
check( 'site health: decisions waiting and no own key are recommended', 'recommended' === $r['attention']['status'] && 'recommended' === $r['secret_key']['status'] );
$off = $good;
$off['environment']['wp_cron_disabled'] = true;
check( 'site health: disabled WP-Cron asks for a system cron', 'system_cron_needed' === SiteHealth::evaluate( $off, $now )['cron']['code'] );

// ----- Example workflows (docs/recipes) ------------------------------------------------------------------------

$e       = engine_site();
$catalog = json_decode( file_get_contents( WORKFLOW_CAPTAIN_PATH . 'docs/catalog/suite-events-v1.json' ), true );
$fields  = array();
foreach ( $catalog['products'] as $product ) {
	foreach ( $product['events'] as $event ) {
		$fields[ $event['type'] ] = $event['data_fields'];
	}
}
foreach ( glob( WORKFLOW_CAPTAIN_PATH . 'docs/recipes/*.json' ) as $file ) {
	$recipe = json_decode( file_get_contents( $file ), true );
	$graph  = $recipe['graph'];
	check( 'recipe ' . basename( $file ) . ' is valid', array() === Graph::violations( $graph, $e->actions ) );
	$type = Graph::trigger( $graph )['event'];
	preg_match_all( '/data\.([a-z0-9_]+)/', json_encode( $graph ), $m );
	check( 'recipe ' . basename( $file ) . ' uses only catalog fields of ' . $type, isset( $fields[ $type ] ) && array() === array_diff( array_unique( $m[1] ), $fields[ $type ] ) );
}

// ----- Bulk requeue (WF-CORE-10) -------------------------------------------------------------------------------

$s = site();
$s->consumer->capture( $s->adapter, checkin() );
$s->consumer->capture( $s->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'b' ) );
$s->consumer->capture( $s->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'c' ) );
$s->consumer->pause_product( 'pass-captain' );
$ids = array_map( fn( $r ) => $r['id'], $s->store->find( array(), 10, 0 ) );
$s->consumer->requeue( $ids[2] );
check( 'bulk requeue: only dead_letter/paused events count, duplicates once', 2 === $s->consumer->requeue_many( array( $ids[0], $ids[1], $ids[1], $ids[2], 999 ) ) );
check( 'bulk requeue: all back in the queue', 3 === count( $s->store->find( array( 'state' => EventStore::RECEIVED ), 10, 0 ) ) );


// ----- WF-CORE-09 / C-16: engine tables per site ---------------------------------------------------------------

$shared = FakeWpdb::memory();
$a      = engine_site( 'UTC', $shared, 'wp_', 1 );
$b      = engine_site( 'UTC', $shared, 'wp_2_', 2 );
publish( $a, sample_graph(), 'Nur Site 1' );
$a->consumer->capture( $a->adapter, checkin() );
$b->consumer->capture( $b->adapter, checkin() );
drain( $a );
drain( $b );
check( 'WF-CORE-09 a workflow of site 1 does not exist on site 2', array() === $b->store->list_workflows( 10, 0 ) && 1 === count( $a->store->list_workflows( 10, 0 ) ) );
check( 'WF-CORE-09 the event on site 2 starts no run of site 1', 1 === count( $a->store->find_runs( array(), 10, 0 ) ) && array() === $b->store->find_runs( array(), 10, 0 ) );
check( 'WF-CORE-09 tags stay on their site', array( 'eingecheckt' ) === $a->store->tags_for( 'pass-captain', 'ticket', '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' ) && array() === $b->store->tags_for( 'pass-captain', 'ticket', '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' ) );
check( 'WF-CORE-09 envelopes carry their own blog ID', 2 === $b->events->find( array(), 1, 0 )[0]['envelope']['site']['blog_id'] );

// ----- WF-CORE-07: consistency after a restore -----------------------------------------------------------------

$e = engine_site();
publish( $e, sample_graph() );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'consistency: a healthy site has no findings', array() === array_filter( $e->store->consistency() ) );
// Simulate a partial restore: versions table older than runs, an orphan step, an active workflow without version.
$e->pdo->exec( 'DELETE FROM wp_workflow_captain_versions' );
$e->pdo->exec( "INSERT INTO wp_workflow_captain_steps (run_id,node_id,step_key,state,attempts,effect,due_at,created_at,updated_at) VALUES (999,'x','" . str_repeat( '0', 64 ) . "','queued',0,'none','2026-01-01 00:00:00','2026-01-01 00:00:00','2026-01-01 00:00:00')" );
$c = $e->store->consistency();
check( 'consistency: runs without version, orphan steps, active workflow without version found', 1 === count( $c['runs_without_version'] ) && 1 === count( $c['steps_without_run'] ) && 1 === count( $c['active_without_version'] ) );
check( 'consistency: reporting changes nothing', 1 === count( $e->store->find_runs( array(), 10, 0 ) ) );

// ----- Overview cards ------------------------------------------------------------------------------------------

$cards = \WorkflowCaptain\Admin\OverviewPage::cards( array( 'environment' => array( 'next_worker_run' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 30 ), 'secret_key_own' => true ), 'workflows' => array( 'active' => 3 ), 'runs' => array( 'running' => 2, 'action_required' => 1, 'paused' => 1 ), 'events' => array( 'dead_letter' => 0 ), 'stuck' => array( 'expired_leases' => 0, 'runs_without_steps' => 0 ) ) );
$by    = array_column( $cards, null, 'label' );
check( 'overview: counts and tones', 3 === $by['Aktive Workflows']['value'] && 2 === $by['Brauchen eine Entscheidung']['value'] && 'warn' === $by['Brauchen eine Entscheidung']['tone'] && 'ok' === $by['Fehlgeschlagene Ereignisse']['tone'] && 'läuft' === $by['Worker']['value'] );

// ----- WF-SET-11: emergency pause ------------------------------------------------------------------------------

$e = engine_site();
publish( $e, sample_graph() );
$e->engine->set_paused( true );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
check( 'pause: event stored and run created, but no step runs', 1 === count( $e->store->find_runs( array(), 10, 0 ) ) && array() === $e->dir->sent && EngineStore::STEP_QUEUED === steps_of( $e )[0]['state'] );
$e->engine->set_paused( false );
drain( $e );
check( 'pause lifted: the run continues', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] && 1 === count( $e->dir->sent ) );
$r = SiteHealth::evaluate( array( 'environment' => array( 'emergency_pause' => true, 'next_worker_run' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 30 ), 'secret_key_own' => true ) ), time() );
check( 'site health: active pause is critical', 'critical' === $r['pause']['status'] );

// ----- WF-SET-12: export and import ----------------------------------------------------------------------------

use WorkflowCaptain\Engine\Transfer;
$e  = engine_site();
$id = publish( $e, sample_graph(), 'Exportiert' );
$x  = Transfer::export( $e->store->get_workflow( $id ), '0.2.0' );
check( 'export: format, version, name and graph; no IDs of the site', Transfer::FORMAT === $x['format'] && 1 === $x['format_version'] && 'Exportiert' === $x['name'] && 6 === count( $x['graph']['nodes'] ) && ! isset( $x['id'] ) && ! isset( $x['published_version_id'] ) );
$wf2 = new Workflows( $e->store, $e->actions, $e->dir, $e->clock, fn() => array() );
$imp = Transfer::import( json_encode( $x ), $wf2 );
$new = $e->store->get_workflow( $imp['id'] );
check( 'import: becomes a draft, never published', $imp['ok'] && EngineStore::WF_DRAFT === $new['status'] && null === $new['published_version_id'] );
check( 'import: missing provider is reported for publishing', in_array( 'node:t:provider_unavailable', $imp['violations'], true ) );
check( 'import: foreign format refused', 'import:unknown_format' === Transfer::import( '{"format":"other"}', $wf2 )['violations'][0] );
check( 'import: newer format version refused', 'import:unsupported_version' === Transfer::import( json_encode( array( 'format_version' => 2 ) + $x ), $wf2 )['violations'][0] );
check( 'import: broken JSON refused', 'import:not_json' === Transfer::import( '{', $wf2 )['violations'][0] );
check( 'import: too large refused', 'import:too_large' === Transfer::import( str_repeat( ' ', Transfer::MAX_BYTES + 1 ), $wf2 )['violations'][0] );
foreach ( glob( WORKFLOW_CAPTAIN_PATH . 'docs/recipes/*.json' ) as $file ) {
	$recipe = json_decode( file_get_contents( $file ), true );
	$r      = Transfer::import( file_get_contents( $file ), $e->workflows );
	check( 'import: recipe ' . basename( $file ) . ' imports without findings', $r['ok'] && array() === $r['violations'] );
}

// ----- Archive and duplicate (konzept 8.2, 15.3) ---------------------------------------------------------------

$e   = engine_site();
$id  = publish( $e, sample_graph(), 'Original' );
$dup = $e->workflows->duplicate( $id );
check( 'duplicate: new draft with the same graph', 'Original (Kopie)' === $e->store->get_workflow( $dup )['name'] && EngineStore::WF_DRAFT === $e->store->get_workflow( $dup )['status'] && $e->store->get_workflow( $dup )['draft'] == $e->store->get_workflow( $id )['draft'] );
$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'before' ) );
$e->consumer->process();
check( 'archive: archived workflow keeps its runs', $e->workflows->archive( $id ) && 1 === count( $e->store->find_runs( array( 'workflow_id' => $id ), 10, 0 ) ) );
$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'after' ) );
$e->consumer->process();
check( 'archive: archived workflow starts no new run', 1 === count( $e->store->find_runs( array( 'workflow_id' => $id ), 10, 0 ) ) );
drain( $e );
check( 'archive: the open run still finishes', EngineStore::RUN_SUCCEEDED === $e->store->find_runs( array( 'workflow_id' => $id ), 10, 0 )[0]['state'] );
check( 'restore: published workflow comes back paused', $e->workflows->restore( $id ) && EngineStore::WF_PAUSED === $e->store->get_workflow( $id )['status'] );

// ----- WF-SET-06: daily notice ---------------------------------------------------------------------------------

use WorkflowCaptain\Notifications;
check( 'notice: nothing open, no mail', null === Notifications::digest( array( 'runs' => array(), 'events' => array(), 'stuck' => array(), 'environment' => array() ), 'https://x/wp-admin/' ) );
$mail = Notifications::digest( array( 'runs' => array( 'action_required' => 2 ), 'events' => array( 'dead_letter' => 1 ), 'stuck' => array(), 'environment' => array( 'emergency_pause' => true ) ), 'https://x/wp-admin/admin.php?page=workflow-captain' );
check( 'notice: lists counts, pause and link, no event content', str_contains( $mail['message'], '- 2 Läufe brauchen eine Entscheidung' ) && str_contains( $mail['message'], '- 1 Ereignisse sind fehlgeschlagen' ) && str_contains( $mail['message'], 'Notfallpause' ) && str_contains( $mail['message'], 'page=workflow-captain' ) );
check( 'settings: notify_daily is 0 or 1, default on', 1 === Settings::defaults()['notify_daily'] && 1 === Settings::sanitize( array( 'notify_daily' => 5 ) )['notify_daily'] && 0 === Settings::sanitize( array( 'notify_daily' => 0 ) )['notify_daily'] );

// ----- WF-SET-05: Action Scheduler or WP-Cron, never both ------------------------------------------------------

use WorkflowCaptain\Scheduler;
function fake_scheduler_api( bool $as, bool $wp_planned, bool $as_planned ): array {
	$log = new ArrayObject();
	return array(
		array(
			'as_available' => fn() => $as,
			'as_next'      => fn() => $as_planned,
			'as_schedule'  => function () use ( $log ) { $log[] = 'as_schedule'; },
			'wp_next'      => fn() => $wp_planned,
			'wp_schedule'  => function () use ( $log ) { $log[] = 'wp_schedule'; },
			'wp_clear'     => function () use ( $log ) { $log[] = 'wp_clear'; },
		),
		$log,
	);
}
list( $api, $log ) = fake_scheduler_api( true, true, false );
check( 'scheduler: with Action Scheduler the WP-Cron plan is cleared and AS planned', Scheduler::ACTION_SCHEDULER === Scheduler::ensure( $api ) && array( 'wp_clear', 'as_schedule' ) === $log->getArrayCopy() );
list( $api, $log ) = fake_scheduler_api( true, false, true );
check( 'scheduler: already planned in AS, nothing happens', Scheduler::ACTION_SCHEDULER === Scheduler::ensure( $api ) && array() === $log->getArrayCopy() );
list( $api, $log ) = fake_scheduler_api( false, false, false );
check( 'scheduler: without Action Scheduler WP-Cron is planned', Scheduler::WP_CRON === Scheduler::ensure( $api ) && array( 'wp_schedule' ) === $log->getArrayCopy() );

// ----- WF-SET-07: separate retention for runs -----------------------------------------------------------------

check( 'settings: separate run retention, at most 30', 30 === Settings::defaults()['retention_runs'] && 30 === Settings::sanitize( array( 'retention_runs' => 99 ) )['retention_runs'] );
$p = Settings::problems( array( 'batch' => '9999', 'max_site' => 'abc', 'max_workflow' => '3', 'notify_daily' => '1' ) );
check( 'WF-SET-13 settings: out-of-range and non-numeric values are reported per field, valid ones are not', array( 'batch', 'max_site' ) === array_keys( $p ) && array( '9999', 200, 1, 200 ) === $p['batch'] && 5 === $p['max_site'][1] );
check( 'WF-SET-13 settings: no problems for an untouched form', array() === Settings::problems( Settings::defaults() ) && array() === Settings::problems( 'x' ) );
check( 'WF-SET-13 settings: report callback stores the clamped values', 200 === Settings::sanitize_and_report( array( 'batch' => '9999' ) )['batch'] );
$GLOBALS['wfc_test_filters']['workflow_captain_retention_days'] = array( fn( $days, $group ) => 'runs' === $group ? 5 : $days );
$e = engine_site();
publish( $e, sample_graph() );
$e->consumer->capture( $e->adapter, checkin() );
drain( $e );
$e->clock->advance( 6 * DAY_IN_SECONDS );
( new \WorkflowCaptain\Privacy\Privacy( fn() => $e->events, $e->clock, fn() => $e->store ) )->apply_retention();
check( 'retention: runs after 5 days gone, the event (30 days) stays', array() === $e->store->find_runs( array(), 10, 0 ) && 1 === count( $e->events->find( array(), 10, 0 ) ) );
$GLOBALS['wfc_test_filters']['workflow_captain_retention_days'] = array();

// ----- WF-SET-13: audit trail of security-relevant changes
$log = array();
for ( $i = 0; $i < 60; $i++ ) {
	$log = \WorkflowCaptain\Audit::append( $log, 'settings', 1, array( 'n' => $i ), '2026-11-16T00:00:00Z' );
}
check( 'WF-SET-13 audit: newest first, at most 50 entries', 50 === count( $log ) && 59 === $log[0]['detail']['n'] );
check( 'WF-SET-13 audit: only changed setting keys with old and new value', array( 'batch' => array( 25, 50 ) ) === \WorkflowCaptain\Audit::changed( array( 'batch' => 25, 'max_site' => 5 ), array( 'batch' => 50, 'max_site' => 5 ) ) );

// ----- WF-SET-06: recipients, kinds, test mail
$cfg = Notifications::sanitize( array( 'recipients' => 'a@example.org, kaputt, B@example.org; a@example.org', 'kinds' => array( 'failed', 'evil' ) ) );
check( 'WF-SET-06 notify: valid unique recipients, unknown kinds dropped', 'a@example.org, b@example.org' === $cfg['recipients'] && array( 'failed' ) === $cfg['kinds'] );
check( 'WF-SET-06 notify: fallback to the site address, all kinds by default', array( 'admin@x' ) === Notifications::recipients( Notifications::sanitize( array() ), 'admin@x' ) && Notifications::KINDS === Notifications::sanitize( array() )['kinds'] );
$only = Notifications::digest( array( 'runs' => array( 'action_required' => 2 ), 'events' => array( 'dead_letter' => 1 ), 'stuck' => array(), 'adapters' => array( 'refused' => array( 'pass-captain' => 'contract_too_old' ) ), 'environment' => array() ), 'u', array( 'failed', 'providers' ) );
check( 'WF-SET-06 notify: only chosen kinds, refused providers reported', null !== $only && ! str_contains( $only['message'], 'Entscheidung' ) && str_contains( $only['message'], 'Provider' ) && str_contains( $only['message'], 'fehlgeschlagen' ) );
check( 'WF-SET-06 notify: no kinds chosen, no mail', null === Notifications::digest( array( 'runs' => array( 'action_required' => 2 ) ), 'u', array() ) );
check( 'WF-SET-06 notify: test mail carries no event data', ! str_contains( Notifications::test_mail( 'Shop' )['message'], '{{' ) );


// ----- WF-SET-05: time budget per worker run
check( 'WF-SET-05 settings: runtime per worker run 5 to 120 seconds, default 20', 20 === Settings::defaults()['max_seconds'] && 120 === Settings::sanitize( array( 'max_seconds' => 999 ) )['max_seconds'] && 5 === Settings::sanitize( array( 'max_seconds' => 1 ) )['max_seconds'] );

// ----- WF-SET-02: templates page and help page
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
$tpl = \WorkflowCaptain\Admin\TemplatesPage::templates( glob( WORKFLOW_CAPTAIN_PATH . 'assets/recipes/*.json' ) );
check( 'WF-SET-02 templates: all shipped recipes listed with name and trigger', 4 === count( $tpl ) && 'pass.ticket.checked_in' === $tpl['einlass-hinweis']['event'] );
$same = true;
foreach ( glob( WORKFLOW_CAPTAIN_PATH . 'docs/recipes/*.json' ) as $doc ) {
	$same = $same && is_file( WORKFLOW_CAPTAIN_PATH . 'assets/recipes/' . basename( $doc ) ) && file_get_contents( $doc ) === file_get_contents( WORKFLOW_CAPTAIN_PATH . 'assets/recipes/' . basename( $doc ) );
}
check( 'WF-SET-02 templates: shipped copies equal docs/recipes', $same && count( glob( WORKFLOW_CAPTAIN_PATH . 'docs/recipes/*.json' ) ) === count( glob( WORKFLOW_CAPTAIN_PATH . 'assets/recipes/*.json' ) ) );
$bad = tempnam( sys_get_temp_dir(), 'wfc' );
file_put_contents( $bad, '{"format":"other"}' );
check( 'WF-SET-02 templates: files in another format are skipped', array() === \WorkflowCaptain\Admin\TemplatesPage::templates( array( $bad, '/does/not/exist.json' ) ) );
unlink( $bad );
$wf_before = count( $e->store->list_workflows( 1000, 0 ) );
$imported  = \WorkflowCaptain\Engine\Transfer::import( (string) file_get_contents( $tpl['einlass-hinweis']['file'] ), $e->workflows );
check( 'WF-SET-02 templates: a template imports as a draft', $imported['ok'] && $wf_before + 1 === count( $e->store->list_workflows( 1000, 0 ) ) );
check( 'WF-SET-02 help: four sections with text', 4 === count( \WorkflowCaptain\Admin\HelpPage::sections() ) );

// ----- WF-CORE-02: run snapshot holds the contract version of each provider
final class PassWriteAction implements \WorkflowCaptain\Engine\Action {
	public int $runs = 0;
	public function key(): string { return 'pass.test_write'; }
	public function label(): string { return 'test'; }
	public function capability(): ?string { return null; }
	public function idempotent(): bool { return true; }
	public function validate_config( array $config ): array { return array(); }
	public function execute( \WorkflowCaptain\Engine\ActionContext $context ): array { ++$this->runs; return array( 'ok' => 1 ); }
}
$contracts_now = array( 'pass-captain' => '1.1.0', 'voucher-captain' => '1.0.0' );
$contracts     = static function () use ( &$contracts_now ) { return $contracts_now; };
$e             = engine_site();
$pass_action   = new PassWriteAction();
$e->actions->add( $pass_action );
$e->workflows = new Workflows( $e->store, $e->actions, $e->dir, $e->clock, null, $contracts );
$e->engine->set_provider_contracts( $contracts );
$g             = sample_graph();
$g['nodes'][3] = array( 'id' => 'tag', 'type' => 'action', 'action' => 'pass.test_write', 'config' => array() );
check( 'WF-CORE-02 snapshot: trigger and action providers with their contract, core actions left out', array( 'pass-captain' => '1.1.0' ) === $e->workflows->provider_snapshot( $g ) );
$wid = publish( $e, $g );
$ver = $e->store->get_version( (int) $e->store->get_workflow( $wid )['published_version_id'] );
check( 'WF-CORE-02 snapshot: stored in the published version', array( 'pass-captain' => '1.1.0' ) === ( $ver['graph']['providers'] ?? null ) );
$contracts_now['pass-captain'] = '1.4.0';
$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'minor', array( 'checkin_source' => 'scanner' ) ) );
drain( $e );
check( 'WF-CORE-02 a MINOR change of the provider keeps the run going', 1 === $pass_action->runs );
$contracts_now['pass-captain'] = '2.0.0';
$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'major', array( 'checkin_source' => 'scanner' ) ) );
drain( $e );
$paused = $e->store->find_runs( array( 'state' => \WorkflowCaptain\Engine\EngineStore::RUN_PAUSED ), 10, 0 );
check( 'WF-CORE-02 a MAJOR change pauses the run before the provider action, with a reason', 1 === $pass_action->runs && 1 === count( $paused ) && 'provider_contract_changed' === $paused[0]['last_error'] );
unset( $contracts_now['pass-captain'] );
check( 'WF-CORE-02 helper: product of a prefix, connectors included', 'woocommerce' === \WorkflowCaptain\Envelope\Validator::product_for_prefix( 'woocommerce' ) && 'starter-connector' === \WorkflowCaptain\Envelope\Validator::product_for_prefix( 'xstarterconnector', array( 'starter-connector' ) ) && null === \WorkflowCaptain\Envelope\Validator::product_for_prefix( 'workflow' ) );

// ----- WF-CORE-04: recurring schedule triggers
use WorkflowCaptain\Engine\Schedule;
$ts = static fn( string $local, string $tz = 'Europe/Berlin' ) => ( new DateTimeImmutable( $local, new DateTimeZone( $tz ) ) )->getTimestamp();
$daily  = array( 'frequency' => 'daily', 'time' => '08:00' );
check( 'WF-CORE-04 schedule: config checked', array() === Schedule::violations( $daily ) && in_array( 'schedule:invalid_time', Schedule::violations( array( 'frequency' => 'daily', 'time' => '25:00' ) ), true ) && in_array( 'schedule:invalid_day', Schedule::violations( array( 'frequency' => 'monthly', 'time' => '08:00', 'day' => 31 ) ), true ) && in_array( 'schedule:invalid_weekday', Schedule::violations( array( 'frequency' => 'weekly', 'time' => '08:00' ) ), true ) );
check( 'WF-CORE-04 schedule: daily, latest due time in local time', $ts( '2026-11-16 08:00' ) === Schedule::last_due( $daily, $ts( '2026-11-16 09:30' ), $ts( '2026-11-15 12:00' ), 'Europe/Berlin' ) );
check( 'WF-CORE-04 schedule: nothing due before the time', null === Schedule::last_due( $daily, $ts( '2026-11-16 07:59' ), $ts( '2026-11-15 08:30' ), 'Europe/Berlin' ) );
check( 'WF-CORE-04 schedule: missed times fire at most once (only the latest)', $ts( '2026-11-16 08:00' ) === Schedule::last_due( $daily, $ts( '2026-11-16 09:00' ), $ts( '2026-11-10 00:00' ), 'Europe/Berlin' ) );
check( 'WF-CORE-04 schedule: 08:00 local stays 08:00 across the DST change', $ts( '2026-10-26 08:00' ) - $ts( '2026-10-24 08:00' ) === 2 * 86400 + 3600 && $ts( '2026-10-26 08:00' ) === Schedule::last_due( $daily, $ts( '2026-10-26 08:01' ), $ts( '2026-10-25 09:00' ), 'Europe/Berlin' ) );
$gap = Schedule::last_due( array( 'frequency' => 'daily', 'time' => '02:30' ), $ts( '2026-03-29 12:00' ), $ts( '2026-03-28 12:00' ), 'Europe/Berlin' );
check( 'WF-CORE-04 schedule: a time in the spring gap moves forward (02:30 → 03:30)', '03:30' === ( new DateTimeImmutable( '@' . $gap ) )->setTimezone( new DateTimeZone( 'Europe/Berlin' ) )->format( 'H:i' ) );
check( 'WF-CORE-04 schedule: weekly on Monday', $ts( '2026-11-16 08:00' ) === Schedule::last_due( array( 'frequency' => 'weekly', 'time' => '08:00', 'weekday' => 1 ), $ts( '2026-11-18 10:00' ), $ts( '2026-11-10 00:00' ), 'Europe/Berlin' ) );
check( 'WF-CORE-04 schedule: monthly on the 1st', $ts( '2026-11-01 08:00' ) === Schedule::last_due( array( 'frequency' => 'monthly', 'time' => '08:00', 'day' => 1 ), $ts( '2026-11-18 10:00' ), $ts( '2026-10-15 00:00' ), 'Europe/Berlin' ) );

$e  = engine_site();
$e->registry->add_core( new \WorkflowCaptain\Providers\ScheduleAdapter() );
$sg = array(
	'nodes' => array(
		array( 'id' => 't', 'type' => 'trigger', 'event' => 'workflow.schedule.due', 'schedule' => array( 'frequency' => 'daily', 'time' => '08:00' ) ),
		array( 'id' => 'mail', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Täglich', 'message' => 'fällig {{data.scheduled_for}}' ) ),
		array( 'id' => 'e', 'type' => 'end' ),
	),
	'edges' => array( array( 'from' => 't', 'to' => 'mail' ), array( 'from' => 'mail', 'to' => 'e' ) ),
);
$bad_sg = $sg;
unset( $bad_sg['nodes'][0]['schedule'] );
check( 'WF-CORE-04 schedule: graph needs a valid schedule', in_array( 'node:t:schedule:missing', Graph::violations( $bad_sg, $e->actions ), true ) && array() === Graph::violations( $sg, $e->actions ) );
$w1 = publish( $e, $sg, 'Täglich A' );
$w2 = publish( $e, $sg, 'Täglich B' );
$e->clock->time = strtotime( '2025-11-17 09:00:00 UTC' );
$due = $e->engine->due_schedules( array() );
check( 'WF-CORE-04 schedule: one due event per scheduled workflow', 2 === count( $due ) );
foreach ( $due as $d ) {
	$e->consumer->capture( $e->registry->get( 'workflow-captain' ), $d['event'] );
	$e->consumer->capture( $e->registry->get( 'workflow-captain' ), $d['event'] ); // doubled timer
}
drain( $e );
$runs = $e->store->find_runs( array(), 10, 0 );
check( 'WF-CORE-04 schedule: each event starts only its own workflow, doubled timer starts one run', 2 === count( $runs ) && array( $w1, $w2 ) === array_values( array_unique( array_map( 'intval', array_column( array_reverse( $runs ), 'workflow_id' ) ) ) ) );
$fired = array( $w1 => $due[0]['due'], $w2 => $due[1]['due'] );
check( 'WF-CORE-04 schedule: not due again until the next time', array() === $e->engine->due_schedules( $fired ) );
// Found in the E2E run (docs/E2E-RESULTS.md): "wp workflow-captain process", the documented system-cron replacement for
// WP-Cron, skipped the schedule tick, so schedule triggers never fired on sites with DISABLE_WP_CRON.
$wfc_cli    = (string) file_get_contents( __DIR__ . '/../src/Cli/Command.php' );
$wfc_plugin = (string) file_get_contents( __DIR__ . '/../src/Plugin.php' );
check( 'WF-CORE-04 schedule: "wp workflow-captain process" runs the shared worker pass that ticks schedules', str_contains( $wfc_cli, 'Plugin::instance()->work(' ) && ! str_contains( $wfc_cli, '->consumer()->process(' ) && 1 === preg_match( '/function work\(.*?\$this->tick_schedules\(\);.*?->consumer\(\)->process\(/s', $wfc_plugin ) && 1 === preg_match( '/function run_worker\(.*?\$this->work\(/s', $wfc_plugin ) );

// ----- WF-INT-01: action catalog matches the action classes of the core
$acat = json_decode( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'docs/catalog/actions-v1.json' ), true );
$e    = engine_site();
$ok   = true;
foreach ( $acat['actions'] as $row ) {
	if ( 'workflow-captain' !== $row['owner'] ) {
		continue;
	}
	$a  = $e->actions->get( $row['key'] );
	$ok = $ok && null !== $a && $a->capability() === $row['capability'] && $a->idempotent() === $row['idempotent'];
}
$core_keys = array_column( array_filter( $acat['actions'], fn( $r ) => 'workflow-captain' === $r['owner'] ), 'key' );
check( 'WF-INT-01 action catalog: every core action listed with capability and idempotency as in the code', $ok && 5 === count( $core_keys ) );
$engine_code = (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Engine/Engine.php' );
check( 'WF-INT-01 action catalog: every documented pause reason exists in the engine', array() === array_filter( $acat['pause_reasons'], fn( $r ) => ! str_contains( $engine_code, "'" . $r . "'" ) ) );

// ----- WF-INT-14: test event per provider (test run without side effects)
$e   = engine_site();
publish( $e, sample_graph() );
$smp = \WorkflowCaptain\Admin\ProvidersPage::sample_event( 'pass.ticket.checked_in', array( 'checkin_source' => 'string', 'device_id' => 'integer', 'reentry' => 'boolean' ) );
check( 'WF-INT-14 sample event: values per data type, no personal data', 'beispiel' === $smp['data']['checkin_source'] && 1 === $smp['data']['device_id'] && false === $smp['data']['reentry'] );
$rep = \WorkflowCaptain\Admin\ProvidersPage::test_event( 'pass.ticket.checked_in', array( 'checkin_source' => 'string' ), $e->store, $e->actions );
check( 'WF-INT-14 test event: every active workflow of the type runs as a test run, nothing executed', 1 === count( $rep ) && 0 === count( $e->store->find_runs( array(), 10, 0 ) ) && 0 === count( $e->dir->mails ?? array() ) );

// ----- 4.10: inbound triggers and the processed-event hook for add-ons (webhooks, n8n)
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['wfc_test_filters'][ $hook ] ?? array() as $callback ) {
			$callback( ...$args );
		}
	}
}
use WorkflowCaptain\Engine\InboundTriggers;
add_filter( 'workflow_captain_inbound_triggers_v1', fn( $t ) => $t + array( 'order_export' => array( 'label' => 'Export', 'fields' => array( 'order_id' => 'integer', 'status' => 'string', 'customer_email' => 'string', 'bad' => 'object' ) ), 'Bad Key' => array( 'fields' => array() ) ) );
$decl = InboundTriggers::declared();
check( '4.10 inbound: declared triggers checked, forbidden field names and unknown types dropped', array( 'order_export' ) === array_keys( $decl ) && array( 'order_id' => 'integer', 'status' => 'string' ) === $decl['order_export']['fields'] );
$ev = InboundTriggers::event( 'order_export', array( 'order_id' => 5, 'status' => 'kunde@example.com', 'extra' => 1 ), 'req-12345678' );
check( '4.10 inbound: only declared fields with the right type, no addresses', array( 'order_id' => 5 ) === $ev['data'] && 'workflow.inbound.order_export' === $ev['type'] && 'req-12345678' === $ev['discriminator'] );
check( '4.10 inbound: unknown trigger or bad request id refused', null === InboundTriggers::event( 'nope', array(), 'req-12345678' ) && null === InboundTriggers::event( 'order_export', array(), 'x' ) );
$e = engine_site();
$e->registry->add_core( new \WorkflowCaptain\Providers\ScheduleAdapter() );
$ig = array(
	'nodes' => array(
		array( 'id' => 't', 'type' => 'trigger', 'event' => 'workflow.inbound.order_export' ),
		array( 'id' => 'g', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'export' ) ),
		array( 'id' => 'e', 'type' => 'end' ),
	),
	'edges' => array( array( 'from' => 't', 'to' => 'g' ), array( 'from' => 'g', 'to' => 'e' ) ),
);
publish( $e, $ig );
$processed = array();
add_action( 'workflow_captain_event_processed_v1', function ( $env, $id ) use ( &$processed ) { $processed[] = $env['type']; } );
$first  = $e->consumer->capture_inbound( 'order_export', array( 'order_id' => 7 ), 'req-abcdefgh' );
$second = $e->consumer->capture_inbound( 'order_export', array( 'order_id' => 7 ), 'req-abcdefgh' );
drain( $e );
check( '4.10 inbound: stored once per request id, starts the workflow', 'captured' === $first && 'captured' !== $second && 1 === count( $e->store->find_runs( array(), 10, 0 ) ) );
check( '4.10 hook: workflow_captain_event_processed_v1 fires once after processing', array( 'workflow.inbound.order_export' ) === $processed );
check( '4.10 inbound: unknown trigger reported', 'unknown_trigger' === $e->consumer->capture_inbound( 'nope', array(), 'req-abcdefgh' ) );
// ----- Spec section 17: runs from another site of the network (ForeignRuns, WF-MS-10, -15, -21, R-17.3 to R-17.7) -----

use WorkflowCaptain\Engine\ForeignRuns;

$shared = FakeWpdb::memory();
$src    = engine_site( 'UTC', $shared, 'wp_', 1 );
$dst    = engine_site( 'UTC', $shared, 'wp_3_', 3 );
$src->consumer->capture( $src->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'net-1', array( 'customer_user_id' => 17 ) ) );
$source_env = $src->events->find( array(), 1, 0 )[0]['envelope'];
$source_env['chain_depth'] = 1; // As if a workflow on the source site had caused it.
$fgraph = array(
	'nodes' => array(
		array( 'id' => 't', 'type' => 'trigger', 'event' => 'pass.ticket.checked_in' ),
		array( 'id' => 'a1', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Netz {{subject.id}}', 'message' => 'Von Website 1' ) ),
		array( 'id' => 'a2', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'netz' ) ),
		array( 'id' => 'e', 'type' => 'end' ),
	),
	'edges' => array( array( 'from' => 't', 'to' => 'a1' ), array( 'from' => 'a1', 'to' => 'a2' ), array( 'from' => 'a2', 'to' => 'e' ) ),
);
$wrapper = array( 'network_id' => 1, 'source_blog_id' => 1, 'workflow_id' => 12, 'version' => 2, 'run_id' => 40 );
$fkey    = hash( 'sha256', 'network-run-40|target-3' );
$q       = ForeignRuns::enqueue( $dst->store, $fgraph, $source_env, $wrapper, $fkey, 'network:12', 'Netzwerk: Test', $dst->clock->mysql() );
$frun    = $dst->store->get_run( $q['run_id'] );
check( 'R-17.4 foreign run: queued on the target site only, with one first step', $q['created'] && null !== $frun && array() === $src->store->find_runs( array(), 10, 0 ) && 1 === count( $dst->store->steps_for_run( $q['run_id'] ) ) );
check( 'R-17.3 foreign run: actor_user_id never reaches the target snapshot', ! array_key_exists( 'actor_user_id', $frun['context'] ) && null === $frun['actor_user_id'] && isset( $source_env['actor_user_id'] ) );
check( 'R-17.5 foreign run: network IDs in their own wrapper next to the snapshot, not inside it', $wrapper === $frun['network'] && ! array_key_exists( 'network', $frun['context'] ) && $frun['context']['id'] === $source_env['id'] );
$q2 = ForeignRuns::enqueue( $dst->store, $fgraph, $source_env, $wrapper, $fkey, 'network:12', 'Netzwerk: Test', $dst->clock->mysql() );
check( 'WF-MS-15 foreign run: a repeated delivery with the same key creates nothing', ! $q2['created'] && $q2['run_id'] === $q['run_id'] && 1 === count( $dst->store->steps_for_run( $q['run_id'] ) ) );
check( 'foreign run: the anchor workflow is not listed and cannot be edited, published or archived', array() === $dst->store->list_workflows( 10, 0 ) && array( 'workflow:network' ) === $dst->workflows->publish( $q['anchor'], 1 ) && array( 'workflow:network' ) === $dst->workflows->save( $q['anchor'], 'x', sample_graph() )[1] && ! $dst->workflows->archive( $q['anchor'] ) && ! $dst->workflows->resume( $q['anchor'] ) && 0 === $dst->workflows->duplicate( $q['anchor'] ) );
check( 'foreign run: consistency check does not report it as a run without version', array() === array_filter( $dst->store->consistency() ) );
$bad = false;
try {
	ForeignRuns::enqueue( $dst->store, $fgraph, $source_env, $wrapper + array( 'target' => 'all' ), hash( 'sha256', 'x' ), 'network:12', 'x', $dst->clock->mysql() );
} catch ( \InvalidArgumentException $ex ) {
	$bad = true;
}
check( 'R-17.5 foreign run: a wrapper with other keys is refused', $bad );

// Without an add-on nobody resolves the run: it pauses, nothing runs.
drain( $dst );
check( 'foreign run: without a resolver the step pauses (foreign_unavailable)', EngineStore::RUN_PAUSED === $dst->store->get_run( $q['run_id'] )['state'] && 'foreign_unavailable' === $dst->store->get_run( $q['run_id'] )['last_error'] && array() === $dst->dir->sent );

$seen    = array();
$consent = null;
$dst->engine->foreign()->set_resolver( fn( array $n ) => 12 === $n['workflow_id'] ? array( 'graph' => $fgraph, 'version' => 2, 'authorized_by_user_id' => 1 ) : null );
$dst->engine->foreign()->set_gate( function ( array $n, array $run, array $node ) use ( &$consent ) { return $consent; } );
$dst->engine->foreign()->set_observer( function ( array $run, string $state, ?string $reason ) use ( &$seen ) { $seen[] = $state . ( null === $reason ? '' : ':' . $reason ); } );
$consent = 'paused_site_consent';
check( 'foreign run: a paused run is resumed by a human', 1 === ForeignRuns::resume( $dst->store, $fkey, $dst->clock->mysql() ) );
drain( $dst );
check( 'R-17.2 foreign run: the gate pauses the step with its reason (revoked consent)', 'paused_site_consent' === $dst->store->get_run( $q['run_id'] )['last_error'] && array() === $dst->dir->sent && in_array( 'paused:paused_site_consent', $seen, true ) );
$consent = null;
ForeignRuns::resume( $dst->store, $fkey, $dst->clock->mysql() );
drain( $dst );
check( 'R-17.4 foreign run: runs on the target site with its own engine and actions', EngineStore::RUN_SUCCEEDED === $dst->store->get_run( $q['run_id'] )['state'] && 1 === count( $dst->dir->sent ) && array( 'netz' ) === $dst->store->tags_for( 'pass-captain', 'ticket', '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' ) && array() === $src->store->tags_for( 'pass-captain', 'ticket', '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' ) );
check( 'R-17.6 foreign run: the observer hears running and succeeded', in_array( 'running', $seen, true ) && 'succeeded' === end( $seen ) );
check( 'WF-MS-15 foreign run: step keys derive from network run + target + node', ForeignRuns::step_key( $fkey, 'a1' ) === $dst->store->steps_for_run( $q['run_id'] )[0]['step_key'] );
check( 'foreign run: find() reads run and steps; cancel of a finished run does nothing', 3 === count( ForeignRuns::find( $dst->store, $fkey )['steps'] ) && ! ForeignRuns::cancel( $dst->store, $fkey, $dst->clock->mysql() ) );

// Only network-safe actions run (R-17.4); notify.user only for a member of the target site.
$g2 = $fgraph;
$g2['nodes'][1] = array( 'id' => 'a1', 'type' => 'action', 'action' => 'test.write', 'config' => array() );
$g3 = array();
$dst->engine->foreign()->set_resolver( function ( array $n ) use ( &$g2, &$g3 ) { return array( 'graph' => 13 === $n['workflow_id'] ? $g2 : $g3, 'version' => 1, 'authorized_by_user_id' => 1 ); } );
$dst->dir->caps[1][] = 'vcap_manage_vouchers';
$k2 = hash( 'sha256', 'network-run-41|target-3' );
$q3 = ForeignRuns::enqueue( $dst->store, $g2, $source_env, array( 'workflow_id' => 13 ) + $wrapper, $k2, 'network:13', 'Netzwerk: B', $dst->clock->mysql() );
drain( $dst );
check( 'R-17.4 foreign run: an action that is not network-safe pauses (action_not_network_safe)', 'action_not_network_safe' === $dst->store->get_run( $q3['run_id'] )['last_error'] && 0 === $dst->cap->runs );
$g3 = $fgraph;
$g3['nodes'][1] = array( 'id' => 'a1', 'type' => 'action', 'action' => 'notify.user', 'config' => array( 'subject' => 'Hallo', 'message' => 'Text', 'user_field' => 'data.customer_user_id' ) );
$sent_before = count( $dst->dir->sent );
$dst->dir->members = array( 1 );
$k3 = hash( 'sha256', 'network-run-42|target-3' );
$q4 = ForeignRuns::enqueue( $dst->store, $g3, $source_env, array( 'workflow_id' => 14 ) + $wrapper, $k3, 'network:14', 'Netzwerk: C', $dst->clock->mysql() );
drain( $dst );
check( 'R-17.4 notify.user: a user who is no member of the target site is not addressed', 'user_not_member' === $dst->store->get_run( $q4['run_id'] )['last_error'] && $sent_before === count( $dst->dir->sent ) );
$dst->dir->members = array( 1, 17 );
ForeignRuns::resume( $dst->store, $k3, $dst->clock->mysql() );
drain( $dst );
check( 'R-17.4 notify.user: a member of the target site gets the message', $sent_before + 1 === count( $dst->dir->sent ) && 'person@example.com' === end( $dst->dir->sent )[0] );
check( 'network_safe in the action catalog matches the classes', array() === array_filter( $acat['actions'], fn( $r ) => 'workflow-captain' === $r['owner'] && ( $e->actions->get( $r['key'] ) instanceof \WorkflowCaptain\Engine\NetworkSafe ) !== ( true === ( $r['network_safe'] ?? null ) ) ) );

// R-17.7: an event caused by a step on the target site continues the chain of the source site.
$probe = new class( $dst ) implements \WorkflowCaptain\Engine\Action, \WorkflowCaptain\Engine\NetworkSafe {
	public function __construct( private object $site ) {}
	public function key(): string { return 'test.capture'; }
	public function label(): string { return 'probe'; }
	public function capability(): ?string { return null; }
	public function idempotent(): bool { return true; }
	public function validate_config( array $config ): array { return array(); }
	public function network_check( \WorkflowCaptain\Engine\ActionContext $context ): ?string { return null; }
	public function execute( \WorkflowCaptain\Engine\ActionContext $context ): array {
		$this->site->consumer->capture( $this->site->adapter, checkin( '7e2b0c9d8e6a3f1e5b4c0d9a8b7e6f5c', 'caused-on-3' ) );
		return array();
	}
};
$dst->actions->add( $probe );
$g4 = $fgraph;
$g4['nodes'][1] = array( 'id' => 'a1', 'type' => 'action', 'action' => 'test.capture', 'config' => array() );
$dst->engine->foreign()->set_resolver( fn( array $n ) => array( 'graph' => $g4, 'version' => 1, 'authorized_by_user_id' => 1 ) );
$k4 = hash( 'sha256', 'network-run-43|target-3' );
$q5 = ForeignRuns::enqueue( $dst->store, $g4, $source_env, array( 'workflow_id' => 15 ) + $wrapper, $k4, 'network:15', 'Netzwerk: D', $dst->clock->mysql() );
$dst->engine->process( 10 );
$caused = null;
foreach ( $dst->events->find( array(), 20, 0 ) as $row ) {
	if ( '7e2b0c9d8e6a3f1e5b4c0d9a8b7e6f5c' === $row['envelope']['subject']['id'] ) {
		$caused = $row['envelope'];
	}
}
check( 'R-17.7 caused event on the target: chain depth counts across sites, correlation of the source event', null !== $caused && 2 === $caused['chain_depth'] && $source_env['correlation_id'] === $caused['correlation_id'] && 3 === $caused['site']['blog_id'] );
check( 'R-7.14 caused event on the target: caused_by_workflow_id is the anchor, which no local workflow has', null !== $caused && $q5['anchor'] === $caused['caused_by_workflow_id'] && EngineStore::WF_NETWORK === $dst->store->get_workflow( $q5['anchor'] )['status'] );
check( 'anchors: one per origin, reused', $q['anchor'] === ForeignRuns::enqueue( $dst->store, $fgraph, $source_env, $wrapper, hash( 'sha256', 'other' ), 'network:12', 'Netzwerk: Test', $dst->clock->mysql() )['anchor'] );

// A foreign step whose action failed temporarily until the plan ran out: a human continues it without confirmation.
$dst->engine->foreign()->set_resolver( fn( array $n ) => array( 'graph' => $fgraph, 'version' => 2, 'authorized_by_user_id' => 1 ) );
$dst->dir->fail_mails = 100;
$k5  = hash( 'sha256', 'network-run-44|target-3' );
$q6  = ForeignRuns::enqueue( $dst->store, $fgraph, $source_env, array( 'workflow_id' => 16 ) + $wrapper, $k5, 'network:16', 'Netzwerk: E', $dst->clock->mysql() );
for ( $i = 0; $i < 10; $i++ ) {
	$dst->engine->process( 10 );
	$dst->clock->advance( DAY_IN_SECONDS );
}
$st6 = $dst->store->steps_for_run( $q6['run_id'] )[0];
check( 'R-8.12 after the last temporary failure the effect booking is cleared (the action said nothing happened)', EngineStore::STEP_ACTION_REQUIRED === $st6['state'] && EngineStore::EFFECT_NONE === $st6['effect'] );
$dst->dir->fail_mails = 0;
$sent_before          = count( $dst->dir->sent );
check( 'foreign run: resume also requeues a step that needs a decision', 1 === ForeignRuns::resume( $dst->store, $k5, $dst->clock->mysql() ) );
drain( $dst );
check( 'foreign run: the resumed step runs once', EngineStore::RUN_SUCCEEDED === $dst->store->get_run( $q6['run_id'] )['state'] && $sent_before + 1 === count( $dst->dir->sent ) );

// ----- WF-CORE-10: reconciliation with the providers (read only, optional requeue)
$e = engine_site();
$e->adapter->reread = array( 'pass.ticket.checked_in' );
$keep = $e->events->insert( array_merge( checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'r1' ), array( 'id' => \WorkflowCaptain\Envelope\Uuid::v4(), 'idempotency_key' => str_repeat( 'a', 64 ), 'site' => array( 'blog_id' => 1 ), 'source' => array( 'product' => 'pass-captain', 'core_version' => '1.5.0', 'contract_version' => '1.1.0' ) ) ), \WorkflowCaptain\Store\EventStore::DEAD_LETTER, $e->clock->mysql() );
$rows = $e->events->find( array( 'state' => 'dead_letter' ), 10, 0 );
$rec  = new \WorkflowCaptain\Consumer\Reconcile( $e->events, $e->registry, $e->consumer );
$res  = $rec->run();
check( 'WF-CORE-10 reconcile: read only by default, counts holding events', 1 === $res['checked'] && 1 === $res['holds'] && 0 === $res['requeued'] && 1 === count( $e->events->find( array( 'state' => 'dead_letter' ), 10, 0 ) ) );
$e->adapter->reread_result = false;
$res = $rec->run( true );
check( 'WF-CORE-10 reconcile: changed at the provider is reported, never requeued', 1 === $res['changed'] && 0 === $res['requeued'] && array( (int) $rows[0]['id'] ) === $res['changed_ids'] );
$e->adapter->reread_result = true;
$res = $rec->run( true );
check( 'WF-CORE-10 reconcile: with --requeue a holding dead_letter event goes back into the queue', 1 === $res['requeued'] && 0 === count( $e->events->find( array( 'state' => 'dead_letter' ), 10, 0 ) ) );

// ----- Suite admins: status of Workflow Captain for one product (counts and links only)
$e = engine_site();
publish( $e, sample_graph() );
$e->consumer->capture( $e->adapter, checkin() );
$st = \WorkflowCaptain\SuiteStatus::for_product( 'pass-captain', $e->registry, $e->events, $e->store, fn( $page, $args = array() ) => $page . '?' . http_build_query( $args ) );
check( 'suite status: adapter, contract, active workflows, events and last correlation id for the product', 'registered' === $st['adapter'] && '1.1.0' === $st['contract_version'] && 1 === $st['active_workflows'] && 1 === array_sum( $st['events'] ) && is_string( $st['last_correlation_id'] ) );
check( 'suite status: unknown product is missing with zero counts, links present', 'missing' === \WorkflowCaptain\SuiteStatus::for_product( 'voucher-captain', $e->registry, $e->events, $e->store, fn( $p, $a = array() ) => $p )['adapter'] && isset( $st['links']['runs'] ) );
check( 'suite status: no event data or personal data in the result', ! str_contains( json_encode( $st ), 'checkin_source' ) && ! str_contains( json_encode( $st ), '@' ) );
