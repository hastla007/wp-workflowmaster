<?php
/**
 * Tests of the consumer without WordPress: envelope, store (real SQL on SQLite), consumer and privacy, labelled with
 * the conformance numbers of docs/SPEC-suite-events.md, section 14.3.
 *
 *   php tests/run.php
 *
 * @package WorkflowCaptain
 */

require __DIR__ . '/support/bootstrap.php';

use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\Consumer\Causation;
use WorkflowCaptain\Consumer\Consumer;
use WorkflowCaptain\Consumer\PermanentFailure;
use WorkflowCaptain\Consumer\RetryPlan;
use WorkflowCaptain\Envelope\IdempotencyKey;
use WorkflowCaptain\Envelope\Validator;
use WorkflowCaptain\Privacy\Privacy;
use WorkflowCaptain\Store\EventStore;
use WorkflowCaptain\Store\WpdbEventStore;

$failures = 0;
$passed   = 0;

function check( string $label, bool $ok ): void {
	global $failures, $passed;
	if ( $ok ) {
		++$passed;
		echo "ok   {$label}\n";
	} else {
		++$failures;
		echo "FAIL {$label}\n";
	}
}

/** A fresh site: store, registry with the test adapter, recording dispatcher, fixed clock, consumer. */
function site( ?PDO $pdo = null, string $prefix = 'wp_', int $blog_id = 1 ): object {
	$pdo = $pdo ?? FakeWpdb::memory();
	$db  = new FakeWpdb( $pdo, $prefix );
	$db->create_tables();
	$s             = new stdClass();
	$s->db         = $db;
	$s->store      = new WpdbEventStore( $db );
	$s->adapter    = new TestAdapter();
	$s->registry   = new AdapterRegistry();
	$s->registry->add( $s->adapter );
	$s->dispatcher = new RecordingDispatcher();
	$s->clock      = new FixedClock();
	$s->rejected   = array();
	$s->consumer   = new Consumer(
		$s->store,
		$s->registry,
		$s->dispatcher,
		$s->clock,
		$blog_id,
		function ( array $codes ) use ( $s ): void {
			$s->rejected[] = $codes;
		}
	);
	return $s;
}

function checkin( string $ticket = '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', string $key = 'scan-1', array $data = array() ): array {
	return array(
		'type'          => 'pass.ticket.checked_in',
		'subject'       => array( 'type' => 'ticket', 'id' => $ticket, 'id_kind' => 'public' ),
		'data'          => $data + array( 'checkin_source' => 'scanner', 'device_id' => 4, 'area_id' => 0 ),
		'actor_user_id' => 17,
		'discriminator' => $key,
	);
}

function only_row( object $s ): array {
	$rows = $s->store->find( array(), 10, 0 );
	return $rows[0] ?? array();
}

// ---------------------------------------------------------------------------------------------------------------
// Envelope validator against the examples and counter-examples of docs/ (same set as bin/validate-spec-examples.py).

