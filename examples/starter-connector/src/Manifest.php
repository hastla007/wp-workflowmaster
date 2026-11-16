<?php
/**
 * Manifest of the starter connector (format: docs/CONNECTOR-CONTRACT.md of Workflow Captain).
 *
 * @package WfcStarterConnector
 */

namespace WfcStarterConnector;

defined( 'ABSPATH' ) || exit;

final class Manifest {

	const KEY = 'starter-connector';

	public static function get(): array {
		return array(
			'key'              => self::KEY,
			'name'             => 'Starter Connector',
			'version'          => '1.0.0',
			'contract_version' => '1.0.0',
			'requires'         => array( 'workflow_captain' => '0.1.0' ),
			'events'           => array(
				'xstarterconnector.ticket.closed' => array(
					'fields'       => array(
						'ticket_id' => array( 'type' => 'integer', 'class' => 'normal' ),
						'priority'  => array( 'type' => 'string', 'class' => 'normal' ),
					),
					'after_commit' => true,
					'actor'        => 'logged-in user who closed the ticket',
				),
			),
			'actions'          => array( 'xstarterconnector.ticket.note' ),
		);
	}
}
