<?php
/**
 * Phase 4 smoke test — assignees, due dates, filters, due sort, the editor meta box.
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file wp-content/plugins/boardkit/tests/smoke/phase4-editorial-fields.php
 *
 * Done-when (spec §9): `_bk_assignees` queries use meta_query equality (no LIKE), and assigning a
 * user who can't edit the post is refused.
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
use BoardKit\Editorial\Meta;
use BoardKit\Editorial\MetaBox;
use BoardKit\Editorial\StageTaxonomy;
use BoardKit\Plugin;
use BoardKit\Query\Filters;

function bk4_req( string $method, string $path, array $params = [] ): WP_REST_Response {
	$req = new WP_REST_Request( $method, '/boardkit/v1/boards/bk_smoke4' . $path );
	$method === 'GET' ? $req->set_query_params( $params ) : $req->set_body_params( $params );
	return rest_do_request( $req );
}
function bk4_ids( string $lane, array $filters = [] ): array {
	return array_column( bk4_req( 'GET', '/lanes/' . $lane . '/cards', $filters )->get_data()['cards'] ?? [], 'id' );
}

$repo    = Plugin::instance()->boards();
$created = [ 'posts' => [], 'users' => [] ];

try {
	wp_set_current_user( 1 );
	$board = $repo->save( [
		'schema' => 2, 'id' => 'bk_smoke4', 'post_types' => [ 'post' ],
		'lanes'  => [ [ 'value' => 'bk4-a', 'label' => 'A' ] ],
		'sort'   => [ 'by' => 'due', 'dir' => 'asc' ],
		'cards'  => [ 'fields' => [ 'author', 'assignees', 'due' ] ],
	] );
	StageTaxonomy::ensure_terms( $board );

	$user = static function ( string $role ) use ( &$created ): int {
		$id = wp_insert_user( [ 'user_login' => 'bk4_' . $role . '_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => $role, 'display_name' => 'BK4 ' . $role ] );
		$created['users'][] = $id;
		return $id;
	};
	$editor  = $user( 'editor' );
	$author  = $user( 'author' );
	$contrib = $user( 'contributor' );

	$post = static function ( string $title, ?string $due, int $by = 1 ) use ( &$created ): int {
		$id = wp_insert_post( [ 'post_title' => $title, 'post_status' => 'draft', 'post_author' => $by ] );
		wp_set_object_terms( $id, [ 'bk4-a' ], StageTaxonomy::TAXONOMY, false );
		if ( $due !== null ) {
			update_post_meta( $id, Meta::DUE, $due );
		}
		$created['posts'][] = $id;
		return $id;
	};
	$late   = $post( 'Late', '2001-01-01' );
	$soon   = $post( 'Soon', '2031-01-01' );
	$none   = $post( 'No due date', null );
	$later  = $post( 'Later', '2032-06-01' );

	// ---- due sort: undated last, in both directions -----------------------------------------
	$order = array_values( array_intersect( bk4_ids( 'bk4-a' ), [ $late, $soon, $none, $later ] ) );
	check( 'due ASC: earliest first, undated last', $order === [ $late, $soon, $later, $none ], wp_json_encode( $order ) );
	$repo->save( [ 'sort' => [ 'by' => 'due', 'dir' => 'desc' ] ] + $board->to_array() );
	$order = array_values( array_intersect( bk4_ids( 'bk4-a' ), [ $late, $soon, $none, $later ] ) );
	check( 'due DESC: latest first, undated still last', $order === [ $later, $soon, $late, $none ], wp_json_encode( $order ) );

	// ---- assignees via REST -------------------------------------------------------------------
	$r = bk4_req( 'POST', "/cards/{$soon}/assignees", [ 'user_ids' => [ $editor, $author ], 'previous' => [] ] );
	$rows = $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT meta_value FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_value", $soon, Meta::ASSIGNEES ) );
	// An Author can't edit an admin's post → the WHOLE request is refused (editor not saved either).
	check( 'editor + author on an admin post → 400 bk_bad_assignee', $r->get_status() === 400 && ( $r->get_data()['code'] ?? '' ) === 'bk_bad_assignee' );
	check( '…and nothing was saved', $rows === [], 'rows=' . wp_json_encode( $rows ) );

	$r = bk4_req( 'POST', "/cards/{$soon}/assignees", [ 'user_ids' => [ $editor ], 'previous' => [] ] );
	$rows = $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT meta_value FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id = %d AND meta_key = %s", $soon, Meta::ASSIGNEES ) );
	check( 'editor assigned: exactly one meta row with their ID', $r->get_status() === 200 && $rows === [ (string) $editor ], wp_json_encode( $rows ) );
	check( 'card shows the assignee', array_column( $r->get_data()['card']['assignees'] ?? [], 'id' ) === [ $editor ] );

	$r = bk4_req( 'POST', "/cards/{$soon}/assignees", [ 'user_ids' => [], 'previous' => [] ] );
	check( 'stale "previous" → 409 with the current card', $r->get_status() === 409 && isset( $r->get_data()['data']['card'] ) );

	$contrib_post = $post( 'Contributor post', null, $contrib );
	$r = bk4_req( 'POST', "/cards/{$contrib_post}/assignees", [ 'user_ids' => [ $contrib, $editor ], 'previous' => [] ] );
	check( 'contributor + editor on a contributor draft → 200', $r->get_status() === 200 );

	// ---- "assigned to me" filter --------------------------------------------------------------
	wp_set_current_user( $editor );
	$captured = '';
	$grab     = static function ( $sql, $q ) use ( &$captured ) {
		if ( strpos( $sql, Meta::ASSIGNEES ) !== false ) {
			$captured = $sql;
		}
		return $sql;
	};
	add_filter( 'posts_request', $grab, 10, 2 );
	$mine = bk4_ids( 'bk4-a', [ 'assignee' => 'me' ] );
	remove_filter( 'posts_request', $grab, 10 );
	sort( $mine );
	$expect = [ $soon, $contrib_post ];
	sort( $expect );
	check( 'assigned to me (editor) → exactly their two posts', $mine === $expect, wp_json_encode( $mine ) );
	check( 'the SQL uses equality on _bk_assignees, no LIKE', $captured !== '' && stripos( $captured, 'LIKE' ) === false && strpos( $captured, "meta_value = '{$editor}'" ) !== false, substr( preg_replace( '/\s+/', ' ', $captured ), 0, 160 ) . '…' );

	wp_set_current_user( $contrib );
	$mine = bk4_ids( 'bk4-a', [ 'assignee' => 'me' ] );
	check( 'contributor: assigned to me → own post', $mine === [ $contrib_post ], wp_json_encode( $mine ) );
	$r = bk4_req( 'POST', "/cards/{$soon}/assignees", [ 'user_ids' => [ $contrib ], 'previous' => [ $editor ] ] );
	check( "contributor can't change assignees on an admin post (403)", $r->get_status() === 403 );

	// ---- due date via REST + overdue filter ---------------------------------------------------
	wp_set_current_user( 1 );
	$r = bk4_req( 'POST', "/cards/{$none}/due", [ 'date' => '2026-02-30', 'previous' => '' ] );
	check( 'impossible date → 400', $r->get_status() === 400 );
	$r = bk4_req( 'POST', "/cards/{$none}/due", [ 'date' => '2020-05-05', 'previous' => '' ] );
	check( 'set due → 200, overdue flagged on the card', $r->get_status() === 200 && ( $r->get_data()['card']['due']['overdue'] ?? false ) === true );
	$overdue = array_values( array_intersect( bk4_ids( 'bk4-a', [ 'overdue' => '1' ] ), [ $late, $soon, $none, $later ] ) );
	sort( $overdue );
	$expect = [ $late, $none ];
	sort( $expect );
	check( 'overdue filter → only past-due unpublished posts', $overdue === $expect, wp_json_encode( $overdue ) );
	$counts = array_column( bk4_req( 'GET', '', [ 'overdue' => '1' ] )->get_data()['lanes'], 'count', 'value' );
	check( 'lane counts follow the filter', ( $counts['bk4-a'] ?? -1 ) === 2, wp_json_encode( $counts ) );
	$r = bk4_req( 'POST', "/cards/{$none}/due", [ 'date' => '', 'previous' => '2020-05-05' ] );
	check( 'clear due → row deleted (not empty string)', $r->get_status() === 200 && ! metadata_exists( 'post', $none, Meta::DUE ) );
	$r = bk4_req( 'POST', "/cards/{$none}/due", [ 'date' => '2030-01-01', 'previous' => '2020-05-05' ] );
	check( 'due with stale previous → 409', $r->get_status() === 409 );

	// ---- people endpoint ----------------------------------------------------------------------
	$people = bk4_req( 'GET', '/people', [ 'search' => 'BK4' ] )->get_data()['people'] ?? [];
	$ids    = array_column( $people, 'id' );
	check( 'people search finds editor, author, contributor', ! array_diff( [ $editor, $author, $contrib ], $ids ), wp_json_encode( $ids ) );
	check( 'people returns only id, name, avatar', $people !== [] && array_keys( $people[0] ) === [ 'id', 'name', 'avatar' ] );

	// ---- meta box save -----------------------------------------------------------------------
	wp_set_current_user( $editor );
	$_POST = [
		MetaBox::NONCE         => wp_create_nonce( MetaBox::NONCE ),
		'bk_assignees_present' => '1',
		'bk_assignees'         => [ (string) $author ], // author can't edit an admin post
		'bk_due'               => '2031-07-07',
	];
	do_action( 'save_post', $later, get_post( $later ), true );
	check( 'meta box: due date saved', Meta::due( $later ) === '2031-07-07' );
	check( 'meta box: ineligible assignee refused', Meta::assignees( $later ) === [] );
	$_POST = [ MetaBox::NONCE => 'bogus', 'bk_due' => '2040-01-01' ];
	do_action( 'save_post', $later, get_post( $later ), true );
	check( 'meta box: bad nonce changes nothing', Meta::due( $later ) === '2031-07-07' );
	$_POST = [];

	// ---- a board move doesn't run the meta box --------------------------------------------------
	wp_set_current_user( 1 );
	$_POST = [ MetaBox::NONCE => wp_create_nonce( MetaBox::NONCE ), 'bk_due' => '2099-09-09' ];
	$status_board = $repo->save( [ 'id' => 'bk_smoke4s', 'post_types' => [ 'post' ], 'lane_source' => [ 'kind' => 'status' ], 'lanes' => [ [ 'value' => 'draft' ], [ 'value' => 'pending' ] ] ] );
	$req = new WP_REST_Request( 'POST', "/boardkit/v1/boards/bk_smoke4s/cards/{$later}/move" );
	$req->set_body_params( [ 'to' => 'pending', 'from' => 'draft' ] );
	$moved = rest_do_request( $req );
	check( 'status move ran (save_post fired) without the meta box overwriting the due date', $moved->get_status() === 200 && Meta::due( $later ) === '2031-07-07', Meta::due( $later ) );
	$_POST = [];
} finally {
	$_POST = [];
	wp_set_current_user( 1 );
	foreach ( $created['posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $created['users'] as $id ) {
		wp_delete_user( $id );
	}
	$t = get_term_by( 'slug', 'bk4-a', StageTaxonomy::TAXONOMY );
	if ( $t ) {
		wp_delete_term( $t->term_id, StageTaxonomy::TAXONOMY );
	}
	$repo->delete( 'bk_smoke4' );
	$repo->delete( 'bk_smoke4s' );
}

echo $pass ? "\nALL PASS\n" : "\nSOME FAILED\n";