$examples = glob( WORKFLOW_CAPTAIN_PATH . 'docs/examples/*.json' );
check( 'examples found (' . count( $examples ) . ')', count( $examples ) >= 30 );
foreach ( $examples as $file ) {
	$doc = json_decode( file_get_contents( $file ), true );
	check( 'validator accepts ' . basename( $file ), Validator::is_valid( $doc ) );
}
$base   = json_decode( file_get_contents( WORKFLOW_CAPTAIN_PATH . 'docs/examples/pass.ticket.checked_in.json' ), true );
$caused = json_decode( file_get_contents( WORKFLOW_CAPTAIN_PATH . 'docs/examples/voucher.voucher.issued_standalone.json' ), true );
$mut    = static function ( array $doc, array $path, $value = '__delete__' ): array {
	$node = &$doc;
	foreach ( array_slice( $path, 0, -1 ) as $part ) {
		$node = &$node[ $part ];
	}
	if ( '__delete__' === $value ) {
		unset( $node[ end( $path ) ] );
	} else {
		$node[ end( $path ) ] = $value;
	}
	return $doc;
};
$counter = array(
	'missing id'                       => $mut( $base, array( 'id' ) ),
	'id is not a UUID v4'              => $mut( $base, array( 'id' ), 'b81bb38e-5237-5cc6-9883-f1d39783f237' ),
	'unknown top-level field'          => $mut( $base, array( 'payload' ), array( 'x' => 1 ) ),
	'unknown product prefix in type'   => $mut( $base, array( 'type' ), 'shop.order.paid' ),
	'local time instead of UTC'        => $mut( $base, array( 'occurred_at' ), '2026-11-16 18:02:11' ),
	'e-mail address as subject'        => $mut( $base, array( 'subject', 'id' ), 'kunde@example.com' ),
	'data carries a code'              => $mut( $base, array( 'data', 'code' ), 'ABCD-1234' ),
	'data carries a voucher code'      => $mut( $base, array( 'data', 'voucher_code' ), 'ABCD-1234' ),
	'partner code under another name'  => $mut( $base, array( 'data', 'partner_code_secret' ), 'x' ),
	'data carries a ticket code'       => $mut( $base, array( 'data', 'ticket_code' ), 'ABCD-1234' ),
	'data carries an e-mail'           => $mut( $base, array( 'data', 'customer_email' ), 'kunde@example.com' ),
	'data carries a license key'       => $mut( $base, array( 'data', 'license_key' ), 'WPLC-XXXX' ),
	'data carries a name'              => $mut( $base, array( 'data', 'attendee_name' ), 'Erika Muster' ),
	'nested data'                      => $mut( $base, array( 'data', 'order' ), array( 'id' => 1 ) ),
	'actor is not a user ID'           => $mut( $base, array( 'actor_user_id' ), 'erika' ),
	'actor 0'                          => $mut( $base, array( 'actor_user_id' ), 0 ),
	'causation without workflow'       => $mut( $caused, array( 'caused_by_workflow_id' ) ),
	'causation with depth 0'           => $mut( $caused, array( 'chain_depth' ), 0 ),
	'workflow without causation'       => $mut( $base, array( 'caused_by_workflow_id' ), 12 ),
	'depth without causation'          => $mut( $base, array( 'chain_depth' ), 2 ),
	'spec version of another major'    => $mut( $base, array( 'spec_version' ), '2.0' ),
	'depth over 8'                     => $mut( $caused, array( 'chain_depth' ), 9 ),
	'25 data fields'                   => $mut( $base, array( 'data' ), array_combine( array_map( fn( $i ) => 'f' . $i, range( 1, 25 ) ), range( 1, 25 ) ) ),
	'data value longer than 191'       => $mut( $base, array( 'data', 'checkin_source' ), str_repeat( 'a', 192 ) ),
);
foreach ( $counter as $label => $doc ) {
	check( 'validator rejects: ' . $label, ! Validator::is_valid( $doc ) );
}
check( 'violation codes carry no value', ! str_contains( implode( ',', Validator::violations( $counter['data carries an e-mail'] ) ), 'example.com' ) );
check( 'partner_code is allowed (D8b)', Validator::is_valid( $mut( $base, array( 'data', 'partner_code' ), 'MARIA10' ) ) );

// Idempotency key (R-7.8)
check( 'idempotency key matches R-7.8', hash( 'sha256', '1|1|pass.order.paid|order|abc|once' ) === IdempotencyKey::make( 1, 1, 'pass.order.paid', 'order', 'abc', IdempotencyKey::ONCE ) );
check( 'status change discriminator cuts to the second', 'a>b@2026-11-16T10:00:00' === IdempotencyKey::status_change( 'a', 'b', '2026-11-16T10:00:00.123Z' ) );

// ---------------------------------------------------------------------------------------------------------------
// C-1, C-11, C-12: guard over the shipped files, and its self-test with planted code.

exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/license-guard.php' ) . ' 2>&1', $out, $code );
check( 'C-1 license guard passes on the shipped files', 0 === $code );
$tmp = sys_get_temp_dir() . '/wfc-guard-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp . '/src', 0777, true );
file_put_contents( $tmp . '/workflow-captain.php', "<?php\n" );
foreach ( array(
	"\$c = new LicenseClient();"                 => 'license client',
	"if ( allows_entitlement( 'x' ) ) {}"        => 'entitlement',
	"\$k = 'voucher-captain-pro';"               => 'product key',
	"wp_set_current_user( 1 );"                  => 'wp_set_current_user',
	"add_filter( 'pre_wp_mail', '__return_true' );" => 'mail filter',
) as $line => $what ) {
	file_put_contents( $tmp . '/src/Planted.php', "<?php\n{$line}\n" );
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/license-guard.php' ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1', $o, $c );
	check( 'guard self-test finds planted ' . $what, 1 === $c );
}
array_map( 'unlink', glob( $tmp . '/src/*' ) );
unlink( $tmp . '/workflow-captain.php' );
rmdir( $tmp . '/src' );
rmdir( $tmp );

// ---------------------------------------------------------------------------------------------------------------
// Capture

$s = site();
check( 'capture stores an event', Consumer::CAPTURED === $s->consumer->capture( $s->adapter, checkin() ) );
$row = only_row( $s );
check( 'C-2 stored envelope passes the schema check', Validator::is_valid( $row['envelope'] ) );
check( 'C-3 id is a UUID v4', 1 === preg_match( Validator::UUID4, $row['envelope']['id'] ) );
check( 'C-3 occurred_at is UTC with Z', '2025-11-16T10:00:00Z' === $row['envelope']['occurred_at'] || str_ends_with( $row['envelope']['occurred_at'], 'Z' ) );
check( 'R-6.1 consumer sets source, site and correlation', 'pass-captain' === $row['envelope']['source']['product'] && 1 === $row['envelope']['site']['blog_id'] && $row['envelope']['correlation_id'] === $row['envelope']['id'] );
check( 'state received', EventStore::RECEIVED === $row['state'] );

check( 'C-4 same idempotency key is not stored again', EventStore::DUPLICATE === $s->consumer->capture( $s->adapter, checkin() ) );
check( 'C-4 still one row', 1 === count( $s->store->find( array(), 10, 0 ) ) );
check( 'two real events get two rows', Consumer::CAPTURED === $s->consumer->capture( $s->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'scan-2' ) ) );

$s2 = site();
check( 'C-2 invalid envelope is rejected', Consumer::REJECTED === $s2->consumer->capture( $s2->adapter, checkin( 'x', 'k', array( 'customer_email' => 'secret-marker@example.com' ) ) ) );
check( 'C-2 rejected envelope is not stored', array() === $s2->store->find( array(), 10, 0 ) );
check( 'C-2 rejection is logged without values', ! str_contains( json_encode( $s2->rejected ), 'secret-marker' ) && array() !== $s2->rejected );

$s3 = site();
$s3->adapter->throw_on_product = true;
$result = 'none';
try {
	$result = $s3->consumer->capture( $s3->adapter, checkin() );
	$threw  = false;
} catch ( \Throwable $e ) {
	$threw = true;
}
check( 'R-7.6 capture never throws, even if the adapter does', ! $threw && EventStore::FAILED === $result );
check( 'R-7.6 exception text is not logged', ! str_contains( json_encode( $s3->rejected ), 'secret-marker' ) );

// ---------------------------------------------------------------------------------------------------------------
// Processing

$s = site();
$s->consumer->capture( $s->adapter, checkin() );
check( 'worker handles one event', 1 === $s->consumer->process() );
$row = only_row( $s );
check( 'event done after dispatch', EventStore::DONE === $row['state'] );
check( 'R-12.4 actor and data cleared when no run waits', ! isset( $row['envelope']['actor_user_id'] ) && array() === $row['envelope']['data'] && null === $row['actor_user_id'] );
check( 'minimized envelope still valid', Validator::is_valid( $row['envelope'] ) );
check( 'nothing left to do', 0 === $s->consumer->process() );

$s = site();
$s->dispatcher->waiting = true;
$s->consumer->capture( $s->adapter, checkin() );
$s->consumer->process();
check( 'data kept while a run waits', 17 === only_row( $s )['actor_user_id'] );

