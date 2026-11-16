<?php
/**
 * Tests of own connectors (F-22, SPEC 4.8, WF-CONNECTOR-01 to -05, -10, -11); included by tests/run.php after the
 * engine tests, uses their check(), engine_site(), publish() and drain().
 *
 * @package WorkflowCaptain
 */

use WorkflowCaptain\Adapter\AdapterRegistry;
use WorkflowCaptain\Connector\ConnectorAdapter;
use WorkflowCaptain\Connector\ConnectorRegistry;
use WorkflowCaptain\Connector\Manifest;
use WorkflowCaptain\Consumer\Consumer;
use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Engine\Graph;
use WorkflowCaptain\Envelope\Validator;
use WorkflowCaptain\Store\EventStore;
use WorkflowCaptain\Store\WpdbEventStore;

$GLOBALS['wfc_test_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['wfc_test_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['wfc_test_options'][ $name ] = $value;
		return true;
	}
}

require_once WORKFLOW_CAPTAIN_PATH . 'examples/starter-connector/src/Manifest.php';
require_once WORKFLOW_CAPTAIN_PATH . 'examples/starter-connector/src/TicketAdapter.php';
require_once WORKFLOW_CAPTAIN_PATH . 'examples/starter-connector/src/TicketNoteAction.php';

use WfcStarterConnector\Manifest as StarterManifest;
use WfcStarterConnector\TicketAdapter;
use WfcStarterConnector\TicketNoteAction;

/** A connector manifest with a normal, a personal field and an action. */
function conn_manifest( string $key = 'learnboating-connector' ): array {
	$prefix = Validator::connector_prefix( $key );
	return array(
		'key'              => $key,
		'name'             => 'LearnBoating',
		'version'          => '2.1.0',
		'contract_version' => '1.0.0',
		'requires'         => array( 'workflow_captain' => '0.1.0' ),
		'events'           => array(
			$prefix . '.access.changed' => array(
				'fields'       => array(
					'course_id'   => array( 'type' => 'integer', 'class' => 'normal' ),
					'status'      => array( 'type' => 'string', 'class' => 'normal' ),
					'birth_year'  => array( 'type' => 'integer', 'class' => 'personal' ),
					'valid_until' => array( 'type' => 'datetime', 'class' => 'normal' ),
				),
				'after_commit' => true,
				'actor'        => 'customer',
			),
		),
		'actions'          => array( $prefix . '.access.grant' ),
	);
}

function conn_event( string $type = 'xlearnboatingconnector.access.changed', array $data = array() ): array {
	return array(
		'type'          => $type,
		'subject'       => array( 'type' => 'access', 'id' => '991', 'id_kind' => 'record' ),
		'data'          => $data + array( 'course_id' => 7, 'status' => 'active' ),
		'actor_user_id' => 17,
		'discriminator' => 'once',
	);
}

/** Consumer site (tests/run.php site()) plus a connector registry whose release lives in $c->release. */
function conn_site( string $core = '0.1.0' ): object {
	$c             = site();
	$c->release    = array();
	$c->connectors = new ConnectorRegistry( $c->registry, fn() => $c->release, $core );
	return $c;
}

// ----- Manifest (WF-CONNECTOR-01) ------------------------------------------------------------------------------

