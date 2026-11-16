<?php
/**
 * Re-encryption after a key change (WF-SEC-02, konzept 9.1). Add-ons that keep secrets with the Vault register a
 * store on the filter "workflow_captain_secret_stores":
 *
 *     add_filter( 'workflow_captain_secret_stores', function ( $stores ) {
 *         $stores['webhooks'] = array(
 *             'context' => 'webhook',
 *             'load'    => fn() => array( 'conn-1' => 'wcv1:…' ),           // id => stored value
 *             'save'    => function ( string $id, string $stored ) { … },
 *         );
 *         return $stores;
 *     } );
 *
 * The result counts values only; it never contains a secret, an id is only used to save.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Security;

defined( 'ABSPATH' ) || exit;

final class SecretStores {

	/** @return array<string,array{context:string,load:callable,save:callable}> */
	public static function registered(): array {
		$stores = function_exists( 'apply_filters' ) ? apply_filters( 'workflow_captain_secret_stores', array() ) : array();
		return array_filter( is_array( $stores ) ? $stores : array(), static fn( $s ) => is_array( $s ) && is_string( $s['context'] ?? null ) && is_callable( $s['load'] ?? null ) && is_callable( $s['save'] ?? null ) );
	}

	/**
	 * Counts per store: readable with the current key, re-encrypted (or re-encryptable in a dry run), unreadable.
	 *
	 * @return array<string,array{current:int,rekeyed:int,unreadable:int}>
	 */
	public static function rekey( Vault $vault, array $stores, bool $dry_run = false ): array {
		$out = array();
		foreach ( $stores as $name => $store ) {
			$count = array( 'current' => 0, 'rekeyed' => 0, 'unreadable' => 0 );
			foreach ( (array) ( $store['load'] )() as $id => $stored ) {
				$stored = (string) $stored;
				if ( null !== $vault->decrypt( $stored, $store['context'] ) && null === $vault->reencrypt( $stored, $store['context'] ) ) {
					++$count['current'];
					continue;
				}
				$new = $vault->reencrypt( $stored, $store['context'] );
				if ( null === $new ) {
					++$count['unreadable'];
					continue;
				}
				if ( ! $dry_run ) {
					( $store['save'] )( (string) $id, $new );
				}
				++$count['rekeyed'];
			}
			$out[ (string) $name ] = $count;
		}
		return $out;
	}
}
