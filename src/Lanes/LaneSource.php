<?php
declare( strict_types=1 );

namespace BoardKit\Lanes;

use BoardKit\Boards\Board;

/**
 * Where a board's lanes come from (spec §4.3).
 *
 * move() is called by MoveService only, after the shared checks (post on board, edit_post,
 * target is a board lane, no conflict). It applies the source's own permission rule (§7.2
 * rule 4) and saves. Returns bool|\WP_Error — the `true` type needs PHP 8.2 (PLAN §0 item 9).
 */
interface LaneSource {
	public function kind(): string;

	/** @return array<string, string> value => default label, for the settings lane editor */
	public function available_values( Board $board ): array;

	/** @return array<string, mixed> WP_Query args that select the posts in one lane */
	public function query_args_for_lane( Board $board, string $value ): array;

	/** @return array<string, mixed> WP_Query args for the read-only "Unsorted" lane: none of the board's lanes */
	public function query_args_for_unsorted( Board $board ): array;

	/** Which of the board's lanes a post is in, or null (Unsorted). */
	public function lane_of( \WP_Post $post, Board $board ): ?string;

	/** @return bool|\WP_Error true on success (never false); WP_Error data is ['status' => int]. */
	public function move( \WP_Post $post, string $to, Board $board ): bool|\WP_Error;
}
