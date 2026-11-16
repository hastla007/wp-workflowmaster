<?php
/**
 * Personal data in the consumer's store (docs/SPEC-suite-events.md, section 12): export, erasure, deleted users and
 * retention. Only actor_user_id is personal in an envelope; data never holds e-mail addresses, names or codes (R-12.3).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Privacy;

use WorkflowCaptain\Clock;
use WorkflowCaptain\Store\EventStore;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	const PAGE_SIZE = 100;

	/** Proposal of R-12.4 (open, F-04): finished events 30 days, dead_letter and paused 90 days. */
	const RETENTION_DAYS = array(
		'finished' => 30,
		'stuck'    => 90,
	);

	/** @var callable():EventStore */
	private $store_provider;

	/** @param callable():EventStore $store_provider Resolved lazily, so registering the hooks opens no database. */
	public function __construct( callable $store_provider, private ?Clock $clock = null ) {
		$this->store_provider = $store_provider;
		$this->clock          = $clock ?? new Clock();
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'deleted_user', array( $this, 'on_deleted_user' ) );
	}

	public function add_exporter( array $exporters ): array {
		$exporters['workflow-captain'] = array(
			'exporter_friendly_name' => __( 'Workflow Captain: gespeicherte Ereignisse', 'workflow-captain' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	public function add_eraser( array $erasers ): array {
		$erasers['workflow-captain'] = array(
			'eraser_friendly_name' => __( 'Workflow Captain: gespeicherte Ereignisse', 'workflow-captain' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/** Exporter callback: every stored event with this user as actor (R-12.5, C-13). */
	public function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$rows  = $this->store()->by_actor( (int) $user->ID, self::PAGE_SIZE, ( max( 1, $page ) - 1 ) * self::PAGE_SIZE );
		$items = array();
		foreach ( $rows as $row ) {
			$envelope = $row['envelope'];
			$fields   = array(
				array( 'name' => __( 'Ereignis', 'workflow-captain' ), 'value' => $row['type'] ),
				array( 'name' => __( 'Objekt', 'workflow-captain' ), 'value' => $row['subject_type'] . ' ' . $row['subject_id'] ),
				array( 'name' => __( 'Zeitpunkt (UTC)', 'workflow-captain' ), 'value' => (string) ( $envelope['occurred_at'] ?? '' ) ),
				array( 'name' => __( 'Zustand', 'workflow-captain' ), 'value' => $row['state'] ),
			);
			foreach ( (array) ( $envelope['data'] ?? array() ) as $name => $value ) {
				$fields[] = array( 'name' => (string) $name, 'value' => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
			}
			$items[] = array(
				'group_id'    => 'workflow-captain-events',
				'group_label' => __( 'Workflow Captain: Ereignisse', 'workflow-captain' ),
				'item_id'     => 'workflow-captain-event-' . $row['id'],
				'data'        => $fields,
			);
		}
		return array( 'data' => $items, 'done' => count( $rows ) < self::PAGE_SIZE );
	}

	/** Eraser callback: removes the user from every stored event; the events stay without actor (R-12.5, C-13). */
	public function erase( string $email, int $page = 1 ): array {
		$user  = get_user_by( 'email', $email );
		$count = $user ? $this->store()->anonymize_actor( (int) $user->ID, $this->clock->mysql() ) : 0;
		return array(
			'items_removed'  => $count > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	public function on_deleted_user( $user_id ): void {
		$this->store()->anonymize_actor( (int) $user_id, $this->clock->mysql() );
	}

	/**
	 * Deletes stored events after the retention period (R-12.4, C-14). Open events (received, processing, retry_wait)
	 * are never deleted here. The periods can only be shortened.
	 */
	public function apply_retention(): void {
		$clock = $this->clock;
		foreach ( self::RETENTION_DAYS as $group => $default ) {
			/**
			 * Retention in days for finished events ("finished") and for dead_letter/paused ("stuck"); only lower values
			 * than the default take effect.
			 *
			 * @since 0.1.0
			 */
			$days   = max( 1, min( $default, (int) apply_filters( 'workflow_captain_retention_days', $default, $group ) ) );
			$states = 'finished' === $group
				? array( EventStore::DONE, EventStore::DISCARDED, EventStore::LOOP_BLOCKED )
				: array( EventStore::DEAD_LETTER, EventStore::PAUSED );
			$this->store()->delete_older( $states, $clock->mysql( $clock->now() - $days * DAY_IN_SECONDS ) );
		}
	}

	private function store(): EventStore {
		return ( $this->store_provider )();
	}
}
