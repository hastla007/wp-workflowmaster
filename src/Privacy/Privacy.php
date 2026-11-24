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
		$where = array_merge( $where, self::sender_roles( \WorkflowCaptain\Messages::stored(), $email ) );
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
		// Owner decision of 24.11.2026: the sender and reply-to address are an operating setting. They are named in the
		// export but kept on erasure (clearing them would change the sender of every mail); the report says so.
		$kept     = array() !== self::sender_roles( \WorkflowCaptain\Messages::stored(), $email );
		$messages = $kept ? array( __( 'The address is set as sender or reply-to address of Workflow Captain’s messages and was kept, because it is an operating setting. Change it under Settings → Messages.', 'workflow-captain' ) ) : array();
		return array( 'items_removed' => $removed, 'items_retained' => $kept, 'messages' => $messages, 'done' => true );
	}

	/**
	 * Owner decision of 24.11.2026: where $email is stored as sender or reply-to address of the messages
	 * (option workflow_captain_messages). Labels for the export; empty when it is neither.
	 *
	 * @param array<string,string> $messages Stored values of Messages::stored().
	 * @return list<string>
	 */
	public static function sender_roles( array $messages, string $email ): array {
		$email = strtolower( trim( $email ) );
		$roles = array();
		if ( '' !== $email && strtolower( trim( (string) ( $messages['sender_email'] ?? '' ) ) ) === $email ) {
			$roles[] = __( 'Sender address of the messages', 'workflow-captain' );
		}
		if ( '' !== $email && strtolower( trim( (string) ( $messages['reply_to'] ?? '' ) ) ) === $email ) {
			$roles[] = __( 'Reply-to address of the messages', 'workflow-captain' );
		}
		return $roles;
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
		if ( 1 === max( 1, $page ) ) {
			$items = array_merge( $items, $this->export_marks( (int) $user->ID ) );
		}
		return array( 'data' => $items, 'done' => count( $rows ) < self::PAGE_SIZE && count( $runs ) < self::PAGE_SIZE );
	}

	/**
	 * Tags and notes on the user as object (R-12.5, docs/LOESCHUNG.md) and the unsubscribe marker of promotional
	 * messages (R-9.7, user option of Unsubscribe). Exported once, on the first page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function export_marks( int $user_id ): array {
		$items = array();
		$marks = null === $this->engine_provider ? array( 'tags' => array(), 'notes' => array() ) : $this->engine()->tags_notes_of_user( $user_id );
		foreach ( $marks['tags'] as $i => $tag ) {
			$items[] = array(
				'group_id'    => 'workflow-captain-tags',
				'group_label' => __( 'Workflow Captain: Tags', 'workflow-captain' ),
				'item_id'     => 'workflow-captain-tag-' . $i,
				'data'        => array(
					array( 'name' => __( 'Tag', 'workflow-captain' ), 'value' => (string) $tag['tag'] ),
					array( 'name' => __( 'Product', 'workflow-captain' ), 'value' => (string) $tag['product'] ),
					array( 'name' => __( 'Set (UTC)', 'workflow-captain' ), 'value' => (string) $tag['created_at'] ),
				),
			);
		}
		foreach ( $marks['notes'] as $note ) {
			$items[] = array(
				'group_id'    => 'workflow-captain-notes',
				'group_label' => __( 'Workflow Captain: Notes', 'workflow-captain' ),
				'item_id'     => 'workflow-captain-note-' . (int) $note['id'],
				'data'        => array(
					array( 'name' => __( 'Note', 'workflow-captain' ), 'value' => (string) $note['note'] ),
					array( 'name' => __( 'Product', 'workflow-captain' ), 'value' => (string) $note['product'] ),
					array( 'name' => __( 'Set (UTC)', 'workflow-captain' ), 'value' => (string) $note['created_at'] ),
				),
			);
		}
		$unsubscribed = function_exists( 'get_user_option' ) ? (string) get_user_option( \WorkflowCaptain\Unsubscribe::META, $user_id ) : '';
		if ( '' !== $unsubscribed ) {
			$items[] = array(
				'group_id'    => 'workflow-captain-unsubscribe',
				'group_label' => __( 'Workflow Captain: Unsubscribed from promotional messages', 'workflow-captain' ),
				'item_id'     => 'workflow-captain-unsubscribe',
				'data'        => array(
					array( 'name' => __( 'Unsubscribed (UTC)', 'workflow-captain' ), 'value' => $unsubscribed ),
				),
			);
		}
		return $items;
	}

	/** Eraser callback: removes the user from every stored event; the events stay without actor (R-12.5, C-13). */
	public function erase( string $email, int $page = 1 ): array {
		$user  = get_user_by( 'email', $email );
		$count = $user ? $this->store()->anonymize_actor( (int) $user->ID, $this->clock->mysql() ) : 0;
		if ( $user && null !== $this->engine_provider ) {
			$count += $this->engine()->anonymize_runs( (int) $user->ID, $this->clock->mysql() );
			$count += $this->engine()->erase_send_counts( (int) $user->ID );
			$count += $this->engine()->erase_tags_notes_of_user( (int) $user->ID );
		}
		// The unsubscribe marker is kept: removing it would send promotional messages again (R-9.7). It goes with
		// the user account. Wording and legal basis: legal review (SET-WC-34).
		$kept = $user && function_exists( 'get_user_option' ) && '' !== (string) get_user_option( \WorkflowCaptain\Unsubscribe::META, (int) $user->ID );
		return array(
			'items_removed'  => $count > 0,
			'items_retained' => $kept,
			'messages'       => $kept ? array( __( 'Workflow Captain keeps the unsubscribe from promotional messages, so that none are sent again. It is deleted with the user account.', 'workflow-captain' ) ) : array(),
			'done'           => true,
		);
	}

	public function on_deleted_user( $user_id ): void {
		$this->store()->anonymize_actor( (int) $user_id, $this->clock->mysql() );
		if ( null !== $this->engine_provider ) {
			$this->engine()->anonymize_runs( (int) $user_id, $this->clock->mysql() );
			$this->engine()->erase_send_counts( (int) $user_id );
			$this->engine()->erase_tags_notes_of_user( (int) $user_id );
		}
	}

	/**
	 * Deletes stored events after the retention period (R-12.4, C-14). Open events (received, processing, retry_wait)
	 * are never deleted here. The periods can only be shortened.
	 */
	public function apply_retention(): void {
		$clock = $this->clock;
		foreach ( array_keys( self::RETENTION_DAYS ) as $group ) {
			$states = 'finished' === $group
				? array( EventStore::DONE, EventStore::DISCARDED, EventStore::LOOP_BLOCKED )
				: array( EventStore::DEAD_LETTER, EventStore::PAUSED );
			$this->store()->delete_older( $states, $clock->mysql( $clock->now() - self::days( $group ) * DAY_IN_SECONDS ) );
		}
		if ( null !== $this->engine_provider ) {
			// Finished runs and their step logs have their own period (WF-SET-07); open runs (running, action
			// required, paused) are never deleted here.
			$days = self::days( 'runs' );
			// Counters of the daily limit (SET-WC-08) are needed only for today; keep two days for time zone changes.
			$this->engine()->purge_send_counts( gmdate( 'Y-m-d', $clock->now() - self::SEND_COUNT_DAYS * DAY_IN_SECONDS ) );
			$this->engine()->delete_runs_older( array( EngineStore::RUN_SUCCEEDED, EngineStore::RUN_STOPPED, EngineStore::RUN_FAILED, EngineStore::RUN_CANCELLED ), $clock->mysql( $clock->now() - $days * DAY_IN_SECONDS ) );
			// Tags and notes (SET-WC-34): no period by default (0), as before; otherwise older ones go.
			$tags = self::days( 'tags_notes' );
			if ( $tags > 0 ) {
				$this->engine()->delete_tags_notes_older( $clock->mysql( $clock->now() - $tags * DAY_IN_SECONDS ) );
			}
		}
	}

	/** Days the counters of the daily limit (table sends) are kept. */
	const SEND_COUNT_DAYS = 2;

	/**
	 * Retention period in effect for a group: "finished", "stuck", "runs" (1 to max_days()) or "tags_notes" (0 to
	 * max_days(), 0 = no period). The one place apply_retention() and the privacy policy text read the periods from.
	 */
	public static function days( string $group ): int {
		$default = array( 'finished' => self::RETENTION_DAYS['finished'], 'stuck' => self::RETENTION_DAYS['stuck'], 'runs' => 30, 'tags_notes' => 0 )[ $group ] ?? 30;
		/**
		 * Retention in days for finished events ("finished"), dead_letter/paused ("stuck"), finished runs ("runs") and
		 * tags and notes ("tags_notes", 0 = none, SET-WC-34). Since 0.3.0 the setting can also lengthen the period up
		 * to the maximum (180 days, E-S7 = F-04).
		 *
		 * @since 0.1.0
		 */
		$days = min( self::max_days(), (int) apply_filters( 'workflow_captain_retention_days', $default, $group ) );
		return 'tags_notes' === $group ? max( 0, $days ) : max( 1, $days );
	}

	private function engine(): EngineStore {
		return ( $this->engine_provider )();
	}

	private function store(): EventStore {
		return ( $this->store_provider )();
	}
}
