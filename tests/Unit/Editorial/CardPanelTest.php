<?php
declare( strict_types=1 );

namespace BoardKit\Tests\Unit\Editorial;

use BoardKit\Boards\Board;
use BoardKit\Boards\BoardRepository;
use BoardKit\Editorial\CardCreator;
use BoardKit\Editorial\CardDetails;
use BoardKit\Lanes\StageSource;
use BoardKit\Lanes\StatusSource;
use BoardKit\Rest\BoardsController;
use BoardKit\Tests\Unit\WpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** Phase 5: the card panel's Details save and "+ Add card". */
final class CardPanelTest extends TestCase {
	private \WP_Post $post;
	/** @var array<int, array<string, mixed>> args passed to wp_update_post */
	private array $updates = [];
	/** @var array<string, bool> */
	private array $caps = [];
	private int $locker = 0;
	private int $thumb = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WpStubs::sanitizers();
		$this->post                    = new \WP_Post();
		$this->post->ID                = 42;
		$this->post->post_type         = 'post';
		$this->post->post_title        = 'Old title';
		$this->post->post_excerpt      = '';
		$this->post->post_status       = 'draft';
		$this->post->post_modified_gmt = '2026-11-04 10:00:00';
		$this->updates                 = [];
		$this->caps                    = [ 'upload_files' => true, 'edit_posts' => true, 'read_post' => true ];
		$this->locker                  = 0;
		$this->thumb                   = 0;

		Functions\when( 'wp_slash' )->alias( static fn( $v ) => is_array( $v ) ? array_map( static fn( $x ) => is_string( $x ) ? addslashes( $x ) : $x, $v ) : addslashes( (string) $v ) );
		Functions\when( 'wp_update_post' )->alias( function ( $args ) {
			$this->updates[] = $args;
			return $args['ID'];
		} );
		Functions\when( 'get_post' )->alias( fn( $id ) => $id === 42 ? $this->post : $this->attachment( (int) $id ) );
		Functions\when( 'wp_check_post_lock' )->alias( fn() => $this->locker );
		Functions\when( 'get_userdata' )->alias( static function ( $id ) {
			$u               = new \WP_User();
			$u->ID           = (int) $id;
			$u->display_name = 'Sam';
			return $u;
		} );
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\when( 'get_post_thumbnail_id' )->alias( fn() => $this->thumb );
		Functions\when( 'set_post_thumbnail' )->alias( function ( $post, $id ) {
			$this->thumb = (int) $id;
			return true;
		} );
		Functions\when( 'delete_post_thumbnail' )->alias( function () {
			$this->thumb = 0;
			return true;
		} );
		Functions\when( 'wp_attachment_is_image' )->alias( static fn( $id ) => (int) $id === 7 );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => $this->caps[ $cap ] ?? false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function attachment( int $id ): ?\WP_Post {
		if ( ! in_array( $id, [ 7, 8 ], true ) ) {
			return null;
		}
		$a            = new \WP_Post();
		$a->ID        = $id;
		$a->post_type = 'attachment';
		return $a;
	}

	private static function board( string $kind, array $lanes ): Board {
		return Board::from_normalised( BoardRepository::normalise( [
			'lane_source' => [ 'kind' => $kind ],
			'lanes'       => $lanes,
		], 'b' ) );
	}

	// ---- details ------------------------------------------------------------------------------

	public function test_details_write_only_title_and_excerpt_slashed_never_status_or_content(): void {
		$r = CardDetails::save( $this->post, [ 'title' => 'C:\temp "quoted"', 'excerpt' => 'Short' ], '2026-11-04 10:00:00' );
		$this->assertInstanceOf( \WP_Post::class, $r );
		$this->assertCount( 1, $this->updates );
		$this->assertSame( [ 'post_title', 'post_excerpt', 'ID' ], array_keys( $this->updates[0] ), 'no status, date or content is ever passed' );
		$this->assertSame( 'C:\\\\temp \\"quoted\\"', $this->updates[0]['post_title'], 'wp_update_post unslashes, so the value must be slashed' );
	}

	public function test_unchanged_fields_write_nothing(): void {
		CardDetails::save( $this->post, [ 'title' => ' Old title ' ], '2026-11-04 10:00:00' );
		$this->assertSame( [], $this->updates );
	}

	public function test_stale_modified_is_a_conflict_and_nothing_is_written(): void {
		$r = CardDetails::save( $this->post, [ 'title' => 'New' ], '2026-11-04 09:00:00' );
		$this->assertSame( 'bk_conflict', $r->get_error_code() );
		$this->assertSame( 409, $r->get_error_data()['status'] );
		$this->assertSame( [], $this->updates );
	}

	public function test_another_users_editor_lock_refuses_the_save(): void {
		$this->locker = 5;
		$r            = CardDetails::save( $this->post, [ 'title' => 'New' ], '2026-11-04 10:00:00' );
		$this->assertSame( 'bk_locked', $r->get_error_code() );
		$this->assertStringContainsString( 'Sam', $r->get_error_message() );
		$this->assertSame( [], $this->updates );
	}

