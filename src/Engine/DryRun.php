<?php
/**
 * Test run with sample data (WF-BUILDER-10): walks the graph like the engine, evaluates conditions, renders message
 * texts, but executes no action and waits for no delay. No side effects, nothing stored.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class DryRun {

	const MAX_STEPS = 100;

	/**
	 * @param array $graph  Workflow graph (src/Engine/Graph.php).
	 * @param array $sample Sample event: at least "data"; type, subject and actor_user_id are filled in when missing.
	 * @return array{path:array<int,array<string,mixed>>,outcome:string,error_node:?string}
	 */
	public static function run( array $graph, array $sample, ActionRegistry $actions ): array {
		$trigger = Graph::trigger( $graph );
		if ( null === $trigger ) {
			return array( 'path' => array(), 'outcome' => 'no_trigger', 'error_node' => null );
		}
		$envelope = $sample + array(
			'type'        => (string) ( $trigger['event'] ?? '' ),
			'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'source'      => array( 'product' => 'sample' ),
			'subject'     => array( 'type' => 'sample', 'id' => '1', 'id_kind' => 'record' ),
			'data'        => array(),
		);
		$path = array( array( 'node' => (string) $trigger['id'], 'type' => 'trigger' ) );
		$id   = Graph::next( $graph, (string) $trigger['id'] );
		for ( $i = 0; null !== $id && $i < self::MAX_STEPS; $i++ ) {
			$node = Graph::node( $graph, $id );
			if ( null === $node ) {
				return array( 'path' => $path, 'outcome' => 'node_missing', 'error_node' => $id );
			}
			$entry = array( 'node' => $id, 'type' => (string) $node['type'] );
			switch ( $node['type'] ) {
				case 'end':
					$path[] = $entry;
					return array( 'path' => $path, 'outcome' => 'succeeded', 'error_node' => null );
				case 'condition':
					$result           = Conditions::evaluate( (array) ( $node['rules'] ?? array() ), $envelope, (string) ( $node['missing'] ?? 'false' ) );
					$entry['outcome'] = $result;
					$path[]           = $entry;
					if ( Conditions::STOP === $result ) {
						return array( 'path' => $path, 'outcome' => 'stopped', 'error_node' => null );
					}
					if ( Conditions::ERROR === $result ) {
						return array( 'path' => $path, 'outcome' => 'condition_field_missing', 'error_node' => $id );
					}
					$id = Graph::next( $graph, $id, Conditions::TRUE === $result ? 'then' : 'else' );
					continue 2;
				case 'delay':
					$entry['wait'] = (int) ( $node['amount'] ?? 0 ) . ' ' . (string) ( $node['unit'] ?? '' );
					break;
				case 'action':
					$action = $actions->get( (string) ( $node['action'] ?? '' ) );
					if ( null === $action ) {
						$path[] = $entry + array( 'action' => (string) ( $node['action'] ?? '' ) );
						return array( 'path' => $path, 'outcome' => 'action_unavailable', 'error_node' => $id );
					}
					$config          = (array) ( $node['config'] ?? array() );
					$entry['action'] = $action->key();
					$entry['would']  = array();
					foreach ( array( 'subject', 'message', 'text', 'tag' ) as $field ) {
						if ( isset( $config[ $field ] ) && is_string( $config[ $field ] ) ) {
							$entry['would'][ $field ] = Template::render( $config[ $field ], $envelope );
						}
					}
					break;
			}
			$path[] = $entry;
			$id     = Graph::next( $graph, $id );
		}
		return array( 'path' => $path, 'outcome' => null === $id ? 'succeeded' : 'too_long', 'error_node' => null );
	}
}
