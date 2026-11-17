<?php
/**
 * Optional part of a Directory: whether a user belongs to the current site (multisite). Used by actions that address a
 * user in a run from another site of the network (NetworkSafe): a user who is no member of the target site is not
 * addressed there.
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Engine;

defined( 'ABSPATH' ) || exit;

interface SiteMembers {

	public function is_member( int $user_id ): bool;
}
