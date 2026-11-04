<?php
declare( strict_types=1 );

namespace BoardKit\Admin;

use BoardKit\Rest\BoardsController;

/** Scripts and styles, only on BoardKit's own screens (checked by hook suffix). */
final class Assets {
	public const HANDLE = 'boardkit-board';

	/**
	 * Flags for any JSON printed into a <script> tag: post titles and lane labels are written by
	 * users and must not be able to close the tag (the same rule ColumnKit fixed in PR #1).
	 */
	public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	public function __construct( private Menu $menu, private BoardPage $page ) {}

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix === '' || $hook_suffix !== $this->menu->hook_suffix() ) {
			return;
		}
		$board = $this->page->current_board();
		if ( $board === null ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, BK_URL . 'assets/board.css', [], BK_VERSION );
		wp_enqueue_script( self::HANDLE, BK_URL . 'assets/board.js', [ 'wp-api-fetch', 'wp-a11y' ], BK_VERSION, true );

		$config = [
			'board'     => $board->id(),
			'namespace' => BoardsController::NAMESPACE,
			'i18n'      => [
				'loading'    => __( 'Loading…', 'boardkit' ),
				'loadMore'   => __( 'Load more', 'boardkit' ),
				'empty'      => __( 'No cards', 'boardkit' ),
				'error'      => __( 'Could not load the board.', 'boardkit' ),
				'overdue'    => __( 'Overdue', 'boardkit' ),
				/* translators: %s: date */
				'due'        => __( 'Due %s', 'boardkit' ),
				/* translators: 1: lane name, 2: number of cards */
				'laneLabel'  => __( '%1$s, %2$d cards', 'boardkit' ),
				/* translators: 1: number of cards, 2: WIP limit */
				'overWip'    => __( '%1$d of %2$d — over the limit', 'boardkit' ),
				'assignees'  => __( 'Assigned to', 'boardkit' ),
				'author'     => __( 'Author', 'boardkit' ),
				'loadedMore' => __( 'More cards loaded', 'boardkit' ),
			],
		];
		wp_add_inline_script( self::HANDLE, 'window.BoardKitConfig = ' . wp_json_encode( $config, self::JSON_FLAGS ) . ';', 'before' );
	}
}
