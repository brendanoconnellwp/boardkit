<?php
declare( strict_types=1 );

namespace BoardKit\Moves;

use BoardKit\Boards\Board;
use BoardKit\Editorial\ActivityLog;
use BoardKit\Lanes\LaneSourceRegistry;
use WP_Error;

/**
 * The one place for the move rules every endpoint shares (spec §7.2 rules 1–3, 5, 6 and the
 * conflict check, PLAN §2). LaneSource::move() adds only its source-specific rule 4 + the write.
 *
 * Conflict check (PLAN §0 item 2): the client sends the lane it last SAW the card in. Stage,
 * taxonomy and meta writes don't change post_modified, so comparing timestamps would miss a
 * concurrent move; comparing the lane itself can't. For sources that write the post row
 * (status, author) the client's post_modified_gmt is compared as well.
 */
final class MoveService {
	/** Sources whose write goes through wp_update_post (and so bumps post_modified). */
	private const ROW_WRITERS = [ 'status', 'author' ];

	private static int $depth = 0;

	public function __construct( private LaneSourceRegistry $sources ) {}

	/**
	 * True while BoardKit itself is saving a post. BoardKit's own save_post handlers (the
	 * stage meta box, Phase 4) return early on this instead of re-running (rule 6).
	 */
	public static function is_moving(): bool {
		return self::$depth > 0;
	}

	/**
	 * @param string $from     The lane the client last saw the card in (Board::UNSORTED for none).
	 * @param string $modified post_modified_gmt as the client last saw it ('' to skip).
	 * @param array{date?: string} $opts date: site-local date picked for a lane that schedules.
	 * @return \WP_Post|WP_Error The post after the move. A 409 carries ['post' => current post].
	 */
	public function move( Board $board, int $post_id, string $to, string $from, string $modified, array $opts = [] ): \WP_Post|WP_Error {
		// Rule 1: the post exists and belongs to this board.
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || $post->post_type !== $board->post_type() || $post->post_status === 'trash' ) {
			return self::error( 'bk_no_post', __( 'That post is not on this board.', 'boardkit' ), 404 );
		}
		// Rule 2.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return self::error( 'bk_forbidden', __( 'You cannot edit this post.', 'boardkit' ), 403 );
		}
		// Rule 3: only the board's own lanes — never whatever the source would happen to accept,
		// and never into the read-only Unsorted lane.
		if ( ! $board->has_lane( $to ) ) {
			return self::error( 'bk_bad_lane', __( 'That lane is not on this board.', 'boardkit' ), 400 );
		}

		$source  = $this->sources->for_board( $board );
		$current = $source->lane_of( $post, $board ) ?? Board::UNSORTED;

		// Conflict: someone moved (or for row writers, edited) the card since the client loaded it.
		$stale_row = $modified !== '' && in_array( $source->kind(), self::ROW_WRITERS, true ) && $modified !== (string) $post->post_modified_gmt;
		if ( $current !== $from || $stale_row ) {
			return new WP_Error( 'bk_conflict', __( 'Updated by someone else — refreshed.', 'boardkit' ), [ 'status' => 409, 'post' => $post ] );
		}
		if ( $current === $to ) {
			return $post; // Already there: nothing to save, nothing to log.
		}

		$status_before = (string) $post->post_status;
		self::$depth++;
		try {
			$result = $source->move( $post, $to, $board, $opts );
		} finally {
			self::$depth--;
		}
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		clean_post_cache( $post->ID );
		$fresh = get_post( $post->ID );
		$fresh = $fresh instanceof \WP_Post ? $fresh : $post;

		ActivityLog::add( $post->ID, $source->kind(), $current, $to );
		// A stage lane that also set the WordPress status: log that separately, so the history
		// says "published", not only "moved to Published".
		if ( $source->kind() !== 'status' && $fresh->post_status !== $status_before ) {
			ActivityLog::add( $post->ID, 'status', $status_before, (string) $fresh->post_status );
		}
		return $fresh;
	}

	public static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
