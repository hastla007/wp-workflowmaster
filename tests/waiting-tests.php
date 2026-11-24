<?php
/**
 * Tests of Suspend, Halt and Waiting (contract 0.2.0): an action holds its run and continues it later, or ends it
 * regularly (included by tests/run.php; uses engine_site() and the helpers of tests/engine-tests.php).
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Engine\ActionContext;
use WorkflowCaptain\Engine\DryRun;
use WorkflowCaptain\Engine\Engine;
use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Engine\ForeignRuns;
use WorkflowCaptain\Engine\Halt;
use WorkflowCaptain\Engine\Suspend;
use WorkflowCaptain\Engine\Waiting;

/** An action whose next calls throw what $plan says ("suspend", "suspend:<seconds>", "halt"); otherwise it succeeds. */
final class HoldAction implements \WorkflowCaptain\Engine\Action, \WorkflowCaptain\Engine\NetworkSafe {
	public array $plan  = array();
	public array $calls = array();
	public function __construct( private string $key = 'test.hold', private ?\Closure $now = null ) {}
	public function key(): string { return $this->key; }
	public function label(): string { return 'hold'; }
	public function capability(): ?string { return null; }
	public function idempotent(): bool { return false; }
	public function validate_config( array $config ): array { return array(); }
	public function network_check( ActionContext $context ): ?string { return null; }
	public function execute( ActionContext $context ): array {
		$this->calls[] = array( 'step' => $context->step_id, 'key' => $context->idempotency_key );
		$next          = array_shift( $this->plan );
		if ( 'halt' === $next ) {
			throw new Halt( 'limit_reached', array( 'count' => 3, 'mail' => 'person@example.com' ) );
		}
		if ( null !== $next && str_starts_with( $next, 'suspend' ) ) {
			$seconds = (int) substr( $next, 8 );
			throw new Suspend( 'needs_release', $seconds > 0 ? ( $this->now )() + $seconds : null );
		}
		return array( 'released' => 1 );
	}
}

/** Trigger -> hold action -> tag -> end. */
function hold_graph( string $action = 'test.hold' ): array {
	return array(
		'nodes' => array(
			array( 'id' => 't', 'type' => 'trigger', 'event' => 'pass.ticket.checked_in' ),
			array( 'id' => 'h', 'type' => 'action', 'action' => $action, 'config' => array() ),
			array( 'id' => 'g', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'freigegeben' ) ),
			array( 'id' => 'e', 'type' => 'end' ),
		),
		'edges' => array( array( 'from' => 't', 'to' => 'h' ), array( 'from' => 'h', 'to' => 'g' ), array( 'from' => 'g', 'to' => 'e' ) ),
	);
}

/** A site with the hold action, one published hold workflow and one started run, worked until it holds. */
function hold_site( array $plan ): object {
	$e        = engine_site();
	$e->hold  = new HoldAction( 'test.hold', fn() => $e->clock->now() );
	$e->other = new HoldAction( 'test.other', fn() => $e->clock->now() );
	$e->hold->plan = $plan;
	$e->actions->add( $e->hold );
	$e->actions->add( $e->other );
	$e->wfid    = publish( $e, hold_graph() );
	$e->waiting = $e->engine->waiting();
	$e->consumer->capture( $e->adapter, checkin() );
	drain( $e );
	return $e;
}

function hold_step( object $e ): array {
	foreach ( steps_of( $e ) as $step ) {
		if ( 'h' === $step['node_id'] ) {
			return $step;
		}
	}
	return array();
}

echo "\n# Contract 0.2.0: Suspend, Halt, Waiting (waiting runs)\n";

// ----- Suspend without a time ----------------------------------------------------------------------------------

