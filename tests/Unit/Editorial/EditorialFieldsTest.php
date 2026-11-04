<?php
declare( strict_types=1 );

namespace BoardKit\Tests\Unit\Editorial;

use BoardKit\Editorial\Assignees;
use BoardKit\Editorial\DueDate;
use BoardKit\Editorial\Meta;
use BoardKit\Query\DueSort;
use BoardKit\Query\Filters;
use BoardKit\Tests\Unit\WpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class EditorialFieldsTest extends TestCase {
	private \WP_Post $post;
	/** @var array<string, array<int, mixed>> meta rows by key */
	private array $rows = [];
	/** @var array<int, bool> user id => can edit the post */
	private array $editors = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WpStubs::sanitizers();
		$this->post     = new \WP_Post();
		$this->post->ID = 42;
		$this->rows     = [];
		$this->editors  = [ 2 => true, 3 => true, 9 => false ];

		Functions\when( 'get_post_meta' )->alias( fn( $id, $key, $single = false ) => $single ? ( $this->rows[ $key ][0] ?? '' ) : ( $this->rows[ $key ] ?? [] ) );
		Functions\when( 'add_post_meta' )->alias( function ( $id, $key, $value ) {
			$this->rows[ $key ][] = $value;
			return true;
		} );
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
			$this->rows[ $key ] = [ $value ];
			return true;
		} );
		Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) {
			unset( $this->rows[ $key ] );
			return true;
		} );
		Functions\when( 'get_userdata' )->alias( function ( $id ) {
			if ( ! array_key_exists( (int) $id, $this->editors ) ) {
				return false;
			}
			$u               = new \WP_User();
			$u->ID           = (int) $id;
			$u->display_name = 'User ' . $id;
			return $u;
		} );
		Functions\when( 'user_can' )->alias( fn( $user, $cap ) => $cap === 'edit_post' && ( $this->editors[ $user->ID ] ?? false ) );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_date' )->justReturn( '2026-11-04' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---- assignees ---------------------------------------------------------------------------

	public function test_assignees_are_one_meta_row_each(): void {
		$this->assertSame( [ 2, 3 ], Assignees::set( $this->post, [ '3', 2, 2, 0, -1 ] ) );
		$this->assertSame( [ 2, 3 ], $this->rows[ Meta::ASSIGNEES ], 'one row per user, deduped, sorted' );
	}

	public function test_assigning_someone_who_cannot_edit_the_post_is_refused_and_nothing_changes(): void {
		Assignees::set( $this->post, [ 2 ] );
		$r = Assignees::set( $this->post, [ 2, 9 ] );
		$this->assertSame( 'bk_bad_assignee', $r->get_error_code() );
		$this->assertSame( [ 2 ], $this->rows[ Meta::ASSIGNEES ] );
	}

	public function test_unknown_user_and_too_many_are_refused(): void {
		$this->assertSame( 'bk_bad_assignee', Assignees::set( $this->post, [ 777 ] )->get_error_code() );
		$this->assertSame( 'bk_too_many', Assignees::set( $this->post, range( 1, 11 ) )->get_error_code() );
	}

	public function test_clearing_assignees_removes_every_row(): void {
		Assignees::set( $this->post, [ 2, 3 ] );
		$this->assertSame( [], Assignees::set( $this->post, [] ) );
		$this->assertArrayNotHasKey( Meta::ASSIGNEES, $this->rows );
	}

	public function test_assignee_change_is_logged(): void {
		Assignees::set( $this->post, [ 2 ] );
		$this->assertSame( 'assignees', $this->rows[ Meta::LOG ][0][0]['field'] );
	}

	// ---- due date ---------------------------------------------------------------------------

	public function test_due_date_validation_and_clearing_deletes_the_row(): void {
		$this->assertSame( '2026-12-01', DueDate::set( $this->post, '2026-12-01' ) );
		$this->assertSame( [ '2026-12-01' ], $this->rows[ Meta::DUE ] );
		foreach ( [ '2026-02-30', '12/01/2026', 'soon', '2026-1-1' ] as $bad ) {
			$this->assertSame( 'bk_bad_date', DueDate::set( $this->post, $bad )->get_error_code(), $bad );
		}
		$this->assertSame( '', DueDate::set( $this->post, '' ) );
		$this->assertArrayNotHasKey( Meta::DUE, $this->rows, "no due date = no row, never ''" );
	}

	// ---- filters ------------------------------------------------------------------------------

	public function test_assigned_to_me_is_meta_equality_never_like(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$args = Filters::from_params( [ 'assignee' => 'me' ] )->apply( [ 'post_status' => [ 'draft' ] ] );
		$clause = $args['meta_query'][0];
		$this->assertSame( [ 'key' => Meta::ASSIGNEES, 'value' => '5', 'compare' => '=' ], $clause );
		$this->assertStringNotContainsString( 'LIKE', wp_json_encode_safe( $args ) );
	}

	public function test_overdue_excludes_published_and_compares_dates(): void {
		$args = Filters::from_params( [ 'overdue' => '1' ] )->apply( [ 'post_status' => [ 'draft', 'publish' ] ] );
		$this->assertSame( [ 'draft' ], $args['post_status'] );
		$this->assertSame( [ 'key' => Meta::DUE, 'value' => '2026-11-04', 'compare' => '<', 'type' => 'DATE' ], $args['meta_query'][0] );
		$only_published = Filters::from_params( [ 'overdue' => '1' ] )->apply( [ 'post_status' => [ 'publish' ] ] );
		$this->assertSame( [ 0 ], $only_published['post__in'] );
	}

	public function test_filters_combine_and_junk_is_ignored(): void {
		$f = Filters::from_params( [ 's' => '<b>launch</b>', 'author' => 'x7', 'assignee' => '3', 'overdue' => '0' ] );
		$this->assertSame( 'launch', $f->search );
		$this->assertSame( 0, $f->author, 'non-numeric author ignored' );
		$this->assertFalse( $f->overdue );
		$this->assertTrue( Filters::from_params( [] )->is_empty() );
	}

	// ---- due sort --------------------------------------------------------------------------

	public function test_due_sort_only_touches_flagged_queries_and_puts_nulls_last(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $posts = 'wp_posts';
			public string $postmeta = 'wp_postmeta';
			public function prepare( $sql, ...$args ) { return str_replace( '%s', "'" . $args[0] . "'", $sql ); }
		};
		$base = [ 'join' => '', 'orderby' => 'wp_posts.post_date DESC', 'groupby' => '' ];

		$plain = new \WP_Query();
		$this->assertSame( $base, DueSort::clauses( $base, $plain ), 'other queries untouched' );

		$q = new \WP_Query( [ DueSort::VAR => 'ASC' ] );
		$c = DueSort::clauses( $base, $q );
		$this->assertStringContainsString( "LEFT JOIN wp_postmeta AS bk_due", $c['join'] );
		$this->assertStringContainsString( "'_bk_due'", $c['join'] );
		$this->assertStringStartsWith( '( MIN( bk_due.meta_value ) IS NULL ) ASC', $c['orderby'] );
		$this->assertSame( 'wp_posts.ID', $c['groupby'] );

		$evil = new \WP_Query( [ DueSort::VAR => 'ASC; DROP TABLE x' ] );
		$this->assertSame( $base, DueSort::clauses( $base, $evil ), 'only the two literals are accepted' );
		unset( $GLOBALS['wpdb'] );
	}
}

function wp_json_encode_safe( $v ): string {
	return (string) json_encode( $v );
}