check( 'manifest: example manifest is valid', array() === Manifest::violations( conn_manifest() ) );
check( 'manifest: starter connector manifest is valid', array() === Manifest::violations( StarterManifest::get() ) );
$cases = array(
	'not an object'                   => array( array( 'a' ), 'manifest:not_object' ),
	'Captain product as key'          => array( array( 'key' => 'pass-captain' ) + conn_manifest(), 'key:invalid' ),
	'wordpress as key'                => array( array( 'key' => 'wordpress' ) + conn_manifest(), 'key:invalid' ),
	'key with capital letters'        => array( array( 'key' => 'Learn_Boating' ) + conn_manifest(), 'key:invalid' ),
	'unknown top-level field'         => array( conn_manifest() + array( 'license' => 'x' ), 'manifest:unknown_field' ),
	'version not semver'              => array( array( 'version' => '2.1' ) + conn_manifest(), 'version:invalid' ),
	'contract version with suffix'    => array( array( 'contract_version' => '1.0.0-beta' ) + conn_manifest(), 'contract_version:invalid' ),
	'requires missing'                => array( array( 'requires' => array() ) + conn_manifest(), 'requires.workflow_captain:invalid' ),
	'empty name'                      => array( array( 'name' => ' ' ) + conn_manifest(), 'name:invalid' ),
	'type of another prefix'          => array( array( 'events' => array( 'xother.access.changed' => conn_manifest()['events']['xlearnboatingconnector.access.changed'] ) ) + conn_manifest(), 'events:type_invalid' ),
	'type of a Captain product'       => array( array( 'events' => array( 'pass.order.paid' => conn_manifest()['events']['xlearnboatingconnector.access.changed'] ) ) + conn_manifest(), 'events:type_invalid' ),
	'events as list'                  => array( array( 'events' => array( 1 ) ) + conn_manifest(), 'events:not_object' ),
	'after_commit missing'            => array( ( function () { $m = conn_manifest(); unset( $m['events']['xlearnboatingconnector.access.changed']['after_commit'] ); return $m; } )(), 'events:after_commit_invalid' ),
	'forbidden field name email'      => array( ( function () { $m = conn_manifest(); $m['events']['xlearnboatingconnector.access.changed']['fields']['email'] = array( 'type' => 'string', 'class' => 'personal' ); return $m; } )(), 'events:field_name_forbidden' ),
	'forbidden field name voucher_code' => array( ( function () { $m = conn_manifest(); $m['events']['xlearnboatingconnector.access.changed']['fields']['voucher_code'] = array( 'type' => 'string', 'class' => 'normal' ); return $m; } )(), 'events:field_name_forbidden' ),
	'unknown data type'               => array( ( function () { $m = conn_manifest(); $m['events']['xlearnboatingconnector.access.changed']['fields']['course_id']['type'] = 'array'; return $m; } )(), 'events:field_spec_invalid' ),
	'unknown data class'              => array( ( function () { $m = conn_manifest(); $m['events']['xlearnboatingconnector.access.changed']['fields']['course_id']['class'] = 'secret'; return $m; } )(), 'events:field_spec_invalid' ),
	'51 types'                        => array( ( function () { $m = conn_manifest(); $spec = $m['events']['xlearnboatingconnector.access.changed']; for ( $i = 0; $i < 51; $i++ ) { $m['events'][ 'xlearnboatingconnector.t' . $i . '.done' ] = $spec; } return $m; } )(), 'events:too_many_types' ),
	'25 fields'                       => array( ( function () { $m = conn_manifest(); for ( $i = 0; $i < 25; $i++ ) { $m['events']['xlearnboatingconnector.access.changed']['fields'][ 'f' . $i ] = array( 'type' => 'integer', 'class' => 'normal' ); } return $m; } )(), 'events:too_many_fields' ),
	'action of another prefix'        => array( array( 'actions' => array( 'xother.access.grant' ) ) + conn_manifest(), 'actions:key_invalid' ),
	'action without x prefix'         => array( array( 'actions' => array( 'notify.admin' ) ) + conn_manifest(), 'actions:key_invalid' ),
	'action with one segment'         => array( array( 'actions' => array( 'xlearnboatingconnector.grant' ) ) + conn_manifest(), 'actions:key_invalid' ),
	'actions as object'               => array( array( 'actions' => array( 'a' => 'xlearnboatingconnector.access.grant' ) ) + conn_manifest(), 'actions:not_list' ),
	'duplicate action'                => array( array( 'actions' => array( 'xlearnboatingconnector.access.grant', 'xlearnboatingconnector.access.grant' ) ) + conn_manifest(), 'actions:duplicate' ),
);
foreach ( $cases as $label => list( $manifest, $code ) ) {
	check( 'manifest: ' . $label . ' → ' . $code, in_array( $code, Manifest::violations( $manifest ), true ) );
}
$m = conn_manifest();
$m['events']['xlearnboatingconnector.access.changed']['fields']['email'] = array( 'type' => 'string', 'class' => 'personal' );
check( 'manifest: violation codes carry no value', ! str_contains( implode( ' ', Manifest::violations( $m ) ), 'email' ) );

// ----- Registry (WF-CONNECTOR-02, -03) --------------------------------------------------------------------------