$e    = hold_site( array( 'suspend' ) );
$step = hold_step( $e );
$run  = run_of( $e );
check( 'Suspend: step waits, no attempt counted, no side effect booked, lease free', EngineStore::STEP_WAITING === $step['state'] && 0 === $step['attempts'] && EngineStore::EFFECT_NONE === $step['effect'] && null === ( $step['lease_owner'] ?? null ) );
check( 'Suspend: step log holds the reason and no time', array( 'waiting' => 'needs_release', 'until' => null ) === $step['result'] );
check( 'Suspend: run waits with the reason, no further step yet', EngineStore::RUN_WAITING === $run['state'] && 'needs_release' === $run['last_error'] && 1 === count( steps_of( $e ) ) );
check( 'Suspend: the waiting run is open (cancel, serialisation, retention keep it)', in_array( EngineStore::RUN_WAITING, EngineStore::RUN_OPEN, true ) );
check( 'Waiting::state() reads the step, null for an unknown step', EngineStore::STEP_WAITING === $e->waiting->state( $step['id'] ) && null === $e->waiting->state( 999999 ) );
$e->clock->advance( 400 * DAY_IN_SECONDS );
check( 'Suspend without a time: never due on its own, holds no worker slot', array() === $e->store->due_steps( 100, $e->clock->mysql() ) && 0 === $e->store->running_counts( $e->clock->mysql() )['total'] && 0 === $e->engine->process( 10 ) );
$stuck = $e->store->stuck( $e->clock->mysql( $e->clock->now() - DAY_IN_SECONDS ) );
check( 'Suspend: a long wait is no hanging run and no consistency problem', 0 === $stuck['expired_leases'] && array() === $stuck['runs_without_steps'] && array() === array_filter( $e->store->consistency() ) );
check( 'Suspend: diagnostics count the waiting run', 1 === \WorkflowCaptain\Admin\Diagnostics::report( $e->events, $e->store, $e->registry, array() )['runs'][ EngineStore::RUN_WAITING ] );
check( 'admin: a waiting step without a time shows no deadline', null === \WorkflowCaptain\Admin\WorkflowsPage::waiting_until( steps_of( $e ) ) && null === \WorkflowCaptain\Admin\WorkflowsPage::timeline( steps_of( $e ) )[0]['wait_until'] );

check( 'resume: refused for a step of another action', ! $e->waiting->resume( $step['id'], 'test.other' ) && EngineStore::STEP_WAITING === $e->waiting->state( $step['id'] ) );
check( 'resume: refused for an unknown step', ! $e->waiting->resume( 999999, 'test.hold' ) );
$first  = $e->waiting->resume( $step['id'], 'test.hold' );
$second = $e->waiting->resume( $step['id'], 'test.hold' );
$woken  = $e->store->get_step( $step['id'] );
check( 'resume: once only; the second call is refused', $first && ! $second );
check( 'resume: step queued and due now, run running, attempts and effect unchanged', EngineStore::STEP_QUEUED === $woken['state'] && $e->clock->mysql() === $woken['due_at'] && EngineStore::RUN_RUNNING === run_of( $e )['state'] && null === run_of( $e )['last_error'] && 0 === $woken['attempts'] && EngineStore::EFFECT_NONE === $woken['effect'] );
drain( $e );
$step2 = hold_step( $e );
check( 'resume after 400 days: not overdue, the action runs again and the run finishes', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] && EngineStore::STEP_SUCCEEDED === $step2['state'] && 1 === $step2['attempts'] && array( 'released' => 1 ) === $step2['result'] );
check( 'resume: the repeated call has the same step ID and idempotency key', 2 === count( $e->hold->calls ) && $e->hold->calls[0] === $e->hold->calls[1] && $step['id'] === $e->hold->calls[1]['step'] && $step['step_key'] === $e->hold->calls[1]['key'] );
check( 'resume: the following step runs (tag set)', array( 'freigegeben' ) === $e->store->tags_for( 'pass-captain', 'ticket', '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' ) );
check( 'resume: refused once the step has run', ! $e->waiting->resume( $step['id'], 'test.hold' ) );

// ----- Suspend with a time -------------------------------------------------------------------------------------

$e     = hold_site( array( 'suspend:3600' ) );
$step  = hold_step( $e );
$until = $e->clock->now() + 3600;
check( 'Suspend with a time: due_at is the time, log holds it as RFC 3339', $e->clock->mysql( $until ) === $step['due_at'] && $e->clock->rfc3339( $until ) === $step['result']['until'] && EngineStore::RUN_WAITING === run_of( $e )['state'] );
check( 'admin: the deadline of a waiting step is shown', $e->clock->mysql( $until ) === \WorkflowCaptain\Admin\WorkflowsPage::waiting_until( steps_of( $e ) ) );
$e->clock->advance( 3599 );
drain( $e );
check( 'Suspend with a time: not due before the time', EngineStore::STEP_WAITING === hold_step( $e )['state'] && 1 === count( $e->hold->calls ) );
$e->clock->advance( 1 );
drain( $e );
check( 'Suspend with a time: due at the time, runs again, the run finishes', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] && 2 === count( $e->hold->calls ) && 1 === hold_step( $e )['attempts'] );

// A step woken by its time long after (worker did not run) is due like any other step; the wait itself never counts.
$e = hold_site( array( 'suspend:' . ( 10 * DAY_IN_SECONDS ) ) );
$e->clock->advance( 10 * DAY_IN_SECONDS + 60 );
drain( $e );
check( 'Suspend with a time: a wait of ten days is not overdue', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] );

