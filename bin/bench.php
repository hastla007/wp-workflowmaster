<?php
/**
 * Throughput measurement of consumer and engine on SQLite (T54, WF-CORE-05): how long a worker run takes for a
 * batch of events with one workflow (condition, mail, tag). Not a WordPress measurement: no network mail, no MySQL.
 *
 *   php bin/bench.php [events=500] [batch=25]
 *
 * @package WorkflowCaptain
 */

define( 'WFC_BENCH', true );
require __DIR__ . '/../tests/support/bootstrap.php';

use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\Consumer\Consumer;
use WorkflowCaptain\Engine\ActionRegistry;
use WorkflowCaptain\Engine\Actions\NotifyAdmin;
use WorkflowCaptain\Engine\Actions\Tag;
use WorkflowCaptain\Engine\Engine;
use WorkflowCaptain\Engine\Workflows;
use WorkflowCaptain\Engine\WpdbEngineStore;
use WorkflowCaptain\Store\WpdbEventStore;

$events = max( 1, (int) ( $argv[1] ?? 500 ) );
$batch  = max( 1, min( 200, (int) ( $argv[2] ?? 25 ) ) );

$db = new FakeWpdb( FakeWpdb::memory(), 'wp_' );
$db->create_tables();
$db->create_engine_tables();
$clock    = new FixedClock();
$dir      = new FakeDirectory();
$store    = new WpdbEngineStore( $db );
$actions  = new ActionRegistry();
$actions->add( new NotifyAdmin( $dir ) );
$actions->add( new Tag( $store, $clock ) );
$engine   = new Engine( $store, $actions, $dir, $clock, 'UTC', array( 'site' => 1000, 'workflow' => 1000 ) );
$adapter  = new TestAdapter();
$registry = new AdapterRegistry();
$registry->add( $adapter );
$consumer = new Consumer( new WpdbEventStore( $db ), $registry, $engine, $clock, 1 );
$engine->set_consumer( $consumer );
$workflows = new Workflows( $store, $actions, $dir, $clock );
list( $id ) = $workflows->save(
	null,
	'Bench',
	array(
		'nodes' => array(
			array( 'id' => 't', 'type' => 'trigger', 'event' => 'pass.ticket.checked_in' ),
			array( 'id' => 'm', 'type' => 'action', 'action' => 'notify.admin', 'config' => array( 'subject' => 'Einlass {{subject.id}}', 'message' => 'x' ) ),
			array( 'id' => 'g', 'type' => 'action', 'action' => 'workflow.tag.set', 'config' => array( 'tag' => 'bench' ) ),
			array( 'id' => 'e', 'type' => 'end' ),
		),
		'edges' => array( array( 'from' => 't', 'to' => 'm' ), array( 'from' => 'm', 'to' => 'g' ), array( 'from' => 'g', 'to' => 'e' ) ),
	)
);
$workflows->publish( $id, 1 );

$t0 = microtime( true );
for ( $i = 0; $i < $events; $i++ ) {
	$consumer->capture( $adapter, array( 'type' => 'pass.ticket.checked_in', 'subject' => array( 'type' => 'ticket', 'id' => sprintf( '%032x', $i ), 'id_kind' => 'public' ), 'data' => array( 'checkin_source' => 'scanner' ), 'discriminator' => (string) $i ) );
}
$capture = microtime( true ) - $t0;

$runs = 0;
$t1   = microtime( true );
$max  = 0.0;
do {
	$start = microtime( true );
	$work  = $consumer->process( $batch ) + $engine->process( $batch );
	$max   = max( $max, microtime( true ) - $start );
	++$runs;
} while ( $work > 0 && $runs < 100000 );
$process = microtime( true ) - $t1;

printf( "events: %d, batch: %d\n", $events, $batch );
printf( "capture: %.1f ms total, %.2f ms per event\n", $capture * 1000, $capture * 1000 / $events );
printf( "worker runs: %d, total %.1f ms, slowest run %.1f ms\n", $runs, $process * 1000, $max * 1000 );
printf( "per event (event + 2 steps): %.2f ms\n", $process * 1000 / $events );
