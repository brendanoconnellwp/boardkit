<?php
declare( strict_types=1 );

namespace BoardKit\Tests\Unit\Boards;

use BoardKit\Boards\BoardRepository;
use BoardKit\Tests\Unit\WpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class BoardRepositoryTest extends TestCase {
	private BoardRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WpStubs::options();
		WpStubs::sanitizers();
		BoardRepository::reset_cache();
		$this->repo = new BoardRepository();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_save_writes_its_own_option_autoload_off_and_the_index(): void {
		$this->repo->save( [ 'id' => 'news', 'label' => 'News', 'lanes' => [ [ 'value' => 'a' ] ] ] );
		$this->assertArrayHasKey( 'bk_board_news', WpStubs::$options );
		$this->assertFalse( WpStubs::$autoload['bk_board_news'] );
		$this->assertSame( [ 'news' ], WpStubs::$options['bk_boards'] );
		$this->assertFalse( WpStubs::$autoload['bk_boards'] );
	}

	public function test_saving_twice_does_not_duplicate_the_index(): void {
		$this->repo->save( [ 'id' => 'news' ] );
		$this->repo->save( [ 'id' => 'news', 'label' => 'Renamed' ] );
		$this->assertSame( [ 'news' ], $this->repo->ids() );
		$this->assertSame( 'Renamed', $this->repo->get( 'news' )->label() );
	}

	public function test_all_skips_index_entries_whose_option_is_gone(): void {
		WpStubs::$options['bk_boards'] = [ 'gone', 'here' ];
		WpStubs::$options['bk_board_here'] = [ 'label' => 'Here' ];
		$this->assertSame( [ 'here' ], array_map( static fn( $b ) => $b->id(), $this->repo->all() ) );
	}

	public function test_delete_removes_option_and_index_entry(): void {
		$this->repo->save( [ 'id' => 'a' ] );
		$this->repo->save( [ 'id' => 'b' ] );
		$this->repo->delete( 'a' );
		$this->assertSame( [ 'b' ], $this->repo->ids() );
		$this->assertNull( $this->repo->get( 'a' ) );
	}

	public function test_seed_runs_once_and_respects_an_emptied_index(): void {
		$this->assertNotNull( $this->repo->seed_default() );
		$this->assertSame( [ 'editorial' ], $this->repo->ids() );
		$this->assertNull( $this->repo->seed_default(), 'second run does nothing' );

		$this->repo->delete( 'editorial' );
		BoardRepository::reset_cache();
		$this->assertNull( $this->repo->seed_default(), 'user deleted every board: do not re-create' );
	}

	public function test_default_board_is_a_stage_board_for_posts(): void {
		$board = $this->repo->seed_default();
		$this->assertSame( 'stage', $board->lane_kind() );
		$this->assertSame( 'post', $board->post_type() );
		$this->assertSame( [ 'pitch', 'draft', 'in-review', 'approved', 'scheduled', 'published' ], $board->lane_values() );
	}

	// ---- normalise ------------------------------------------------------------

	public function test_normalise_fills_every_key_from_nothing(): void {
		$n = BoardRepository::normalise( [], 'x' );
		$this->assertSame( 1, $n['schema'] );
		$this->assertSame( 'x', $n['label'] );
		$this->assertSame( [ 'post' ], $n['post_types'] );
		$this->assertSame( [ 'kind' => 'stage' ], $n['lane_source'] );
		$this->assertSame( [], $n['lanes'] );
		$this->assertSame( [ 'by' => 'modified', 'dir' => 'desc' ], $n['sort'] );
		$this->assertSame( 'builtin', $n['cards']['source'] );
	}

	public function test_normalise_keeps_only_the_first_post_type(): void {
		$n = BoardRepository::normalise( [ 'post_types' => [ 'page', 'post' ] ], 'x' );
		$this->assertSame( [ 'page' ], $n['post_types'] );
	}

	public function test_normalise_drops_duplicate_and_empty_lane_values(): void {
		$n = BoardRepository::normalise( [ 'lanes' => [
			[ 'value' => 'Draft', 'label' => 'Draft' ],
			[ 'value' => 'draft', 'label' => 'Again' ],
			[ 'value' => '!!!' ],
			'not-an-array',
			[ 'value' => 'b', 'wip' => -3, 'color' => 'red' ],
		] ], 'x' );
		$this->assertSame( [ 'draft', 'b' ], array_column( $n['lanes'], 'value' ) );
		$this->assertSame( 0, $n['lanes'][1]['wip'], 'negative WIP clamps to 0' );
		$this->assertSame( '', $n['lanes'][1]['color'], 'non-hex colour dropped' );
		$this->assertSame( 'b', $n['lanes'][1]['label'], 'missing label falls back to value' );
	}

	public function test_normalise_rejects_unknown_kinds_and_sorts(): void {
		$n = BoardRepository::normalise( [ 'lane_source' => [ 'kind' => 'evil' ], 'sort' => [ 'by' => 'post_password', 'dir' => 'sideways' ] ], 'x' );
		$this->assertSame( 'stage', $n['lane_source']['kind'] );
		$this->assertSame( [ 'by' => 'modified', 'dir' => 'desc' ], $n['sort'] );
	}

	public function test_normalise_keeps_a_columnkit_source_when_columnkit_is_off(): void {
		// So re-activating ColumnKit restores the board's real config (PLAN §3 Phase 7).
		$n = BoardRepository::normalise( [ 'lane_source' => [ 'kind' => 'columnkit', 'set' => 'ed', 'column' => 'acf_status' ] ], 'x' );
		$this->assertSame( [ 'kind' => 'columnkit', 'set' => 'ed', 'column' => 'acf_status' ], $n['lane_source'] );
	}

	public function test_normalise_card_fields_are_allowlisted(): void {
		$n = BoardRepository::normalise( [ 'cards' => [ 'fields' => [ 'author', 'post_password', 'terms' ], 'taxonomy' => 'genre' ] ], 'x' );
		$this->assertSame( [ 'author', 'terms' ], $n['cards']['fields'] );
		$this->assertSame( 'genre', $n['cards']['taxonomy'] );
	}

	public function test_get_rejects_unsafe_ids(): void {
		$this->assertNull( $this->repo->get( '' ) );
		$this->assertNull( $this->repo->get( '../../wp_options' ) );
	}
}
