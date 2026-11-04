<?php
declare( strict_types=1 );

namespace BoardKit\Admin;

use BoardKit\Boards\BoardRepository;
use BoardKit\Query\Visibility;

/** Top-level "Boards" menu with one submenu per board the user can view (spec §5.1). */
final class Menu {
	public const SLUG = 'boardkit';

	private string $hook_suffix = '';

	public function __construct(
		private BoardRepository $boards,
		private BoardPage $page
	) {}

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register' ] );
	}

	public function register(): void {
		$visible = array_values( array_filter( $this->boards->all(), [ Visibility::class, 'can_view_board' ] ) );
		if ( $visible === [] ) {
			return; // No board this user can open: no menu at all.
		}

		// The menu itself needs only `read`; the page re-checks the board's own capability.
		$this->hook_suffix = (string) add_menu_page(
			__( 'Boards', 'boardkit' ),
			__( 'Boards', 'boardkit' ),
			'read',
			self::SLUG,
			[ $this->page, 'render' ],
			'dashicons-columns',
			26
		);

		foreach ( $visible as $i => $board ) {
			$slug = $i === 0 ? self::SLUG : 'admin.php?page=' . self::SLUG . '&board=' . $board->id();
			add_submenu_page( self::SLUG, $board->label(), $board->label(), 'read', $slug, $i === 0 ? [ $this->page, 'render' ] : '' );
		}
	}

	public function hook_suffix(): string {
		return $this->hook_suffix;
	}

	public static function board_url( string $board_id ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&board=' . rawurlencode( $board_id ) );
	}
}