// resume before the time.
$e    = hold_site( array( 'suspend:86400' ) );
$step = hold_step( $e );
check( 'resume before the time: accepted, the step runs right away', $e->waiting->resume( $step['id'], 'test.hold' ) );
drain( $e );
check( 'resume before the time: the run finishes without waiting for the time', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] && 2 === count( $e->hold->calls ) );

// Suspend twice in a row: the step waits again.
$e    = hold_site( array( 'suspend', 'suspend' ) );
$step = hold_step( $e );
$e->waiting->resume( $step['id'], 'test.hold' );
drain( $e );
check( 'Suspend again after resume: the step waits again, still no attempt counted', EngineStore::STEP_WAITING === hold_step( $e )['state'] && 0 === hold_step( $e )['attempts'] && EngineStore::RUN_WAITING === run_of( $e )['state'] );

// ----- Restart: a new engine on the same store -------------------------------------------------------------------

$e     = hold_site( array( 'suspend' ) );
$step  = hold_step( $e );
$fresh = new Engine( $e->store, $e->actions, $e->dir, $e->clock );
$fresh->set_consumer( $e->consumer );
check( 'restart: a new engine wakes the step of the old one', ( new Waiting( $e->store, $e->clock ) )->resume( $step['id'], 'test.hold' ) );
$fresh->process( 10 );
$fresh->process( 10 );
check( 'restart: the new engine runs the woken step and the rest of the run', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] && $step['step_key'] === end( $e->hold->calls )['key'] );

// ----- Emergency pause and worker limits -------------------------------------------------------------------------

$e    = hold_site( array( 'suspend' ) );
$step = hold_step( $e );
$e->engine->set_paused( true );
check( 'emergency pause: resume still accepted, but nothing runs', $e->waiting->resume( $step['id'], 'test.hold' ) && 0 === $e->engine->process( 10 ) && EngineStore::STEP_QUEUED === hold_step( $e )['state'] );
$e->engine->set_paused( false );
drain( $e );
check( 'emergency pause lifted: the woken step runs', EngineStore::RUN_SUCCEEDED === run_of( $e )['state'] );

// Serialisation: a waiting run keeps later runs of the same object waiting (it is open).
$e        = engine_site();
$e->hold  = new HoldAction( 'test.hold', fn() => $e->clock->now() );
$e->hold->plan = array( 'suspend' );
$e->actions->add( $e->hold );
$sg                        = hold_graph();
$sg['nodes'][0]['serialize'] = 'subject';
publish( $e, $sg );
$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'one' ) );
drain( $e );
$e->consumer->capture( $e->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'two' ) );
drain( $e );
$runs = $e->store->find_runs( array(), 10, 0 );
check( 'serialisation: a later run of the same object waits behind the waiting run', 2 === count( $runs ) && EngineStore::RUN_RUNNING === $runs[0]['state'] && EngineStore::RUN_WAITING === $runs[1]['state'] && 1 === count( $e->hold->calls ) );

// ----- Cancel a waiting run --------------------------------------------------------------------------------------

$e    = hold_site( array( 'suspend:3600' ) );
$step = hold_step( $e );
check( 'cancel: a waiting run can be cancelled', $e->workflows->cancel_run( run_of( $e )['id'] ) );
check( 'cancel: the waiting step is cancelled too', EngineStore::STEP_CANCELLED === $e->waiting->state( $step['id'] ) && EngineStore::RUN_CANCELLED === run_of( $e )['state'] );
check( 'cancel: resume of a cancelled step is refused', ! $e->waiting->resume( $step['id'], 'test.hold' ) );
$e->clock->advance( 7200 );
drain( $e );
check( 'cancel: the time passing runs nothing', 1 === count( $e->hold->calls ) && EngineStore::RUN_CANCELLED === run_of( $e )['state'] );

// ----- Halt ------------------------------------------------------------------------------------------------------

$e    = hold_site( array( 'halt' ) );
$step = hold_step( $e );
$run  = run_of( $e );
check( 'Halt: the step succeeded with its redacted result and the reason', EngineStore::STEP_SUCCEEDED === $step['state'] && array( 'halted' => 'limit_reached', 'count' => 3 ) === $step['result'] && 1 === $step['attempts'] );
check( 'Halt: no further step, the run is stopped with the reason', 1 === count( steps_of( $e ) ) && EngineStore::RUN_STOPPED === $run['state'] && 'limit_reached' === $run['last_error'] && array() === $e->store->tags_for( 'pass-captain', 'ticket', '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d' ) );
$e->clock->advance( DAY_IN_SECONDS );
drain( $e );
check( 'Halt: no retry', 1 === count( $e->hold->calls ) && EngineStore::RUN_STOPPED === run_of( $e )['state'] );

