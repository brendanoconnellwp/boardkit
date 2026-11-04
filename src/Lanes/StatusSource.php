<?php
declare( strict_types=1 );

namespace BoardKit\Lanes;

use BoardKit\Boards\Board;
use BoardKit\Moves\MoveService;

/** Lanes are core post statuses (spec §4.3). */
final class StatusSource implements LaneSource {
	/** Never lanes: not real editorial states. */
	private const EXCLUDED = [ 'trash', 'auto-draft', 'inherit' ];

	/** Statuses that make a post public or scheduled: moving into them needs publish_posts. */
	private const PUBLISHING = [ 'publish', 'future', 'private' ];

	public function kind(): string {
		return 'status';
	}

	public function available_values( Board $board ): array {
		$out = [];
		foreach ( self::board_statuses() as $status ) {
			$obj            = get_post_status_object( $status );
			$out[ $status ] = $obj ? (string) $obj->label : $status;
		}
		return $out;
	}

	public function query_args_for_lane( Board $board, string $value ): array {
		return [ 'post_status' => [ $value ] ];
	}

	public function query_args_for_unsorted( Board $board ): array {
		// Statuses the board shows at all, minus the ones that have a lane. [] → nothing unsorted.
		$rest = array_values( array_diff( self::board_statuses(), $board->lane_values() ) );
		return $rest === [] ? [ 'post__in' => [ 0 ] ] : [ 'post_status' => $rest ];
	}

	/**
	 * Rule 4 for status boards (spec §7.2), with the post type's MAPPED caps:
	 * - into publish / future / private needs publish_posts (all three publish the post);
	 * - out of publish needs edit_published_posts;
	 * - into future needs a post_date in the future, else 400 "Set a date first".
	 */
	public function move( \WP_Post $post, string $to, Board $board ): bool|\WP_Error {
		$type = get_post_type_object( $post->post_type );
		if ( ! $type ) {
			return MoveService::error( 'bk_no_post', __( 'Unknown post type.', 'boardkit' ), 404 );
		}
		$cap  = $type->cap;
		$from = (string) $post->post_status;

		if ( in_array( $to, self::PUBLISHING, true ) && ! current_user_can( $cap->publish_posts ) ) {
			return MoveService::error( 'bk_cannot_publish', __( 'You cannot publish this post.', 'boardkit' ), 403 );
		}
		if ( $from === 'publish' && ! current_user_can( $cap->edit_published_posts ) ) {
			return MoveService::error( 'bk_cannot_unpublish', __( 'You cannot change a published post.', 'boardkit' ), 403 );
		}

		$fields = [ 'ID' => $post->ID, 'post_status' => $to ];
		if ( $to === 'future' ) {
			$gmt = (string) $post->post_date_gmt;
			if ( $gmt === '' || $gmt === '0000-00-00 00:00:00' || strtotime( $gmt . ' UTC' ) <= time() ) {
				return MoveService::error( 'bk_needs_date', __( 'Set a date first.', 'boardkit' ), 400 );
			}
			// wp_update_post() turns 'future' with a past date into 'publish'; keep the date as is.
			$fields['post_date']     = $post->post_date;
			$fields['post_date_gmt'] = $gmt;
		}
		if ( $to === 'publish' && $from === 'future' ) {
			// Publishing a scheduled post now goes live now, as core's "Publish" button does.
			$fields['post_date']     = current_time( 'mysql' );
			$fields['post_date_gmt'] = current_time( 'mysql', true );
		}
		// A draft's never-set ("floating") date stays floating: wp_update_post() only fixes it when
		// edit_date is passed, which we don't. Publishing later then dates it "now", as core does.
		$result = wp_update_post( wp_slash( $fields ), true );
		if ( $result instanceof \WP_Error ) {
			return MoveService::error( 'bk_save_failed', $result->get_error_message(), 500 );
		}
		return true;
	}

	public function lane_of( \WP_Post $post, Board $board ): ?string {
		return $board->has_lane( (string) $post->post_status ) ? (string) $post->post_status : null;
	}

	/**
	 * Statuses a board can show: what the admin "All" view lists (publish, future, draft,
	 * pending, private + any registered with show_in_admin_all_list), never trash/auto-draft.
	 *
	 * @return array<int, string>
	 */
	public static function board_statuses(): array {
		$stati = get_post_stati( [ 'show_in_admin_all_list' => true ] );
		return array_values( array_diff( array_map( 'strval', array_values( (array) $stati ) ), self::EXCLUDED ) );
	}
}
