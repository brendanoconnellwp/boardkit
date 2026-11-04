<?php
/**
 * Phase 3b smoke test — stage lanes that also set the WordPress status, scheduling with a date
 * picked on the board, and the v1 → v2 starter board migration (decision of 2026-11-04).
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file wp-content/plugins/boardkit/tests/smoke/phase3b-stage-status.php
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

use BoardKit\Boards\BoardRepository;
use BoardKit\Editorial\ActivityLog;
use BoardKit\Editorial\StageTaxonomy;
use BoardKit\Plugin;

function bk3b_move( int $id, string $to, string $from, string $date = '' ): WP_REST_Response {
	$req = new WP_REST_Request( 'POST', "/boardkit/v1/boards/bk_smoke_ed/cards/{$id}/move" );
	$req->set_body_params( [ 'to' => $to, 'from' => $from, 'date' => $date ] );
	return rest_do_request( $req );
}
function bk3b_post( int $id ): WP_Post {
	clean_post_cache( $id );
	return get_post( $id );
}
function bk3b_stage( int $id ): array {
	clean_object_term_cache( $id, 'post' );
	return wp_get_object_terms( $id, StageTaxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
}

$repo    = Plugin::instance()->boards();
$created = [ 'posts' => [], 'users' => [] ];
$tz_was  = get_option( 'timezone_string' );

try {
	wp_set_current_user( 1 );

	// ---- the starter board on this site ----------------------------------------------------
	$starter = $repo->get( 'editorial' );
	if ( $starter ) {
		$labels = array_column( $starter->lanes(), 'label' );
		echo '(starter board lanes: ' . implode( ' → ', $labels ) . ")\n";
		check( 'starter board reads as v2 (no lane called "Draft" any more)', ! in_array( 'Draft', $labels, true ) );
		check( 'starter Published lane publishes', ( array_column( $starter->lanes(), 'status', 'value' )['published'] ?? '' ) === 'publish' );
		$payload = rest_do_request( new WP_REST_Request( 'GET', '/boardkit/v1/boards/editorial' ) )->get_data();
		check( 'payload says which lanes set a status', array_column( $payload['lanes'], 'sets_status', 'value' )['scheduled'] === 'future' );
		check( 'payload carries the site timezone', ( $payload['timezone'] ?? '' ) !== '' );
	}

	// ---- fixture board: stages, three of which set a status ---------------------------------
	update_option( 'timezone_string', 'Australia/Sydney' ); // a non-UTC site, so GMT conversion is visible
	$board = $repo->save( [
		'schema'     => 2,
		'id'         => 'bk_smoke_ed',
		'post_types' => [ 'post' ],
		'lanes'      => [
			[ 'value' => 'bk3b-pitch', 'label' => 'Pitch' ],
			[ 'value' => 'bk3b-ready', 'label' => 'Ready', 'status' => 'pending' ],
			[ 'value' => 'bk3b-sched', 'label' => 'Scheduled', 'status' => 'future' ],
			[ 'value' => 'bk3b-pub', 'label' => 'Published', 'status' => 'publish' ],
		],
	] );
	StageTaxonomy::ensure_terms( $board );

	$user = static function ( string $role ) use ( &$created ): int {
		$id = wp_insert_user( [ 'user_login' => 'bk3b_' . $role . '_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => $role ] );
		$created['users'][] = $id;
		return $id;
	};
	$author  = $user( 'author' );
	$contrib = $user( 'contributor' );
	$post    = static function ( int $by ) use ( &$created ): int {
		$id = wp_insert_post( [ 'post_title' => 'BK 3b', 'post_author' => $by, 'post_status' => 'draft' ] );
		wp_set_object_terms( $id, [ 'bk3b-pitch' ], StageTaxonomy::TAXONOMY, false );
		$created['posts'][] = $id;
		return $id;
	};

	// Contributor.
	$c = $post( $contrib );
	wp_set_current_user( $contrib );
	$r = bk3b_move( $c, 'bk3b-pub', 'bk3b-pitch' );
	check( 'contributor → Published is 403', $r->get_status() === 403 );
	check( '…and the stage did not change either', bk3b_stage( $c ) === [ 'bk3b-pitch' ] && bk3b_post( $c )->post_status === 'draft' );
	check( 'contributor → Ready submits for review', bk3b_move( $c, 'bk3b-ready', 'bk3b-pitch' )->get_status() === 200 && bk3b_post( $c )->post_status === 'pending' && bk3b_stage( $c ) === [ 'bk3b-ready' ] );
	check( 'contributor → Scheduled is 403 (even with a date)', bk3b_move( $c, 'bk3b-sched', 'bk3b-ready', '2031-03-05 09:30' )->get_status() === 403 );

	// Author.
	$a = $post( $author );
	wp_set_current_user( $author );
	$r = bk3b_move( $a, 'bk3b-sched', 'bk3b-pitch' );
	check( 'Scheduled without a date → 400 bk_needs_date', $r->get_status() === 400 && ( $r->get_data()['code'] ?? '' ) === 'bk_needs_date' );
	check( '…stage unchanged', bk3b_stage( $a ) === [ 'bk3b-pitch' ] );
	check( 'past date → 400', bk3b_move( $a, 'bk3b-sched', 'bk3b-pitch', '2001-01-01 09:00' )->get_status() === 400 );

	$r = bk3b_move( $a, 'bk3b-sched', 'bk3b-pitch', '2031-03-05 09:30' );
	$p = bk3b_post( $a );
	check( 'Scheduled with a date → 200', $r->get_status() === 200, (string) $r->get_status() . ' ' . wp_json_encode( $r->get_data() ) );
	check( 'post is scheduled', $p->post_status === 'future' );
	check( 'date stored as site time', $p->post_date === '2031-03-05 09:30:00', $p->post_date );
	check( 'and converted to GMT (Sydney is UTC+11 in March)', $p->post_date_gmt === '2031-03-04 22:30:00', $p->post_date_gmt );
	check( 'card reports the new status', ( $r->get_data()['card']['status']['value'] ?? '' ) === 'future' );
	$fields = array_column( ActivityLog::entries( $a ), 'field' );
	check( 'log records stage and status', array_slice( $fields, 0, 2 ) === [ 'status', 'stage' ], implode( ',', $fields ) );

	$r = bk3b_move( $a, 'bk3b-pub', 'bk3b-sched' );
	check( 'Scheduled → Published goes live now', $r->get_status() === 200 && bk3b_post( $a )->post_status === 'publish' && strtotime( bk3b_post( $a )->post_date_gmt . ' UTC' ) <= time() + 5 );
	check( 'Published → Pitch keeps it published (stage-only lane)', bk3b_move( $a, 'bk3b-pitch', 'bk3b-pub' )->get_status() === 200 && bk3b_post( $a )->post_status === 'publish' );
} finally {
	update_option( 'timezone_string', $tz_was );
	wp_set_current_user( 1 );
	foreach ( $created['posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $created['users'] as $id ) {
		wp_delete_user( $id );
	}
	foreach ( [ 'bk3b-pitch', 'bk3b-ready', 'bk3b-sched', 'bk3b-pub' ] as $slug ) {
		$t = get_term_by( 'slug', $slug, StageTaxonomy::TAXONOMY );
		if ( $t ) {
			wp_delete_term( $t->term_id, StageTaxonomy::TAXONOMY );
		}
	}
	$repo->delete( 'bk_smoke_ed' );
}

echo $pass ? "\nALL PASS\n" : "\nSOME FAILED\n";
