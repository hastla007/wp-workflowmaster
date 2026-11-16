<?php
/**
 * Export and import of workflows (WF-SET-12). The file holds name and draft graph with a format version; an import
 * always becomes a draft and is checked like any draft (graph, providers). Nothing is published on import, and no
 * site-specific IDs, run data or secrets are exported.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

final class Transfer {

	const FORMAT         = 'workflow-captain/workflow';
	const FORMAT_VERSION = 1;
	const MAX_BYTES      = 512000;

	/** @return array<string,mixed> */
	public static function export( array $workflow, string $plugin_version ): array {
		$graph = (array) $workflow['draft'];
		return array(
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => $plugin_version,
			'exported_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'name'           => (string) $workflow['name'],
			'graph'          => array(
				'nodes' => array_values( (array) ( $graph['nodes'] ?? array() ) ),
				'edges' => array_values( (array) ( $graph['edges'] ?? array() ) ),
			),
		);
	}

	/**
	 * Imports a file as a new draft.
	 *
	 * @return array{ok:bool,id:int,violations:string[]} violations: format errors, or what would block publishing.
	 */
	public static function import( string $json, Workflows $workflows ): array {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return array( 'ok' => false, 'id' => 0, 'violations' => array( 'import:too_large' ) );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return array( 'ok' => false, 'id' => 0, 'violations' => array( 'import:not_json' ) );
		}
		if ( self::FORMAT !== ( $data['format'] ?? null ) ) {
			return array( 'ok' => false, 'id' => 0, 'violations' => array( 'import:unknown_format' ) );
		}
		if ( ! is_int( $data['format_version'] ?? null ) || $data['format_version'] > self::FORMAT_VERSION || $data['format_version'] < 1 ) {
			return array( 'ok' => false, 'id' => 0, 'violations' => array( 'import:unsupported_version' ) );
		}
		$graph = $data['graph'] ?? null;
		if ( ! is_array( $graph ) || ! is_array( $graph['nodes'] ?? null ) || ! is_array( $graph['edges'] ?? null ) ) {
			return array( 'ok' => false, 'id' => 0, 'violations' => array( 'import:graph_missing' ) );
		}
		$graph = array( 'nodes' => array_values( $graph['nodes'] ), 'edges' => array_values( $graph['edges'] ) );
		$name  = is_string( $data['name'] ?? null ) ? $data['name'] : '';
		list( $id, $violations ) = $workflows->save( null, $name, $graph );
		return array( 'ok' => true, 'id' => $id, 'violations' => array_merge( $violations, $workflows->availability( $graph ) ) );
	}
}
