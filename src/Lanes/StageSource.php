<?php
declare( strict_types=1 );

namespace BoardKit\Lanes;

use BoardKit\Boards\Board;
use BoardKit\Editorial\StageTaxonomy;
use BoardKit\Moves\MoveService;
use BoardKit\Moves\StatusChange;

final class StageSource implements LaneSource {
	public function kind(): string {
		return 'stage';
	}

	public function available_values( Board $board ): array {
		$out = [];
		foreach ( $board->lanes() as $lane ) {
			$out[ $lane['value'] ] = $lane['label'];
		}
		return $out;
	}

	public function query_args_for_lane( Board $board, string $value ): array {
		return [
			'tax_query' => [
				[
					'taxonomy'         => StageTaxonomy::TAXONOMY,
					'field'            => 'slug',
					'terms'            => [ $value ],
					'include_children' => false,
				],
			],
		];
	}

	public function query_args_for_unsorted( Board $board ): array {
		$values = $board->lane_values();
		if ( $values === [] ) {
			return []; // No lanes: every post is unsorted.
		}
		// NOT IN also matches posts with no bk_stage term at all.
		return [
			'tax_query' => [
				[
					'taxonomy'         => StageTaxonomy::TAXONOMY,
					'field'            => 'slug',
					'terms'            => $values,
					'operator'         => 'NOT IN',
					'include_children' => false,
				],
			],
		];
	}

	/**
	 * Set the stage; if the lane also sets a WordPress status, set that too (StatusChange's rules).
	 * Every check runs before anything is written, so a refused status change leaves the stage
	 * untouched as well.
	 */
	public function move( \WP_Post $post, string $to, Board $board, array $opts = [] ): bool|\WP_Error {
		// Rule 4: assign_terms for the taxonomy (its mapped cap, not a literal).
		if ( ! self::can_assign() ) {
			return MoveService::error( 'bk_forbidden', __( 'You cannot change the stage.', 'boardkit' ), 403 );
		}
		$term = self::term_for( $to, $board );
		if ( $term instanceof \WP_Error ) {
			return $term;
		}

		// The lane's status first: it's the step that can be refused (publish rights, a date).
		$status = $this->status_set_by( $board, $to );
		if ( $status !== '' ) {
			$changed = StatusChange::apply( $post, $status, (string) ( $opts['date'] ?? '' ) );
			if ( $changed instanceof \WP_Error ) {
				return $changed;
			}
		}

		return self::set_term( $post, $term );
	}

	/**
	 * New cards: only in lanes that set no status, or set draft/pending (a new card never
	 * publishes or schedules), and only for users who may assign the stage.
	 */
	public function new_post_status( Board $board, string $lane ): ?string {
		if ( ! $board->has_lane( $lane ) || ! self::can_assign() ) {
			return null;
		}
		$status = $this->status_set_by( $board, $lane );
		if ( $status === '' ) {
			return 'draft';
		}
		return in_array( $status, [ 'draft', 'pending' ], true ) ? $status : null;
	}

	public function place_new( \WP_Post $post, string $lane, Board $board ): bool|\WP_Error {
		if ( ! self::can_assign() ) {
			return MoveService::error( 'bk_forbidden', __( 'You cannot change the stage.', 'boardkit' ), 403 );
		}
		$term = self::term_for( $lane, $board );
		return $term instanceof \WP_Error ? $term : self::set_term( $post, $term );
	}

	private static function can_assign(): bool {
		$tax = get_taxonomy( StageTaxonomy::TAXONOMY );
		$cap = $tax && isset( $tax->cap->assign_terms ) ? (string) $tax->cap->assign_terms : '';
		return $cap !== '' && current_user_can( $cap );
	}

	/**
	 * By term ID, never by slug: wp_set_object_terms() with an unknown slug silently CREATES a
	 * term named after it. Make sure the lane's term exists first.
	 */
	private static function term_for( string $lane, Board $board ): \WP_Term|\WP_Error {
		$term = get_term_by( 'slug', $lane, StageTaxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			StageTaxonomy::ensure_terms( $board );
			$term = get_term_by( 'slug', $lane, StageTaxonomy::TAXONOMY );
		}
		if ( ! $term instanceof \WP_Term ) {
			return MoveService::error( 'bk_no_term', __( 'That stage is missing. Re-save the board.', 'boardkit' ), 500 );
		}
		return $term;
	}

	/** One term per post: replace (append = false) fixes any post that somehow has several. */
	private static function set_term( \WP_Post $post, \WP_Term $term ): bool|\WP_Error {
		$set = wp_set_object_terms( $post->ID, [ (int) $term->term_id ], StageTaxonomy::TAXONOMY, false );
		if ( $set instanceof \WP_Error ) {
			return MoveService::error( 'bk_save_failed', $set->get_error_message(), 500 );
		}
		return true;
	}

	public function status_set_by( Board $board, string $lane ): string {
		foreach ( $board->lanes() as $l ) {
			if ( $l['value'] === $lane ) {
				return (string) ( $l['status'] ?? '' );
			}
		}
		return '';
	}

	public function lane_of( \WP_Post $post, Board $board ): ?string {
		$terms = get_the_terms( $post, StageTaxonomy::TAXONOMY );
		if ( ! is_array( $terms ) || $terms === [] ) {
			return null;
		}
		$slugs = array_map( static fn( \WP_Term $t ): string => (string) $t->slug, $terms );
		// One term per post is the rule; if a post has several, the first in LANE order wins (§4.2).
		foreach ( $board->lane_values() as $value ) {
			if ( in_array( $value, $slugs, true ) ) {
				return $value;
			}
		}
		return null;
	}
}