// ----- Invalid reasons -------------------------------------------------------------------------------------------

$refused = static function ( callable $make ): bool {
	try {
		$make();
	} catch ( \InvalidArgumentException $ex ) {
		return true;
	}
	return false;
};
check( 'Suspend: an invalid reason is refused (InvalidArgumentException)', $refused( fn() => new Suspend( 'Needs Release!' ) ) && $refused( fn() => new Suspend( '' ) ) && $refused( fn() => new Suspend( str_repeat( 'a', 65 ) ) ) && $refused( fn() => new Suspend( "ok\n" ) ) );
check( 'Suspend: a fixed code up to 64 characters is accepted', 'a:b.c-d_1' === ( new Suspend( 'a:b.c-d_1', 5 ) )->reason && null === ( new Suspend( 'x' ) )->until && 5 === ( new Suspend( 'a:b.c-d_1', 5 ) )->until && 64 === strlen( ( new Suspend( str_repeat( 'a', 64 ) ) )->reason ) );
check( 'Halt: an invalid reason is refused, the result is kept', $refused( fn() => new Halt( 'mit leerzeichen' ) ) && array( 'n' => 1 ) === ( new Halt( 'done', array( 'n' => 1 ) ) )->result );
check( 'Suspend and Halt are RuntimeExceptions, not failures of the consumer', new Suspend( 'x' ) instanceof \RuntimeException && ! ( new Suspend( 'x' ) instanceof \WorkflowCaptain\Consumer\PermanentFailure ) && new Halt( 'x' ) instanceof \RuntimeException );

// ----- Runs from another site of the network ---------------------------------------------------------------------

$e        = engine_site();
$e->hold  = new HoldAction( 'test.hold', fn() => $e->clock->now() );
$e->hold->plan = array( 'suspend:3600' );
$e->actions->add( $e->hold );
$e->consumer->capture( $e->adapter, checkin() );
$fenv  = $e->events->find( array(), 1, 0 )[0]['envelope'];
$fg    = hold_graph();
$e->engine->foreign()->set_resolver( fn( array $n ) => array( 'graph' => $fg, 'version' => 1, 'authorized_by_user_id' => 1 ) );
$fq    = ForeignRuns::enqueue( $e->store, $fg, $fenv, array( 'network_id' => 1, 'source_blog_id' => 2, 'workflow_id' => 9, 'version' => 1, 'run_id' => 5 ), hash( 'sha256', 'net-hold' ), 'network:9', 'Netz', $e->clock->mysql() );
$e->engine->process( 10 );
$frun  = $e->store->get_run( $fq['run_id'] );
$fstep = $e->store->steps_for_run( $fq['run_id'] )[0];
check( 'foreign run: Suspend is not allowed, the step pauses with suspend_not_network_safe', EngineStore::RUN_PAUSED === $frun['state'] && 'suspend_not_network_safe' === $frun['last_error'] && EngineStore::STEP_PAUSED === $fstep['state'] && EngineStore::EFFECT_NONE === $fstep['effect'] );
check( 'foreign run: resume cannot wake it', ! $e->engine->waiting()->resume( $fstep['id'], 'test.hold' ) );

// ----- Test run with sample data ---------------------------------------------------------------------------------

$e        = engine_site();
$e->hold  = new HoldAction( 'test.hold', fn() => $e->clock->now() );
$e->hold->plan = array( 'suspend' );
$e->actions->add( $e->hold );
$dry = DryRun::run( hold_graph(), array( 'data' => array( 'checkin_source' => 'scanner' ) ), $e->actions );
check( 'test run: the hold action is listed, executes nothing and stores nothing', 'succeeded' === $dry['outcome'] && 'test.hold' === $dry['path'][1]['action'] && array() === $e->hold->calls && array() === $e->store->find_runs( array(), 10, 0 ) );

// ----- Contract and hook -----------------------------------------------------------------------------------------

$main = (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'workflow-captain.php' );
$boot = (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . 'src/Plugin.php' );
check( 'contract: WORKFLOW_CAPTAIN_CONTRACT_VERSION is 0.2.0', str_contains( $main, "define( 'WORKFLOW_CAPTAIN_CONTRACT_VERSION', '0.2.0' );" ) );
check( 'hook: workflow_captain_waiting_v1 is announced on init, priority 20, once per request', str_contains( $boot, "add_action( 'init', array( \$this, 'announce_waiting' ), 20 );" ) && str_contains( $boot, "did_action( 'workflow_captain_waiting_v1' )" ) && 1 === substr_count( $boot, "do_action( 'workflow_captain_waiting_v1'" ) );
