<?php
declare( strict_types=1 );

namespace BoardKit\Query;

use BoardKit\Editorial\Meta;

/**
 * Sort by due date with posts that have NO due date last, in both directions (spec §4.4, §8).
 *
 * The spec's suggestion (named EXISTS clause + OR NOT EXISTS, ordered by the clause) puts NULLs
 * FIRST on ASC in MySQL. So this joins _bk_due itself with a LEFT JOIN and orders by
 * "value IS NULL" first. Scoped to queries carrying the private `bk_due_sort` query var, so no
 * other query on the site is touched. MIN() + GROUP BY keeps one row per post even if bad data
 * left two due rows, and is valid under ONLY_FULL_GROUP_BY.
 */
final class DueSort {
	public const VAR = 'bk_due_sort';

	public static function register_hooks(): void {
		add_filter( 'posts_clauses', [ self::class, 'clauses' ], 10, 2 );
	}

	/**
	 * @param array<string, string> $clauses
	 * @return array<string, string>
	 */
	public static function clauses( array $clauses, \WP_Query $query ): array {
		$dir = $query->get( self::VAR );
		if ( $dir !== 'ASC' && $dir !== 'DESC' ) {
			return $clauses;
		}
		global $wpdb;
		$posts             = $wpdb->posts;
		$clauses['join']  .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS bk_due ON ( bk_due.post_id = {$posts}.ID AND bk_due.meta_key = %s )", Meta::DUE );
		$clauses['groupby'] = "{$posts}.ID";
		// $dir is one of two literals (checked above), never user input.
		$clauses['orderby'] = "( MIN( bk_due.meta_value ) IS NULL ) ASC, MIN( bk_due.meta_value ) {$dir}, {$posts}.ID {$dir}";
		return $clauses;
	}
}
