<?php
declare( strict_types=1 );

namespace BoardKit\Editorial;

use BoardKit\Moves\MoveService;
use WP_Error;

/**
 * The card panel's Details tab (PLAN Phase 5): title, excerpt and featured image.
 *
 * The caller (CardsController) has already checked the post is on the board and the user has
 * edit_post. Rules here:
 *   - compare-and-swap on post_modified_gmt: title and excerpt live on the post row, so the
 *     timestamp is a real version (unlike stage/meta writes);
 *   - refused while another user holds the editor's post lock — their next save would
 *     silently overwrite ours;
 *   - only title / excerpt / thumbnail are ever written. Status, date and content are never
 *     passed to wp_update_post, so the panel can't publish anything;
 *   - wp_update_post() UNSLASHES its input, so the values are wp_slash()ed first, or a title
 *     like `C:\temp` would lose its backslash.
 */
final class CardDetails {
	public const TITLE_MAX   = 300;
	public const EXCERPT_MAX = 5000;

	/** @return array<string, mixed> what the panel needs besides the card itself */
	public static function read( \WP_Post $post ): array {
		$type     = (string) $post->post_type;
		$thumb_id = (int) get_post_thumbnail_id( $post );
		$locker   = self::locked_by( $post );
		$user     = $locker ? get_userdata( $locker ) : false;
		$edit     = (string) get_edit_post_link( $post->ID, 'raw' );

		return [
			'title'      => (string) $post->post_title,
			'excerpt'    => (string) $post->post_excerpt,
			'featured'   => $thumb_id > 0 ? self::image( $thumb_id ) : null,
			'supports'   => [
				'title'     => post_type_supports( $type, 'title' ),
				'excerpt'   => post_type_supports( $type, 'excerpt' ),
				'thumbnail' => post_type_supports( $type, 'thumbnail' ),
				'editor'    => post_type_supports( $type, 'editor' ),
			],
			// The media modal lists the library through admin-ajax, which needs upload_files.
			'can_upload' => current_user_can( 'upload_files' ),
			'locked_by'  => $user ? (string) $user->display_name : '',
			'editor_url' => $edit !== '' ? add_query_arg( 'bk_panel', '1', $edit ) : '',
		];
	}

	/**
	 * @param array{title?: string, excerpt?: string, featured_media?: int} $changes only the keys present are written
	 * @return \WP_Post|WP_Error the post after saving; a 409 carries ['post' => current post]
	 */
	public static function save( \WP_Post $post, array $changes, string $modified ): \WP_Post|WP_Error {
		if ( $modified !== (string) $post->post_modified_gmt ) {
			return new WP_Error( 'bk_conflict', __( 'Updated by someone else — refreshed.', 'boardkit' ), [ 'status' => 409, 'post' => $post ] );
		}
		$locker = self::locked_by( $post );
		if ( $locker ) {
			$user = get_userdata( $locker );
			return new WP_Error(
				'bk_locked',
				/* translators: %s: user name */
				sprintf( __( '%s is editing this post right now. Try again when they’re done.', 'boardkit' ), $user ? $user->display_name : __( 'Someone', 'boardkit' ) ),
				[ 'status' => 409, 'post' => $post ]
			);
		}

		$row = [];
		if ( array_key_exists( 'title', $changes ) ) {
			$title = trim( (string) $changes['title'] );
			if ( mb_strlen( $title ) > self::TITLE_MAX ) {
				return MoveService::error( 'bk_too_long', __( 'That title is too long.', 'boardkit' ), 400 );
			}
			if ( $title !== (string) $post->post_title ) {
				$row['post_title'] = $title;
			}
		}
		if ( array_key_exists( 'excerpt', $changes ) ) {
			$excerpt = trim( (string) $changes['excerpt'] );
			if ( mb_strlen( $excerpt ) > self::EXCERPT_MAX ) {
				return MoveService::error( 'bk_too_long', __( 'That excerpt is too long.', 'boardkit' ), 400 );
			}
			if ( $excerpt !== (string) $post->post_excerpt ) {
				$row['post_excerpt'] = $excerpt;
			}
		}

		// Check the image before writing anything, so a bad image doesn't leave half a save.
		$thumb = null;
		if ( array_key_exists( 'featured_media', $changes ) ) {
			$thumb = (int) $changes['featured_media'];
			if ( ! post_type_supports( (string) $post->post_type, 'thumbnail' ) ) {
				return MoveService::error( 'bk_no_thumbnail', __( 'This post type has no featured image.', 'boardkit' ), 400 );
			}
			if ( $thumb > 0 && ! self::usable_image( $thumb ) ) {
				return MoveService::error( 'bk_bad_image', __( 'That image can’t be used.', 'boardkit' ), 400 );
			}
			if ( $thumb === (int) get_post_thumbnail_id( $post ) ) {
				$thumb = null; // unchanged
			}
		}

		if ( $row !== [] ) {
			$row['ID'] = $post->ID;
			$saved     = wp_update_post( wp_slash( $row ), true );
			if ( $saved instanceof WP_Error ) {
				return MoveService::error( 'bk_save_failed', $saved->get_error_message(), 500 );
			}
		}
		if ( $thumb !== null ) {
			if ( $thumb > 0 ) {
				set_post_thumbnail( $post->ID, $thumb );
			} else {
				delete_post_thumbnail( $post->ID );
			}
		}

		$fresh = get_post( $post->ID );
		return $fresh instanceof \WP_Post ? $fresh : $post;
	}

	/** Another user's live editor lock on the post (0 if none, or if it's the current user's). */
	public static function locked_by( \WP_Post $post ): int {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		return (int) wp_check_post_lock( $post->ID );
	}

	/** An image attachment the current user can see. */
	public static function usable_image( int $id ): bool {
		$att = get_post( $id );
		return $att instanceof \WP_Post
			&& $att->post_type === 'attachment'
			&& wp_attachment_is_image( $id )
			&& current_user_can( 'read_post', $id );
	}

	/** @return array{id: int, url: string, alt: string}|null */
	public static function image( int $id ): ?array {
		$url = wp_get_attachment_image_url( $id, 'medium' );
		if ( ! $url ) {
			return null;
		}
		return [
			'id'  => $id,
			'url' => (string) $url,
			'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		];
	}
}
