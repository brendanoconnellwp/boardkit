<?php
declare( strict_types=1 );

namespace BoardKit\Editorial;

/**
 * Editorial post-meta keys (spec §4.5). Underscore-prefixed = protected meta, hidden from the
 * custom-fields box and REST by default. Reading/writing logic lands in Phase 4.
 */
final class Meta {
	/** One meta row per assigned user ID (add_post_meta(…, false)), so meta_query can match one user. */
	public const ASSIGNEES = '_bk_assignees';

	/** 'Y-m-d', one row; absent (never '') when there's no due date. */
	public const DUE = '_bk_due';

	/** Activity log (Phase 3). */
	public const LOG = '_bk_log';

	/** @return array<int, int> */
	public static function assignees( int $post_id ): array {
		$ids = array_map( 'intval', (array) get_post_meta( $post_id, self::ASSIGNEES, false ) );
		return array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
	}

	public static function due( int $post_id ): string {
		$due = (string) get_post_meta( $post_id, self::DUE, true );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) ? $due : '';
	}
}