$c = conn_site();
check( 'registry: valid connector is accepted', true === $c->connectors->add( conn_manifest(), new TestAdapter( 'learnboating-connector' ) ) );
check( 'registry: accepted connector is in the adapter registry as the core wrapper', $c->registry->get( 'learnboating-connector' ) instanceof ConnectorAdapter );
check( 'registry: accepted connector waits for release (not_enabled)', ConnectorRegistry::NOT_ENABLED === $c->connectors->reason( 'learnboating-connector' ) && ! $c->connectors->is_enabled( 'learnboating-connector' ) );
check( 'registry: second registration of the same key refused (duplicate), first one kept', false === $c->connectors->add( conn_manifest(), new TestAdapter( 'learnboating-connector' ) ) && ConnectorRegistry::DUPLICATE === $c->connectors->refused()['learnboating-connector (2)']['reason'] && null !== $c->connectors->get( 'learnboating-connector' ) );
check( 'registry: adapter product other than the manifest key refused (invalid_manifest)', false === $c->connectors->add( conn_manifest( 'crm-bridge' ), new TestAdapter( 'other-bridge' ) ) && ConnectorRegistry::INVALID_MANIFEST === $c->connectors->reason( 'crm-bridge' ) && in_array( 'adapter:product_mismatch', $c->connectors->refused()['crm-bridge']['violations'], true ) );
check( 'registry: invalid manifest refused with violation codes', false === $c->connectors->add( array( 'version' => 'x' ) + conn_manifest( 'berth-booking' ), new TestAdapter( 'berth-booking' ) ) && array( 'version:invalid' ) === $c->connectors->refused()['berth-booking']['violations'] );
check( 'registry: newer core required refused (requires_newer_core)', false === $c->connectors->add( array( 'requires' => array( 'workflow_captain' => '0.2.0' ) ) + conn_manifest( 'team-chat' ), new TestAdapter( 'team-chat' ) ) && ConnectorRegistry::REQUIRES_NEWER_CORE === $c->connectors->reason( 'team-chat' ) );
$c2 = conn_site( '1.0.0' );
check( 'registry: built for another major of the core refused (contract_major)', false === $c2->connectors->add( conn_manifest(), new TestAdapter( 'learnboating-connector' ) ) && ConnectorRegistry::CONTRACT_MAJOR === $c2->connectors->reason( 'learnboating-connector' ) && null === $c2->registry->get( 'learnboating-connector' ) );
check( 'registry: AdapterRegistry::add() still refuses connector keys', false === ( new AdapterRegistry() )->add( new TestAdapter( 'learnboating-connector' ) ) );
check( 'registry: refused connectors appear in the rows with reason', 'refused' === array_column( $c->connectors->rows(), null, 'key' )['team-chat']['status'] && 'disabled' === array_column( $c->connectors->rows(), null, 'key' )['learnboating-connector']['status'] );
check( 'registry: other adapters keep running after refusals', null !== $c->registry->get( 'pass-captain' ) );

// ----- Release and filtering at capture (WF-CONNECTOR-04, -11) --------------------------------------------------

