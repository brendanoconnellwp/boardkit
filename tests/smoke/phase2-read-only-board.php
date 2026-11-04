<?php
/**
 * Phase 2 smoke test — read-only board, role matrix (spec §9 Phase 2 "done when").
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file wp-content/plugins/boardkit/tests/smoke/phase2-read-only-board.php
 *
 * Creates throwaway users (editor, author, contributor) and posts, calls the REST routes as
 * each role, and deletes everything afterwards. Uses its own board ('bk_smoke'), not the
 * starter board.
 */

global $pass;
$pass = true;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $pass;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label;
	if ( $detail !== '' ) { echo "  ($detail)"; }
	echo "\n";
	if ( ! $ok ) { $pass = false; }
}

use BoardKit\Boards\Board;
use BoardKit\Boards\BoardRepository;
use BoardKit\Editorial\Meta;
use BoardKit\Editorial\StageTaxonomy;
use BoardKit\Plugin;

/** All card ids on the board for the current user, across every lane and page. */
function bk_smoke_cards(): array {
	$board = rest_do_request( new WP_REST_Request( 'GET', '/boardkit/v1/boards/bk_smoke' ) );
	if ( $board->is_error() ) {
		return [ 'error' => $board->get_status() ];
	}
	$out = [];
	foreach ( $board->get_data()['lanes'] as $lane ) {
		$offset = 0;
		do {
			$req = new WP_REST_Request( 'GET', '/boardkit/v1/boards/bk_smoke/lanes/' . $lane['value'] . '/cards' );
			$req->set_param( 'offset', $offset );
			$res = rest_do_request( $req )->get_data();
			foreach ( $res['cards'] as $card ) {
				$out[ $card['id'] ] = $lane['value'];
			}
			$offset = $res['next_offset'];
		} while ( $offset !== null );
	}
	return $out;
}

$repo    = Plugin::instance()->boards();
$created = [ 'posts' => [], 'users' => [] ];

