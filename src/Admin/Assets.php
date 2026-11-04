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
		// SortableJS 1.15.6 (MIT), vendored with its licence — see assets/vendor/VENDOR.md.
		wp_register_script( 'boardkit-sortable', BK_URL . 'assets/vendor/sortable.min.js', [], '1.15.6', true );
		wp_enqueue_script( self::HANDLE, BK_URL . 'assets/board.js', [ 'wp-api-fetch', 'wp-a11y', 'boardkit-sortable' ], BK_VERSION, true );

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
				/* translators: %s: post title */
				'actions'    => __( 'Actions for %s', 'boardkit' ),
				'open'       => __( 'Open', 'boardkit' ),
				'view'       => __( 'View post', 'boardkit' ),
				/* translators: %s: lane name */
				'moveTo'     => __( 'Move to %s', 'boardkit' ),
				/* translators: 1: post title, 2: lane name */
				'moved'      => __( 'Moved “%1$s” to %2$s', 'boardkit' ),
				/* translators: 1: post title, 2: lane name */
				'pickedUp'   => __( '“%1$s” picked up, in %2$s. Use the left and right arrow keys to choose a lane, Space to drop, Escape to cancel.', 'boardkit' ),
				/* translators: %s: lane name */
				'targetSame' => __( '%s (current lane)', 'boardkit' ),
				'cancelled'  => __( 'Move cancelled.', 'boardkit' ),
				'cardHelp'   => __( 'Press Space to pick up this card and move it to another lane.', 'boardkit' ),
			],
		];
		wp_add_inline_script( self::HANDLE, 'window.BoardKitConfig = ' . wp_json_encode( $config, self::JSON_FLAGS ) . ';', 'before' );
	}
}
