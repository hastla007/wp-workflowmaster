<?php
/**
 * Register of the settings (SET-WC-01): the one source for option, type, default, range, tab, label, help text,
 * capability and export rule of every value. Settings, Notifications, the settings page, export/import and WP-CLI
 * read from here. The two options and their settings groups stay as they are, so nothing stored is lost.
 *
 * Every value can be changed by the filter `workflow_captain_setting_<key>`; the filter wins, the result is still
 * checked against the type and range of the field.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain;

defined( 'ABSPATH' ) || exit;

final class SettingsRegistry {

	/** Format number of exported settings files (SET-WC-11). */
	const FORMAT = 1;

	/** Longest retention the free core allows (E-S7 = F-04): 180 days; the defaults stay 30 and 90. */
	const RETENTION_MAX = 180;

	const TYPE_INT    = 'int';
	const TYPE_BOOL   = 'bool';
	const TYPE_EMAILS = 'emails';
	const TYPE_KINDS  = 'kinds';
	const TYPE_ROLES  = 'roles';
	/** Weekdays as a bit mask stored as a number: 1 = Monday … 64 = Sunday (SET-WC-07). */
	const TYPE_DAYS   = 'days';
	/** One address or empty (SET-WC-10). */
	const TYPE_EMAIL    = 'email';
	/** One line of text, at most `max` characters. */
	const TYPE_TEXT     = 'text';
	/** Several lines of plain text, at most `max` characters. */
	const TYPE_TEXTAREA = 'textarea';

	/**
	 * key => array( option, type, default, min, max, tab, export ). `export` false: the value is tied to this
	 * installation or holds personal data and is left out of an export.
	 */
	const SPEC = array(
		'batch'              => array( Settings::OPTION, self::TYPE_INT, 25, 1, 200, 'execution', true ),
		'max_site'           => array( Settings::OPTION, self::TYPE_INT, 5, 1, 20, 'execution', true ),
		'max_workflow'       => array( Settings::OPTION, self::TYPE_INT, 2, 1, 10, 'execution', true ),
		'catch_up_hours'     => array( Settings::OPTION, self::TYPE_INT, 24, 1, 168, 'execution', true ),
		'max_seconds'        => array( Settings::OPTION, self::TYPE_INT, 20, 5, 120, 'execution', true ),
		'test_mode'          => array( Settings::OPTION, self::TYPE_INT, 0, 0, 2, 'execution', false ),
		'retry_attempts'     => array( Settings::OPTION, self::TYPE_INT, 8, 1, 8, 'execution', true ),
		'max_chain_depth'    => array( Settings::OPTION, self::TYPE_INT, 3, 1, 8, 'execution', true ),
		'retention_finished' => array( Settings::OPTION, self::TYPE_INT, 30, 1, self::RETENTION_MAX, 'privacy', true ),
		'retention_runs'     => array( Settings::OPTION, self::TYPE_INT, 30, 1, self::RETENTION_MAX, 'privacy', true ),
		'retention_stuck'    => array( Settings::OPTION, self::TYPE_INT, 90, 1, self::RETENTION_MAX, 'privacy', true ),
		'require_own_key'    => array( Settings::OPTION, self::TYPE_BOOL, 0, 0, 1, 'privacy', false ),
		'audit_keep'         => array( Settings::OPTION, self::TYPE_INT, 50, 10, 500, 'privacy', true ),
		'delete_data_on_uninstall' => array( Settings::OPTION, self::TYPE_BOOL, 0, 0, 1, 'privacy', false ),
		'quiet_hours_start'  => array( Settings::OPTION, self::TYPE_INT, -1, -1, 23, 'messages', true ),
		'quiet_hours_end'    => array( Settings::OPTION, self::TYPE_INT, -1, -1, 23, 'messages', true ),
		'send_days'          => array( Settings::OPTION, self::TYPE_DAYS, 127, 1, 127, 'messages', true ),
		'daily_limit'        => array( Settings::OPTION, self::TYPE_INT, 0, 0, 20, 'messages', true ),
		'daily_limit_action' => array( Settings::OPTION, self::TYPE_INT, 0, 0, 1, 'messages', true ),
		'notify_daily'       => array( Settings::OPTION, self::TYPE_BOOL, 1, 0, 1, 'notify', true ),
		'sender_name'        => array( Messages::OPTION, self::TYPE_TEXT, '', 0, 100, 'messages', true ),
		'sender_email'       => array( Messages::OPTION, self::TYPE_EMAIL, '', 0, 0, 'messages', false ),
		'reply_to'           => array( Messages::OPTION, self::TYPE_EMAIL, '', 0, 0, 'messages', false ),
		'message_footer'     => array( Messages::OPTION, self::TYPE_TEXTAREA, '', 0, 1000, 'messages', true ),
		'recipients'         => array( Notifications::OPTION, self::TYPE_EMAILS, '', 0, 10, 'notify', false ),
		'kinds'              => array( Notifications::OPTION, self::TYPE_KINDS, Notifications::KINDS, 0, 0, 'notify', true ),
		'notify_admin_roles' => array( Notifications::OPTION, self::TYPE_ROLES, array(), 0, 20, 'notify', true ),
	);

	/** Settings group of each option (register_setting; options.php resets the fields of a group it does not get). */
	const GROUPS = array(
		Settings::OPTION      => 'workflow_captain',
		Notifications::OPTION => 'workflow_captain_notify',
		Messages::OPTION      => 'workflow_captain_messages',
	);

	/** Capability needed to change a setting. */
	const CAPABILITY = 'manage_workflow_captain';

	/** @return string[] Keys of one option, in register order. */
	public static function keys( string $option ): array {
		return array_keys( array_filter( self::SPEC, static fn( array $f ): bool => $option === $f[0] ) );
	}

	/** @return string[] Keys shown on one tab of the settings page, in register order. */
	public static function tab( string $tab, ?string $option = null ): array {
		return array_keys(
			array_filter(
				self::SPEC,
				static fn( array $f ): bool => $tab === $f[5] && ( null === $option || $option === $f[0] )
			)
		);
	}

	/** Field definition with names: option, type, default, min, max, tab, export. */
	public static function field( string $key ): ?array {
		if ( ! isset( self::SPEC[ $key ] ) ) {
			return null;
		}
		list( $option, $type, $default, $min, $max, $tab, $export ) = self::SPEC[ $key ];
		return array(
			'key'     => $key,
			'option'  => $option,
			'type'    => $type,
			'default' => $default,
			'min'     => $min,
			'max'     => $max,
			'tab'     => $tab,
			'export'  => $export,
			'filter'  => 'workflow_captain_setting_' . $key,
		);
	}

	/** Checks one value against its field; returns the stored form (int, string or list). */
	public static function clean( string $key, $value ) {
		$f = self::field( $key );
		if ( null === $f ) {
			return null;
		}
		switch ( $f['type'] ) {
			case self::TYPE_INT:
			case self::TYPE_BOOL:
				return is_numeric( $value ) ? max( $f['min'], min( $f['max'], (int) $value ) ) : $f['default'];
			case self::TYPE_DAYS:
				$value = is_array( $value ) ? self::days_mask( $value ) : $value;
				return is_numeric( $value ) && (int) $value >= $f['min'] && (int) $value <= $f['max'] ? (int) $value : $f['default'];
			case self::TYPE_EMAIL:
				$address = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
				return false !== filter_var( $address, FILTER_VALIDATE_EMAIL ) ? $address : '';
			case self::TYPE_TEXT:
				$text = is_scalar( $value ) ? trim( preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', strip_tags( (string) $value ) ) ?? '' ) : '';
				return self::cut( $text, $f['max'] );
			case self::TYPE_TEXTAREA:
				$text = is_scalar( $value ) ? str_replace( array( "\r\n", "\r" ), "\n", strip_tags( (string) $value ) ) : '';
				return self::cut( trim( preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $text ) ?? '' ), $f['max'] );
			case self::TYPE_EMAILS:
				$list = array();
				foreach ( preg_split( '/[\s,;]+/', is_scalar( $value ) ? (string) $value : '' ) ?: array() as $address ) {
					$address = strtolower( trim( $address ) );
					if ( '' !== $address && false !== filter_var( $address, FILTER_VALIDATE_EMAIL ) && ! in_array( $address, $list, true ) ) {
						$list[] = $address;
					}
				}
				return implode( ', ', array_slice( $list, 0, $f['max'] ) );
			case self::TYPE_KINDS:
				return is_array( $value ) ? array_values( array_intersect( $f['default'], array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) ) : $f['default'];
			case self::TYPE_ROLES:
				return is_array( $value ) ? array_slice( array_values( array_filter( array_unique( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ), array( self::class, 'is_role' ) ) ), 0, $f['max'] ) : $f['default'];
		}
		return $f['default'];
	}

	/**
	 * Labels of the choices of a number field shown as a list (value => label), empty for plain numbers.
	 *
	 * @return array<int,string>
	 */
	public static function choices( string $key ): array {
		if ( 'quiet_hours_start' === $key || 'quiet_hours_end' === $key ) {
			$out = array( -1 => __( 'Off', 'workflow-captain' ) );
			for ( $h = 0; $h < 24; $h++ ) {
				$out[ $h ] = sprintf( '%02d:00', $h );
			}
			return $out;
		}
		if ( 'daily_limit_action' === $key ) {
			return array(
				\WorkflowCaptain\Engine\Pacing::LIMIT_DEFER => __( 'Send on the next day', 'workflow-captain' ),
				\WorkflowCaptain\Engine\Pacing::LIMIT_SKIP  => __( 'Skip the message', 'workflow-captain' ),
			);
		}
		if ( 'test_mode' === $key ) {
			return array(
				TestMode::OFF      => __( 'Off', 'workflow-captain' ),
				TestMode::REDIRECT => __( 'Redirect messages', 'workflow-captain' ),
				TestMode::DRY      => __( 'Do not execute anything, only log', 'workflow-captain' ),
			);
		}
		return array();
	}

	/**
	 * Names of the weekdays by ISO number (1 = Monday), translated.
	 *
	 * @return array<int,string>
	 */
	public static function weekdays(): array {
		return array(
			1 => __( 'Monday', 'workflow-captain' ),
			2 => __( 'Tuesday', 'workflow-captain' ),
			3 => __( 'Wednesday', 'workflow-captain' ),
			4 => __( 'Thursday', 'workflow-captain' ),
			5 => __( 'Friday', 'workflow-captain' ),
			6 => __( 'Saturday', 'workflow-captain' ),
			7 => __( 'Sunday', 'workflow-captain' ),
		);
	}

	/** Bit mask of a list of ISO weekday numbers (1 to 7); other entries are ignored. */
	public static function days_mask( array $days ): int {
		$mask = 0;
		foreach ( $days as $day ) {
			if ( is_scalar( $day ) && preg_match( '/^[1-7]$/', trim( (string) $day ) ) ) {
				$mask |= 1 << ( (int) $day - 1 );
			}
		}
		return $mask;
	}

	/** @return int[] ISO weekday numbers of a bit mask. */
	public static function days_list( int $mask ): array {
		return array_values( array_filter( range( 1, 7 ), static fn( int $d ): bool => 0 !== ( $mask & ( 1 << ( $d - 1 ) ) ) ) );
	}

	/** Text shortened to $max characters (multibyte-safe). */
	public static function cut( string $text, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
	}

	/** A role slug of this site (any well-formed slug where WordPress is not loaded, as in the tests). */
	public static function is_role( string $role ): bool {
		if ( ! preg_match( '/^[a-z0-9_-]{1,60}$/', $role ) ) {
			return false;
		}
		return function_exists( 'wp_roles' ) ? null !== wp_roles()->get_role( $role ) : true;
	}

	/**
	 * Applies the filter of a field to a stored value; the filter wins within type and range. A switch takes true and
	 * false (`__return_false` turns it off), addresses also a list. A result of the wrong type keeps the stored value:
	 * falling back to the default would switch a mail back on or widen the chosen kinds (P1 finding).
	 */
	public static function filtered( string $key, $value ) {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $value;
		}
		$filtered = apply_filters( 'workflow_captain_setting_' . $key, $value );
		if ( $filtered === $value ) {
			return $value;
		}
		$f = self::field( $key );
		switch ( $f['type'] ?? '' ) {
			case self::TYPE_BOOL:
				$filtered = is_bool( $filtered ) ? (int) $filtered : $filtered;
				return is_numeric( $filtered ) ? self::clean( $key, $filtered ) : $value;
			case self::TYPE_INT:
				return is_numeric( $filtered ) ? self::clean( $key, $filtered ) : $value;
			case self::TYPE_DAYS:
				return is_numeric( $filtered ) || is_array( $filtered ) ? self::clean( $key, $filtered ) : $value;
			case self::TYPE_EMAIL:
			case self::TYPE_TEXT:
			case self::TYPE_TEXTAREA:
				return is_scalar( $filtered ) ? self::clean( $key, $filtered ) : $value;
			case self::TYPE_EMAILS:
				if ( is_array( $filtered ) ) {
					$filtered = implode( ', ', array_filter( $filtered, 'is_scalar' ) );
				}
				return is_scalar( $filtered ) ? self::clean( $key, $filtered ) : $value;
			case self::TYPE_KINDS:
			case self::TYPE_ROLES:
				return is_array( $filtered ) ? self::clean( $key, $filtered ) : $value;
		}
		return $value;
	}

	/**
	 * Label and help text of every field (translated at call time).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function texts(): array {
		return array(
			'batch'              => array( __( 'Events and steps per worker pass', 'workflow-captain' ), __( 'More per pass works through a backlog faster but makes each pass longer. Choose a smaller value if memory is low or your host allows only short run times.', 'workflow-captain' ) ),
			'max_site'           => array( __( 'Maximum concurrent steps on this site', 'workflow-captain' ), __( 'Protects the server when many events arrive at once. Further steps wait until a slot is free.', 'workflow-captain' ) ),
			'max_workflow'       => array( __( 'Maximum concurrent steps per workflow', 'workflow-captain' ), __( 'Prevents a single workflow from taking all slots; the others still get their turn.', 'workflow-captain' ) ),
			'catch_up_hours'     => array( __( 'Catch-up limit in hours', 'workflow-captain' ), __( 'A step that is overdue for longer (for example because WP-Cron did not run) is not executed automatically but shown for a decision.', 'workflow-captain' ) ),
			'max_seconds'        => array( __( 'Run time per worker pass in seconds', 'workflow-captain' ), __( 'After this, the worker does not start any new step; a step already started runs to completion. Keeps page loads short when WP-Cron runs on visitor traffic.', 'workflow-captain' ) ),
			'test_mode'          => array( __( 'Test mode', 'workflow-captain' ), __( 'For test environments and trying things out. “Redirect messages”: every message from a workflow goes to the first recipient of the notice email (otherwise to the site’s address), with “[Test]” in the subject. “Do not execute anything”: workflows run, but no step takes effect; each step is only logged as skipped. The constant WORKFLOW_CAPTAIN_TEST_MODE in wp-config.php takes precedence.', 'workflow-captain' ) ),
			'retry_attempts'     => array( __( 'Retries after a temporary error', 'workflow-captain' ), __( 'How often an event or step is retried after a temporary error, with growing intervals (1 min to 24 hrs). After that, an event is shown as failed and a step needs a decision. Messages with an unclear outcome are never retried automatically.', 'workflow-captain' ) ),
			'max_chain_depth'    => array( __( 'Maximum depth of a chain of workflows', 'workflow-captain' ), __( 'When a workflow triggers an event that starts another workflow, a chain is created. More deeply nested events are stored but start nothing (loop protection).', 'workflow-captain' ) ),
			'retention_finished' => array( __( 'Delete completed events after (days)', 'workflow-captain' ), __( 'Default 30 days, at most 180. Check with your legal advisor which retention period applies to your business.', 'workflow-captain' ) ),
			'retention_runs'     => array( __( 'Delete completed runs with step log after (days)', 'workflow-captain' ), __( 'Default 30 days, at most 180; applies to completed, failed and canceled runs, open ones are kept. Logs contain no message content or event data except the copy in the run.', 'workflow-captain' ) ),
			'retention_stuck'    => array( __( 'Delete failed and paused events after (days)', 'workflow-captain' ), __( 'Default 90 days, at most 180. The defaults are a suggestion pending legal review.', 'workflow-captain' ) ),
			'require_own_key'    => array( __( 'Store secrets only with your own key', 'workflow-captain' ), __( 'On: Credentials (for example from an add-on\'s connections) are only stored if WORKFLOW_CAPTAIN_SECRET_KEY is set in wp-config.php. Without your own key, encryption depends on the WordPress salts; if they change, stored secrets are lost. Stored values remain readable.', 'workflow-captain' ) ),
			'audit_keep'         => array( __( 'Change log entries', 'workflow-captain' ), __( 'This many of the latest changes (settings, capabilities, emergency pause, approvals) stay visible in the Status tab. Older ones are dropped. The log contains user IDs but no event content.', 'workflow-captain' ) ),
			'delete_data_on_uninstall' => array( __( 'Delete all data when the plugin is deleted', 'workflow-captain' ), __( 'Off (default): When the plugin is deleted, events, runs, workflows and settings are kept so that a reinstall can pick up where it left off. On: When the plugin is deleted, all Workflow Captain tables, settings and capabilities on this site are permanently removed. Type DELETE to switch it on.', 'workflow-captain' ) ),
			'quiet_hours_start'  => array( __( 'Quiet time from', 'workflow-captain' ), __( 'Messages from workflows to customers (“Message to user”) are not sent during the quiet time; they wait until it ends. Off, or the same hour as “until”: no quiet time. Times in the site’s time zone. Messages to the site operator are not held back.', 'workflow-captain' ) ),
			'quiet_hours_end'    => array( __( 'Quiet time until', 'workflow-captain' ), __( 'The quiet time may span midnight, for example 22:00 to 07:00.', 'workflow-captain' ) ),
			'send_days'          => array( __( 'Sending days', 'workflow-captain' ), __( 'On other days, messages to customers wait for the next sending day. At least one day.', 'workflow-captain' ) ),
			'daily_limit'        => array( __( 'Messages per customer and day', 'workflow-captain' ), __( 'At most this many messages from all workflows together to one customer per calendar day of the site. 0: no limit.', 'workflow-captain' ) ),
			'daily_limit_action' => array( __( 'When the limit is reached', 'workflow-captain' ), __( '“Send on the next day” keeps every message; “Skip” drops the surplus and notes it in the run.', 'workflow-captain' ) ),
			'sender_name'        => array( __( 'Sender name', 'workflow-captain' ), __( 'Name shown as sender of messages from workflows. Used together with the sender address.', 'workflow-captain' ) ),
			'sender_email'       => array( __( 'Sender address', 'workflow-captain' ), __( 'Empty: the sender WordPress or your mail plugin sets (today’s behaviour). Use an address of your own domain, otherwise messages may land in spam.', 'workflow-captain' ) ),
			'reply_to'           => array( __( 'Reply address', 'workflow-captain' ), __( 'Replies to messages from workflows go here. Empty: to the sender.', 'workflow-captain' ) ),
			'message_footer'     => array( __( 'Footer of messages to customers', 'workflow-captain' ), __( 'Added below every “Message to user”, for example your contact details and links to your legal notice and privacy policy. Placeholders: {site_name}, {site_url}, {privacy_policy_url}. Empty: no footer. Check the wording with your legal advisor.', 'workflow-captain' ) ),
			'notify_daily'       => array( __( 'Daily notice email to the site operator address', 'workflow-captain' ), __( 'Only sent when decisions are waiting, events have failed, work is stuck or the emergency pause is on. Without event contents.', 'workflow-captain' ) ),
			'recipients'         => array( __( 'Recipients', 'workflow-captain' ), __( 'Up to ten addresses, separated by commas. Empty: the site\'s address.', 'workflow-captain' ) ),
			'kinds'              => array( __( 'Notice types', 'workflow-captain' ), '' ),
			'notify_admin_roles' => array( __( 'Roles as recipients of “Message to site operator”', 'workflow-captain' ), __( 'In the workflow editor you can then also choose all users with these roles as recipients (at most 50 addresses per message). With nothing selected: the site’s address or the holders of the fixed capabilities.', 'workflow-captain' ) ),
		);
	}
}
