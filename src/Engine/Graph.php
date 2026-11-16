<?php
/**
 * Workflow graph (WF-BUILDER-02) and its server-side validation before saving and publishing (WF-BUILDER-06).
 *
 *     { "nodes": [ { "id": "n1", "type": "trigger", "event": "pass.ticket.checked_in" },
 *                  { "id": "n2", "type": "condition", "rules": {…}, "missing": "false" },
 *                  { "id": "n3", "type": "action", "action": "notify.admin", "config": {…} },
 *                  { "id": "n4", "type": "delay", "unit": "days", "amount": 1 },
 *                  { "id": "n5", "type": "end" } ],
 *       "edges": [ { "from": "n1", "to": "n2" }, { "from": "n2", "to": "n3", "branch": "then" }, … ] }
 *
 * Rules: exactly one trigger, every node reachable from it, trigger/action/delay have exactly one outgoing edge, a
 * condition exactly one "then" and one "else", an end none; no cycles (v1). Then every path ends at an end node.
 * Optional "x"/"y" on nodes hold the canvas position and are ignored here.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

use WorkflowCaptain\Envelope\Validator;

defined( 'ABSPATH' ) || exit;

final class Graph {

	const TYPES     = array( 'trigger', 'condition', 'action', 'delay', 'end' );
	const UNITS     = array( 'minutes', 'hours', 'days', 'weeks' );
	const MAX_NODES = 100;

	/**
	 * @return string[] Violation codes ("node:<id>:<rule>", "graph:<rule>"); empty when the graph can be published.
	 */
	public static function violations( $graph, ActionRegistry $actions ): array {
		if ( ! is_array( $graph ) || ! isset( $graph['nodes'], $graph['edges'] ) || ! is_array( $graph['nodes'] ) || ! is_array( $graph['edges'] ) ) {
			return array( 'graph:invalid' );
		}
		$v     = array();
		$nodes = array();
		foreach ( $graph['nodes'] as $node ) {
			$id = is_array( $node ) ? ( $node['id'] ?? null ) : null;
			if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ) {
				$v[] = 'graph:invalid_node_id';
				continue;
			}
			if ( isset( $nodes[ $id ] ) ) {
				$v[] = 'node:' . $id . ':duplicate_id';
				continue;
			}
			$nodes[ $id ] = $node;
		}
		if ( count( $nodes ) > self::MAX_NODES ) {
			$v[] = 'graph:too_many_nodes';
		}
		$triggers = array_keys( array_filter( $nodes, static fn( $n ) => 'trigger' === ( $n['type'] ?? '' ) ) );
		if ( 1 !== count( $triggers ) ) {
			$v[] = 'graph:needs_exactly_one_trigger';
		}

		foreach ( $nodes as $id => $node ) {
			$type = $node['type'] ?? '';
			if ( ! in_array( $type, self::TYPES, true ) ) {
				$v[] = 'node:' . $id . ':unknown_type';
				continue;
			}
			if ( 'trigger' === $type && ( ! is_string( $node['event'] ?? null ) || ! preg_match( Validator::TYPE, $node['event'] ) ) ) {
				$v[] = 'node:' . $id . ':invalid_event';
			}
			if ( 'action' === $type ) {
				$action = is_string( $node['action'] ?? null ) ? $actions->get( $node['action'] ) : null;
				if ( null === $action ) {
					$v[] = 'node:' . $id . ':unknown_action';
				} else {
					foreach ( $action->validate_config( is_array( $node['config'] ?? null ) ? $node['config'] : array() ) as $code ) {
						$v[] = 'node:' . $id . ':' . $code;
					}
				}
			}
			if ( 'condition' === $type ) {
				foreach ( Conditions::validate( $node['rules'] ?? null ) as $code ) {
					$v[] = 'node:' . $id . ':' . $code;
				}
				if ( ! in_array( $node['missing'] ?? 'false', Conditions::MISSING, true ) ) {
					$v[] = 'node:' . $id . ':invalid_missing_mode';
				}
			}
			if ( 'delay' === $type && ( ! in_array( $node['unit'] ?? '', self::UNITS, true ) || ! is_int( $node['amount'] ?? null ) || $node['amount'] < 1 || $node['amount'] > 1000 ) ) {
				$v[] = 'node:' . $id . ':invalid_delay';
			}
		}

		$out = array_fill_keys( array_keys( $nodes ), array() );
		$in  = array_fill_keys( array_keys( $nodes ), 0 );
		foreach ( $graph['edges'] as $edge ) {
			$from   = is_array( $edge ) ? ( $edge['from'] ?? null ) : null;
			$to     = is_array( $edge ) ? ( $edge['to'] ?? null ) : null;
			$branch = is_array( $edge ) ? ( $edge['branch'] ?? null ) : null;
			if ( ! is_string( $from ) || ! is_string( $to ) || ! isset( $nodes[ $from ], $nodes[ $to ] ) ) {
				$v[] = 'graph:edge_to_unknown_node';
				continue;
			}
			if ( 'trigger' === ( $nodes[ $to ]['type'] ?? '' ) ) {
				$v[] = 'node:' . $to . ':trigger_has_incoming_edge';
			}
			$is_condition = 'condition' === ( $nodes[ $from ]['type'] ?? '' );
			if ( $is_condition && ! in_array( $branch, array( 'then', 'else' ), true ) ) {
				$v[] = 'node:' . $from . ':branch_needs_then_or_else';
			}
			if ( ! $is_condition && null !== $branch ) {
				$v[] = 'node:' . $from . ':branch_only_on_condition';
			}
			$out[ $from ][] = array( $to, $branch );
			++$in[ $to ];
		}

		foreach ( $nodes as $id => $node ) {
			$type     = $node['type'] ?? '';
			$branches = array_map( static fn( $e ) => $e[1], $out[ $id ] );
			if ( 'end' === $type && array() !== $out[ $id ] ) {
				$v[] = 'node:' . $id . ':end_has_outgoing_edge';
			} elseif ( 'condition' === $type ) {
				if ( 1 !== count( array_keys( $branches, 'then', true ) ) || 1 !== count( array_keys( $branches, 'else', true ) ) || 2 !== count( $branches ) ) {
					$v[] = 'node:' . $id . ':needs_one_then_and_one_else';
				}
			} elseif ( in_array( $type, array( 'trigger', 'action', 'delay' ), true ) && 1 !== count( $out[ $id ] ) ) {
				$v[] = 'node:' . $id . ':needs_exactly_one_outgoing_edge';
			}
		}

		if ( 1 === count( $triggers ) ) {
			$seen  = array();
			$stack = array( $triggers[0] );
			while ( $stack ) {
				$id = array_pop( $stack );
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				foreach ( $out[ $id ] as $edge ) {
					$stack[] = $edge[0];
				}
			}
			foreach ( array_keys( $nodes ) as $id ) {
				if ( ! isset( $seen[ $id ] ) ) {
					$v[] = 'node:' . $id . ':unreachable';
				}
			}
		}
		if ( self::has_cycle( $out ) ) {
			$v[] = 'graph:cycle';
		}
		return array_values( array_unique( $v ) );
	}

	/** @return array|null The node with this ID. */
	public static function node( array $graph, string $id ): ?array {
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( ( $node['id'] ?? null ) === $id ) {
				return $node;
			}
		}
		return null;
	}

	public static function trigger( array $graph ): ?array {
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( 'trigger' === ( $node['type'] ?? '' ) ) {
				return $node;
			}
		}
		return null;
	}

	/** ID of the node after $id, on $branch for a condition. */
	public static function next( array $graph, string $id, ?string $branch = null ): ?string {
		foreach ( $graph['edges'] ?? array() as $edge ) {
			if ( ( $edge['from'] ?? null ) === $id && ( $edge['branch'] ?? null ) === $branch ) {
				return (string) $edge['to'];
			}
		}
		return null;
	}

	/** Action keys the graph uses. */
	public static function action_keys( array $graph ): array {
		$keys = array();
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( 'action' === ( $node['type'] ?? '' ) && is_string( $node['action'] ?? null ) ) {
				$keys[] = $node['action'];
			}
		}
		return array_values( array_unique( $keys ) );
	}

	private static function has_cycle( array $out ): bool {
		$state = array();
		foreach ( array_keys( $out ) as $start ) {
			if ( isset( $state[ $start ] ) ) {
				continue;
			}
			$stack = array( array( $start, 0 ) );
			while ( $stack ) {
				$top            = count( $stack ) - 1;
				list( $id, $i ) = $stack[ $top ];
				if ( 0 === $i ) {
					$state[ $id ] = 1;
				}
				if ( $i < count( $out[ $id ] ) ) {
					$stack[ $top ][1] = $i + 1;
					$next             = $out[ $id ][ $i ][0];
					if ( 1 === ( $state[ $next ] ?? 0 ) ) {
						return true;
					}
					if ( ! isset( $state[ $next ] ) ) {
						$stack[] = array( $next, 0 );
					}
				} else {
					$state[ $id ] = 2;
					array_pop( $stack );
				}
			}
		}
		return false;
	}
}
