<?php
declare( strict_types=1 );

namespace BoardKit\Query;

use BoardKit\Editorial\Meta;

/**
 * Board filter bar (spec §5.3): search, assignee (me / a user), author, overdue only. Kept in
 * the URL by the client; parsed and allowlisted here; turned into WP_Query args by apply().
 */
final class Filters {
	public function __construct(
		public string $search = '',
		public int $assignee = 0,
		public int $author = 0,
		public bool $overdue = false
	) {}

	/** From request params (REST or $_GET). 'me' for assignee means the current user. */
	public static function from_params( array $p ): self {
		$assignee = $p['assignee'] ?? '';
		return new self(
			substr( sanitize_text_field( (string) ( $p['s'] ?? '' ) ), 0, 100 ),
			$assignee === 'me' ? get_current_user_id() : absint( $assignee ),
			absint( $p['author'] ?? 0 ),
			! empty( $p['overdue'] ) && $p['overdue'] !== '0'
		);
	}

	public function is_empty(): bool {
		return $this->search === '' && $this->assignee === 0 && $this->author === 0 && ! $this->overdue;
	}

	/**
	 * @param array<string, mixed> $args WP_Query args (lane + visibility already applied)
	 * @return array<string, mixed>
	 */
	public function apply( array $args ): array {
		if ( $this->search !== '' ) {
			$args['s'] = $this->search;
		}
		if ( $this->author > 0 ) {
			$args['author'] = $this->author;
		}
		$meta = [];
		if ( $this->assignee > 0 ) {
			// One row per assignee: exact equality, never LIKE on a serialised list (spec §4.5).
			$meta[] = [ 'key' => Meta::ASSIGNEES, 'value' => (string) $this->assignee, 'compare' => '=' ];
		}
		if ( $this->overdue ) {
			// Overdue = due before today (site time) and not published.
			$meta[] = [ 'key' => Meta::DUE, 'value' => wp_date( 'Y-m-d' ), 'compare' => '<', 'type' => 'DATE' ];
			$statuses = array_values( array_diff( (array) ( $args['post_status'] ?? [] ), [ 'publish' ] ) );
			if ( $statuses === [] ) {
				$args['post__in'] = [ 0 ];
			} else {
				$args['post_status'] = $statuses;
			}
		}
		if ( $meta !== [] ) {
			$existing           = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? [ $args['meta_query'] ] : [];
			$args['meta_query'] = array_merge( [ 'relation' => 'AND' ], $existing, $meta );
		}
		return $args;
	}
}
