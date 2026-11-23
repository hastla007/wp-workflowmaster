<?php
/**
 * notify.user (8.1): an additional message to one WordPress user, addressed by user ID (R-5.6). The address is resolved
 * at execution and not stored.
 *
 * Every message is a transaction (default, also for versions published before this field existed) or promotional
 * (SET-WC-03, decisions E-S2 and S11). A promotional message goes out only with the user's consent (filter
 * workflow_captain_has_consent_v1), never to a user who unsubscribed, and always with an unsubscribe link and the
 * List-Unsubscribe headers (SET-WC-04). Otherwise the step runs and sends nothing, with the reason in the log.
 *
 * Both kinds respect the sending window and the daily limit per recipient of the site (SET-WC-07/-08, Pacing): a
 * message outside the window waits, over the limit it waits for the next day or is skipped.
 *
 * Language versions (SET-WC-18): `versions` may hold up to ten other texts by locale ({"de_DE": {"subject": …,
 * "message": …}}). The recipient's locale picks the version: the exact locale, else the bare language (de), else the
 * first version of the same language, else the main subject and message. Dates and amounts with a modifier are written in the language of the
 * chosen text (SET-WC-32); the main text uses the site's language. Versions published without the field behave as before.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine\Actions;

use WorkflowCaptain\Consumer\TransientFailure;
use WorkflowCaptain\Engine\Action;
use WorkflowCaptain\Engine\ActionContext;
use WorkflowCaptain\Engine\Directory;
use WorkflowCaptain\Engine\Marketing;
use WorkflowCaptain\Engine\MessageFooter;
use WorkflowCaptain\Engine\NetworkSafe;
use WorkflowCaptain\Engine\Pacing;
use WorkflowCaptain\Engine\SiteMembers;
use WorkflowCaptain\Engine\Template;
use WorkflowCaptain\Engine\UserLocale;

defined( 'ABSPATH' ) || exit;

final class NotifyUser implements Action, NetworkSafe {

	const PURPOSE_TRANSACTIONAL = 'transactional';
	const PURPOSE_PROMOTIONAL   = 'promotional';

	/** At most this many language versions besides the main text (SET-WC-18). */
	const MAX_VERSIONS = 10;

	public function __construct( private Directory $directory, private ?Pacing $pacing = null ) {}

	public function key(): string {
		return 'notify.user';
	}

	public function label(): string {
		return __( 'Message to user', 'workflow-captain' );
	}

	public function capability(): ?string {
		return null;
	}

	public function idempotent(): bool {
		return false;
	}

	public function validate_config( array $config ): array {
		$v     = array_merge( Template::validate( $config['subject'] ?? null, 'subject', 200 ), Template::validate( $config['message'] ?? null, 'message', 5000 ) );
		$field = $config['user_field'] ?? 'actor_user_id';
		if ( 'actor_user_id' !== $field && ! preg_match( '/^data\.[a-z0-9_]*user_id$/', (string) $field ) ) {
			$v[] = 'user_field:invalid';
		}
		if ( ! in_array( $config['purpose'] ?? self::PURPOSE_TRANSACTIONAL, array( self::PURPOSE_TRANSACTIONAL, self::PURPOSE_PROMOTIONAL ), true ) ) {
			$v[] = 'purpose:invalid';
		}
		return array_merge( $v, self::validate_versions( $config['versions'] ?? null ) );
	}

	/** Language versions: a map of locale to subject and message, checked like the main text (SET-WC-18). */
	private static function validate_versions( $versions ): array {
		if ( null === $versions || array() === $versions ) {
			return array();
		}
		if ( ! is_array( $versions ) || array_is_list( $versions ) ) {
			return array( 'versions:invalid' );
		}
		if ( count( $versions ) > self::MAX_VERSIONS ) {
			return array( 'versions:too_many' );
		}
		$v = array();
		foreach ( $versions as $locale => $text ) {
			if ( ! is_string( $locale ) || ! preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale ) ) {
				$v[] = 'versions:invalid_locale';
				continue;
			}
			if ( ! is_array( $text ) || array() !== array_diff( array_keys( $text ), array( 'subject', 'message' ) ) ) {
				$v[] = 'versions:invalid';
				continue;
			}
			$v = array_merge( $v, Template::validate( $text['subject'] ?? null, 'versions.' . $locale . '.subject', 200 ), Template::validate( $text['message'] ?? null, 'versions.' . $locale . '.message', 5000 ) );
		}
		return $v;
	}

	/**
	 * The text for a recipient's locale: exact locale, else a version for the bare language (de), else the first of
	 * the same language, else the main text with the site's language. Returns [subject, message, locale for the formats].
	 */
	public static function pick_version( array $config, ?string $locale, string $site_locale ): array {
		$main     = array( (string) $config['subject'], (string) $config['message'], $site_locale );
		$versions = is_array( $config['versions'] ?? null ) ? $config['versions'] : array();
		if ( null === $locale || array() === $versions ) {
			return $main;
		}
		$exact = preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?/', $locale, $m ) ? $m[0] : '';
		$lang  = (string) strtok( $locale, '_' );
		$found = isset( $versions[ $exact ] ) ? $exact : ( isset( $versions[ $lang ] ) ? $lang : null );
		if ( null === $found ) {
			foreach ( array_keys( $versions ) as $key ) {
				if ( strtok( (string) $key, '_' ) === $lang ) {
					$found = (string) $key;
					break;
				}
			}
		}
		if ( null === $found || ! is_array( $versions[ $found ] ) ) {
			return $main;
		}
		return array( (string) ( $versions[ $found ]['subject'] ?? '' ), (string) ( $versions[ $found ]['message'] ?? '' ), $found );
	}

	/** Editor fields; the kind of message is a required choice with "transaction" first (SET-WC-03). */
	public function builder_fields(): array {
		return array(
			array( 'key' => 'subject', 'label' => __( 'Subject', 'workflow-captain' ) ),
			array( 'key' => 'message', 'label' => __( 'Message', 'workflow-captain' ), 'type' => 'textarea' ),
			array( 'key' => 'user_field', 'label' => __( 'User from (actor_user_id or data.…_user_id)', 'workflow-captain' ) ),
			array(
				'key'     => 'purpose',
				'label'   => __( 'Kind of message', 'workflow-captain' ),
				'type'    => 'select',
				'options' => array(
					array( 'value' => self::PURPOSE_TRANSACTIONAL, 'label' => __( 'Transaction (about an order, booking or account)', 'workflow-captain' ) ),
					array( 'value' => self::PURPOSE_PROMOTIONAL, 'label' => __( 'Promotional (only with consent, with unsubscribe link)', 'workflow-captain' ) ),
				),
			),
			array(
				'key'    => 'versions',
				'label'  => __( 'Language versions', 'workflow-captain' ),
				'type'   => 'versions',
				'help'   => __( 'Optional. The recipient gets the version of their profile language (for example de_DE, or de for every German locale); without a match the main text above.', 'workflow-captain' ),
				'labels' => array(
					'locale'  => __( 'Language (locale, e.g. de_DE)', 'workflow-captain' ),
					'subject' => __( 'Subject', 'workflow-captain' ),
					'message' => __( 'Message', 'workflow-captain' ),
					'add'     => __( 'Add language version', 'workflow-captain' ),
					'remove'  => __( 'Remove language version', 'workflow-captain' ),
					'version' => __( 'Language version', 'workflow-captain' ),
					'invalid' => __( 'Use a locale such as de_DE or de that no other version uses.', 'workflow-captain' ),
				),
			),
		);
	}

	/**
	 * In a run from another site only a user ID from a released data field can be addressed (the actor never crosses,
	 * R-17.3), and only a member of this site (R-17.4). Without a user ID the step runs and sends nothing.
	 */
	public function network_check( ActionContext $context ): ?string {
		$user_id = $this->recipient( $context );
		if ( null === $user_id ) {
			return null;
		}
		return $this->directory instanceof SiteMembers && $this->directory->is_member( $user_id ) ? null : 'user_not_member';
	}

	private function recipient( ActionContext $context ): ?int {
		$field   = (string) ( $context->config['user_field'] ?? 'actor_user_id' );
		$user_id = 'actor_user_id' === $field
			? ( $context->envelope['actor_user_id'] ?? null )
			: ( $context->envelope['data'][ substr( $field, 5 ) ] ?? null );
		return is_int( $user_id ) && $user_id > 0 ? $user_id : null;
	}

	public function execute( ActionContext $context ): array {
		$user_id = $this->recipient( $context );
		$address = null === $user_id ? null : $this->directory->email_of( $user_id );
		if ( null === $address ) {
			return array( 'sent' => 0, 'skipped' => 'no_recipient' );
		}
		// Language version and formats of the recipient's language (SET-WC-18/-32).
		$lang = $this->directory instanceof UserLocale ? $this->directory : null;
		list( $subject, $message, $locale ) = self::pick_version( $context->config, $lang?->locale_of( (int) $user_id ), null === $lang ? Template::site_locale() : $lang->site_locale() );
		$subject = str_replace( array( "\r", "\n" ), ' ', Template::render( $subject, $context->envelope, $locale ) );
		$message = Template::render( $message, $context->envelope, $locale );
		// Footer of the site (SET-WC-10): contact details, legal notice; before an unsubscribe line.
		$footer = $this->directory instanceof MessageFooter ? trim( $this->directory->footer() ) : '';
		if ( '' !== $footer ) {
			$message .= "\n\n" . $footer;
		}
		if ( self::PURPOSE_PROMOTIONAL === ( $context->config['purpose'] ?? self::PURPOSE_TRANSACTIONAL ) ) {
			return $this->promotional( (int) $user_id, $address, $subject, $message, $context );
		}
		$skip = $this->admit( (int) $user_id );
		if ( null !== $skip ) {
			return array( 'sent' => 0, 'skipped' => $skip );
		}
		if ( ! $this->directory->mail( $address, $subject, $message ) ) {
			$this->pacing?->release( (int) $user_id );
			throw new TransientFailure( 'mail_failed' );
		}
		return array( 'sent' => 1 );
	}

	/**
	 * Sending window and daily limit (SET-WC-07/-08). Throws Deferred to wait; returns a skip reason or null.
	 *
	 * @throws \WorkflowCaptain\Engine\Deferred
	 */
	private function admit( int $user_id ): ?string {
		return null === $this->pacing ? null : $this->pacing->admit( $user_id );
	}

	/** Consent first, then the unsubscribe list; the link line is added to the text (wording: legal review). */
	private function promotional( int $user_id, string $address, string $subject, string $message, ActionContext $context ): array {
		$m = $this->directory instanceof Marketing ? $this->directory : null;
		if ( null === $m || ! $m->has_consent( $user_id, array( 'workflow_id' => $context->workflow_id, 'event' => (string) ( $context->envelope['type'] ?? '' ) ) ) ) {
			return array( 'sent' => 0, 'skipped' => 'no_consent', 'purpose' => self::PURPOSE_PROMOTIONAL );
		}
		if ( $m->unsubscribed( $user_id ) ) {
			return array( 'sent' => 0, 'skipped' => 'unsubscribed', 'purpose' => self::PURPOSE_PROMOTIONAL );
		}
		$skip = $this->admit( $user_id );
		if ( null !== $skip ) {
			return array( 'sent' => 0, 'skipped' => $skip, 'purpose' => self::PURPOSE_PROMOTIONAL );
		}
		$url = $m->unsubscribe_url( $user_id );
		/* translators: %s: unsubscribe link */
		$message .= "\n\n-- \n" . sprintf( __( 'To stop receiving these messages: %s', 'workflow-captain' ), $url );
		if ( ! $m->mail_promotional( $address, $subject, $message, $url ) ) {
			$this->pacing?->release( $user_id );
			throw new TransientFailure( 'mail_failed' );
		}
		return array( 'sent' => 1, 'purpose' => self::PURPOSE_PROMOTIONAL );
	}
}
