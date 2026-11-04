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

	/**
	 * Cache-busting version: the plugin version plus the file's modified time. BK_VERSION alone
	 * stays the same while code changes between releases, so a browser keeps running a stale
	 * board.js against a newer server (it did: a move into Scheduled sent no date).
	 */
	public static function version( string $relative ): string {
		$mtime = @filemtime( BK_DIR . $relative );
		return $mtime ? BK_VERSION . '.' . $mtime : BK_VERSION;
	}

	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix === '' || $hook_suffix !== $this->menu->hook_suffix() ) {
			return;
		}
		$board = $this->page->current_board();
		if ( $board === null ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, BK_URL . 'assets/board.css', [], self::version( 'assets/board.css' ) );
		// The core media modal, for the card panel's featured image. Its library is listed via
		// admin-ajax, which needs upload_files — so only for users who have it.
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
		// SortableJS 1.15.6 (MIT), vendored with its licence — see assets/vendor/VENDOR.md.
		wp_register_script( 'boardkit-sortable', BK_URL . 'assets/vendor/sortable.min.js', [], '1.15.6', true );
		wp_enqueue_script( self::HANDLE, BK_URL . 'assets/board.js', [ 'wp-api-fetch', 'wp-a11y', 'boardkit-sortable' ], self::version( 'assets/board.js' ), true );

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
				'cardHelp'   => __( 'Keyboard: select a card with Tab, press Space or Enter to pick it up, the arrow keys to choose a lane, and Space or Enter to drop it.', 'boardkit' ),
				/* translators: %s: post title */
				'hint'       => __( 'Moving “%s” — ← → choose a lane · Space or Enter to drop · Esc to cancel', 'boardkit' ),
				/* translators: %s: status label, e.g. "Published" */
				'statusNow'  => __( 'Status is now %s.', 'boardkit' ),
				// Under a lane's name: what dropping a card there does to the post.
				'sets'       => [
					'draft'   => __( 'Sets status: Draft', 'boardkit' ),
					'pending' => __( 'Sets status: Pending Review', 'boardkit' ),
					'future'  => __( 'Schedules the post', 'boardkit' ),
					'publish' => __( 'Publishes the post', 'boardkit' ),
					'private' => __( 'Publishes privately', 'boardkit' ),
				],
				/* translators: %s: lane name, e.g. "Scheduled" */
				'when'       => __( 'Publish on (%s)', 'boardkit' ),
				/* translators: %s: post title */
				'whenLabel'  => __( 'Choose when to publish “%s”', 'boardkit' ),
				/* translators: %s: timezone, e.g. "Australia/Sydney" */
				'siteTime'   => __( 'Site time: %s', 'boardkit' ),
				'schedule'   => __( 'Schedule', 'boardkit' ),
				'cancel'     => __( 'Cancel', 'boardkit' ),
				// Phase 4: assignees, due dates, filters.
				'assign'       => __( 'Assign…', 'boardkit' ),
				'setDue'       => __( 'Set due date…', 'boardkit' ),
				'save'         => __( 'Save', 'boardkit' ),
				'clear'        => __( 'Clear', 'boardkit' ),
				'saved'        => __( 'Saved.', 'boardkit' ),
				'searchPeople' => __( 'Search people', 'boardkit' ),
				'noPeople'     => __( 'Nobody found.', 'boardkit' ),
				/* translators: %s: post title */
				'assignLabel'  => __( 'Assign people to “%s”', 'boardkit' ),
				/* translators: %s: post title */
				'dueLabel'     => __( 'Due date for “%s”', 'boardkit' ),
				'dueDate'      => __( 'Due date', 'boardkit' ),
				'filters'      => __( 'Filter cards', 'boardkit' ),
				'search'       => __( 'Search', 'boardkit' ),
				'searchCards'  => __( 'Search titles and content', 'boardkit' ),
				'anyone'       => __( 'Anyone', 'boardkit' ),
				'me'           => __( 'Me', 'boardkit' ),
				'overdueOnly'  => __( 'Overdue only', 'boardkit' ),
				'clearFilters' => __( 'Clear filters', 'boardkit' ),
				/* translators: %d: number of cards */
				'filtered'     => __( '%d cards match.', 'boardkit' ),
				// Phase 5: card panel and "+ Add card".
				'panel'        => [
					'close'        => __( 'Close', 'boardkit' ),
					'details'      => __( 'Details', 'boardkit' ),
					'write'        => __( 'Write', 'boardkit' ),
					'title'        => __( 'Title', 'boardkit' ),
					'excerpt'      => __( 'Excerpt', 'boardkit' ),
					'excerptHelp'  => __( 'A short summary. Themes show it in post lists, and many SEO tools use it as the meta description.', 'boardkit' ),
					'featured'     => __( 'Featured image', 'boardkit' ),
					'setImage'     => __( 'Set featured image', 'boardkit' ),
					'replaceImage' => __( 'Replace', 'boardkit' ),
					'removeImage'  => __( 'Remove', 'boardkit' ),
					'useImage'     => __( 'Use as featured image', 'boardkit' ),
					'noImage'      => __( 'No featured image.', 'boardkit' ),
					'noUpload'     => __( 'You can’t choose images on this site.', 'boardkit' ),
					'lane'         => __( 'Lane', 'boardkit' ),
					'fullEditor'   => __( 'Open in full editor', 'boardkit' ),
					'saveDetails'  => __( 'Save details', 'boardkit' ),
					'saved'        => __( 'Saved.', 'boardkit' ),
					'loading'      => __( 'Loading…', 'boardkit' ),
					/* translators: %s: user name */
					'locked'       => __( '%s is editing this post right now. Saving here is off until they’re done.', 'boardkit' ),
					/* translators: %s: post title */
					'editorTitle'  => __( 'Editor for “%s”', 'boardkit' ),
					'unsaved'      => __( 'You have unsaved changes in the editor.', 'boardkit' ),
					'saveClose'    => __( 'Save and continue', 'boardkit' ),
					'discard'      => __( 'Discard changes', 'boardkit' ),
					'keepEditing'  => __( 'Keep editing', 'boardkit' ),
					'saveFirst'    => __( 'Save in the editor first, or discard the changes.', 'boardkit' ),
					'conflict'     => __( 'Someone else changed this post. The panel now shows the latest version; your changes weren’t saved.', 'boardkit' ),
					'left'         => __( 'The editor left this post, so the panel was closed.', 'boardkit' ),
					'noChanges'    => __( 'Nothing to save.', 'boardkit' ),
					'unsavedDetails' => __( 'You have unsaved changes to the details.', 'boardkit' ),
				],
				'addCard'      => __( '+ Add card', 'boardkit' ),
				'add'          => __( 'Add', 'boardkit' ),
				'cardTitle'    => __( 'Card title', 'boardkit' ),
				/* translators: %s: lane name */
				'addTo'        => __( 'Add a card to %s', 'boardkit' ),
				/* translators: 1: post title, 2: lane name */
				'added'        => __( 'Added “%1$s” to %2$s', 'boardkit' ),
				'openEditor'   => __( 'Open in editor', 'boardkit' ),
			],
		];
		wp_add_inline_script( self::HANDLE, 'window.BoardKitConfig = ' . wp_json_encode( $config, self::JSON_FLAGS ) . ';', 'before' );
	}
}
