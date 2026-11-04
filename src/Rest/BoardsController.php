<?php
declare( strict_types=1 );

namespace BoardKit\Rest;

use BoardKit\Boards\Board;
use BoardKit\Boards\BoardRepository;
use BoardKit\Query\CardPresenter;
use BoardKit\Query\CardQuery;
use BoardKit\Query\Visibility;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Read routes (spec §7.1):
 *   GET boardkit/v1/boards/{id}                     board config + lane counts
 *   GET boardkit/v1/boards/{id}/lanes/{lane}/cards  one page of cards (?offset=)
 *
 * Every route has a real permission_callback. The lane param is checked against the board's
 * own lanes — never passed through to a query as given.
 */
final class BoardsController {
	public const NAMESPACE = 'boardkit/v1';

	public function __construct(
		private BoardRepository $boards,
		private CardQuery $cards,
		private CardPresenter $presenter
	) {}

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$id = '(?P<id>[a-z0-9_\-]{1,40})';
		register_rest_route( self::NAMESPACE, "/boards/{$id}", [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_board' ],
			'permission_callback' => [ $this, 'can_view' ],
		] );
		register_rest_route( self::NAMESPACE, "/boards/{$id}/lanes/(?P<lane>[a-z0-9_\-]{1,60})/cards", [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_cards' ],
			'permission_callback' => [ $this, 'can_view' ],
			'args'                => [
				'offset' => [
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	/** @return bool|WP_Error */
	public function can_view( WP_REST_Request $request ) {
		$board = $this->boards->get( (string) $request['id'] );
		if ( $board === null ) {
			return new WP_Error( 'bk_no_board', __( 'Board not found.', 'boardkit' ), [ 'status' => 404 ] );
		}
		if ( ! Visibility::can_view_board( $board ) ) {
			return new WP_Error( 'bk_forbidden', __( 'You cannot view this board.', 'boardkit' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return true;
	}

	public function get_board( WP_REST_Request $request ): WP_REST_Response {
		$board  = $this->board( $request );
		$counts = $this->cards->counts( $board );
		return new WP_REST_Response( self::board_payload( $board, $counts ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function get_cards( WP_REST_Request $request ) {
		$board = $this->board( $request );
		$lane  = (string) $request['lane'];
		if ( $lane !== Board::UNSORTED && ! $board->has_lane( $lane ) ) {
			return new WP_Error( 'bk_no_lane', __( 'Lane not found.', 'boardkit' ), [ 'status' => 404 ] );
		}
		$page = $this->cards->page( $board, $lane, (int) $request['offset'] );
		return new WP_REST_Response( [
			'lane'        => $lane,
			'cards'       => $this->presenter->cards( $page['posts'], $board ),
			'next_offset' => $page['next_offset'],
		] );
	}

	/**
	 * @param array<string, int> $counts
	 * @return array<string, mixed>
	 */
	public static function board_payload( Board $board, array $counts ): array {
		$lanes = [];
		foreach ( $board->lanes() as $lane ) {
			$lanes[] = $lane + [ 'count' => $counts[ $lane['value'] ] ?? 0, 'readonly' => false ];
		}
		$lanes[] = [
			'value'    => Board::UNSORTED,
			'label'    => __( 'Unsorted', 'boardkit' ),
			'color'    => '',
			'wip'      => 0,
			'count'    => $counts[ Board::UNSORTED ] ?? 0,
			'readonly' => true, // Cards can leave Unsorted but never be dropped into it (§4.3).
		];
		return [
			'id'        => $board->id(),
			'label'     => $board->label(),
			'post_type' => $board->post_type(),
			'lane_kind' => $board->lane_kind(),
			'sort'      => $board->sort(),
			'lanes'     => $lanes,
			'per_page'  => CardQuery::PER_PAGE,
		];
	}

	/** Only called after can_view() has confirmed the board exists. */
	private function board( WP_REST_Request $request ): Board {
		$board = $this->boards->get( (string) $request['id'] );
		if ( $board === null ) {
			throw new \LogicException( 'permission_callback must run first' );
		}
		return $board;
	}
}
