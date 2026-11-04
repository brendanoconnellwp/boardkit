<?php
declare( strict_types=1 );

namespace BoardKit\Lanes;

use BoardKit\Boards\Board;
use BoardKit\Editorial\StageTaxonomy;

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
