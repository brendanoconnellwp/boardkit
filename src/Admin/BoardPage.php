<?php
declare( strict_types=1 );

namespace BoardKit\Admin;

use BoardKit\Boards\Board;
use BoardKit\Boards\BoardRepository;
use BoardKit\Query\Visibility;

/**
 * The board screen shell. Lanes and cards are fetched by board.js over REST, so the server
 * renders only the frame; nothing user-written is printed here except escaped labels.
 */
final class BoardPage {
	public function __construct( private BoardRepository $boards ) {}

	/** The board this request is for: ?board=, else the first board the user can view. */
	public function current_board(): ?Board {
		$requested = isset( $_GET['board'] ) && is_string( $_GET['board'] ) ? BoardRepository::sanitize_id( wp_unslash( $_GET['board'] ) ) : '';
		if ( $requested !== '' ) {
			$board = $this->boards->get( $requested );
			return $board !== null && Visibility::can_view_board( $board ) ? $board : null;
		}
		foreach ( $this->boards->all() as $board ) {
			if ( Visibility::can_view_board( $board ) ) {
				return $board;
			}
		}
		return null;
	}

	public function render(): void {
		$board = $this->current_board();
		if ( $board === null ) {
			wp_die( esc_html__( 'Board not found, or you cannot view it.', 'boardkit' ), 403 );
		}
		$type     = get_post_type_object( $board->post_type() );
		$list_url = admin_url( 'edit.php' . ( $board->post_type() === 'post' ? '' : '?post_type=' . $board->post_type() ) );
		?>
		<div class="wrap bk-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $board->label() ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( $list_url ); ?>">
				<?php
				/* translators: %s: post type plural name, e.g. "Posts" */
				printf( esc_html__( 'Back to %s', 'boardkit' ), esc_html( $type ? $type->labels->name : $board->post_type() ) );
				?>
			</a>
			<hr class="wp-header-end">
			<div id="bk-notice" class="bk-notice" role="status" aria-live="polite"></div>
			<div id="bk-board" class="bk-board" data-board="<?php echo esc_attr( $board->id() ); ?>" aria-busy="true">
				<p class="bk-loading"><?php esc_html_e( 'Loading board…', 'boardkit' ); ?></p>
			</div>
			<noscript><p><?php esc_html_e( 'The board needs JavaScript.', 'boardkit' ); ?></p></noscript>
		</div>
		<?php
	}
}
