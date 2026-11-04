<?php
declare( strict_types=1 );

namespace BoardKit\Lanes;

use BoardKit\Boards\Board;

/** Lanes are core post statuses (spec §4.3). */
final class StatusSource implements LaneSource {
	/** Never lanes: not real editorial states. */
	private const EXCLUDED = [ 'trash', 'auto-draft', 'inherit' ];

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
