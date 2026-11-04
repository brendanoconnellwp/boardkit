<?php
declare( strict_types=1 );

namespace BoardKit\Rest;

use BoardKit\Boards\Board;
use BoardKit\Boards\BoardRepository;
use BoardKit\Editorial\Assignees;
use BoardKit\Editorial\DueDate;
use BoardKit\Editorial\Meta;
use BoardKit\Moves\MoveService;
use BoardKit\Query\CardPresenter;
use BoardKit\Query\Visibility;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Write routes for cards (spec §7.1):
 *   POST boardkit/v1/boards/{id}/cards/{post_id}/move       { to, from, modified, date }
 *   POST boardkit/v1/boards/{id}/cards/{post_id}/assignees  { user_ids[], previous[] }
 *   POST boardkit/v1/boards/{id}/cards/{post_id}/due        { date ('' clears), previous }
 *   GET  boardkit/v1/boards/{id}/people?search=             users who can edit this post type
 *
 * Assignee and due routes sit under the board (spec had /cards/{id}/…) so the response can be a
 * card for that board and the permission gate is the same as for moves. `previous` is what the
 * client last saw: if the stored value differs, 409 with the current card (same reasoning as
 * moves — meta writes don't bump post_modified).
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
				// Site-local 'Y-m-d H:i' picked on the board when the target lane schedules the post.
				'date'     => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
		$this->register_field_routes();
	}

	private function register_field_routes(): void {
		$card = '/boards/(?P<id>[a-z0-9_\-]{1,40})/cards/(?P<post_id>\d+)';
		$ids  = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'default' => [] ];
		register_rest_route( BoardsController::NAMESPACE, $card . '/assignees', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'set_assignees' ],
			'permission_callback' => [ $this, 'can_view' ],
			'args'                => [ 'user_ids' => $ids, 'previous' => $ids ],
		] );
		register_rest_route( BoardsController::NAMESPACE, $card . '/due', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'set_due' ],
			'permission_callback' => [ $this, 'can_view' ],
			'args'                => [
				'date'     => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'previous' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
		register_rest_route( BoardsController::NAMESPACE, '/boards/(?P<id>[a-z0-9_\-]{1,40})/people', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'people' ],
			'permission_callback' => [ $this, 'can_view' ],
			'args'                => [ 'search' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ] ],
		] );
	}

	/** Shared gate for field writes: post on this board, current user can edit it. */
	private function editable_post( Board $board, int $post_id ): \WP_Post|WP_Error {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || $post->post_type !== $board->post_type() || $post->post_status === 'trash' ) {
			return MoveService::error( 'bk_no_post', __( 'That post is not on this board.', 'boardkit' ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return MoveService::error( 'bk_forbidden', __( 'You cannot edit this post.', 'boardkit' ), 403 );
		}
		return $post;
	}

	private function conflict( \WP_Post $post, Board $board ): WP_Error {
		return new WP_Error( 'bk_conflict', __( 'Updated by someone else — refreshed.', 'boardkit' ), [ 'status' => 409, 'card' => $this->presenter->card( $post, $board ) ] );
	}

	public function set_assignees( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$board = $this->boards->get( (string) $request['id'] );
		$post  = $board ? $this->editable_post( $board, (int) $request['post_id'] ) : MoveService::error( 'bk_no_board', __( 'Board not found.', 'boardkit' ), 404 );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$previous = array_map( 'intval', (array) $request['previous'] );
		$current  = Meta::assignees( $post->ID );
		sort( $previous );
		sort( $current );
		if ( $previous !== $current ) {
			return $this->conflict( $post, $board );
		}
		$result = Assignees::set( $post, (array) $request['user_ids'] );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return new WP_REST_Response( [ 'card' => $this->presenter->card( $post, $board ) ] );
	}

	public function set_due( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$board = $this->boards->get( (string) $request['id'] );
		$post  = $board ? $this->editable_post( $board, (int) $request['post_id'] ) : MoveService::error( 'bk_no_board', __( 'Board not found.', 'boardkit' ), 404 );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		if ( (string) $request['previous'] !== Meta::due( $post->ID ) ) {
			return $this->conflict( $post, $board );
		}
		$result = DueDate::set( $post, (string) $request['date'] );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return new WP_REST_Response( [ 'card' => $this->presenter->card( $post, $board ) ] );
	}

	/**
	 * Candidates for the assignee picker: users with the post type's edit_posts. The real check
	 * (can edit THIS post) runs when assigning. Only id, name and avatar are returned.
	 */
	public function people( WP_REST_Request $request ): WP_REST_Response {
		$board  = $this->boards->get( (string) $request['id'] );
		$cap    = $board ? Visibility::cap( $board->post_type(), 'edit_posts' ) : '';
		$search = trim( (string) $request['search'] );
		$args   = [ 'number' => 20, 'orderby' => 'display_name', 'fields' => [ 'ID' ], 'capability' => $cap !== '' ? $cap : 'edit_posts' ];
		if ( $search !== '' ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = [ 'display_name', 'user_login', 'user_nicename' ];
		}
		$out = [];
		foreach ( get_users( $args ) as $row ) {
			$person = CardPresenter::person( (int) $row->ID );
			if ( $person ) {
				$out[] = $person;
			}
		}
		return new WP_REST_Response( [ 'people' => $out ] );
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
			(string) $request['modified'],
			[ 'date' => (string) $request['date'] ]
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
