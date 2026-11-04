<?php
declare( strict_types=1 );

namespace BoardKit\Tests\Unit\Moves;

use BoardKit\Boards\Board;
use BoardKit\Boards\BoardRepository;
use BoardKit\Editorial\Meta;
use BoardKit\Editorial\StageTaxonomy;
use BoardKit\Lanes\LaneSourceRegistry;
use BoardKit\Lanes\StageSource;
use BoardKit\Lanes\StatusSource;
use BoardKit\Moves\MoveService;
use BoardKit\Tests\Unit\WpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Every rule in spec §7.2, against a CPT whose capability_type is 'article', so any literal
 * 'publish_posts' / 'edit_posts' in the code would fail these tests.
 */
final class MoveServiceTest extends TestCase {
	private const ID = 42;

	/** @var array<string, bool> */
	private array $caps = [];
	private \WP_Post $post;
	/** @var array<int, string> current stage slugs of the post */
	private array $terms = [];
	/** @var array<int, array<string, mixed>> */
	private array $updates = [];
	/** @var array<string, mixed> */
	private array $meta = [];
	private MoveService $moves;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WpStubs::sanitizers();
		WpStubs::post_type( 'article' );

		$this->caps    = [ 'edit_post' => true, 'edit_articles' => true ];
		$this->terms   = [ 'pitch' ];
		$this->updates = [];
		$this->meta    = [];

		$this->post                    = new \WP_Post();
		$this->post->ID                = self::ID;
		$this->post->post_type         = 'article';
		$this->post->post_status       = 'draft';
		$this->post->post_date         = '2030-01-01 09:00:00';
		$this->post->post_date_gmt     = '2030-01-01 09:00:00';
		$this->post->post_modified_gmt = '2026-11-04 10:00:00';

