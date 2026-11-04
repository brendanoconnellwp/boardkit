<?php
declare( strict_types=1 );

namespace BoardKit\Query;

use BoardKit\Boards\Board;
use BoardKit\Editorial\Meta;
use BoardKit\Lanes\StatusSource;

/**
 * Who sees which cards (spec §5.2, §8).
 *
 * A user can open a board if they can edit_posts for its post type. Cards are the posts they
 * could open in the editor:
 *
 * - With edit_others_posts: no scoping (core's own list table shows them everything editable).
 * - Without: "my posts OR posts assigned to me", each confirmed with current_user_can('edit_post')
 *   so map_meta_cap rules (e.g. a Contributor can't edit their own published post) are honoured.
 *   WP_Query can't express author OR meta_query, so two ID-only queries are merged. The result
 *   is computed once per request and applied to every lane as post__in, which keeps paging and
 *   counts exact.
 */
final class Visibility {
	/** Upper bound per branch. A user without edit_others_posts rarely owns this many posts. */
	public const MAX_IDS = 2000;

	/** @var array<string, array<int, int>|null> board id + user id => ids */
	private array $cache = [];

	public static function can_view_board( Board $board ): bool {
		$cap = self::cap( $board->post_type(), 'edit_posts' );
		return $cap !== '' && current_user_can( $cap );
	}

	/**
	 * @return array<int, int>|null null = no restriction; [] = sees nothing.
	 */
	public function visible_ids( Board $board ): ?array {
		$others = self::cap( $board->post_type(), 'edit_others_posts' );
		if ( $others !== '' && current_user_can( $others ) ) {
			return null;
		}
		$user = get_current_user_id();
		$key  = $board->id() . ':' . $user;
		if ( array_key_exists( $key, $this->cache ) ) {
			return $this->cache[ $key ];
		}
		if ( $user <= 0 ) {
			return $this->cache[ $key ] = [];
		}

		$base = [
			'post_type'              => $board->post_type(),
			'post_status'            => StatusSource::board_statuses(),
			'fields'                 => 'ids',
			'posts_per_page'         => self::MAX_IDS,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
			'orderby'                => 'ID',
		];
		$mine     = self::query_ids( $base + [ 'author' => $user ] );
		$assigned = self::query_ids( $base + [
			'meta_query' => [
				[ 'key' => Meta::ASSIGNEES, 'value' => (string) $user, 'compare' => '=' ],
			],
		] );

		$ids = [];
		foreach ( array_unique( array_merge( $mine, $assigned ) ) as $id ) {
			if ( current_user_can( 'edit_post', $id ) ) {
				$ids[] = (int) $id;
			}
		}
		sort( $ids );
		return $this->cache[ $key ] = $ids;
	}

	/**
	 * The post type's MAPPED capability (spec §7.2 rule 5) — never a literal string, so CPTs
	 * with a custom capability_type work. '' if the post type doesn't exist.
	 */
	public static function cap( string $post_type, string $cap ): string {
		$obj = get_post_type_object( $post_type );
		if ( ! $obj || ! isset( $obj->cap->{$cap} ) ) {
			return '';
		}
		return (string) $obj->cap->{$cap};
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<int, int>
	 */
	private static function query_ids( array $args ): array {
		$q = new \WP_Query( $args );
		return array_map( 'intval', (array) $q->posts );
	}
}