// C-5: retry plan, then dead_letter
$s = site();
$s->dispatcher->fail_times = 100;
$s->consumer->capture( $s->adapter, checkin() );
$s->consumer->process();
$row = only_row( $s );
check( 'C-5 first failure: retry_wait after 60 s', EventStore::RETRY_WAIT === $row['state'] && $s->clock->mysql( $s->clock->now() + 60 ) === $row['next_attempt_at'] );
check( 'C-5 last_error is a code', 'db_timeout' === $row['last_error'] );
check( 'C-5 not due before the delay', 0 === $s->consumer->process() );
$seen = array();
foreach ( RetryPlan::DELAYS as $i => $delay ) {
	$s->clock->advance( $delay );
	$s->consumer->process();
	$seen[] = only_row( $s )['state'];
}
check( 'C-5 eight retries follow the plan, then dead_letter', array_fill( 0, 7, EventStore::RETRY_WAIT ) === array_slice( $seen, 0, 7 ) && EventStore::DEAD_LETTER === end( $seen ) );
check( 'C-5 nine attempts in total', 9 === only_row( $s )['attempts'] && 9 === count( $s->dispatcher->calls ) );
check( 'C-5 dead_letter is listed for the admin', 1 === count( $s->store->find( array( 'state' => EventStore::DEAD_LETTER ), 10, 0 ) ) );
check( 'C-10 retries started each workflow only once', 2 === count( $s->dispatcher->runs ) );
check( 'C-10 every retry got the same envelope', 1 === count( array_unique( array_map( fn( $c ) => $c[0]['id'], $s->dispatcher->calls ) ) ) );
$s->dispatcher->fail_times = 0;
check( 'requeue of dead_letter', $s->consumer->requeue( only_row( $s )['id'] ) );
$s->consumer->process();
check( 'requeued event is processed', EventStore::DONE === only_row( $s )['state'] );

// Permanent failure
$s = site();
$s->dispatcher = new class() implements \WorkflowCaptain\Consumer\Dispatcher {
	public function dispatch( array $envelope, ?int $exclude_workflow_id ): \WorkflowCaptain\Consumer\DispatchResult {
		throw new PermanentFailure( 'contract_unknown' );
	}
};
$c = new Consumer( $s->store, $s->registry, $s->dispatcher, $s->clock, 1 );
$c->capture( $s->adapter, checkin() );
$c->process();
check( 'permanent failure goes straight to dead_letter', EventStore::DEAD_LETTER === only_row( $s )['state'] && 1 === only_row( $s )['attempts'] );

// C-6: a crashed worker leaves nothing in processing for good
$s = site();
$s->consumer->capture( $s->adapter, checkin() );
$claimed = $s->store->claim_due( 10, $s->clock->mysql(), 'crashed-worker', $s->clock->mysql( $s->clock->now() + 300 ) );
check( 'C-6 claim sets processing', 1 === count( $claimed ) && EventStore::PROCESSING === only_row( $s )['state'] );
check( 'C-6 a second worker cannot claim it during the lease', array() === $s->store->claim_due( 10, $s->clock->mysql(), 'other', $s->clock->mysql( $s->clock->now() + 300 ) ) );
$s->clock->advance( 301 );
check( 'C-6 after the lease the event is processed', 1 === $s->consumer->process() && EventStore::DONE === only_row( $s )['state'] );
check( 'C-6 stale owner cannot overwrite', false === $s->store->finish( only_row( $s )['id'], 'crashed-worker', array( 'state' => EventStore::RETRY_WAIT ), $s->clock->mysql() ) );

// C-7: re-read before start
$s = site();
$s->adapter->reread        = array( 'pass.ticket.checked_in' );
$s->adapter->reread_result = false;
$s->consumer->capture( $s->adapter, checkin() );
$s->consumer->process();
check( 'C-7 contradicting re-read discards the event', EventStore::DISCARDED === only_row( $s )['state'] && array() === $s->dispatcher->calls );
$s = site();
$s->adapter->reread        = array( 'pass.ticket.checked_in' );
$s->adapter->reread_result = null;
$s->consumer->capture( $s->adapter, checkin() );
$s->consumer->process();
check( 'C-7 unreadable provider means retry', EventStore::RETRY_WAIT === only_row( $s )['state'] && 'provider_unreachable' === only_row( $s )['last_error'] );