		Functions\when( 'get_post' )->alias( fn( $id ) => (int) $id === self::ID ? $this->post : null );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => $this->caps[ $cap ] ?? false );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2026-11-04 12:00:00' );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
			$this->meta[ $key ] = $value;
			return true;
		} );
		Functions\when( 'get_the_terms' )->alias( fn() => array_map( static function ( $slug ) {
			$t       = new \WP_Term();
			$t->slug = $slug;
			return $t;
		}, $this->terms ) );
		Functions\when( 'get_taxonomy' )->justReturn( (object) [ 'cap' => (object) [ 'assign_terms' => 'assign_stages' ] ] );
		Functions\when( 'get_term_by' )->alias( static function ( $field, $slug ) {
			$t          = new \WP_Term();
			$t->term_id = crc32( $slug ) % 1000;
			$t->slug    = $slug;
			return $t;
		} );
		Functions\when( 'wp_set_object_terms' )->alias( function ( $id, $ids, $tax, $append ) {
			$this->assertSame( StageTaxonomy::TAXONOMY, $tax );
			$this->assertFalse( $append, 'stage writes must replace, never append' );
			$this->assertIsInt( $ids[0], 'by term ID, never by slug (a slug would create a term)' );
			$this->terms = [ 'moved' ];
			return $ids;
		} );
		Functions\when( 'wp_update_post' )->alias( function ( $fields ) {
			$this->updates[]         = $fields;
			$this->post->post_status = $fields['post_status'];
			return self::ID;
		} );

		$sources = new LaneSourceRegistry();
		$sources->register( new StageSource() );
		$sources->register( new StatusSource() );
		$this->moves = new MoveService( $sources );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function stage_board(): Board {
		return Board::from_normalised( BoardRepository::normalise( [
			'post_types' => [ 'article' ],
			'lanes'      => [ [ 'value' => 'pitch' ], [ 'value' => 'review' ] ],
		], 'b' ) );
	}

	private static function status_board(): Board {
		return Board::from_normalised( BoardRepository::normalise( [
			'post_types'  => [ 'article' ],
			'lane_source' => [ 'kind' => 'status' ],
			'lanes'       => array_map( static fn( $s ) => [ 'value' => $s ], [ 'draft', 'pending', 'future', 'publish', 'private' ] ),
		], 'b' ) );
	}

	private static function http( $r ): int {
		return $r instanceof \WP_Error ? (int) $r->get_error_data()['status'] : 0;
	}

	private function stage_move( string $to = 'review', string $from = 'pitch' ) {
		return $this->moves->move( self::stage_board(), self::ID, $to, $from, '2026-11-04 10:00:00' );
	}

	private function status_move( string $to, string $from = 'draft' ) {
		$this->post->post_status = $from;
		return $this->moves->move( self::status_board(), self::ID, $to, $from, '2026-11-04 10:00:00' );
	}

	// ---- rules 1–3 -----------------------------------------------------------------

	public function test_rule1_unknown_post_wrong_type_or_trashed_is_404(): void {
		$this->assertSame( 404, self::http( $this->moves->move( self::stage_board(), 999, 'review', 'pitch', '' ) ) );
		$this->post->post_type = 'page';
		$this->assertSame( 404, self::http( $this->stage_move() ) );
		$this->post->post_type   = 'article';
		$this->post->post_status = 'trash';
		$this->assertSame( 404, self::http( $this->stage_move() ) );
	}

	public function test_rule2_without_edit_post_is_403(): void {
		$this->caps = [];
		$this->assertSame( 403, self::http( $this->stage_move() ) );
		$this->assertSame( [ 'pitch' ], $this->terms );
	}

	public function test_rule3_target_must_be_a_board_lane(): void {
		$this->caps['assign_stages'] = true;
		$this->assertSame( 400, self::http( $this->stage_move( 'not-a-lane' ) ) );
		$this->assertSame( 400, self::http( $this->stage_move( Board::UNSORTED ) ), 'never INTO Unsorted' );
		// A real status that just isn't a lane on this stage board.
		$this->assertSame( 400, self::http( $this->stage_move( 'publish' ) ) );
	}

	// ---- conflicts --------------------------------------------------------------------

	public function test_lane_changed_since_client_loaded_is_409_with_post(): void {
		$this->caps['assign_stages'] = true;
		$this->terms = [ 'review' ]; // someone else already moved it
		$r = $this->stage_move( 'review', 'pitch' );
		$this->assertSame( 409, self::http( $r ) );
		$this->assertSame( $this->post, $r->get_error_data()['post'] );
	}

	public function test_stage_move_ignores_modified_but_status_move_checks_it(): void {
		$this->caps['assign_stages'] = true;
		$this->assertInstanceOf( \WP_Post::class, $this->moves->move( self::stage_board(), self::ID, 'review', 'pitch', '1999-01-01 00:00:00' ) );

		$this->caps['edit_articles'] = true;
		$r = $this->moves->move( self::status_board(), self::ID, 'pending', 'draft', '1999-01-01 00:00:00' );
		$this->assertSame( 409, self::http( $r ), 'status moves write the post row: an edit since load is a conflict' );
	}

	public function test_from_unsorted(): void {
		$this->caps['assign_stages'] = true;
		$this->terms = [];
		$this->assertSame( 409, self::http( $this->stage_move( 'review', 'pitch' ) ) );
		$this->assertInstanceOf( \WP_Post::class, $this->stage_move( 'review', Board::UNSORTED ) );
	}

	public function test_moving_to_current_lane_is_a_noop_without_log(): void {
		$this->caps['assign_stages'] = true;
		$this->assertSame( $this->post, $this->stage_move( 'pitch', 'pitch' ) );
		$this->assertArrayNotHasKey( Meta::LOG, $this->meta );
	}

	// ---- stage (rule 4) ------------------------------------------------------------------

	public function test_stage_needs_the_taxonomys_assign_terms_cap(): void {
		$this->assertSame( 403, self::http( $this->stage_move() ) );
		$this->caps['assign_stages'] = true;
		$this->assertInstanceOf( \WP_Post::class, $this->stage_move() );
		$this->assertSame( [ 'moved' ], $this->terms );
	}

	public function test_successful_move_is_logged(): void {
		$this->caps['assign_stages'] = true;
		$this->stage_move();
		$log = $this->meta[ Meta::LOG ];
		$this->assertSame( [ 'user' => 7, 'field' => 'stage', 'from' => 'pitch', 'to' => 'review' ], array_diff_key( $log[0], [ 't' => 1 ] ) );
	}

	public function test_log_is_capped_at_50_newest_first(): void {
		$this->caps['assign_stages'] = true;
		$this->meta[ Meta::LOG ] = array_fill( 0, 50, [ 't' => 1, 'user' => 1, 'field' => 'stage', 'from' => 'a', 'to' => 'b' ] );
		$this->stage_move();
		$this->assertCount( 50, $this->meta[ Meta::LOG ] );
		$this->assertSame( 'review', $this->meta[ Meta::LOG ][0]['to'] );
	}

	public function test_is_moving_is_true_only_during_the_write(): void {
		$this->caps['assign_stages'] = true;
		$seen = null;
		Functions\when( 'wp_set_object_terms' )->alias( static function ( $id, $ids ) use ( &$seen ) {
			$seen = MoveService::is_moving();
			return $ids;
		} );
		$this->stage_move();
		$this->assertTrue( $seen );
		$this->assertFalse( MoveService::is_moving() );
	}

	// ---- status (rule 4) -----------------------------------------------------------------

	public function test_contributor_cannot_publish_schedule_or_make_private_by_dragging(): void {
		foreach ( [ 'publish', 'future', 'private' ] as $to ) {
			$r = $this->status_move( $to );
			$this->assertSame( 403, self::http( $r ), $to );
		}
		$this->assertSame( [], $this->updates, 'nothing written' );
	}

	public function test_contributor_can_submit_for_review(): void {
		$this->assertInstanceOf( \WP_Post::class, $this->status_move( 'pending' ) );
		$this->assertSame( 'pending', $this->updates[0]['post_status'] );
	}

	public function test_publish_with_mapped_publish_cap(): void {
		$this->caps['publish_articles'] = true;
		$this->assertInstanceOf( \WP_Post::class, $this->status_move( 'publish' ) );
	}

	public function test_moving_out_of_publish_needs_edit_published(): void {
		$this->caps['publish_articles'] = true;
		$this->assertSame( 403, self::http( $this->status_move( 'draft', 'publish' ) ) );
		$this->caps['edit_published_articles'] = true;
		$this->assertInstanceOf( \WP_Post::class, $this->status_move( 'draft', 'publish' ) );
	}

	public function test_future_needs_a_future_date(): void {
		$this->caps['publish_articles'] = true;
		$this->post->post_date_gmt = '2001-01-01 00:00:00';
		$r = $this->status_move( 'future' );
		$this->assertSame( 400, self::http( $r ) );
		$this->assertSame( 'bk_needs_date', $r->get_error_code() );

		$this->post->post_date_gmt = '0000-00-00 00:00:00';
		$this->assertSame( 400, self::http( $this->status_move( 'future' ) ), 'floating draft date' );

		$this->post->post_date_gmt = '2030-01-01 09:00:00';
		$this->assertInstanceOf( \WP_Post::class, $this->status_move( 'future' ) );
		$this->assertSame( '2030-01-01 09:00:00', end( $this->updates )['post_date_gmt'], 'date kept so core does not flip it to publish' );
	}

	public function test_publishing_a_scheduled_post_goes_live_now(): void {
		$this->caps['publish_articles'] = true;
		$this->status_move( 'publish', 'future' );
		$this->assertSame( '2026-11-04 12:00:00', $this->updates[0]['post_date'] );
	}
}
