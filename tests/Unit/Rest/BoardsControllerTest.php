<?php
declare( strict_types=1 );

namespace BoardKit\Tests\Unit\Rest;

use BoardKit\Boards\Board;
use BoardKit\Boards\BoardRepository;
use BoardKit\Lanes\LaneSourceRegistry;
use BoardKit\Lanes\StageSource;
use BoardKit\Query\CardPresenter;
use BoardKit\Query\CardQuery;
use BoardKit\Query\Visibility;
use BoardKit\Rest\BoardsController;
use BoardKit\Tests\Unit\WpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class BoardsControllerTest extends TestCase {
	private BoardsController $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WpStubs::options();
		WpStubs::sanitizers();
		WpStubs::post_type();
		BoardRepository::reset_cache();
		Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );

		$repo = new BoardRepository();
		$repo->save( [ 'id' => 'ed', 'lanes' => [ [ 'value' => 'pitch', 'label' => 'Pitch' ] ] ] );
		$sources = new LaneSourceRegistry();
		$sources->register( new StageSource() );
		$this->controller = new BoardsController( $repo, new CardQuery( $sources, new Visibility() ), new CardPresenter( $sources ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function request( array $params ): \WP_REST_Request {
		$r = new \WP_REST_Request();
		foreach ( $params as $k => $v ) {
			$r[ $k ] = $v;
		}
		return $r;
	}

	public function test_unknown_board_is_404(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$r = $this->controller->can_view( self::request( [ 'id' => 'nope' ] ) );
		$this->assertInstanceOf( \WP_Error::class, $r );
		$this->assertSame( 404, $r->get_error_data()['status'] );
	}

	public function test_user_without_edit_posts_for_the_type_is_forbidden(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$r = $this->controller->can_view( self::request( [ 'id' => 'ed' ] ) );
		$this->assertSame( 403, $r->get_error_data()['status'] );
	}

	public function test_contributor_level_user_can_view(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => $cap === 'edit_posts' );
		$this->assertTrue( $this->controller->can_view( self::request( [ 'id' => 'ed' ] ) ) );
	}

	public function test_unknown_lane_is_404_before_any_query(): void {
		// No WP_Query stub: reaching a query would fatal, so this also proves the early return.
		$r = $this->controller->get_cards( self::request( [ 'id' => 'ed', 'lane' => 'not-a-lane', 'offset' => 0 ] ) );
		$this->assertInstanceOf( \WP_Error::class, $r );
		$this->assertSame( 404, $r->get_error_data()['status'] );
	}

	public function test_payload_appends_read_only_unsorted_lane(): void {
		$board   = ( new BoardRepository() )->get( 'ed' );
		$payload = BoardsController::board_payload( $board, [ 'pitch' => 4, Board::UNSORTED => 2 ] );
		$this->assertSame( [ 'pitch', Board::UNSORTED ], array_column( $payload['lanes'], 'value' ) );
		$this->assertSame( [ 4, 2 ], array_column( $payload['lanes'], 'count' ) );
		$this->assertSame( [ false, true ], array_column( $payload['lanes'], 'readonly' ) );
	}

	public function test_text_strips_markup_and_decodes_entities(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
		$this->assertSame( 'Hi there', CardPresenter::text( '<em>Hi</em> there' ) );
		// Entities decode to the characters the author typed; board.js shows them via textContent.
		$this->assertSame( "It\u{2019}s <b>", CardPresenter::text( 'It&#8217;s &lt;b&gt;' ) );
	}
}
