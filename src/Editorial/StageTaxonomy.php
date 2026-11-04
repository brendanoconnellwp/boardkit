<?php
declare( strict_types=1 );

namespace BoardKit\Editorial;

use BoardKit\Boards\Board;
use BoardKit\Boards\BoardRepository;

/**
 * Workflow stage as a private taxonomy, `bk_stage` (spec §4.2) — not a custom post status.
 * Terms are created from a stage board's lanes; the term slug is the lane value.
 */
final class StageTaxonomy {
	public const TAXONOMY = 'bk_stage';

	public function __construct( private BoardRepository $boards ) {}

	public function register_hooks(): void {
		// Priority 5: before most plugins' own init work that might query it.
		add_action( 'init', [ $this, 'register' ], 5 );
	}

	public function register(): void {
		register_taxonomy(
			self::TAXONOMY,
			$this->post_types(),
			[
				'labels'            => [
					'name'          => __( 'Stages', 'boardkit' ),
					'singular_name' => __( 'Stage', 'boardkit' ),
				],
				'public'            => false,
				'show_ui'           => false,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => false,
				'query_var'         => false,
				'show_admin_column' => false,
				// Assigning needs edit_posts (core's default for assign_terms) — checked again per
				// post on every move (spec §7.2).
				'capabilities'      => [
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_posts',
				],
			]
		);
	}

	/** @return array<int, string> post types that have a stage board */
	public function post_types(): array {
		$types = [];
		foreach ( $this->boards->all() as $board ) {
			if ( $board->lane_kind() === 'stage' ) {
				$types[] = $board->post_type();
			}
		}
		return array_values( array_unique( $types ) );
	}

	/**
	 * Make sure every lane of a stage board has its term (slug = lane value, name = lane label).
	 * Renaming a lane renames the term; the slug never changes.
	 */
	public static function ensure_terms( Board $board ): void {
		if ( $board->lane_kind() !== 'stage' || ! taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}
		foreach ( $board->lanes() as $lane ) {
			$term = get_term_by( 'slug', $lane['value'], self::TAXONOMY );
			if ( $term instanceof \WP_Term ) {
				if ( $term->name !== $lane['label'] ) {
					wp_update_term( $term->term_id, self::TAXONOMY, [ 'name' => $lane['label'] ] );
				}
				continue;
			}
			wp_insert_term( $lane['label'], self::TAXONOMY, [ 'slug' => $lane['value'] ] );
		}
	}
}
