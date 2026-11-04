<?php
declare( strict_types=1 );

namespace BoardKit\Lanes;

use BoardKit\Boards\Board;
use BoardKit\Editorial\StageTaxonomy;
use BoardKit\Moves\MoveService;

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

	public function move( \WP_Post $post, string $to, Board $board ): bool|\WP_Error {
		// Rule 4: assign_terms for the taxonomy (its mapped cap, not a literal).
		$tax = get_taxonomy( StageTaxonomy::TAXONOMY );
		$cap = $tax && isset( $tax->cap->assign_terms ) ? (string) $tax->cap->assign_terms : '';
		if ( $cap === '' || ! current_user_can( $cap ) ) {
			return MoveService::error( 'bk_forbidden', __( 'You cannot change the stage.', 'boardkit' ), 403 );
		}

		// By term ID, never by slug: wp_set_object_terms() with an unknown slug silently CREATES a
		// term named after it. Make sure the lane's term exists first.
		$term = get_term_by( 'slug', $to, StageTaxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			StageTaxonomy::ensure_terms( $board );
			$term = get_term_by( 'slug', $to, StageTaxonomy::TAXONOMY );
		}
		if ( ! $term instanceof \WP_Term ) {
			return MoveService::error( 'bk_no_term', __( 'That stage is missing. Re-save the board.', 'boardkit' ), 500 );
		}

		// One term per post: replace (append = false) fixes any post that somehow has several.
		$set = wp_set_object_terms( $post->ID, [ (int) $term->term_id ], StageTaxonomy::TAXONOMY, false );
		if ( $set instanceof \WP_Error ) {
			return MoveService::error( 'bk_save_failed', $set->get_error_message(), 500 );
		}
		return true;
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