	public function test_featured_image_must_be_an_image_attachment_and_bad_one_writes_nothing(): void {
		$r = CardDetails::save( $this->post, [ 'title' => 'New', 'featured_media' => 8 ], '2026-11-04 10:00:00' );
		$this->assertSame( 'bk_bad_image', $r->get_error_code(), '8 is an attachment but not an image' );
		$this->assertSame( [], $this->updates, 'checked before the title is written' );
		$this->assertSame( 'bk_bad_image', CardDetails::save( $this->post, [ 'featured_media' => 999 ], '2026-11-04 10:00:00' )->get_error_code() );

		CardDetails::save( $this->post, [ 'featured_media' => 7 ], '2026-11-04 10:00:00' );
		$this->assertSame( 7, $this->thumb );
		CardDetails::save( $this->post, [ 'featured_media' => 0 ], '2026-11-04 10:00:00' );
		$this->assertSame( 0, $this->thumb, '0 removes it' );
	}

	public function test_title_length_is_capped(): void {
		$r = CardDetails::save( $this->post, [ 'title' => str_repeat( 'x', CardDetails::TITLE_MAX + 1 ) ], '2026-11-04 10:00:00' );
		$this->assertSame( 'bk_too_long', $r->get_error_code() );
	}

	// ---- + Add card -----------------------------------------------------------------------------

	public function test_new_cards_only_in_lanes_that_set_no_status_or_draft_or_pending(): void {
		Functions\when( 'get_taxonomy' )->justReturn( (object) [ 'cap' => (object) [ 'assign_terms' => 'edit_posts' ] ] );
		$board  = self::board( 'stage', [
			[ 'value' => 'pitch' ],
			[ 'value' => 'ready', 'status' => 'pending' ],
			[ 'value' => 'scheduled', 'status' => 'future' ],
			[ 'value' => 'published', 'status' => 'publish' ],
		] );
		$source = new StageSource();
		$this->assertSame( 'draft', $source->new_post_status( $board, 'pitch' ) );
		$this->assertSame( 'pending', $source->new_post_status( $board, 'ready' ) );
		$this->assertNull( $source->new_post_status( $board, 'scheduled' ) );
		$this->assertNull( $source->new_post_status( $board, 'published' ) );
		$this->assertNull( $source->new_post_status( $board, 'nope' ) );

		$this->caps['edit_posts'] = false; // no assign_terms → no new cards anywhere
		$this->assertNull( $source->new_post_status( $board, 'pitch' ) );
	}

	public function test_status_boards_create_in_draft_and_pending_only(): void {
		WpStubs::statuses();
		$board = self::board( 'status', [ [ 'value' => 'draft' ], [ 'value' => 'pending' ], [ 'value' => 'publish' ] ] );
		$this->assertSame( 'draft', ( new StatusSource() )->new_post_status( $board, 'draft' ) );
		$this->assertNull( ( new StatusSource() )->new_post_status( $board, 'publish' ) );
	}

	public function test_create_needs_the_post_types_create_posts_cap(): void {
		Functions\when( 'get_post_type_object' )->justReturn( (object) [ 'cap' => (object) [ 'create_posts' => 'create_articles' ] ] );
		$board = self::board( 'stage', [ [ 'value' => 'pitch' ] ] );
		$r     = CardCreator::create( $board, new StageSource(), 'Idea', 'pitch' );
		$this->assertSame( 'bk_forbidden', $r->get_error_code() );
		$this->assertSame( [], CardCreator::creatable_lanes( $board, new StageSource() ) );
	}

	public function test_create_refuses_a_publishing_lane_before_inserting(): void {
		Functions\when( 'get_post_type_object' )->justReturn( (object) [ 'cap' => (object) [ 'create_posts' => 'edit_posts' ] ] );
		Functions\when( 'get_taxonomy' )->justReturn( (object) [ 'cap' => (object) [ 'assign_terms' => 'edit_posts' ] ] );
		Functions\expect( 'wp_insert_post' )->never();
		$board = self::board( 'stage', [ [ 'value' => 'pitch' ], [ 'value' => 'published', 'status' => 'publish' ] ] );
		$this->assertSame( 'bk_cannot_create', CardCreator::create( $board, new StageSource(), 'Idea', 'published' )->get_error_code() );
		$this->assertSame( 'bk_no_title', CardCreator::create( $board, new StageSource(), '   ', 'pitch' )->get_error_code() );
		$this->assertSame( [ 'pitch' ], CardCreator::creatable_lanes( $board, new StageSource() ) );
	}

	public function test_a_card_that_cant_be_placed_is_removed_again(): void {
		Functions\when( 'get_post_type_object' )->justReturn( (object) [ 'cap' => (object) [ 'create_posts' => 'edit_posts' ] ] );
		Functions\when( 'get_taxonomy' )->justReturn( (object) [ 'cap' => (object) [ 'assign_terms' => 'edit_posts' ] ] );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'wp_insert_post' )->alias( function ( $args ) {
			$this->assertSame( 'draft', $args['post_status'] );
			$this->assertSame( 'It\\\'s new', $args['post_title'], 'slashed for wp_insert_post' );
			return 42;
		} );
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'taxonomy_exists' )->justReturn( false ); // the lane's term can't be made
		Functions\expect( 'wp_delete_post' )->once()->with( 42, true );
		$board = self::board( 'stage', [ [ 'value' => 'pitch' ] ] );
		$r     = CardCreator::create( $board, new StageSource(), "It's new", 'pitch' );
		$this->assertSame( 'bk_no_term', $r->get_error_code() );
	}

	public function test_board_payload_marks_creatable_lanes_and_never_unsorted(): void {
		$board   = self::board( 'stage', [ [ 'value' => 'pitch' ], [ 'value' => 'published', 'status' => 'publish' ] ] );
		$payload = BoardsController::board_payload( $board, [], [ 'pitch' ] );
		$this->assertSame( [ true, false, false ], array_column( $payload['lanes'], 'can_create' ) );
	}
}