// C-8, C-9: causation, self-start and depth
$s     = site();
$s->consumer->capture( $s->adapter, checkin() );
$root  = only_row( $s )['envelope'];
$cause = Consumer::cause_for( $root, 12 );
$s->consumer->run_command(
	$cause,
	function () use ( $s ) {
		$s->consumer->capture( $s->adapter, checkin( 'aaaabbbbccccddddeeeeffff00001111', 'scan-x' ) );
	}
);
$caused = $s->store->find( array(), 1, 0 )[0]['envelope'];
check( 'C-9 event inside a command carries causation', $cause->command_id === $caused['causation_id'] && 12 === $caused['caused_by_workflow_id'] && 1 === $caused['chain_depth'] && $root['correlation_id'] === $caused['correlation_id'] );
check( 'C-9 context ends with the command', Consumer::CAPTURED === $s->consumer->capture( $s->adapter, checkin( 'aaaabbbbccccddddeeeeffff00002222', 'k' ) ) && null === $s->store->find( array(), 1, 0 )[0]['envelope']['causation_id'] );
$s->consumer->remember_cause( 'ticket', 'cccc0000cccc0000cccc0000cccc0000', $cause );
$s->clock->advance( 3600 );
$s->consumer->capture( $s->adapter, checkin( 'cccc0000cccc0000cccc0000cccc0000', 'later' ) );
$later = $s->store->find( array(), 1, 0 )[0]['envelope'];
check( 'C-9 later event on a created object inherits the causation', $cause->command_id === $later['causation_id'] && 1 === $later['chain_depth'] );
$s->consumer->process( 100 );
$excluded = array_filter( $s->dispatcher->calls, fn( $c ) => 12 === $c[1] );
check( 'C-8 caused events are dispatched with their own workflow excluded', 2 === count( $excluded ) );
$own_keys = array( $caused['idempotency_key'] . '|12', $later['idempotency_key'] . '|12' );
check( 'C-8 workflow 12 never started by its own events', array() === array_intersect( $own_keys, array_keys( $s->dispatcher->runs ) ) );
check( 'C-8 other workflows still start on caused events', isset( $s->dispatcher->runs[ $caused['idempotency_key'] . '|13' ] ) );

$deep = new Causation( \WorkflowCaptain\Envelope\Uuid::v4(), 13, $root['correlation_id'], 3 );
$s    = site();
$s->consumer->run_command(
	$deep,
	function () use ( $s ) {
		$s->consumer->capture( $s->adapter, checkin() );
	}
);
check( 'C-8 depth 4 is stored as loop_blocked', EventStore::LOOP_BLOCKED === only_row( $s )['state'] && 4 === only_row( $s )['envelope']['chain_depth'] );
check( 'C-8 loop_blocked starts nothing', 0 === $s->consumer->process() && array() === $s->dispatcher->calls );

// R-13.3: unknown type is stored but starts nothing
$s = site();
$s->consumer->capture( $s->adapter, array( 'type' => 'pass.ticket.transferred', 'subject' => array( 'type' => 'ticket', 'id' => 't1', 'id_kind' => 'public' ), 'data' => array(), 'discriminator' => 'x' ) );
$s->consumer->process();
check( 'R-13.3 unknown type stored, done, nothing started', EventStore::DONE === only_row( $s )['state'] && 'unknown_type' === only_row( $s )['last_error'] && array() === $s->dispatcher->calls );

// C-15: adapter gone
$s = site();
$s->consumer->capture( $s->adapter, checkin() );
$s->consumer->capture( $s->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'scan-2' ) );
check( 'C-15 open events of a product are paused', 2 === $s->consumer->pause_product( 'pass-captain' ) );
check( 'C-15 nothing deleted, nothing processed', 2 === count( $s->store->find( array( 'state' => EventStore::PAUSED ), 10, 0 ) ) && 0 === $s->consumer->process() );
$s = site();
$s->consumer->capture( $s->adapter, checkin() );
$s->registry->remove( 'pass-captain' );
$s->consumer->process();
check( 'C-15 event of an unregistered adapter is paused when processed', EventStore::PAUSED === only_row( $s )['state'] );

