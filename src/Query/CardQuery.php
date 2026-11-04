<?php
declare( strict_types=1 );

namespace BoardKit\Query;

use BoardKit\Boards\Board;
use BoardKit\Lanes\LaneSourceRegistry;
use BoardKit\Lanes\StatusSource;

/**
 * Cards for one lane, one page at a time (spec §5.3, §8).
 *
 * build_args() is pure (no WP_Query) so the unit suite can pin every branch; run() and count()
 * are thin wrappers checked by the smoke test.
 */
final class CardQuery {
	public const PER_PAGE = 50;

	/** WP_Query orderby for each board sort field. */
	private const ORDERBY = [
		'modified'   => 'modified',
		'date'       => 'date',
		'title'      => 'title',
		'menu_order' => 'menu_order',
	];

	public function __construct(
		private LaneSourceRegistry $sources,
		private Visibility $visibility
	) {}

	/**
	 * @param string          $lane    A lane value, or Board::UNSORTED.
	 * @param array<int,int>|null $visible From Visibility::visible_ids() (null = unrestricted).
	 * @return array<string, mixed>
	 */
	public function build_args( Board $board, string $lane, int $offset, ?array $visible, ?Filters $filters = null ): array {
		$source = $this->sources->for_board( $board );
		$lane_args = $lane === Board::UNSORTED
			? $source->query_args_for_unsorted( $board )
			: $source->query_args_for_lane( $board, $lane );

		$sort    = $board->sort();
		$orderby = self::ORDERBY[ $sort['by'] ] ?? 'modified';
		$order   = $sort['dir'] === 'asc' ? 'ASC' : 'DESC';

		$args = [
			'post_type'              => $board->post_type(),
			'post_status'            => StatusSource::board_statuses(),
			'posts_per_page'         => self::PER_PAGE,
			'offset'                 => max( 0, $offset ),
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => false,
			// Card rendering reads meta and terms: let WP_Query prime both caches in one go.
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
			// ID as tie-breaker: stable paging when many posts share a modified time.
			'orderby'                => [ $orderby => $order, 'ID' => $order ],
		];
		if ( $sort['by'] === 'due' ) {
			// Undated posts last in BOTH directions: done in SQL by DueSort (posts_clauses), because
			// ordering by a meta clause puts NULLs first on ASC (PLAN §0 item 6).
			$args['orderby']     = [ 'ID' => $order ];
			$args['bk_due_sort'] = $order;
		}
		// The lane narrows post_status for status boards; otherwise keep the board's statuses.
		$args = array_merge( $args, $lane_args );

		if ( $visible !== null ) {
			$allowed = $visible;
			if ( isset( $args['post__in'] ) ) {
				$allowed = array_values( array_intersect( $allowed, array_map( 'intval', (array) $args['post__in'] ) ) );
			}
			// post__in => [] means "no restriction" to WP_Query, so an empty set must be [0].
			$args['post__in'] = $allowed === [] ? [ 0 ] : $allowed;
		}
		return $filters ? $filters->apply( $args ) : $args;
	}

	/** @return array{posts: array<int, \WP_Post>, next_offset: int|null} */
	public function page( Board $board, string $lane, int $offset, ?Filters $filters = null ): array {
		$args = $this->build_args( $board, $lane, $offset, $this->visibility->visible_ids( $board ), $filters );
		// One extra row tells us whether there's a next page without a COUNT(*).
		$args['posts_per_page'] = self::PER_PAGE + 1;
		$posts                  = array_values( array_filter( (array) ( new \WP_Query( $args ) )->posts, static fn( $p ) => $p instanceof \WP_Post ) );
		$more                   = count( $posts ) > self::PER_PAGE;
		$posts                  = array_slice( $posts, 0, self::PER_PAGE );
		return [
			'posts'       => $posts,
			'next_offset' => $more ? $offset + self::PER_PAGE : null,
		];
	}

	public function count( Board $board, string $lane, ?Filters $filters = null ): int {
		$args = $this->build_args( $board, $lane, 0, $this->visibility->visible_ids( $board ), $filters );
		$args = array_merge( $args, [
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'offset'                 => 0,
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'none',
			'bk_due_sort'            => '',
		] );
		return (int) ( new \WP_Query( $args ) )->found_posts;
	}

	/** @return array<string, int> lane value (and Board::UNSORTED) => count */
	public function counts( Board $board, ?Filters $filters = null ): array {
		$out = [];
		foreach ( array_merge( $board->lane_values(), [ Board::UNSORTED ] ) as $lane ) {
			$out[ $lane ] = $this->count( $board, $lane, $filters );
		}
		return $out;
	}
}
