<?php
declare( strict_types=1 );

namespace BoardKit\Tests\Unit\Lanes;

use BoardKit\Boards\Board;
use BoardKit\Boards\BoardRepository;
use BoardKit\Lanes\LaneSourceRegistry;
use BoardKit\Lanes\StageSource;
use BoardKit\Lanes\StatusSource;
use BoardKit\Tests\Unit\WpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class LaneSourceTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WpStubs::sanitizers();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function board( string $kind, array $lanes ): Board {
		return Board::from_normalised( BoardRepository::normalise( [
			'lane_source' => [ 'kind' => $kind ],
			'lanes'       => array_map( static fn( $v ) => [ 'value' => $v ], $lanes ),
		], 'b' ) );
	}

	private static function term( string $slug ): \WP_Term {
		$t       = new \WP_Term();
		$t->slug = $slug;
		return $t;
	}

	public function test_stage_lane_of_picks_first_term_in_lane_order(): void {
		$board = self::board( 'stage', [ 'pitch', 'draft', 'review' ] );
		Functions\when( 'get_the_terms' )->justReturn( [ self::term( 'review' ), self::term( 'draft' ) ] );
		$this->assertSame( 'draft', ( new StageSource() )->lane_of( new \WP_Post(), $board ) );
	}

	public function test_stage_lane_of_is_null_for_no_or_foreign_terms(): void {
		$board = self::board( 'stage', [ 'pitch' ] );
		Functions\when( 'get_the_terms' )->justReturn( false );
		$this->assertNull( ( new StageSource() )->lane_of( new \WP_Post(), $board ) );
		Functions\when( 'get_the_terms' )->justReturn( [ self::term( 'other-boards-lane' ) ] );
		$this->assertNull( ( new StageSource() )->lane_of( new \WP_Post(), $board ) );
	}

	public function test_status_lane_of(): void {
		$board             = self::board( 'status', [ 'draft', 'pending' ] );
		$post              = new \WP_Post();
		$post->post_status = 'pending';
		$this->assertSame( 'pending', ( new StatusSource() )->lane_of( $post, $board ) );
		$post->post_status = 'publish';
		$this->assertNull( ( new StatusSource() )->lane_of( $post, $board ) );
	}

	public function test_board_statuses_exclude_trash_auto_draft_inherit(): void {
		Functions\when( 'get_post_stati' )->justReturn( [ 'publish' => 'publish', 'trash' => 'trash', 'auto-draft' => 'auto-draft', 'inherit' => 'inherit', 'draft' => 'draft' ] );
		$this->assertSame( [ 'publish', 'draft' ], StatusSource::board_statuses() );
	}

	public function test_registry_falls_back_to_stage_for_unavailable_kinds(): void {
		$registry = new LaneSourceRegistry();
		$registry->register( new StageSource() );
		$this->assertSame( 'stage', $registry->for_board( self::board( 'columnkit', [] ) )->kind() );
		$this->assertFalse( $registry->is_available( 'columnkit' ) );
	}
}