try {
	wp_set_current_user( 1 );

	// --- starter board + taxonomy -----------------------------------------------
	check( 'bk_stage taxonomy registered', taxonomy_exists( StageTaxonomy::TAXONOMY ) );
	$starter = $repo->get( 'editorial' );
	check( 'starter board seeded', $starter instanceof Board );
	check( 'starter board stage terms exist', $starter && get_term_by( 'slug', 'in-review', StageTaxonomy::TAXONOMY ) instanceof WP_Term );
	check( 'bk_boards index is not autoloaded', 'no' === $GLOBALS['wpdb']->get_var( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'bk_boards'" ) || 'off' === $GLOBALS['wpdb']->get_var( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'bk_boards'" ) );

	// --- fixture board ----------------------------------------------------------
	$board = $repo->save( [
		'id'          => 'bk_smoke',
		'label'       => 'Smoke',
		'post_types'  => [ 'post' ],
		'lane_source' => [ 'kind' => 'stage' ],
		'lanes'       => [ [ 'value' => 'bk-smoke-a', 'label' => 'A' ], [ 'value' => 'bk-smoke-b', 'label' => 'B' ] ],
		'cards'       => [ 'fields' => [ 'author', 'status', 'assignees', 'due' ] ],
	] );
	StageTaxonomy::ensure_terms( $board );

	$user = static function ( string $role ) use ( &$created ): int {
		$id = wp_insert_user( [ 'user_login' => 'bk_smoke_' . $role . '_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => $role, 'display_name' => "Smoke $role" ] );
		$created['users'][] = $id;
		return $id;
	};
	$editor  = $user( 'editor' );
	$author  = $user( 'author' );
	$contrib = $user( 'contributor' );

	$post = static function ( string $title, int $by, string $status, ?string $lane ) use ( &$created ): int {
		$id = wp_insert_post( [ 'post_title' => $title, 'post_author' => $by, 'post_status' => $status, 'post_type' => 'post' ] );
		if ( $lane !== null ) {
			wp_set_object_terms( $id, [ $lane ], StageTaxonomy::TAXONOMY, false );
		}
		$created['posts'][] = $id;
		return $id;
	};
	$p_admin_a     = $post( 'Admin draft in A', 1, 'draft', 'bk-smoke-a' );
	$p_author_b    = $post( 'Author draft in B', $author, 'draft', 'bk-smoke-b' );
	$p_author_pub  = $post( 'Author published, unsorted', $author, 'publish', null );
	$p_contrib_a   = $post( 'Contributor draft in A', $contrib, 'pending', 'bk-smoke-a' );
	$p_contrib_pub = $post( 'Contributor published (cannot edit)', $contrib, 'publish', 'bk-smoke-b' );
	$p_assigned    = $post( "Admin's draft assigned to author", 1, 'draft', 'bk-smoke-b' );
	add_post_meta( $p_assigned, Meta::ASSIGNEES, $author );
	$p_trash       = $post( 'Trashed', 1, 'trash', 'bk-smoke-a' );
	$p_xss         = $post( '<img src=x onerror=alert(1)> & "quotes"', 1, 'draft', 'bk-smoke-a' );
	update_post_meta( $p_admin_a, Meta::DUE, '2001-01-01' );

	$all_mine = [ $p_admin_a, $p_author_b, $p_author_pub, $p_contrib_a, $p_contrib_pub, $p_assigned, $p_xss ];

	// --- admin --------------------------------------------------------------------
	wp_set_current_user( 1 );
	$cards = bk_smoke_cards();
	check( 'admin sees every non-trashed fixture', array_diff( $all_mine, array_keys( $cards ) ) === [] );
	check( 'admin: trashed post never shown', ! isset( $cards[ $p_trash ] ) );
	check( 'admin: cards are in the right lanes', ( $cards[ $p_admin_a ] ?? '' ) === 'bk-smoke-a' && ( $cards[ $p_author_b ] ?? '' ) === 'bk-smoke-b' && ( $cards[ $p_author_pub ] ?? '' ) === Board::UNSORTED );

	$lane = rest_do_request( new WP_REST_Request( 'GET', '/boardkit/v1/boards/bk_smoke/lanes/bk-smoke-a/cards' ) )->get_data();
	$byid = array_column( $lane['cards'], null, 'id' );
	check( 'card title is plain text (tags stripped)', isset( $byid[ $p_xss ] ) && strpos( $byid[ $p_xss ]['title'], '<img' ) === false, $byid[ $p_xss ]['title'] ?? '' );
	check( 'overdue flag on past due date', ( $byid[ $p_admin_a ]['due']['overdue'] ?? false ) === true );
	$counts = array_column( rest_do_request( new WP_REST_Request( 'GET', '/boardkit/v1/boards/bk_smoke' ) )->get_data()['lanes'], 'count', 'value' );
	check( 'lane count matches cards for admin', ( $counts['bk-smoke-a'] ?? -1 ) === count( array_keys( $cards, 'bk-smoke-a', true ) ), wp_json_encode( $counts ) );

	// --- editor -------------------------------------------------------------------
	wp_set_current_user( $editor );
	$cards = bk_smoke_cards();
	check( 'editor sees every fixture', array_diff( $all_mine, array_keys( $cards ) ) === [] );

	// --- author -------------------------------------------------------------------
	wp_set_current_user( $author );
	$cards = bk_smoke_cards();
	$fixture_ids = array_values( array_intersect( array_keys( $cards ), $all_mine ) );
	sort( $fixture_ids );
	$expect = [ $p_author_b, $p_author_pub ];
	sort( $expect );
	check( 'author sees only own posts', $fixture_ids === $expect, 'got ' . wp_json_encode( $fixture_ids ) );
	check( "author doesn't see a post assigned to them they can't edit", ! isset( $cards[ $p_assigned ] ) );
	$counts = array_column( rest_do_request( new WP_REST_Request( 'GET', '/boardkit/v1/boards/bk_smoke' ) )->get_data()['lanes'], 'count', 'value' );
	check( 'author lane counts are scoped too', ( $counts['bk-smoke-a'] ?? -1 ) === 0, wp_json_encode( $counts ) );

	// --- contributor --------------------------------------------------------------
	wp_set_current_user( $contrib );
	$cards = bk_smoke_cards();
	check( 'contributor sees own pending post', isset( $cards[ $p_contrib_a ] ) );
	check( "contributor doesn't see own published post (can't edit it)", ! isset( $cards[ $p_contrib_pub ] ) );
	check( "contributor doesn't see others' posts", ! isset( $cards[ $p_admin_a ] ) && ! isset( $cards[ $p_author_b ] ) );

	// --- subscriber / bad input ---------------------------------------------------
	$sub = $user( 'subscriber' );
	wp_set_current_user( $sub );
	check( 'subscriber gets 403', rest_do_request( new WP_REST_Request( 'GET', '/boardkit/v1/boards/bk_smoke' ) )->get_status() === 403 );
	wp_set_current_user( 0 );
	check( 'logged out gets 401', rest_do_request( new WP_REST_Request( 'GET', '/boardkit/v1/boards/bk_smoke' ) )->get_status() === 401 );
	wp_set_current_user( 1 );
	check( 'unknown board 404', rest_do_request( new WP_REST_Request( 'GET', '/boardkit/v1/boards/nope' ) )->get_status() === 404 );
	check( 'unknown lane 404', rest_do_request( new WP_REST_Request( 'GET', '/boardkit/v1/boards/bk_smoke/lanes/zzz/cards' ) )->get_status() === 404 );

	// --- list table link ----------------------------------------------------------
	$views = ( new BoardKit\Admin\ListTableLink( $repo ) )->add_links( [], 'post' );
	check( 'list table gets a Board link', isset( $views['bk-board-bk_smoke'] ) && strpos( $views['bk-board-bk_smoke'], 'page=boardkit&#038;board=bk_smoke' ) !== false, $views['bk-board-bk_smoke'] ?? '' );
} finally {
	wp_set_current_user( 1 );
	foreach ( $created['posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $created['users'] as $id ) {
		wp_delete_user( $id );
	}
	foreach ( [ 'bk-smoke-a', 'bk-smoke-b' ] as $slug ) {
		$t = get_term_by( 'slug', $slug, StageTaxonomy::TAXONOMY );
		if ( $t ) {
			wp_delete_term( $t->term_id, StageTaxonomy::TAXONOMY );
		}
	}
	$repo->delete( 'bk_smoke' );
}

echo $pass ? "\nALL PASS\n" : "\nSOME FAILED\n";
