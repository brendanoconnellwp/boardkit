<?php
/**
 * Phase 3 smoke test — moving cards (spec §7.2, §7.3, §7.4).
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file wp-content/plugins/boardkit/tests/smoke/phase3-moving-cards.php
 *
 * Done-when (spec §9): every §7.2 rule holds against real WordPress, and a Contributor can't
 * publish by dragging. Creates throwaway users/posts/boards and removes them afterwards.
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
use BoardKit\Editorial\ActivityLog;
use BoardKit\Editorial\StageTaxonomy;
use BoardKit\Moves\MoveService;
use BoardKit\Plugin;

function bk_move( string $board, int $id, string $to, string $from, string $modified = '' ): WP_REST_Response {
	$req = new WP_REST_Request( 'POST', "/boardkit/v1/boards/{$board}/cards/{$id}/move" );
	$req->set_body_params( [ 'to' => $to, 'from' => $from, 'modified' => $modified ] );
	return rest_do_request( $req );
}
function bk_status_of( int $id ): string {
	clean_post_cache( $id );
	return (string) get_post_status( $id );
}
function bk_stage_of( int $id ): array {
	clean_object_term_cache( $id, 'post' );
	return wp_get_object_terms( $id, StageTaxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
}

$repo    = Plugin::instance()->boards();
$created = [ 'posts' => [], 'users' => [] ];

try {
	wp_set_current_user( 1 );
	$stage = $repo->save( [
		'id' => 'bk_smoke_stage', 'post_types' => [ 'post' ], 'lane_source' => [ 'kind' => 'stage' ],
		'lanes' => [ [ 'value' => 'bk-sm-a', 'label' => 'A' ], [ 'value' => 'bk-sm-b', 'label' => 'B' ] ],
	] );
	StageTaxonomy::ensure_terms( $stage );
	$repo->save( [
		'id' => 'bk_smoke_status', 'post_types' => [ 'post' ], 'lane_source' => [ 'kind' => 'status' ],
		'lanes' => array_map( static fn( $s ) => [ 'value' => $s ], [ 'draft', 'pending', 'future', 'publish' ] ),
	] );

	$user = static function ( string $role ) use ( &$created ): int {
		$id = wp_insert_user( [ 'user_login' => 'bk_sm3_' . $role . '_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => $role ] );
		$created['users'][] = $id;
		return $id;
	};
	$editor  = $user( 'editor' );
	$author  = $user( 'author' );
	$contrib = $user( 'contributor' );

	$post = static function ( int $by, string $status = 'draft', ?string $lane = 'bk-sm-a', array $extra = [] ) use ( &$created ): int {
		$id = wp_insert_post( $extra + [ 'post_title' => 'BK smoke 3', 'post_author' => $by, 'post_status' => $status ] );
		if ( $lane ) {
			wp_set_object_terms( $id, [ $lane ], StageTaxonomy::TAXONOMY, false );
		}
		$created['posts'][] = $id;
		return $id;
	};

	// ---- stage board ---------------------------------------------------------------------
	$p = $post( 1 );
	$before_modified = get_post( $p )->post_modified_gmt;
	$terms_before    = (int) wp_count_terms( [ 'taxonomy' => StageTaxonomy::TAXONOMY, 'hide_empty' => false ] );

	$r = bk_move( 'bk_smoke_stage', $p, 'bk-sm-b', 'bk-sm-a' );
	check( 'admin stage move 200', $r->get_status() === 200, (string) $r->get_status() );
	check( 'term replaced, not appended', bk_stage_of( $p ) === [ 'bk-sm-b' ], implode( ',', bk_stage_of( $p ) ) );
	check( 'response card is in the new lane', ( $r->get_data()['card']['lane'] ?? '' ) === 'bk-sm-b' );
	$log = ActivityLog::entries( $p );
	check( 'move logged', ( $log[0]['from'] ?? '' ) === 'bk-sm-a' && ( $log[0]['to'] ?? '' ) === 'bk-sm-b' && $log[0]['user'] === 1 );
	check( 'stage move does not bump post_modified', get_post( $p )->post_modified_gmt === $before_modified );
	check( 'is_moving() is false again', ! MoveService::is_moving() );

	$r = bk_move( 'bk_smoke_stage', $p, 'bk-sm-a', 'bk-sm-a' ); // client still thinks it's in A
	check( 'stale lane → 409', $r->get_status() === 409 );
	check( '409 carries the current card', ( $r->get_data()['data']['card']['lane'] ?? '' ) === 'bk-sm-b', wp_json_encode( $r->get_data()['data'] ?? null ) );
	check( '409 does not leak a WP_Post', ! isset( $r->get_data()['data']['post'] ) );

	check( 'into Unsorted → 400', bk_move( 'bk_smoke_stage', $p, Board::UNSORTED, 'bk-sm-b' )->get_status() === 400 );
	check( 'unknown lane → 400', bk_move( 'bk_smoke_stage', $p, 'bk-sm-zzz', 'bk-sm-b' )->get_status() === 400 );
	check( 'no stray stage terms created', (int) wp_count_terms( [ 'taxonomy' => StageTaxonomy::TAXONOMY, 'hide_empty' => false ] ) === $terms_before );
	check( 'unknown post → 404', bk_move( 'bk_smoke_stage', 999999999, 'bk-sm-a', 'bk-sm-b' )->get_status() === 404 );
	$page = $post( 1, 'draft', null, [ 'post_type' => 'page' ] );
	check( 'post of another type → 404', bk_move( 'bk_smoke_stage', $page, 'bk-sm-a', Board::UNSORTED )->get_status() === 404 );

	$unsorted = $post( 1, 'draft', null );
	check( 'Unsorted card can be moved out', bk_move( 'bk_smoke_stage', $unsorted, 'bk-sm-a', Board::UNSORTED )->get_status() === 200 );

	// Contributor on stage board.
	$own = $post( $contrib );
	wp_set_current_user( $contrib );
	check( 'contributor moves own draft between stages', bk_move( 'bk_smoke_stage', $own, 'bk-sm-b', 'bk-sm-a' )->get_status() === 200 );
	check( "contributor can't move admin's post (403)", bk_move( 'bk_smoke_stage', $p, 'bk-sm-a', 'bk-sm-b' )->get_status() === 403 );
	check( "admin's post unchanged", bk_stage_of( $p ) === [ 'bk-sm-b' ] );

	// ---- status board --------------------------------------------------------------------
	$c_draft = $post( $contrib, 'draft', null );
	wp_set_current_user( $contrib );
	foreach ( [ 'publish', 'future' ] as $to ) {
		$r = bk_move( 'bk_smoke_status', $c_draft, $to, 'draft' );
		check( "contributor → {$to} is 403", $r->get_status() === 403, (string) $r->get_status() );
	}
	check( 'contributor post still a draft', bk_status_of( $c_draft ) === 'draft' );
	check( 'contributor can submit for review', bk_move( 'bk_smoke_status', $c_draft, 'pending', 'draft' )->get_status() === 200 && bk_status_of( $c_draft ) === 'pending' );

	$a_draft = $post( $author, 'draft', null );
	wp_set_current_user( $author );
	$r = bk_move( 'bk_smoke_status', $a_draft, 'future', 'draft' );
	check( 'future without a future date → 400 "Set a date first"', $r->get_status() === 400 && ( $r->get_data()['code'] ?? '' ) === 'bk_needs_date' );
	check( 'author can publish own post', bk_move( 'bk_smoke_status', $a_draft, 'publish', 'draft' )->get_status() === 200 && bk_status_of( $a_draft ) === 'publish' );
	check( 'author can move own published post back to draft', bk_move( 'bk_smoke_status', $a_draft, 'draft', 'publish' )->get_status() === 200 && bk_status_of( $a_draft ) === 'draft' );

	$scheduled_date = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
	$a_dated = $post( $author, 'draft', null, [ 'post_date_gmt' => $scheduled_date, 'post_date' => get_date_from_gmt( $scheduled_date ) ] );
	check( 'future with a future date → scheduled', bk_move( 'bk_smoke_status', $a_dated, 'future', 'draft' )->get_status() === 200 && bk_status_of( $a_dated ) === 'future' );
	check( 'scheduled date kept', get_post( $a_dated )->post_date_gmt === $scheduled_date );

	$stale = get_post( $a_dated )->post_modified_gmt;
	wp_set_current_user( 1 );
	wp_update_post( [ 'ID' => $a_dated, 'post_title' => 'Edited by someone else' ] );
	$wait_until = time() + 2;
	while ( get_post( $a_dated )->post_modified_gmt === $stale && time() < $wait_until ) {
		usleep( 200000 );
		wp_update_post( [ 'ID' => $a_dated, 'post_content' => 'tick ' . microtime() ] );
		clean_post_cache( $a_dated );
	}
	wp_set_current_user( $author );
	check( 'status move with stale modified → 409', bk_move( 'bk_smoke_status', $a_dated, 'draft', 'future', $stale )->get_status() === 409 );

	wp_set_current_user( $editor );
	$x = $post( $author, 'pending', null );
	check( "editor publishes someone else's post", bk_move( 'bk_smoke_status', $x, 'publish', 'pending' )->get_status() === 200 );

	// ---- subscriber / logged out --------------------------------------------------------
	$sub = $user( 'subscriber' );
	wp_set_current_user( $sub );
	check( 'subscriber → 403', bk_move( 'bk_smoke_stage', $p, 'bk-sm-a', 'bk-sm-b' )->get_status() === 403 );
	wp_set_current_user( 0 );
	check( 'logged out → 401', bk_move( 'bk_smoke_stage', $p, 'bk-sm-a', 'bk-sm-b' )->get_status() === 401 );
} finally {
	wp_set_current_user( 1 );
	foreach ( $created['posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $created['users'] as $id ) {
		wp_delete_user( $id );
	}
	foreach ( [ 'bk-sm-a', 'bk-sm-b' ] as $slug ) {
		$t = get_term_by( 'slug', $slug, StageTaxonomy::TAXONOMY );
		if ( $t ) {
			wp_delete_term( $t->term_id, StageTaxonomy::TAXONOMY );
		}
	}
	$repo->delete( 'bk_smoke_stage' );
	$repo->delete( 'bk_smoke_status' );
}

echo $pass ? "\nALL PASS\n" : "\nSOME FAILED\n";
