<?php
declare( strict_types=1 );

namespace BoardKit\Rest;

use BoardKit\Boards\Board;
use BoardKit\Boards\BoardRepository;
use BoardKit\Moves\MoveService;
use BoardKit\Query\CardPresenter;
use BoardKit\Query\Visibility;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Write routes for cards (spec §7.1). Phase 3:
 *   POST boardkit/v1/boards/{id}/cards/{post_id}/move  { to, from, modified }
 *
 * permission_callback is the cheap gate (board exists, user can view it). The real rules run in
 * MoveService on every request (§7.2) — never trust that the UI only offered allowed lanes.
 * The REST nonce comes from wp.apiFetch; REST cookie auth rejects requests without it.
 */
final class CardsController {
	public function __construct(
		private BoardRepository $boards,
		private MoveService $moves,
		private CardPresenter $presenter
	) {}

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( BoardsController::NAMESPACE, '/boards/(?P<id>[a-z0-9_\-]{1,40})/cards/(?P<post_id>\d+)/move', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'move' ],
			'permission_callback' => [ $this, 'can_view' ],
			'args'                => [
				'to'       => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => [ BoardRepository::class, 'sanitize_lane_value' ] ],
				'from'     => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => [ BoardRepository::class, 'sanitize_lane_value' ] ],
				'modified' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
	}

	public function can_view( WP_REST_Request $request ): bool|WP_Error {
		$board = $this->boards->get( (string) $request['id'] );
		if ( $board === null ) {
			return new WP_Error( 'bk_no_board', __( 'Board not found.', 'boardkit' ), [ 'status' => 404 ] );
		}
		if ( ! Visibility::can_view_board( $board ) ) {
			return new WP_Error( 'bk_forbidden', __( 'You cannot view this board.', 'boardkit' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return true;
	}

	public function move( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$board = $this->boards->get( (string) $request['id'] );
		if ( $board === null ) {
			return new WP_Error( 'bk_no_board', __( 'Board not found.', 'boardkit' ), [ 'status' => 404 ] );
		}
		$result = $this->moves->move(
			$board,
			(int) $request['post_id'],
			(string) $request['to'],
			(string) $request['from'],
			(string) $request['modified']
		);

		if ( $result instanceof WP_Error ) {
			return self::with_card( $result, $board );
		}
		return new WP_REST_Response( [ 'card' => $this->presenter->card( $result, $board ) ] );
	}

	/**
	 * A 409 carries the post as it is now; swap it for a card so the client can redraw it in
	 * its real lane. A WP_Post must never be serialised into a response as-is.
	 */
	private function with_card( WP_Error $error, Board $board ): WP_Error {
		$data = $error->get_error_data();
		if ( is_array( $data ) && ( $data['post'] ?? null ) instanceof \WP_Post ) {
			$post = $data['post'];
			unset( $data['post'] );
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$data['card'] = $this->presenter->card( $post, $board );
			}
			return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
		}
		return $error;
	}
}
