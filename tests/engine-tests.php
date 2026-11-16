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
function engine_site( string $tz = 'UTC' ): object {
	$pdo = FakeWpdb::memory();
	$db  = new FakeWpdb( $pdo );
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
	$e->consumer  = new Consumer( $e->events, $e->registry, $e->engine, $e->clock, 1 );
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
check( 'settings: engine limits mapped', array( 'site' => 5, 'workflow' => 2, 'catch_up' => 86400 ) === Settings::engine_limits( Settings::defaults() ) );
$e = new Engine( new WpdbEngineStore( new FakeWpdb( FakeWpdb::memory() ) ), new ActionRegistry(), new FakeDirectory(), new FixedClock(), 'UTC', array( 'site' => 9, 'bogus' => 3, 'workflow' => 0 ) );
check( 'engine: limits taken from settings, invalid ignored', array( 'site' => 9, 'workflow' => 2, 'catch_up' => 86400 ) === $e->limits() );

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
