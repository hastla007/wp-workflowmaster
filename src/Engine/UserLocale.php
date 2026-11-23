<?php
/**
 * Optional part of a Directory: the language of a user and of the site (SET-WC-18), for the language version of a
 * message and the way dates and amounts are written (SET-WC-32).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

interface UserLocale {

	/** Locale of the user (e.g. de_DE, de_DE_formal, en_US), null when unknown. */
	public function locale_of( int $user_id ): ?string;

	/** Locale of the site. */
	public function site_locale(): string;
}
