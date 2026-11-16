<?php
/**
 * Plugin Name: E2E test site helpers (test site only, never ship)
 * Captures every wp_mail() call into a JSON-lines file instead of sending it (pre_wp_mail), can force a
 * permanently failing dispatcher (E2E_FAIL_DISPATCH=1) and switches off update checks of the offline test site.
 */
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		// E2E only: option "e2e_mail_fail" = [subject part, count] lets the next <count> matching mails fail (retry).
		$fail = get_option( 'e2e_mail_fail' );
		if ( is_array( $fail ) && (int) $fail[1] > 0 && str_contains( (string) $atts['subject'], (string) $fail[0] ) ) {
			update_option( 'e2e_mail_fail', array( $fail[0], (int) $fail[1] - 1 ), false );
			return false;
		}
		$file = getenv( 'E2E_MAIL_FILE' ) ?: '/home/user/wp-e2e/logs/mail.jsonl';
		$row  = array(
			'time'    => gmdate( 'c' ),
			'to'      => $atts['to'],
			'subject' => $atts['subject'],
			'message' => $atts['message'],
			'headers' => $atts['headers'],
		);
		file_put_contents( $file, wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND | LOCK_EX );
		return true;
	},
	10,
	2
);

// E2E only: with E2E_FAIL_DISPATCH=1 every event fails permanently, to produce a dead-letter event through the
// plugin's public "workflow_captain_dispatcher" extension point.
if ( getenv( 'E2E_FAIL_DISPATCH' ) ) {
	add_filter(
		'workflow_captain_dispatcher',
		static function () {
			return new class() implements \WorkflowCaptain\Consumer\Dispatcher {
				public function dispatch( array $envelope, ?int $exclude_workflow_id ): \WorkflowCaptain\Consumer\DispatchResult {
					throw new \WorkflowCaptain\Consumer\PermanentFailure( 'e2e_forced' );
				}
			};
		}
	);
}

// Offline test site: no update checks against wordpress.org (blocked here; they only add warnings to the log).
add_action(
	'init',
	static function () {
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		remove_action( 'load-plugins.php', 'wp_update_plugins' );
		remove_action( 'load-update-core.php', 'wp_update_plugins' );
		remove_action( 'load-themes.php', 'wp_update_themes' );
		remove_action( 'wp_version_check', 'wp_version_check' );
	}
);

// Yoast SEO builds its indexables only on production sites; the test site is "local". WF-SEO-08 compares them.
add_filter( 'Yoast\\WP\\SEO\\should_index_indexables', '__return_true' );
