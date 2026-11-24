<?php
/**
 * Suggested text for the privacy policy (SET-WC-34): registered with wp_add_privacy_policy_content(), so it shows in
 * Settings → Privacy → Policy Guide. It describes what Workflow Captain stores, with the periods currently in effect.
 *
 * The mechanism is in place; the wording is a suggestion pending legal review (like SET-WC-03/-04). The text says so
 * itself and asks the site operator to check it before use.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Privacy;

use WorkflowCaptain\Settings;

defined( 'ABSPATH' ) || exit;

final class PolicyText {

	/** Name under which the text appears in the policy guide. */
	const NAME = 'Workflow Captain';

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'add' ) );
	}

	/** admin_init callback: registers the text; does nothing on WordPress versions without the policy guide. */
	public static function add(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$html = self::text();
		wp_add_privacy_policy_content( self::NAME, function_exists( 'wp_kses_post' ) ? wp_kses_post( $html ) : $html );
	}

	/** "%d days" in the language of the site. */
	private static function days( int $n ): string {
		/* translators: %d: number of days; used after "after" (German: "nach %d Tagen") */
		return sprintf( _n( '%d day', '%d days', $n, 'workflow-captain' ), $n );
	}

	/** The suggested text as HTML, with the periods in effect now (settings and filters, see Privacy::days()). */
	public static function text(): string {
		$settings = Settings::get();
		$tags     = Privacy::days( 'tags_notes' );
		$p        = static fn( string $text ): string => '<p>' . $text . '</p>';
		$tutorial = static fn( string $text ): string => '<p class="privacy-policy-tutorial">' . $text . '</p>';

		$out  = $tutorial( '<strong>' . __( 'Suggestion – have it checked legally before use.', 'workflow-captain' ) . '</strong> ' . __( 'This text was put together by Workflow Captain from its current settings. It is not legal advice and does not know which workflows you have set up. Adjust it to your workflows, add the purpose and the legal basis, and have it checked before you publish it. If you change a period in the settings, update the text.', 'workflow-captain' ) );
		$out .= '<h2>' . __( 'Automated workflows', 'workflow-captain' ) . '</h2>';
		$out .= $p( __( 'We use the plugin Workflow Captain to run automated workflows on this website, for example to send a message after an order or to mark an object. For this, the following data is processed on the server of this website:', 'workflow-captain' ) );
		$out .= $p(
			'<strong>' . __( 'Events:', 'workflow-captain' ) . '</strong> '
			/* translators: 1: period for completed events, 2: period for failed and paused events (e.g. "30 days") */
			. sprintf( __( 'When something happens on this website (for example an order, a check-in or a change of status), an event is stored: the type of event, the object concerned (for example an order number), the time, status values and amounts, and the user ID of the person who triggered it, if any. Email addresses, names and codes are not stored. The user ID is removed as soon as no workflow waits for the event any more. Completed events are deleted after %1$s, failed and paused events after %2$s.', 'workflow-captain' ), self::days( Privacy::days( 'finished' ) ), self::days( Privacy::days( 'stuck' ) ) )
		);
		$out .= $p(
			'<strong>' . __( 'Runs and logs:', 'workflow-captain' ) . '</strong> '
			/* translators: %s: period for finished runs (e.g. "30 days") */
			. sprintf( __( 'Each run of a workflow keeps a copy of the event and a log of its steps (state and a shortened result, no message content). Completed, failed and cancelled runs are deleted %s after they end; runs that still wait are kept until they end.', 'workflow-captain' ), self::days( Privacy::days( 'runs' ) ) )
		);
		$out .= $p(
			'<strong>' . __( 'Tags and notes:', 'workflow-captain' ) . '</strong> '
			. __( 'Workflows can set tags and notes on objects (for example on an order). They contain only the text set in the workflow.', 'workflow-captain' ) . ' '
			. ( $tags > 0
				/* translators: %s: period for tags and notes (e.g. "90 days") */
				? sprintf( __( 'They are deleted after %s.', 'workflow-captain' ), self::days( $tags ) )
				: __( 'They are kept until a workflow removes them; no period is set.', 'workflow-captain' ) )
		);
		$out .= $p(
			'<strong>' . __( 'Messages:', 'workflow-captain' ) . '</strong> '
			. __( 'If a workflow sends an email, the address is looked up from the user account only at the time of sending and is not stored by Workflow Captain. The email is sent through WordPress and the mail service of this website.', 'workflow-captain' )
			. ( (int) ( $settings['daily_limit'] ?? 0 ) > 0
				/* translators: %s: period for the counters (e.g. "2 days") */
				? ' ' . sprintf( __( 'To limit the number of messages per day, the number of messages sent to a user ID is counted per calendar day; these counters are deleted after %s.', 'workflow-captain' ), self::days( Privacy::SEND_COUNT_DAYS ) )
				: '' )
		);
		$out .= $p(
			'<strong>' . __( 'Unsubscribing:', 'workflow-captain' ) . '</strong> '
			. __( 'Promotional messages contain a link to unsubscribe. If it is used, the time of unsubscribing is stored in the user account, so that no further promotional messages are sent. It is kept as long as the user account exists.', 'workflow-captain' )
		);
		$out .= $p(
			'<strong>' . __( 'Notices to the site operator:', 'workflow-captain' ) . '</strong> '
			/* translators: %d: number of change log entries kept */
			. sprintf( __( 'A daily notice email goes only to the addresses of the site operator and contains no event content. A change log keeps the last %d changes to settings and capabilities with the user ID of the staff member who made them.', 'workflow-captain' ), (int) ( $settings['audit_keep'] ?? 50 ) )
		);
		$out .= $p(
			'<strong>' . __( 'Transfer to others:', 'workflow-captain' ) . '</strong> '
			. __( 'Apart from the emails described above, Workflow Captain itself sends no data to other services.', 'workflow-captain' )
		);
		$out .= $tutorial( __( 'If you use an add-on that sends data to external services (outgoing webhooks, n8n), name here the recipients, the data sent and the purpose. Workflow Captain does not know these settings.', 'workflow-captain' ) );
		$out .= $p(
			'<strong>' . __( 'Your rights:', 'workflow-captain' ) . '</strong> '
			. __( 'Workflow Captain is connected to the WordPress tools for exporting and erasing personal data. The export contains the stored events and runs, tags and notes on the user account and the time of unsubscribing. On erasure, or when a user account is deleted, the user ID is removed from stored events and runs, and tags and notes on the user account and the message counters are deleted. The unsubscribe from promotional messages is kept, so that none are sent again.', 'workflow-captain' )
		);
		return $out;
	}
}
