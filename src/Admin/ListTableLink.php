<?php
declare( strict_types=1 );

namespace BoardKit\Admin;

use BoardKit\Boards\BoardRepository;
use BoardKit\Query\Visibility;

/**
 * A "Board" link among the list table's view links (All | Mine | Published …) for post types
 * that have a board (spec §5.1). Uses `views_edit-{post_type}`, so ColumnKit needs no change.
 */
final class ListTableLink {
	public function __construct( private BoardRepository $boards ) {}

	public function register_hooks(): void {
		add_action( 'current_screen', [ $this, 'maybe_hook' ] );
	}

	public function maybe_hook( \WP_Screen $screen ): void {
		if ( $screen->base !== 'edit' || $screen->post_type === '' ) {
			return;
		}
		add_filter( 'views_edit-' . $screen->post_type, fn( array $views ): array => $this->add_links( $views, $screen->post_type ) );
	}

	/**
	 * @param array<string, string> $views
	 * @return array<string, string>
	 */
	public function add_links( array $views, string $post_type ): array {
		foreach ( $this->boards->for_post_type( $post_type ) as $board ) {
			if ( ! Visibility::can_view_board( $board ) ) {
				continue;
			}
			$views[ 'bk-board-' . $board->id() ] = sprintf(
				'<a href="%s" class="bk-board-link"><span class="dashicons dashicons-columns" aria-hidden="true"></span> %s</a>',
				esc_url( Menu::board_url( $board->id() ) ),
				esc_html( $board->label() )
			);
		}
		return $views;
	}
}