// Registry
$r = new AdapterRegistry();
check( 'registry refuses the consumer itself and unknown products', ! $r->add( new TestAdapter( 'workflow-captain' ) ) && ! $r->add( new TestAdapter( 'shop' ) ) );
check( 'registry: one adapter per product', $r->add( new TestAdapter() ) && ! $r->add( new TestAdapter() ) );
check( 'registry refuses an invalid contract version', ! ( new AdapterRegistry() )->add( new TestAdapter( 'pass-captain', 'v1' ) ) );

// C-13: export and erasure
$s = site();
$GLOBALS['wfc_test_users'] = array( 17 => 'person@example.com' );
$s->dispatcher->waiting    = true;
$s->consumer->capture( $s->adapter, checkin() );
$privacy = new Privacy( fn() => $s->store, $s->clock );
$export  = $privacy->export( 'person@example.com' );
check( 'C-13 export lists the events of the user', 1 === count( $export['data'] ) && $export['done'] );
check( 'C-13 export of an unknown address is empty', array() === $privacy->export( 'nobody@example.com' )['data'] );
$erased = $privacy->erase( 'person@example.com' );
check( 'C-13 erasure removes the user from every event', $erased['items_removed'] && array() === $s->store->by_actor( 17, 10, 0 ) && ! isset( only_row( $s )['envelope']['actor_user_id'] ) );
check( 'C-13 erased envelope still valid', Validator::is_valid( only_row( $s )['envelope'] ) );

// affiliate.affiliate.erased clears every event about the affiliate
$s = site();
$s->adapter->product = 'affiliate-captain';
$s->registry = new AdapterRegistry();
$s->registry->add( $s->adapter );
$s->dispatcher->waiting = true;
$c = new Consumer( $s->store, $s->registry, $s->dispatcher, $s->clock, 1 );
$c->capture( $s->adapter, array( 'type' => 'affiliate.commission.approved', 'subject' => array( 'type' => 'commission', 'id' => '433', 'id_kind' => 'record' ), 'data' => array( 'affiliate_id' => 57, 'amount_minor' => 990, 'currency' => 'EUR', 'kind' => 'sale' ), 'actor_user_id' => 3, 'discriminator' => 'x' ) );
$c->process();
$c->capture( $s->adapter, array( 'type' => 'affiliate.affiliate.erased', 'subject' => array( 'type' => 'affiliate', 'id' => '57', 'id_kind' => 'record' ), 'data' => array( 'deferred' => false ), 'discriminator' => IdempotencyKey::ONCE ) );
$c->process();
$commission = $s->store->find( array(), 10, 0 )[1];
check( 'R-12.5 affiliate erased clears data and actor of its events', array() === $commission['envelope']['data'] && null === $commission['actor_user_id'] );

// C-14: retention
$s = site();
$s->consumer->capture( $s->adapter, checkin() );
$s->consumer->process();
$s->consumer->capture( $s->adapter, checkin( '8f3a1c0d9e7b4a2f6c5d1e0b9a8f7c6d', 'open' ) );
$s->clock->advance( 31 * DAY_IN_SECONDS );
( new Privacy( fn() => $s->store, $s->clock ) )->apply_retention();
$left = $s->store->find( array(), 10, 0 );
check( 'C-14 finished event deleted after 30 days, open event kept', 1 === count( $left ) && EventStore::RECEIVED === $left[0]['state'] );
add_filter( 'workflow_captain_retention_days', fn( $days, $group ) => 1000 );
$s = site();
$s->consumer->capture( $s->adapter, checkin() );
$s->consumer->process();
$s->clock->advance( 31 * DAY_IN_SECONDS );
( new Privacy( fn() => $s->store, $s->clock ) )->apply_retention();
check( 'C-14 retention cannot be extended by filter', array() === $s->store->find( array(), 10, 0 ) );

// C-16: sites are separate
$pdo = FakeWpdb::memory();
$a   = site( $pdo, 'wp_', 1 );
$b   = site( $pdo, 'wp_2_', 2 );
$a->consumer->capture( $a->adapter, checkin() );
check( 'C-16 site B sees no event of site A', array() === $b->store->find( array(), 10, 0 ) && 1 === count( $a->store->find( array(), 10, 0 ) ) );
check( 'C-16 the same event on site B is not a duplicate', Consumer::CAPTURED === $b->consumer->capture( $b->adapter, checkin() ) );

echo "\n{$passed} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
