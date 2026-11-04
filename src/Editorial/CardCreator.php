<?php
declare( strict_types=1 );

namespace BoardKit\Editorial;

use BoardKit\Boards\Board;
use BoardKit\Lanes\LaneSource;
use BoardKit\Moves\MoveService;
use WP_Error;

/**
 * "+ Add card" (PLAN Phase 5): a new post of the board's type, straight into a lane.
 *
 * - create_posts for the post type (its mapped cap, never the literal 'edit_posts');
 * - the lane source decides which lanes accept new cards (draft/pending only — a new card
 *   never publishes or schedules) and puts the post in the lane;
 * - if placing it fails, the half-made post is removed again, so a refused add leaves nothing.
 */
final class CardCreator {
	/** Lanes the current user can add cards to. @return array<int, string> */
	public static function creatable_lanes( Board $board, LaneSource $source ): array {
		if ( ! self::can_create( $board ) ) {
			return [];
		}
		return array_values( array_filter( $board->lane_values(), static fn( string $v ): bool => $source->new_post_status( $board, $v ) !== null ) );
	}

	public static function can_create( Board $board ): bool {
		$type = get_post_type_object( $board->post_type() );
		$cap  = $type && isset( $type->cap->create_posts ) ? (string) $type->cap->create_posts : '';
		return $cap !== '' && current_user_can( $cap );
	}

	public static function create( Board $board, LaneSource $source, string $title, string $lane ): \WP_Post|WP_Error {
		if ( ! self::can_create( $board ) ) {
			return MoveService::error( 'bk_forbidden', __( 'You cannot add posts here.', 'boardkit' ), 403 );
		}
		$title = trim( $title );
		if ( $title === '' ) {
			return MoveService::error( 'bk_no_title', __( 'Give the card a title.', 'boardkit' ), 400 );
		}
		if ( mb_strlen( $title ) > CardDetails::TITLE_MAX ) {
			return MoveService::error( 'bk_too_long', __( 'That title is too long.', 'boardkit' ), 400 );
		}
		$status = $source->new_post_status( $board, $lane );
		if ( $status === null ) {
			return MoveService::error( 'bk_cannot_create', __( 'Cards can’t be added to this lane. Add it to another lane, then move it here.', 'boardkit' ), 400 );
		}

		// wp_insert_post() unslashes its input.
		$id = wp_insert_post( wp_slash( [
			'post_type'   => $board->post_type(),
			'post_title'  => $title,
			'post_status' => $status,
			'post_author' => get_current_user_id(),
		] ), true );
		if ( $id instanceof WP_Error || ! $id ) {
			return MoveService::error( 'bk_save_failed', $id instanceof WP_Error ? $id->get_error_message() : __( 'Could not add the card.', 'boardkit' ), 500 );
		}
		$post = get_post( (int) $id );
		if ( ! $post instanceof \WP_Post ) {
			return MoveService::error( 'bk_save_failed', __( 'Could not add the card.', 'boardkit' ), 500 );
		}

		$placed = $source->place_new( $post, $lane, $board );
		if ( $placed instanceof WP_Error ) {
			wp_delete_post( $post->ID, true ); // our own post from this request: undo it
			return $placed;
		}
		ActivityLog::add( $post->ID, 'created', '', $lane );
		return $post;
	}
}
