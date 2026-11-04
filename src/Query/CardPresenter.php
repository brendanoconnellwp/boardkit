<?php
declare( strict_types=1 );

namespace BoardKit\Query;

use BoardKit\Boards\Board;
use BoardKit\Editorial\Meta;
use BoardKit\Lanes\LaneSource;
use BoardKit\Lanes\LaneSourceRegistry;

/**
 * Posts → card data for the REST responses (PLAN §1: cards are data, not HTML).
 *
 * Every string here is PLAIN TEXT. board.js puts them in the DOM with textContent / setAttribute,
 * never innerHTML, so a post title can't inject markup.
 */
final class CardPresenter {
	public function __construct( private LaneSourceRegistry $sources ) {}

	public function source( Board $board ): LaneSource {
		return $this->sources->for_board( $board );
	}

	/**
	 * Prime the user cache for every author and assignee on the page in one query (spec §8).
	 *
	 * @param array<int, \WP_Post> $posts
	 */
	public static function prime_users( array $posts ): void {
		$ids = [];
		foreach ( $posts as $post ) {
			$ids[] = (int) $post->post_author;
			foreach ( Meta::assignees( $post->ID ) as $uid ) {
				$ids[] = $uid;
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( $ids !== [] ) {
			cache_users( $ids );
		}
	}

	/**
	 * @param array<int, \WP_Post> $posts
	 * @return array<int, array<string, mixed>>
	 */
	public function cards( array $posts, Board $board ): array {
		self::prime_users( $posts );
		return array_map( fn( \WP_Post $p ): array => $this->card( $p, $board ), $posts );
	}

	/** @return array<string, mixed> */
	public function card( \WP_Post $post, Board $board ): array {
		$fields = (array) ( $board->cards()['fields'] ?? [] );
		$status = get_post_status_object( $post->post_status );
		$due    = Meta::due( $post->ID );

		$card = [
			'id'           => $post->ID,
			'title'        => self::text( get_the_title( $post ) ) ?: __( '(no title)', 'boardkit' ),
			'lane'         => $this->sources->for_board( $board )->lane_of( $post, $board ),
			'edit_url'     => current_user_can( 'edit_post', $post->ID ) ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
			'view_url'     => (string) get_permalink( $post ),
			'modified_gmt' => (string) $post->post_modified_gmt,
			// Site-local 'Y-m-d H:i' if the post has a real date (drafts often don't), for the
			// schedule picker's default.
			'date'         => self::has_date( $post ) ? substr( (string) $post->post_date, 0, 16 ) : '',
			'status'       => [
				'value' => (string) $post->post_status,
				'label' => $status ? (string) $status->label : (string) $post->post_status,
			],
			'fields'       => $fields,
		];

		if ( in_array( 'author', $fields, true ) ) {
			$card['author'] = self::person( (int) $post->post_author );
		}
		if ( in_array( 'assignees', $fields, true ) ) {
			$card['assignees'] = array_values( array_filter( array_map( [ self::class, 'person' ], Meta::assignees( $post->ID ) ) ) );
		}
		if ( in_array( 'due', $fields, true ) ) {
			$card['due'] = [
				'date'    => $due,
				// Overdue: a due date before today (site timezone) on a post that isn't published.
				'overdue' => $due !== '' && $due < wp_date( 'Y-m-d' ) && $post->post_status !== 'publish',
			];
		}
		if ( in_array( 'terms', $fields, true ) ) {
			$tax           = (string) ( $board->cards()['taxonomy'] ?? 'category' );
			$terms         = taxonomy_exists( $tax ) ? get_the_terms( $post, $tax ) : false;
			$card['terms'] = is_array( $terms ) ? array_map( static fn( \WP_Term $t ): string => self::text( $t->name ), $terms ) : [];
		}
		if ( in_array( 'thumbnail', $fields, true ) ) {
			$card['thumbnail'] = (string) get_the_post_thumbnail_url( $post, 'thumbnail' );
		}
		return $card;
	}

	/** @return array{id: int, name: string, avatar: string}|null */
	public static function person( int $user_id ): ?array {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return null;
		}
		return [
			'id'     => (int) $user->ID,
			'name'   => self::text( (string) $user->display_name ),
			'avatar' => (string) get_avatar_url( $user->ID, [ 'size' => 48 ] ),
		];
	}

	public static function has_date( \WP_Post $post ): bool {
		$gmt = (string) $post->post_date_gmt;
		return $gmt !== '' && $gmt !== '0000-00-00 00:00:00';
	}

	/** Display text → plain text: strip tags, decode entities (wptexturize turns ' into &#8217;). */
	public static function text( string $s ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
