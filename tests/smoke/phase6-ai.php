<?php
/**
 * Phase 6 smoke test — AI suggestions, the appended outline and the SEO meta description.
 * Makes TWO real AI calls (titles, excerpt) on the site's connected provider.
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file wp-content/plugins/boardkit/tests/smoke/phase6-ai.php
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

use BoardKit\Ai\Assistant;
use BoardKit\Editorial\StageTaxonomy;
use BoardKit\Plugin;

function bk6_req( string $method, string $path, array $params = [] ): WP_REST_Response {
	$req = new WP_REST_Request( $method, '/boardkit/v1/boards/bk_smoke6' . $path );
	$method === 'GET' ? $req->set_query_params( $params ) : $req->set_body_params( $params );
	return rest_do_request( $req );
}

$repo    = Plugin::instance()->boards();
$created = [ 'posts' => [], 'users' => [] ];

try {
	wp_set_current_user( 1 );
	$board = $repo->save( [
		'schema' => 2, 'id' => 'bk_smoke6', 'post_types' => [ 'post' ],
		'lanes'  => [ [ 'value' => 'bk6-pitch', 'label' => 'Pitch' ] ],
	] );
	StageTaxonomy::ensure_terms( $board );
	$contrib = wp_insert_user( [ 'user_login' => 'bk6_c_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'contributor' ] );
	$created['users'][] = $contrib;

	$content = "<!-- wp:paragraph -->\n<p>Composting at home turns kitchen scraps into rich soil. You need a bin, a mix of greens and browns, and a little patience.</p>\n<!-- /wp:paragraph -->";
	$post    = wp_insert_post( [ 'post_title' => 'Composting at home', 'post_status' => 'draft', 'post_content' => $content ] );
	$created['posts'][] = $post;
	wp_set_object_terms( $post, [ 'bk6-pitch' ], StageTaxonomy::TAXONOMY, false );
	delete_transient( 'bk_ai_calls_1' );

	// ---- availability --------------------------------------------------------------------------
	check( 'AI is available on this site', Assistant::available() );
	$d = bk6_req( 'GET', '/cards/' . $post )->get_data()['details'];
	check( 'details offer AI with the hourly allowance', ( $d['ai']['remaining'] ?? null ) === Assistant::DEFAULT_CAP, wp_json_encode( $d['ai'] ) );
	check( 'no SEO plugin → no meta field', $d['seo'] === null );

	// ---- real calls ------------------------------------------------------------------------------
	$t     = microtime( true );
	$r     = bk6_req( 'POST', '/cards/' . $post . '/suggest', [ 'kind' => 'titles' ] );
	$secs  = round( microtime( true ) - $t, 1 );
	$s     = $r->get_data()['suggestions'] ?? [];
	check( 'titles → 200 with 1–5 plain-text suggestions', $r->get_status() === 200 && count( $s ) >= 1 && count( $s ) <= 5 && $s === array_map( 'wp_strip_all_tags', $s ), $secs . 's: ' . wp_json_encode( $s ) );
	check( '…and the post is unchanged (suggestions are never saved)', get_post( $post )->post_title === 'Composting at home' );
	$r = bk6_req( 'POST', '/cards/' . $post . '/suggest', [ 'kind' => 'excerpt' ] );
	$s = $r->get_data()['suggestions'] ?? [];
	check( 'excerpt → one suggestion', $r->get_status() === 200 && count( $s ) === 1, wp_json_encode( $s ) );
	check( '…remaining counts down', ( $r->get_data()['remaining'] ?? 0 ) === Assistant::DEFAULT_CAP - 2 );

	// ---- refusals that never call the AI ---------------------------------------------------------
	check( 'unknown kind → 400', bk6_req( 'POST', '/cards/' . $post . '/suggest', [ 'kind' => 'essay' ] )->get_status() === 400 );
	wp_set_current_user( $contrib );
	check( 'contributor on someone else\'s post → 403', bk6_req( 'POST', '/cards/' . $post . '/suggest', [ 'kind' => 'titles' ] )->get_status() === 403 );
	wp_set_current_user( 1 );
	$cap = static fn() => 2;
	add_filter( 'boardkit/ai_hourly_limit', $cap );
	$lim = bk6_req( 'POST', '/cards/' . $post . '/suggest', [ 'kind' => 'titles' ] );
	check( 'over the hourly limit → 429', $lim->get_status() === 429, (string) $lim->get_status() );
	remove_filter( 'boardkit/ai_hourly_limit', $cap );
	add_filter( 'wp_supports_ai', '__return_false' );
	Assistant::reset();
	check( 'AI switched off on the site → 501, and no AI section offered', bk6_req( 'POST', '/cards/' . $post . '/suggest', [ 'kind' => 'titles' ] )->get_status() === 501 && bk6_req( 'GET', '/cards/' . $post )->get_data()['details']['ai'] === null );
	remove_filter( 'wp_supports_ai', '__return_false' );
	Assistant::reset();

	// ---- outline appended, never replacing --------------------------------------------------------
	$mod = get_post( $post )->post_modified_gmt;
	$o   = bk6_req( 'POST', '/cards/' . $post . '/details', [ 'append_outline' => [ 'Why compost — Less waste, better soil.', 'Getting <b>started</b> — Pick a bin.' ], 'modified' => $mod ] );
	$c   = get_post( $post )->post_content;
	check( 'add outline → 200', $o->get_status() === 200, (string) $o->get_status() );
	check( '…the original text is still first', str_starts_with( $c, $content ) );
	check( '…two heading blocks appended, model text escaped', substr_count( $c, '<!-- wp:heading -->' ) === 2 && str_contains( $c, 'Getting &lt;b&gt;started&lt;/b&gt;' ) && count( parse_blocks( $c ) ) >= 5 );
	check( '…still a draft', get_post_status( $post ) === 'draft' );

	// ---- meta description ------------------------------------------------------------------------
	$m = bk6_req( 'POST', '/cards/' . $post . '/details', [ 'meta_description' => 'x', 'modified' => get_post( $post )->post_modified_gmt ] );
	check( 'meta description without an SEO plugin → 400', $m->get_status() === 400 );
	$seo = static fn() => [ 'key' => '_bk6_metadesc', 'plugin' => 'Test SEO' ];
	add_filter( 'boardkit/seo_meta_target', $seo );
	$m = bk6_req( 'POST', '/cards/' . $post . '/details', [ 'meta_description' => 'Turn scraps into soil <em>fast</em>.', 'modified' => get_post( $post )->post_modified_gmt ] );
	check( 'with an SEO plugin → saved to its field as plain text', $m->get_status() === 200 && get_post_meta( $post, '_bk6_metadesc', true ) === 'Turn scraps into soil fast.', (string) get_post_meta( $post, '_bk6_metadesc', true ) );
	check( '…and the panel reads it back', ( $m->get_data()['details']['seo']['description'] ?? '' ) === 'Turn scraps into soil fast.' );
	remove_filter( 'boardkit/seo_meta_target', $seo );
} finally {
	wp_set_current_user( 1 );
	delete_transient( 'bk_ai_calls_1' );
	foreach ( $created['posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $created['users'] as $id ) {
		delete_transient( 'bk_ai_calls_' . $id );
		wp_delete_user( $id );
	}
	$t = get_term_by( 'slug', 'bk6-pitch', StageTaxonomy::TAXONOMY );
	if ( $t ) {
		wp_delete_term( $t->term_id, StageTaxonomy::TAXONOMY );
	}
	$repo->delete( 'bk_smoke6' );
}

echo $pass ? "\nALL PASS\n" : "\nSOME FAILED\n";
