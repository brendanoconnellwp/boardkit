<?php
/**
 * Phase 5 smoke test — the card panel (details, featured image, editor flag) and "+ Add card".
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file wp-content/plugins/boardkit/tests/smoke/phase5-card-panel.php
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

use BoardKit\Admin\PanelEditor;
use BoardKit\Editorial\ActivityLog;
use BoardKit\Editorial\StageTaxonomy;
use BoardKit\Plugin;

function bk5_req( string $method, string $path, array $params = [] ): WP_REST_Response {
	$req = new WP_REST_Request( $method, '/boardkit/v1/boards/bk_smoke5' . $path );
	$method === 'GET' ? $req->set_query_params( $params ) : $req->set_body_params( $params );
	return rest_do_request( $req );
}

$repo    = Plugin::instance()->boards();
$created = [ 'posts' => [], 'users' => [] ];

try {
	wp_set_current_user( 1 );
	$board = $repo->save( [
		'schema' => 2, 'id' => 'bk_smoke5', 'post_types' => [ 'post' ],
		'lanes'  => [
			[ 'value' => 'bk5-pitch', 'label' => 'Pitch' ],
			[ 'value' => 'bk5-ready', 'label' => 'Ready', 'status' => 'pending' ],
			[ 'value' => 'bk5-pub', 'label' => 'Published', 'status' => 'publish' ],
		],
		'cards'  => [ 'fields' => [ 'author', 'thumbnail' ] ],
	] );
	StageTaxonomy::ensure_terms( $board );

	$user = static function ( string $role ) use ( &$created ): int {
		$id = wp_insert_user( [ 'user_login' => 'bk5_' . $role . '_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => $role, 'display_name' => 'BK5 ' . $role ] );
		$created['users'][] = $id;
		return $id;
	};
	$editor  = $user( 'editor' );
	$contrib = $user( 'contributor' );
	$sub     = $user( 'subscriber' );

	// ---- which lanes take new cards -----------------------------------------------------------
	$lanes = bk5_req( 'GET', '' )->get_data()['lanes'];
	check( 'admin: Pitch and Ready accept new cards; Published and Unsorted don\'t', array_column( $lanes, 'can_create' ) === [ true, true, false, false ], wp_json_encode( array_column( $lanes, 'can_create' ) ) );

	// ---- + Add card as a contributor -------------------------------------------------------------
	wp_set_current_user( $contrib );
	$r = bk5_req( 'POST', '/cards', [ 'title' => 'A pitch \\ with "quotes"', 'lane' => 'bk5-pitch' ] );
	$new = $r->get_data()['card']['id'] ?? 0;
	if ( $new ) { $created['posts'][] = $new; }
	$p = get_post( $new );
	check( 'contributor adds a card in Pitch → 201', $r->get_status() === 201, (string) $r->get_status() );
	check( '…a draft by them, title exact (slashes kept)', $p && $p->post_status === 'draft' && (int) $p->post_author === $contrib && $p->post_title === 'A pitch \\ with "quotes"', $p ? $p->post_title : '' );
	check( '…in the Pitch lane', $r->get_data()['card']['lane'] === 'bk5-pitch' );
	$log = ActivityLog::entries( $new );
	check( '…logged as created', ( $log[0]['field'] ?? '' ) === 'created' && ( $log[0]['to'] ?? '' ) === 'bk5-pitch' );

	$r2 = bk5_req( 'POST', '/cards', [ 'title' => 'Ready one', 'lane' => 'bk5-ready' ] );
	$ready = $r2->get_data()['card']['id'] ?? 0;
	if ( $ready ) { $created['posts'][] = $ready; }
	check( 'contributor adds in Ready → pending (submitted for review)', get_post_status( $ready ) === 'pending', (string) get_post_status( $ready ) );

	$before = (int) wp_count_posts()->publish + (int) wp_count_posts()->draft;
	$r3     = bk5_req( 'POST', '/cards', [ 'title' => 'Sneaky', 'lane' => 'bk5-pub' ] );
	$after  = (int) wp_count_posts()->publish + (int) wp_count_posts()->draft;
	check( 'adding straight into Published is refused (400) and creates nothing', $r3->get_status() === 400 && $before === $after, $r3->get_status() . ' ' . $before . '→' . $after );
	check( 'empty title refused', bk5_req( 'POST', '/cards', [ 'title' => '  ', 'lane' => 'bk5-pitch' ] )->get_status() === 400 );

	wp_set_current_user( $sub );
	check( 'subscriber can\'t add cards (403)', bk5_req( 'POST', '/cards', [ 'title' => 'x', 'lane' => 'bk5-pitch' ] )->get_status() === 403 );

	// ---- details: read --------------------------------------------------------------------------
	wp_set_current_user( $contrib );
	$d = bk5_req( 'GET', '/cards/' . $new );
	$details = $d->get_data()['details'] ?? [];
	check( 'GET card details → 200 with title, excerpt, supports, editor_url', $d->get_status() === 200 && isset( $details['title'], $details['excerpt'], $details['supports'] ) );
	check( '…editor_url opens the panel editor (bk_panel=1)', str_contains( (string) $details['editor_url'], 'bk_panel=1' ) );
	check( '…contributor has no upload_files', $details['can_upload'] === false );

	// ---- details: save ----------------------------------------------------------------------------
	$mod = get_post( $new )->post_modified_gmt;
	$s   = bk5_req( 'POST', '/cards/' . $new . '/details', [ 'title' => 'Better <script>alert(1)</script>title', 'excerpt' => 'Short summary.', 'modified' => $mod ] );
	$p   = get_post( $new );
	check( 'save title + excerpt → 200', $s->get_status() === 200, (string) $s->get_status() );
	check( '…kses ran for a contributor (no <script>)', ! str_contains( $p->post_title, '<script' ), $p->post_title );
	check( '…excerpt saved, status unchanged (still draft)', $p->post_excerpt === 'Short summary.' && $p->post_status === 'draft' );
	check( '…a revision was kept', count( wp_get_post_revisions( $new ) ) >= 1 );

	$stale = bk5_req( 'POST', '/cards/' . $new . '/details', [ 'title' => 'Lost', 'modified' => $mod ] );
	$sd    = $stale->get_data();
	check( 'stale modified → 409 with the current card and details', $stale->get_status() === 409 && isset( $sd['data']['card'], $sd['data']['details'] ) && ! isset( $sd['data']['post'] ), (string) $stale->get_status() );
	check( '…and nothing changed', get_post( $new )->post_title === $p->post_title );

	// Someone else has it open in the editor.
	wp_set_current_user( $editor );
	require_once ABSPATH . 'wp-admin/includes/post.php';
	wp_set_post_lock( $new );
	wp_set_current_user( $contrib );
	$locked = bk5_req( 'POST', '/cards/' . $new . '/details', [ 'title' => 'Mine', 'modified' => get_post( $new )->post_modified_gmt ] );
	check( 'editor lock held by another user → 409 bk_locked', $locked->get_status() === 409 && ( $locked->get_data()['code'] ?? '' ) === 'bk_locked', wp_json_encode( $locked->get_data()['code'] ?? '' ) );
	check( '…GET reports who holds it', bk5_req( 'GET', '/cards/' . $new )->get_data()['details']['locked_by'] === 'BK5 editor' );
	delete_post_meta( $new, '_edit_lock' );

	// A contributor can't edit an admin's post.
	wp_set_current_user( 1 );
	$admins = wp_insert_post( [ 'post_title' => 'Admin post', 'post_status' => 'draft' ] );
	$created['posts'][] = $admins;
	wp_set_object_terms( $admins, [ 'bk5-pitch' ], StageTaxonomy::TAXONOMY, false );
	wp_set_current_user( $contrib );
	check( 'contributor: details of someone else\'s post → 403', bk5_req( 'GET', '/cards/' . $admins )->get_status() === 403 );
	check( 'contributor: saving them → 403', bk5_req( 'POST', '/cards/' . $admins . '/details', [ 'title' => 'x', 'modified' => '' ] )->get_status() === 403 );

	// ---- featured image -----------------------------------------------------------------------------
	wp_set_current_user( 1 );
	$png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
	$bits = wp_upload_bits( 'bk5-smoke.png', null, $png );
	$img  = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'bk5', 'post_status' => 'inherit' ], $bits['file'] );
	$created['posts'][] = $img;
	$txt  = wp_upload_bits( 'bk5-smoke.txt', null, 'hello' );
	$doc  = wp_insert_attachment( [ 'post_mime_type' => 'text/plain', 'post_title' => 'bk5 txt', 'post_status' => 'inherit' ], $txt['file'] );
	$created['posts'][] = $doc;

	$mod = get_post( $new )->post_modified_gmt;
	$f   = bk5_req( 'POST', '/cards/' . $new . '/details', [ 'featured_media' => $img, 'modified' => $mod ] );
	check( 'set featured image → 200 and saved', $f->get_status() === 200 && (int) get_post_thumbnail_id( $new ) === $img, (string) $f->get_status() );
	check( '…the reply carries the image for the panel', ( $f->get_data()['details']['featured']['id'] ?? 0 ) === $img );
	$bad = bk5_req( 'POST', '/cards/' . $new . '/details', [ 'featured_media' => $doc, 'modified' => get_post( $new )->post_modified_gmt ] );
	check( 'a non-image attachment is refused (400)', $bad->get_status() === 400 && (int) get_post_thumbnail_id( $new ) === $img );
	$rm = bk5_req( 'POST', '/cards/' . $new . '/details', [ 'featured_media' => 0, 'modified' => get_post( $new )->post_modified_gmt ] );
	check( 'featured_media 0 removes it', $rm->get_status() === 200 && ! has_post_thumbnail( $new ) );

	// ---- panel editor flag survives a classic-editor save -------------------------------------------
	$_POST['_wp_http_referer'] = '/wp-admin/post.php?post=' . $new . '&action=edit&bk_panel=1';
	$kept = ( new PanelEditor() )->keep_flag( admin_url( 'post.php?post=' . $new . '&action=edit&message=1' ) );
	$_POST['_wp_http_referer'] = '/wp-admin/post.php?post=' . $new . '&action=edit';
	$not  = ( new PanelEditor() )->keep_flag( admin_url( 'post.php?post=' . $new . '&action=edit&message=1' ) );
	check( 'classic save redirect keeps bk_panel only when it came from the panel', str_contains( $kept, 'bk_panel=1' ) && ! str_contains( $not, 'bk_panel' ) );
	$_POST = [];
} finally {
	$_POST = [];
	wp_set_current_user( 1 );
	foreach ( $created['posts'] as $id ) {
		get_post_type( $id ) === 'attachment' ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $created['users'] as $id ) {
		wp_delete_user( $id );
	}
	foreach ( [ 'bk5-pitch', 'bk5-ready', 'bk5-pub' ] as $slug ) {
		$t = get_term_by( 'slug', $slug, StageTaxonomy::TAXONOMY );
		if ( $t ) {
			wp_delete_term( $t->term_id, StageTaxonomy::TAXONOMY );
		}
	}
	$repo->delete( 'bk_smoke5' );
}

echo $pass ? "\nALL PASS\n" : "\nSOME FAILED\n";
