<?php
/**
 * Adapter for WordPress itself (source.product "wordpress", F-20, SPEC-suite-events 4.7): new users and published
 * posts. Lives in the free core and needs no license. Minimum WordPress 6.4.
 *
 * Passes only the allowed fields of the catalog: never a name, e-mail address, login, title or content (R-4.9).
 *
 * @package WorkflowCaptain
 */

namespace WorkflowCaptain\Providers;

use WorkflowCaptain\Consumer\TransientFailure;

defined( 'ABSPATH' ) || exit;

final class WordPressAdapter extends CoreAdapter {

	const USER_REGISTERED = 'wordpress.user.registered';
	const POST_PUBLISHED  = 'wordpress.post.published';

	/** Post types that never count as a published post, whatever their settings say. */
	const SKIPPED_POST_TYPES = array( 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );

	public function product(): string {
		return 'wordpress';
	}

	public function types(): array {
		return array( self::USER_REGISTERED, self::POST_PUBLISHED );
	}

	protected function detect_version(): string {
		global $wp_version;
		return is_string( $wp_version ?? null ) ? $wp_version : '';
	}

	protected function min_version(): string {
		return '6.4.0';
	}

	public function hook(): void {
		add_action( 'user_register', array( $this, 'on_user_register' ), self::PRIORITY, 1 );
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), self::PRIORITY, 3 );
	}

	/**
	 * Hook "user_register".
	 *
	 * @param int|string $user_id
	 */
	public function on_user_register( $user_id ): void {
		try {
			$user_id = (int) $user_id;
			$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
			if ( ! is_object( $user ) ) {
				return;
			}
			$roles = isset( $user->roles ) && is_array( $user->roles ) ? array_values( $user->roles ) : array();
			$this->emit(
				array(
					'type'          => self::USER_REGISTERED,
					'subject'       => array( 'type' => 'user', 'id' => (string) $user_id, 'id_kind' => 'record' ),
					'data'          => array( 'role' => self::key( $roles[0] ?? '' ) ),
					'actor_user_id' => self::current_actor(),
					'discriminator' => 'once',
				)
			);
		} catch ( \Throwable $e ) {
			return; // Nothing may reach WordPress (R-7.6).
		}
	}

	/**
	 * Hook "transition_post_status": only the first step into "publish" of a public post type counts.
	 *
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ): void {
		try {
			if ( 'publish' !== $new_status || 'publish' === $old_status || ! is_object( $post ) || empty( $post->ID ) ) {
				return;
			}
			if ( ! self::countable_post_type( (string) ( $post->post_type ?? '' ) ) ) {
				return;
			}
			if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post ) ) {
				return;
			}
			$this->emit(
				array(
					'type'          => self::POST_PUBLISHED,
					'subject'       => array( 'type' => 'post', 'id' => (string) (int) $post->ID, 'id_kind' => 'record' ),
					'data'          => array(
						'post_type'       => self::key( $post->post_type ),
						'author_user_id'  => (int) ( $post->post_author ?? 0 ),
						'previous_status' => self::key( $old_status ),
					),
					'actor_user_id' => self::current_actor(),
					'discriminator' => 'once',
				)
			);
		} catch ( \Throwable $e ) {
			return; // Nothing may reach WordPress (R-7.6).
		}
	}

	/**
	 * Re-reads through get_userdata() and get_post() (R-7.4): the user still exists, the post is still published.
	 */
	public function reread( array $envelope ): bool {
		$id = (int) ( $envelope['subject']['id'] ?? 0 );
		if ( $id < 1 ) {
			return false;
		}
		switch ( $envelope['type'] ?? '' ) {
			case self::USER_REGISTERED:
				if ( ! function_exists( 'get_userdata' ) ) {
					throw new TransientFailure( 'provider_unreachable' );
				}
				return is_object( get_userdata( $id ) );
			case self::POST_PUBLISHED:
				if ( ! function_exists( 'get_post' ) ) {
					throw new TransientFailure( 'provider_unreachable' );
				}
				$post = get_post( $id );
				return is_object( $post ) && 'publish' === ( $post->post_status ?? '' ) && (string) ( $post->post_type ?? '' ) === (string) ( $envelope['data']['post_type'] ?? '' );
		}
		return false;
	}

	private static function countable_post_type( string $type ): bool {
		if ( '' === $type || in_array( $type, self::SKIPPED_POST_TYPES, true ) ) {
			return false;
		}
		return function_exists( 'is_post_type_viewable' ) ? (bool) is_post_type_viewable( $type ) : false;
	}
}
