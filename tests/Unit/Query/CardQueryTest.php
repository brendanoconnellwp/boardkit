<?php
declare( strict_types=1 );

namespace BoardKit\Tests\Unit\Query;

use BoardKit\Boards\Board;
use BoardKit\Boards\BoardRepository;
use BoardKit\Editorial\StageTaxonomy;
use BoardKit\Lanes\LaneSourceRegistry;
use BoardKit\Lanes\StageSource;
use BoardKit\Lanes\StatusSource;
use BoardKit\Query\CardQuery;
use BoardKit\Query\Visibility;
use BoardKit\Tests\Unit\WpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CardQueryTest extends TestCase {
	private CardQuery $query;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WpStubs::sanitizers();
		WpStubs::statuses();
		$sources = new LaneSourceRegistry();
		$sources->register( new StageSource() );
		$sources->register( new StatusSource() );
		$this->query = new CardQuery( $sources, new Visibility() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function board( array $over = [] ): Board {
		return Board::from_normalised( BoardRepository::normalise( $over + [
			'post_types' => [ 'post' ],
			'lanes'      => [ [ 'value' => 'pitch' ], [ 'value' => 'draft' ] ],
		], 'b' ) );
	}

	public function test_stage_lane_queries_one_term_by_slug(): void {
		$args = $this->query->build_args( self::board(), 'pitch', 0, null );
		$this->assertSame( StageTaxonomy::TAXONOMY, $args['tax_query'][0]['taxonomy'] );
		$this->assertSame( [ 'pitch' ], $args['tax_query'][0]['terms'] );
		$this->assertSame( 'slug', $args['tax_query'][0]['field'] );
		$this->assertSame( 'post', $args['post_type'] );
		$this->assertSame( 50, $args['posts_per_page'] );
		$this->assertTrue( $args['no_found_rows'] );
		$this->assertTrue( $args['update_post_meta_cache'] && $args['update_post_term_cache'] );
	}

	public function test_stage_unsorted_is_not_in_all_lanes(): void {
		$args = $this->query->build_args( self::board(), Board::UNSORTED, 0, null );
		$this->assertSame( 'NOT IN', $args['tax_query'][0]['operator'] );
		$this->assertSame( [ 'pitch', 'draft' ], $args['tax_query'][0]['terms'] );
	}

	public function test_board_with_no_lanes_puts_everything_in_unsorted(): void {
		$args = $this->query->build_args( self::board( [ 'lanes' => [] ] ), Board::UNSORTED, 0, null );
		$this->assertArrayNotHasKey( 'tax_query', $args );
	}

	public function test_default_statuses_never_include_trash_or_auto_draft(): void {
		$args = $this->query->build_args( self::board(), 'pitch', 0, null );
		$this->assertSame( [ 'publish', 'future', 'draft', 'pending', 'private' ], $args['post_status'] );
	}

	public function test_status_lane_narrows_post_status(): void {
		$board = self::board( [ 'lane_source' => [ 'kind' => 'status' ], 'lanes' => [ [ 'value' => 'draft' ], [ 'value' => 'pending' ] ] ] );
		$this->assertSame( [ 'draft' ], $this->query->build_args( $board, 'draft', 0, null )['post_status'] );
		$this->assertSame( [ 'publish', 'future', 'private' ], $this->query->build_args( $board, Board::UNSORTED, 0, null )['post_status'] );
	}

	public function test_status_board_covering_every_status_has_an_empty_unsorted_lane(): void {
		$board = self::board( [ 'lane_source' => [ 'kind' => 'status' ], 'lanes' => array_map( static fn( $s ) => [ 'value' => $s ], [ 'publish', 'future', 'draft', 'pending', 'private' ] ) ] );
		$this->assertSame( [ 0 ], $this->query->build_args( $board, Board::UNSORTED, 0, null )['post__in'] );
	}

	public function test_sort_and_stable_tiebreak(): void {
		$args = $this->query->build_args( self::board( [ 'sort' => [ 'by' => 'title', 'dir' => 'asc' ] ] ), 'pitch', 0, null );
		$this->assertSame( [ 'title' => 'ASC', 'ID' => 'ASC' ], $args['orderby'] );
		$args = $this->query->build_args( self::board(), 'pitch', 100, null );
		$this->assertSame( [ 'modified' => 'DESC', 'ID' => 'DESC' ], $args['orderby'] );
		$this->assertSame( 100, $args['offset'] );
	}

	public function test_negative_offset_clamps(): void {
		$this->assertSame( 0, $this->query->build_args( self::board(), 'pitch', -5, null )['offset'] );
	}

	// ---- visibility ------------------------------------------------------------

	public function test_unrestricted_user_gets_no_post_in(): void {
		$this->assertArrayNotHasKey( 'post__in', $this->query->build_args( self::board(), 'pitch', 0, null ) );
	}

	public function test_restricted_user_is_limited_to_visible_ids(): void {
		$this->assertSame( [ 3, 7 ], $this->query->build_args( self::board(), 'pitch', 0, [ 3, 7 ] )['post__in'] );
	}

	public function test_restricted_user_who_sees_nothing_gets_post_in_zero_not_empty(): void {
		// post__in => [] means "no restriction" to WP_Query — the classic leak.
		$this->assertSame( [ 0 ], $this->query->build_args( self::board(), 'pitch', 0, [] )['post__in'] );
	}

	public function test_visibility_intersects_with_a_lane_post_in(): void {
		$board = self::board( [ 'lane_source' => [ 'kind' => 'status' ], 'lanes' => array_map( static fn( $s ) => [ 'value' => $s ], [ 'publish', 'future', 'draft', 'pending', 'private' ] ) ] );
		$this->assertSame( [ 0 ], $this->query->build_args( $board, Board::UNSORTED, 0, [ 3, 7 ] )['post__in'] );
	}

	public function test_can_view_board_uses_the_mapped_capability(): void {
		WpStubs::post_type( 'article' );
		$granted = [];
		Functions\when( 'current_user_can' )->alias( static function ( $cap ) use ( &$granted ) {
			$granted[] = $cap;
			return $cap === 'edit_articles';
		} );
		$this->assertTrue( Visibility::can_view_board( self::board() ) );
		$this->assertSame( [ 'edit_articles' ], $granted, 'never the literal edit_posts' );
	}

	public function test_editor_is_unrestricted_without_running_queries(): void {
		WpStubs::post_type();
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => $cap === 'edit_others_posts' );
		$this->assertNull( ( new Visibility() )->visible_ids( self::board() ) );
	}

	public function test_logged_out_user_sees_nothing(): void {
		WpStubs::post_type();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->assertSame( [], ( new Visibility() )->visible_ids( self::board() ) );
	}
}
