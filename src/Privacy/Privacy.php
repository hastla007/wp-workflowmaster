<?php
/**
 * Personal data in the consumer's store (docs/SPEC-suite-events.md, section 12): export, erasure, deleted users and
 * retention. Only actor_user_id is personal in an envelope; data never holds e-mail addresses, names or codes (R-12.3).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Privacy;

use WorkflowCaptain\Clock;
use WorkflowCaptain\Engine\EngineStore;
use WorkflowCaptain\Store\EventStore;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	const PAGE_SIZE = 100;

	/**
	 * Longest retention in days. 180 in the free core (E-S7 = F-04); an add-on may raise it with the filter
	 * `workflow_captain_retention_max_days` (never below 1).
	 */
	public static function max_days(): int {
		return max( 1, (int) apply_filters( 'workflow_captain_retention_max_days', \WorkflowCaptain\SettingsRegistry::RETENTION_MAX ) );
	}

	/** Defaults of R-12.4 (F-04, decided 23.11.2026): finished events 30 days, dead_letter and paused 90 days. */
	const RETENTION_DAYS = array(
		'finished' => 30,
		'stuck'    => 90,
	);

	/** @var callable():EventStore */
	private $store_provider;

	/** @param callable():EventStore $store_provider Resolved lazily, so registering the hooks opens no database. */
	/** @var callable():EngineStore|null */
	private $engine_provider;

	/**
	 * @param callable():EventStore       $store_provider  Resolved lazily, so registering the hooks opens no database.
	 * @param callable():EngineStore|null $engine_provider Runs of the workflow engine; they hold a copy of the event.
	 */
	public function __construct( callable $store_provider, private ?Clock $clock = null, ?callable $engine_provider = null ) {
		$this->store_provider  = $store_provider;
		$this->engine_provider = $engine_provider;
		$this->clock          = $clock ?? new Clock();
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'deleted_user', array( $this, 'on_deleted_user' ) );
	}

	public function add_exporter( array $exporters ): array {
		$exporters['workflow-captain'] = array(
			'exporter_friendly_name' => __( 'Workflow Captain: saved events', 'workflow-captain' ),
			'callback'               => array( $this, 'export' ),
		);
		$exporters['workflow-captain-settings'] = array(
			'exporter_friendly_name' => __( 'Workflow Captain: notice recipients', 'workflow-captain' ),
			'callback'               => array( self::class, 'export_settings' ),
		);
		return $exporters;
	}

	public function add_eraser( array $erasers ): array {
		$erasers['workflow-captain'] = array(
			'eraser_friendly_name' => __( 'Workflow Captain: saved events', 'workflow-captain' ),
			'callback'             => array( $this, 'erase' ),
		);
		$erasers['workflow-captain-settings'] = array(
			'eraser_friendly_name' => __( 'Workflow Captain: notice recipients', 'workflow-captain' ),
			'callback'             => array( self::class, 'erase_settings' ),
		);
		return $erasers;
	}

	/**
	 * SET-WC-29 (decided 23.11.2026): an e-mail address in the recipients of the notice mail, or in the settings backup
	 * kept for "undo", is personal data of that person. The address need not belong to a WordPress user.
	 */
	public static function export_settings( string $email, int $page = 1 ): array {
		$email = strtolower( trim( $email ) );
		$items = array();
		$where = array();
		if ( self::in_list( (string) ( \WorkflowCaptain\Notifications::stored()['recipients'] ?? '' ), $email ) ) {
			$where[] = __( 'Recipient of the daily notice email', 'workflow-captain' );
		}
		$backup = function_exists( 'get_option' ) ? get_option( \WorkflowCaptain\SettingsTransfer::BACKUP_OPTION, null ) : null;
		if ( is_array( $backup ) && self::in_list( (string) ( $backup['values']['recipients'] ?? '' ), $email ) ) {
			$where[] = __( 'Backup of the settings for “undo” (kept 30 days)', 'workflow-captain' );
		}
		foreach ( $where as $i => $label ) {
			$items[] = array(
				'group_id'    => 'workflow-captain-settings',
				'group_label' => __( 'Workflow Captain: Settings', 'workflow-captain' ),
				'item_id'     => 'workflow-captain-settings-' . $i,
				'data'        => array(
					array( 'name' => __( 'Email address', 'workflow-captain' ), 'value' => $email ),
					array( 'name' => __( 'Stored as', 'workflow-captain' ), 'value' => $label ),
				),
			);
		}
		return array( 'data' => $items, 'done' => true );
	}

	/** Eraser of SET-WC-29: removes the address from the notice recipients and from the settings backup. */
	public static function erase_settings( string $email, int $page = 1 ): array {
		$email   = strtolower( trim( $email ) );
		$removed = false;
		$notify  = function_exists( 'get_option' ) ? get_option( \WorkflowCaptain\Notifications::OPTION, array() ) : array();
		if ( is_array( $notify ) && self::in_list( (string) ( $notify['recipients'] ?? '' ), $email ) ) {
			$notify['recipients'] = self::without( (string) $notify['recipients'], $email );
			update_option( \WorkflowCaptain\Notifications::OPTION, $notify, false );
			$removed = true;
		}
		$backup = function_exists( 'get_option' ) ? get_option( \WorkflowCaptain\SettingsTransfer::BACKUP_OPTION, null ) : null;
		if ( is_array( $backup ) && self::in_list( (string) ( $backup['values']['recipients'] ?? '' ), $email ) ) {
			$backup['values']['recipients'] = self::without( (string) $backup['values']['recipients'], $email );
			update_option( \WorkflowCaptain\SettingsTransfer::BACKUP_OPTION, $backup, false );
			$removed = true;
		}
		return array( 'items_removed' => $removed, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}

	/** Whether a comma separated address list holds $email (case-insensitive). */
	public static function in_list( string $list, string $email ): bool {
		return '' !== $email && in_array( strtolower( trim( $email ) ), array_map( static fn( $a ) => strtolower( trim( $a ) ), explode( ',', $list ) ), true );
	}

	/** The list without $email, in the stored form ("a, b"). */
	public static function without( string $list, string $email ): string {
		$email = strtolower( trim( $email ) );
		return implode( ', ', array_values( array_filter( array_map( 'trim', explode( ',', $list ) ), static fn( $a ) => '' !== $a && strtolower( $a ) !== $email ) ) );
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
				array( 'name' => __( 'Event', 'workflow-captain' ), 'value' => $row['type'] ),
				array( 'name' => __( 'Object', 'workflow-captain' ), 'value' => $row['subject_type'] . ' ' . $row['subject_id'] ),
				array( 'name' => __( 'Timestamp (UTC)', 'workflow-captain' ), 'value' => (string) ( $envelope['occurred_at'] ?? '' ) ),
				array( 'name' => __( 'State', 'workflow-captain' ), 'value' => $row['state'] ),
			);
			foreach ( (array) ( $envelope['data'] ?? array() ) as $name => $value ) {
				$fields[] = array( 'name' => (string) $name, 'value' => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
			}
			$items[] = array(
				'group_id'    => 'workflow-captain-events',
				'group_label' => __( 'Workflow Captain: Events', 'workflow-captain' ),
				'item_id'     => 'workflow-captain-event-' . $row['id'],
				'data'        => $fields,
			);
		}
		$runs = null === $this->engine_provider ? array() : $this->engine()->runs_by_actor( (int) $user->ID, self::PAGE_SIZE, ( max( 1, $page ) - 1 ) * self::PAGE_SIZE );
		foreach ( $runs as $run ) {
			$items[] = array(
				'group_id'    => 'workflow-captain-runs',
				'group_label' => __( 'Workflow Captain: Runs', 'workflow-captain' ),
				'item_id'     => 'workflow-captain-run-' . $run['id'],
				'data'        => array(
					array( 'name' => __( 'Event', 'workflow-captain' ), 'value' => (string) ( $run['context']['type'] ?? '' ) ),
					array( 'name' => __( 'Object', 'workflow-captain' ), 'value' => (string) ( $run['context']['subject']['type'] ?? '' ) . ' ' . (string) ( $run['context']['subject']['id'] ?? '' ) ),
					array( 'name' => __( 'State', 'workflow-captain' ), 'value' => $run['state'] ),
					array( 'name' => __( 'Started (UTC)', 'workflow-captain' ), 'value' => $run['created_at'] ),
				),
			);
		}
		return array( 'data' => $items, 'done' => count( $rows ) < self::PAGE_SIZE && count( $runs ) < self::PAGE_SIZE );
	}

	/** Eraser callback: removes the user from every stored event; the events stay without actor (R-12.5, C-13). */
	public function erase( string $email, int $page = 1 ): array {
		$user  = get_user_by( 'email', $email );
		$count = $user ? $this->store()->anonymize_actor( (int) $user->ID, $this->clock->mysql() ) : 0;
		if ( $user && null !== $this->engine_provider ) {
			$count += $this->engine()->anonymize_runs( (int) $user->ID, $this->clock->mysql() );
			$count += $this->engine()->erase_send_counts( (int) $user->ID );
		}
		return array(
			'items_removed'  => $count > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	public function on_deleted_user( $user_id ): void {
		$this->store()->anonymize_actor( (int) $user_id, $this->clock->mysql() );
		if ( null !== $this->engine_provider ) {
			$this->engine()->anonymize_runs( (int) $user_id, $this->clock->mysql() );
			$this->engine()->erase_send_counts( (int) $user_id );
		}
	}

	/**
	 * Deletes stored events after the retention period (R-12.4, C-14). Open events (received, processing, retry_wait)
	 * are never deleted here. The periods can only be shortened.
	 */
	public function apply_retention(): void {
		$clock = $this->clock;
		foreach ( self::RETENTION_DAYS as $group => $default ) {
			/**
			 * Retention in days for finished events ("finished") and for dead_letter/paused ("stuck"). Since 0.3.0 the
			 * setting can also lengthen the period up to the maximum (180 days, E-S7 = F-04).
			 *
			 * @since 0.1.0
			 */
			$days   = max( 1, min( self::max_days(), (int) apply_filters( 'workflow_captain_retention_days', $default, $group ) ) );
			$states = 'finished' === $group
				? array( EventStore::DONE, EventStore::DISCARDED, EventStore::LOOP_BLOCKED )
				: array( EventStore::DEAD_LETTER, EventStore::PAUSED );
			$this->store()->delete_older( $states, $clock->mysql( $clock->now() - $days * DAY_IN_SECONDS ) );
		}
		if ( null !== $this->engine_provider ) {
			// Finished runs and their step logs have their own period (WF-SET-07); open runs (running, action
			// required, paused) are never deleted here.
			$days = max( 1, min( self::max_days(), (int) apply_filters( 'workflow_captain_retention_days', 30, 'runs' ) ) );
			// Counters of the daily limit (SET-WC-08) are needed only for today; keep two days for time zone changes.
			$this->engine()->purge_send_counts( gmdate( 'Y-m-d', $clock->now() - 2 * DAY_IN_SECONDS ) );
			$this->engine()->delete_runs_older( array( EngineStore::RUN_SUCCEEDED, EngineStore::RUN_STOPPED, EngineStore::RUN_FAILED, EngineStore::RUN_CANCELLED ), $clock->mysql( $clock->now() - $days * DAY_IN_SECONDS ) );
		}
	}

	private function engine(): EngineStore {
		return ( $this->engine_provider )();
	}

	private function store(): EventStore {
		return ( $this->store_provider )();
	}
}