$c     = conn_site();
$inner = new TestAdapter( 'learnboating-connector' );
$c->connectors->add( conn_manifest(), $inner );
check( 'release: disabled connector: event refused with reason code', Consumer::REJECTED === $c->consumer->capture( $inner, conn_event() ) && array( 'connector:not_enabled' ) === end( $c->rejected ) );
check( 'release: disabled connector: nothing stored', array() === $c->store->find( array(), 10, 0 ) );
check( 'release: disabled connector: no event types for workflows', array() === $c->registry->get( 'learnboating-connector' )->types() && array() === $c->connectors->event_types() );
$c->release = array( 'learnboating-connector' => array( 'enabled' => true ) );
check( 'release: enabled connector: event captured', Consumer::CAPTURED === $c->consumer->capture( $inner, conn_event( 'xlearnboatingconnector.access.changed', array( 'birth_year' => 1980, 'extra_score' => 5 ) ) ) );
$row  = only_row( $c );
$data = $row['envelope']['data'] ?? array();
check( 'R-5.2 undeclared field dropped at capture', ! array_key_exists( 'extra_score', $data ) && 7 === ( $data['course_id'] ?? null ) );
check( 'WF-CONNECTOR-11 personal field dropped until enabled', ! array_key_exists( 'birth_year', $data ) );
check( 'envelope: source.product is the connector key, contract version from the manifest', 'learnboating-connector' === $row['envelope']['source']['product'] && '1.0.0' === $row['envelope']['source']['contract_version'] && '2.1.0' === $row['envelope']['source']['core_version'] );
$c->release = array( 'learnboating-connector' => $c->connectors->release_entry( 'learnboating-connector', true, array( 'xlearnboatingconnector.access.changed' => array( 'birth_year', 'status', 'nonsense' ) ) ) );
check( 'release entry keeps only declared personal fields', array( 'xlearnboatingconnector.access.changed' => array( 'birth_year' ) ) === $c->release['learnboating-connector']['personal_fields'] );
$c->consumer->capture( $inner, array( 'subject' => array( 'type' => 'access', 'id' => '992', 'id_kind' => 'record' ) ) + conn_event( 'xlearnboatingconnector.access.changed', array( 'birth_year' => 1980 ) ) );
$rows = $c->store->find( array(), 10, 0 );
$with = array_values( array_filter( $rows, fn( $r ) => '992' === $r['envelope']['subject']['id'] ) )[0] ?? array();
check( 'WF-CONNECTOR-11 personal field kept once enabled per field', 1980 === ( $with['envelope']['data']['birth_year'] ?? null ) );
$before = count( $rows );
check( 'capture: value of the wrong data type refuses the event', Consumer::REJECTED === $c->consumer->capture( $inner, conn_event( 'xlearnboatingconnector.access.changed', array( 'course_id' => '7' ) ) ) && array( 'connector:field_type' ) === end( $c->rejected ) );
check( 'capture: undeclared event type refused', Consumer::REJECTED === $c->consumer->capture( $inner, conn_event( 'xlearnboatingconnector.access.deleted' ) ) && array( 'connector:undeclared_type' ) === end( $c->rejected ) );
check( 'R-4.10 connector type with a Captain product rejected (type:product_mismatch)', Consumer::REJECTED === $c->consumer->capture( $c->adapter, conn_event() ) && in_array( 'type:product_mismatch', end( $c->rejected ), true ) );
$wrong = array( 'source' => array( 'product' => 'crm-bridge', 'core_version' => '1.0.0', 'contract_version' => null ), 'type' => 'xlearnboatingconnector.access.changed' );
check( 'R-4.10 validator: connector type with another connector key is a mismatch', in_array( 'type:product_mismatch', Validator::violations( $wrong ), true ) );
check( 'capture: unregistered connector key refused, nothing stored', Consumer::REJECTED === $c->consumer->capture( new TestAdapter( 'crm-bridge' ), conn_event( 'xcrmbridge.contact.created' ) ) && array( 'connector:not_registered' ) === end( $c->rejected ) );
$rogue = new ConnectorAdapter( new TestAdapter( 'learnboating-connector' ), array( 'key' => 'learnboating-connector', 'version' => '9.9.9', 'contract_version' => '1.0.0', 'events' => array( 'xlearnboatingconnector.access.changed' => array( 'fields' => array( 'extra_score' => array( 'type' => 'integer', 'class' => 'normal' ) ), 'after_commit' => true, 'actor' => '' ) ) ), new ConnectorRegistry( new AdapterRegistry(), fn() => array( 'learnboating-connector' => array( 'enabled' => true ) ), '0.1.0' ) );
$c->consumer->capture( $rogue, array( 'subject' => array( 'type' => 'access', 'id' => '993', 'id_kind' => 'record' ) ) + conn_event( 'xlearnboatingconnector.access.changed', array( 'extra_score' => 5 ) ) );
$rogue_row = array_values( array_filter( $c->store->find( array(), 20, 0 ), fn( $r ) => '993' === $r['envelope']['subject']['id'] ) )[0] ?? array();
check( 'bypass: a self-made wrapper is ignored, the registered manifest applies', ! array_key_exists( 'extra_score', $rogue_row['envelope']['data'] ?? array( 'extra_score' => 1 ) ) && '2.1.0' === ( $rogue_row['envelope']['source']['core_version'] ?? '' ) );
check( 'capture: refusals never store and never carry values', $before + 1 === count( $c->store->find( array(), 20, 0 ) ) && ! str_contains( (string) json_encode( $c->rejected ), '"7"' ) );

