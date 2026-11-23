<?php
/**
 * Translatable texts of the shipped data files (SET-WC-06 part 3, decision S4): the names and messages of the recipes
 * in assets/recipes/ and the core-mail overview in assets/data/core-mails.json. The files hold the English text; this
 * class lists each one in a gettext call, so it is in the template and the German files, and translates it at runtime.
 * Texts it does not know (add-on recipes, edited drafts) stay as they are.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Admin;

defined( 'ABSPATH' ) || exit;

final class DataTexts {

	/** English text => translated text. */
	public static function texts(): array {
		return array(
			'Commission approved: notify partner management after one day' => __( 'Commission approved: notify partner management after one day', 'workflow-captain' ),
			'Large commission approved' => __( 'Large commission approved', 'workflow-captain' ),
			'Commission {{subject.id}} of {{data.amount_minor}} {{data.currency}} has been approved for one day.' => __( 'Commission {{subject.id}} of {{data.amount_minor}} {{data.currency}} has been approved for one day.', 'workflow-captain' ),
			'Date canceled: tag the date and notify the site operator' => __( 'Date canceled: tag the date and notify the site operator', 'workflow-captain' ),
			'Date {{subject.id}} canceled' => __( 'Date {{subject.id}} canceled', 'workflow-captain' ),
			'{{data.issued_tickets}} issued tickets are affected. Pass Captain informs the guests itself; this notice is in addition.' => __( '{{data.issued_tickets}} issued tickets are affected. Pass Captain informs the guests itself; this notice is in addition.', 'workflow-captain' ),
			'Check-in: notice to the site operator' => __( 'Check-in: notice to the site operator', 'workflow-captain' ),
			'Check-in {{subject.id}}' => __( 'Check-in {{subject.id}}', 'workflow-captain' ),
			'Ticket {{subject.id}} was checked in at {{occurred_at}} on device {{data.device_id}}.' => __( 'Ticket {{subject.id}} was checked in at {{occurred_at}} on device {{data.device_id}}.', 'workflow-captain' ),
			'Voucher redeemed: tag and add a note' => __( 'Voucher redeemed: tag and add a note', 'workflow-captain' ),
			'Redeemed: {{data.amount_minor}} {{data.currency}} via {{data.channel}}' => __( 'Redeemed: {{data.amount_minor}} {{data.currency}} via {{data.channel}}', 'workflow-captain' ),
			'on application without automatic approval' => __( 'on application without automatic approval', 'workflow-captain' ),
			'for many codes; with a delivery date only at the time of delivery' => __( 'for many codes; with a delivery date only at the time of delivery', 'workflow-captain' ),
			'the voucher email itself was sent before this event' => __( 'the voucher email itself was sent before this event', 'workflow-captain' ),
			'the same email as for “Order paid”' => __( 'the same email as for “Order paid”', 'workflow-captain' ),
			'for each affected date, also the cancellation of the date' => __( 'for each affected date, also the cancellation of the date', 'workflow-captain' ),
			'depending on the status change' => __( 'depending on the status change', 'workflow-captain' ),
			'depending on the change; reactivation without email' => __( 'depending on the change; reactivation without email', 'workflow-captain' ),
			'depending on the path: application, automatic approval or creation in the admin' => __( 'depending on the path: application, automatic approval or creation in the admin', 'workflow-captain' ),
			'with credit note; not for WooCommerce orders' => __( 'with credit note; not for WooCommerce orders', 'workflow-captain' ),
			'with PDF tickets; for WooCommerce orders also the order emails of WooCommerce' => __( 'with PDF tickets; for WooCommerce orders also the order emails of WooCommerce', 'workflow-captain' ),
			'not for WooCommerce orders (WooCommerce sends those) and not for refunds in the payment provider’s dashboard' => __( 'not for WooCommerce orders (WooCommerce sends those) and not for refunds in the payment provider’s dashboard', 'workflow-captain' ),
			'gifts only, only on the first redemption' => __( 'gifts only, only on the first redemption', 'workflow-captain' ),
			'only if “send” is chosen when issuing (default)' => __( 'only if “send” is chosen when issuing (default)', 'workflow-captain' ),
			'only if the commission goes into review; later via cron' => __( 'only if the commission goes into review; later via cron', 'workflow-captain' ),
			'only if there was a credit note' => __( 'only if there was a credit note', 'workflow-captain' ),
			'later via cron' => __( 'later via cron', 'workflow-captain' ),
			'also the order emails of WooCommerce' => __( 'also the order emails of WooCommerce', 'workflow-captain' ),
			'setting notify_admin_review' => __( 'setting notify_admin_review', 'workflow-captain' ),
			'setting notify_commissions (off by default) and the partner’s unsubscribe' => __( 'setting notify_commissions (off by default) and the partner’s unsubscribe', 'workflow-captain' ),
			'setting notify_payout_paid (on by default)' => __( 'setting notify_payout_paid (on by default)', 'workflow-captain' ),
			'can be turned off under WooCommerce → Settings → Emails' => __( 'can be turned off under WooCommerce → Settings → Emails', 'workflow-captain' ),
			'can be turned off under WooCommerce → Settings → Emails, off by default' => __( 'can be turned off under WooCommerce → Settings → Emails, off by default', 'workflow-captain' ),
			'cannot be turned off (only with a code filter)' => __( 'cannot be turned off (only with a code filter)', 'workflow-captain' ),
			'only with the filter wpac_notify_credit_note' => __( 'only with the filter wpac_notify_credit_note', 'workflow-captain' ),
			'only with the filter wpcap_send_refund_mail' => __( 'only with the filter wpcap_send_refund_mail', 'workflow-captain' ),
		);
	}

	/** Readable name of a recipient key of the core-mail overview. */
	public static function recipients(): array {
		return array(
			'gift_recipient'     => __( 'Gift recipient', 'workflow-captain' ),
			'operator'           => __( 'Site operator', 'workflow-captain' ),
			'recipient_or_buyer' => __( 'Recipient or buyer', 'workflow-captain' ),
			'customer'           => __( 'Customer', 'workflow-captain' ),
			'buyer'              => __( 'Buyer', 'workflow-captain' ),
			'partner'            => __( 'Partner', 'workflow-captain' ),
			'ticket_holder'      => __( 'Ticket holder', 'workflow-captain' ),
		);
	}

	/** The translation of a shipped text, or the text itself. */
	public static function translate( string $text ): string {
		return self::texts()[ $text ] ?? $text;
	}

	/**
	 * A recipe in the export format with its name and the texts of its steps translated (subject, message, note text).
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function recipe( array $data ): array {
		if ( isset( $data['name'] ) && is_string( $data['name'] ) ) {
			$data['name'] = self::translate( $data['name'] );
		}
		foreach ( (array) ( $data['graph']['nodes'] ?? array() ) as $i => $node ) {
			foreach ( array( 'subject', 'message', 'text' ) as $field ) {
				if ( isset( $node['config'][ $field ] ) && is_string( $node['config'][ $field ] ) ) {
					$data['graph']['nodes'][ $i ]['config'][ $field ] = self::translate( $node['config'][ $field ] );
				}
			}
		}
		return $data;
	}

	/**
	 * Core-mail overview by event type with translated texts; "to" stays the key, "to_label" is its name.
	 *
	 * @param array<string,mixed> $events
	 * @return array<string,mixed>
	 */
	public static function core_mails( array $events ): array {
		$names = self::recipients();
		foreach ( $events as $type => $mails ) {
			foreach ( (array) $mails as $i => $mail ) {
				if ( ! is_array( $mail ) ) {
					continue;
				}
				$to                                = (string) ( $mail['to'] ?? '' );
				$events[ $type ][ $i ]['to_label'] = $names[ $to ] ?? $to;
				foreach ( array( 'off', 'note' ) as $field ) {
					if ( isset( $mail[ $field ] ) && is_string( $mail[ $field ] ) ) {
						$events[ $type ][ $i ][ $field ] = self::translate( $mail[ $field ] );
					}
				}
			}
		}
		return $events;
	}
}
