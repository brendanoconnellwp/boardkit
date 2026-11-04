<?php
/**
 * Phase 1 smoke test — plugin skeleton.
 * Run with (LocalWP site "ai-experiments", BoardKit linked into wp-content/plugins):
 *   wp eval-file wp-content/plugins/boardkit/tests/smoke/phase1-skeleton.php
 *
 * Checks the plugin is active and booted, constants are sane, the GitHub updater is hooked,
 * the dev-checkout guard suppresses updates on this linked git checkout, and the ColumnKit
 * listener is registered.
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

use BoardKit\Updater\GitHubUpdater;

// --- 1. Active + booted -------------------------------------------------------
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
check( 'plugin is active', is_plugin_active( 'boardkit/boardkit.php' ) );
check( 'Plugin booted', class_exists( \BoardKit\Plugin::class ) && \BoardKit\Plugin::instance()->is_booted() );

// --- 2. Constants -------------------------------------------------------------
check( 'BK_VERSION defined', defined( 'BK_VERSION' ) && preg_match( '/^\d+\.\d+\.\d+$/', BK_VERSION ) === 1, defined( 'BK_VERSION' ) ? BK_VERSION : 'undefined' );
check( 'BK_BASENAME', defined( 'BK_BASENAME' ) && BK_BASENAME === 'boardkit/boardkit.php', defined( 'BK_BASENAME' ) ? BK_BASENAME : '' );
check( 'BK_DIR points at plugin', defined( 'BK_DIR' ) && is_file( BK_DIR . 'boardkit.php' ) );
$header = get_file_data( BK_FILE, [ 'Version' => 'Version', 'UpdateURI' => 'Update URI' ] );
check( 'header Version matches BK_VERSION', $header['Version'] === BK_VERSION, $header['Version'] );
check( 'Update URI is the boardkit repo', $header['UpdateURI'] === 'https://github.com/brendanoconnellwp/boardkit' );
// wp_load_alloptions() holds only autoloaded options, so an autoload-off option must be absent.
check( 'bk_version option stored with autoload off', get_option( 'bk_version' ) !== false
	&& ! array_key_exists( 'bk_version', wp_load_alloptions() ), 'activation hook' );

// --- 3. Updater hooked --------------------------------------------------------
check( 'update_plugins_github.com filter registered', has_filter( 'update_plugins_github.com' ) !== false );
check( 'check-now admin-post action registered', has_action( 'admin_post_' . GitHubUpdater::CHECK_ACTION ) !== false );

// --- 4. Dev-checkout guard ------------------------------------------------------
$updater  = new GitHubUpdater();
$is_git   = file_exists( BK_DIR . '.git' );
check( 'guard detects this install', $updater->is_dev_checkout() === $is_git, $is_git ? 'git checkout' : 'release install' );
if ( $is_git ) {
	$http_before = did_action( 'http_api_debug' );
	$result      = $updater->check_update( false, [ 'Version' => '0.0.1' ], BK_BASENAME );
	check( 'no update offered on a git checkout', $result === false );
	check( 'no GitHub request made by guarded check', did_action( 'http_api_debug' ) === $http_before );
}

// Foreign plugins pass straight through.
check( 'foreign plugin passthrough', $updater->check_update( 'sentinel', [ 'Version' => '1.0' ], 'other/other.php' ) === 'sentinel' );

// --- 5. ColumnKit listener ------------------------------------------------------
check( 'columnkit/loaded listener registered', has_action( 'columnkit/loaded' ) !== false );
$ck_api = class_exists( '\ColumnKit\Api' );
check(
	'ColumnKit adapter state matches Api presence',
	\BoardKit\Integrations\ColumnKit::is_enabled() === $ck_api,
	$ck_api ? 'ColumnKit Api present' : 'standalone (no ColumnKit\\Api yet — expected before ColumnKit 0.8.0)'
);

echo $pass ? "\nALL PASS\n" : "\nSOME CHECKS FAILED\n";
