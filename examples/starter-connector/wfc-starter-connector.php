<?php
/**
 * Plugin Name:       WFC Starter Connector
 * Description:       Beispiel für einen eigenen Connector von Workflow Captain: ein Ereignis, eine Aktion, ein Manifest.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  workflow-captain
 * License:           GPL-2.0-or-later
 * Text Domain:       wfc-starter-connector
 *
 * @package WfcStarterConnector
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/Manifest.php';
require_once __DIR__ . '/src/TicketAdapter.php';
require_once __DIR__ . '/src/TicketNoteAction.php';

/*
 * Without Workflow Captain these hooks simply never fire: the connector keeps working on its own.
 */

// 1. Register the connector with its manifest. It stays off until the operator enables it under
// "Workflow Captain → Eigene Connectoren".
add_action(
	'workflow_captain_register_connectors_v1',
	static function ( $registry ): void {
		$adapter = new \WfcStarterConnector\TicketAdapter();
		if ( $registry->add( \WfcStarterConnector\Manifest::get(), $adapter ) ) {
			$adapter->listen();
		}
	}
);

// 2. Offer the action. Workflow Captain offers it only while the connector is enabled.
add_action(
	'workflow_captain_actions_v1',
	static function ( $registry ): void {
		$registry->add( new \WfcStarterConnector\TicketNoteAction() );
	}
);

// 3. Demo trigger: in a real project this is the project's own hook after the ticket was saved.
//    Try it with: wp eval 'do_action( "wfc_starter_ticket_closed", 42, "high" );'