// Disabled after storing: open events pause, nothing is lost.
$c          = conn_site();
$inner      = new TestAdapter( 'learnboating-connector' );
$c->release = array( 'learnboating-connector' => array( 'enabled' => true ) );
$c->connectors->add( conn_manifest(), $inner );
$c->consumer->capture( $inner, conn_event() );
$c->release = array();
$c->consumer->process();
check( 'release: event stored while enabled pauses when the connector is switched off', EventStore::PAUSED === only_row( $c )['state'] && 'connector_not_enabled' === only_row( $c )['last_error'] && array() === $c->dispatcher->calls );
$c->release = array( 'learnboating-connector' => array( 'enabled' => true ) );
$c->consumer->requeue( (int) only_row( $c )['id'] );
$c->consumer->process();
check( 'release: requeued after switching on again, it is dispatched', EventStore::DONE === only_row( $c )['state'] && 1 === count( $c->dispatcher->calls ) );

// ----- Builder and actions (WF-CONNECTOR-10) --------------------------------------------------------------------

$c = conn_site();
$c->connectors->add( conn_manifest(), new TestAdapter( 'learnboating-connector' ) );
check( 'builder: no fields of a disabled connector', array() === $c->connectors->builder_fields() );
$c->release = array( 'learnboating-connector' => array( 'enabled' => true ) );
$fields     = $c->connectors->builder_fields();
check( 'builder: fields with data types of an enabled connector, personal ones left out', array( 'course_id' => 'integer', 'status' => 'string', 'valid_until' => 'datetime' ) === ( $fields['xlearnboatingconnector.access.changed'] ?? null ) );
check( 'builder: event types of an enabled connector', array( 'xlearnboatingconnector.access.changed' ) === $c->connectors->event_types() );
$c->release = array();
check( 'actions: x action unavailable while the connector is off', false === $c->connectors->action_available( 'xlearnboatingconnector.access.grant' ) );
$c->release = array( 'learnboating-connector' => array( 'enabled' => true ) );
check( 'actions: x action available once enabled', true === $c->connectors->action_available( 'xlearnboatingconnector.access.grant' ) );
check( 'actions: x action not in the manifest stays unavailable', false === $c->connectors->action_available( 'xlearnboatingconnector.access.revoke' ) );
check( 'actions: x action of an unknown connector unavailable', false === $c->connectors->action_available( 'xmembership.access.grant' ) );
check( 'actions: core actions are always available', true === $c->connectors->action_available( 'notify.admin' ) );

// ----- Engine end to end with the starter connector (WF-CONNECTOR-05) -------------------------------------------

