<?php
declare( strict_types=1 );

namespace BoardKit\Editorial;

use BoardKit\Moves\MoveService;
use WP_Error;

/**
 * Who's working on a post (spec §4.5): `_bk_assignees`, ONE META ROW PER USER ID, so
 * "assigned to me" is a plain meta_query equality — never LIKE on a serialised array.
 */
final class Assignees {
	/** A card shows avatars; more than this is a team, not an assignment. */
	public const MAX = 10;

	/**
	 * Replace a post's assignees. Every user must be able to edit_post THIS post (checked here,
	 * not only in the picker). Caller has checked edit_post for the current user.
	 *
	 * @param array<int, mixed> $user_ids
	 * @return array<int, int>|WP_Error The saved list.
	 */
	public static function set( \WP_Post $post, array $user_ids ): array|WP_Error {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( count( $ids ) > self::MAX ) {
			/* translators: %d: maximum number of assignees */
			return MoveService::error( 'bk_too_many', sprintf( __( 'A post can have at most %d assignees.', 'boardkit' ), self::MAX ), 400 );
		}
		foreach ( $ids as $id ) {
			$user = get_userdata( $id );
			if ( ! $user || ! user_can( $user, 'edit_post', $post->ID ) ) {
				return MoveService::error(
					'bk_bad_assignee',
					/* translators: %s: user's display name, or a user ID */
					sprintf( __( '%s cannot edit this post, so they cannot be assigned to it.', 'boardkit' ), $user ? $user->display_name : '#' . $id ),
					400
				);
			}
		}
		sort( $ids );

		$current = Meta::assignees( $post->ID );
		sort( $current );
		if ( $current === $ids ) {
			return $ids;
		}
		delete_post_meta( $post->ID, Meta::ASSIGNEES );
		foreach ( $ids as $id ) {
			add_post_meta( $post->ID, Meta::ASSIGNEES, $id, false );
		}
		ActivityLog::add( $post->ID, 'assignees', implode( ',', $current ), implode( ',', $ids ) );
		return $ids;
	}
}