$e          = engine_site();
$e->release = array();
$e->conn    = new ConnectorRegistry( $e->registry, fn() => $e->release, '0.1.0' );
$e->actions->set_availability( array( $e->conn, 'action_available' ) );
$note       = new TicketNoteAction();
check( 'starter: action key with x prefix is accepted by the action registry', true === $e->actions->add( $note ) );
$starter = new TicketAdapter( fn() => $e->consumer );
check( 'starter: connector registers', true === $e->conn->add( StarterManifest::get(), $starter ) );
$graph = array(
	'nodes' => array(
		array( 'id' => 't', 'type' => 'trigger', 'event' => 'xstarterconnector.ticket.closed' ),
		array( 'id' => 'c', 'type' => 'condition', 'rules' => array( 'op' => 'and', 'items' => array( array( 'field' => 'data.priority', 'operator' => 'eq', 'value' => 'high' ) ) ), 'missing' => 'false' ),
		array( 'id' => 'n', 'type' => 'action', 'action' => 'xstarterconnector.ticket.note', 'config' => array( 'label' => 'eskaliert' ) ),
		array( 'id' => 'e1', 'type' => 'end' ),
		array( 'id' => 'e2', 'type' => 'end' ),
	),
	'edges' => array(
		array( 'from' => 't', 'to' => 'c' ),
		array( 'from' => 'c', 'to' => 'n', 'branch' => 'then' ),
		array( 'from' => 'c', 'to' => 'e2', 'branch' => 'else' ),
		array( 'from' => 'n', 'to' => 'e1' ),
	),
);
check( 'starter: action not offered and graph not publishable while the connector is off', null === $e->actions->get( 'xstarterconnector.ticket.note' ) && ! isset( $e->actions->all()['xstarterconnector.ticket.note'] ) && array() !== Graph::violations( $graph, $e->actions ) );
check( 'starter: event of the disabled connector is refused and not stored', Consumer::REJECTED === $starter->on_ticket_closed( 42, 'high' ) && array() === $e->events->find( array(), 10, 0 ) );
$e->release = array( StarterManifest::KEY => array( 'enabled' => true ) );
check( 'starter: graph valid once enabled', array() === Graph::violations( $graph, $e->actions ) );
$wf = publish( $e, $graph, 'Starter' );
check( 'starter: event captured from the demo hook', Consumer::CAPTURED === $starter->on_ticket_closed( 42, 'high' ) );
check( 'starter: duplicate hook call is a duplicate (idempotency key)', EventStore::DUPLICATE === $starter->on_ticket_closed( 42, 'high' ) );
drain( $e );
$run   = run_of( $e );
$notes = get_option( TicketNoteAction::OPTION, array() );
check( 'starter: enabled connector event starts a workflow run that succeeds', EngineStore::RUN_SUCCEEDED === ( $run['state'] ?? '' ) && $wf === ( $run['workflow_id'] ?? 0 ) );
check( 'starter: note written once with the step idempotency key', 1 === count( $notes ) && 42 === ( array_values( $notes )[0]['ticket_id'] ?? 0 ) && 'eskaliert' === ( array_values( $notes )[0]['label'] ?? '' ) );
$step   = array_values( array_filter( steps_of( $e ), fn( $s ) => 'n' === $s['node_id'] ) )[0] ?? array();
$ctx    = new \WorkflowCaptain\Engine\ActionContext( $run['context'] ?? array(), array( 'label' => 'eskaliert' ), (string) array_keys( $notes )[0], $wf, (int) $run['id'], (int) ( $step['id'] ?? 0 ), 1 );
check( 'starter: repeating the action with the same key writes nothing again', array( 'ticket_id' => 42, 'repeated' => true ) === $note->execute( $ctx ) && 1 === count( get_option( TicketNoteAction::OPTION, array() ) ) );
check( 'starter: invalid label refused by validate_config', array( 'label:invalid' ) === $note->validate_config( array( 'label' => 'Freitext mit Leerzeichen' ) ) );
$e->release = array();
check( 'starter: action unavailable again once the connector is switched off', null === $e->actions->get( 'xstarterconnector.ticket.note' ) );
check( 'starter: connector is not shipped (export-ignore) and not inside src/', str_contains( (string) file_get_contents( WORKFLOW_CAPTAIN_PATH . '.gitattributes' ), '/examples export-ignore' ) && ! is_dir( WORKFLOW_CAPTAIN_PATH . 'src/Connector/starter-connector' ) );
check( 'install: manage_workflow_integrations is a capability of the core', in_array( 'manage_workflow_integrations', \WorkflowCaptain\Install::CAPS, true ) );

// ----- WF-CONNECTOR-12: Pro can bind the connector runtime to workflow.custom_connectors
$GLOBALS['wfc_test_filters']['workflow_captain_connector_runtime_allowed_v1'][] = fn( $allowed, $key ) => 'blocked-connector' !== $key;
$reg_rt = new \WorkflowCaptain\Connector\ConnectorRegistry( new \WorkflowCaptain\Adapter\AdapterRegistry(), fn() => array(), '0.1.0' );
check( 'WF-CONNECTOR-12 runtime switch: a refused runtime keeps the connector off with its own reason', false === $reg_rt->add( conn_manifest( 'blocked-connector' ), new TestAdapter( 'blocked-connector' ) ) && \WorkflowCaptain\Connector\ConnectorRegistry::RUNTIME_NOT_ALLOWED === $reg_rt->reason( 'blocked-connector' ) );
unset( $GLOBALS['wfc_test_filters']['workflow_captain_connector_runtime_allowed_v1'] );
