<?php
declare( strict_types=1 );

namespace BoardKit\Tests\Unit\Updater;

use Brain\Monkey;
use Brain\Monkey\Functions;
use BoardKit\Updater\GitHubUpdater;
use PHPUnit\Framework\TestCase;

final class GitHubUpdaterTest extends TestCase {
	/** @var array<string, mixed> in-memory transient store */
	private array $transients = [];

	private int $http_calls = 0;

	/** @var array<string, mixed>|\WP_Error|null next wp_remote_get response */
	private $next_response = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients    = [];
		$this->http_calls    = 0;
		$this->next_response = null;

		Functions\when( 'get_transient' )->alias( fn( $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias( function ( $k, $v, $ttl = 0 ) {
			$this->transients[ $k ] = $v;
			return true;
		} );
		Functions\when( 'delete_transient' )->alias( function ( $k ) {
			unset( $this->transients[ $k ] );
			return true;
		} );
		Functions\when( 'wp_remote_get' )->alias( function () {
			$this->http_calls++;
			return $this->next_response;
		} );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v === null );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['code'] ?? 0 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] ?? '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** A realistic /releases/latest payload with two assets (zip + stray file). */
	private function release_response( string $tag ): array {
		$version = ltrim( $tag, 'v' );
		return [
			'code' => 200,
			'body' => (string) json_encode( [
				'tag_name' => $tag,
				'html_url' => 'https://github.com/brendanoconnellwp/boardkit/releases/tag/' . $tag,
				'assets'   => [
					[ 'id' => 11, 'name' => 'notes.txt', 'browser_download_url' => 'https://example.com/notes.txt' ],
					[ 'id' => 42, 'name' => "boardkit-{$version}.zip", 'browser_download_url' => "https://github.com/brendanoconnellwp/boardkit/releases/download/{$tag}/boardkit-{$version}.zip" ],
				],
			] ),
		];
	}

	public function test_other_plugins_pass_through_without_http(): void {
		$updater = new GitHubUpdater( '' );
		$result  = $updater->check_update( false, [ 'Version' => '1.0.0' ], 'other-plugin/other.php' );
		$this->assertFalse( $result );
		$this->assertSame( 0, $this->http_calls );
	}

	public function test_no_update_when_release_is_not_newer(): void {
		$this->next_response = $this->release_response( 'v0.6.0' );
		$updater = new GitHubUpdater( '' );
		$this->assertFalse( $updater->check_update( false, [ 'Version' => '0.6.0' ], BK_BASENAME ) );
		$this->assertFalse( $updater->check_update( false, [ 'Version' => '0.7.0' ], BK_BASENAME ) );
	}

	public function test_offers_update_with_browser_url_when_no_token(): void {
		$this->next_response = $this->release_response( 'v0.6.0' );
		$updater = new GitHubUpdater( '' );
		$update  = $updater->check_update( false, [ 'Version' => '0.5.3' ], BK_BASENAME );

		$this->assertIsArray( $update );
		$this->assertSame( '0.6.0', $update['version'] );
		$this->assertSame( BK_BASENAME, $update['plugin'] );
		$this->assertSame( 'boardkit', $update['slug'] );
		// Picks the boardkit-{version}.zip asset, not notes.txt.
		$this->assertStringEndsWith( 'boardkit-0.6.0.zip', $update['package'] );
		$this->assertStringContainsString( 'releases/download', $update['package'] );
	}

	/** @param array<int, array<string, mixed>> $assets */
	private function release_with_assets( string $tag, array $assets ): array {
		return [ 'code' => 200, 'body' => (string) json_encode( [ 'tag_name' => $tag, 'assets' => $assets ] ) ];
	}

	public function test_no_fallback_to_a_differently_named_zip(): void {
		// A hand-uploaded zip that isn't the CI-built boardkit-{version}.zip is never offered.
		$this->next_response = $this->release_with_assets( 'v0.8.0', [
			[ 'id' => 1, 'name' => 'boardkit.zip', 'browser_download_url' => 'https://github.com/brendanoconnellwp/boardkit/releases/download/v0.8.0/boardkit.zip' ],
		] );
		$this->assertFalse( ( new GitHubUpdater( '' ) )->check_update( false, [ 'Version' => '0.7.0' ], BK_BASENAME ) );
	}

	public function test_download_url_must_be_this_repos_release_download(): void {
		$this->next_response = $this->release_with_assets( 'v0.8.0', [
			[ 'id' => 1, 'name' => 'boardkit-0.8.0.zip', 'browser_download_url' => 'https://evil.example/boardkit-0.8.0.zip' ],
		] );
		$this->assertFalse( ( new GitHubUpdater( '' ) )->check_update( false, [ 'Version' => '0.7.0' ], BK_BASENAME ) );
	}

	public function test_non_semver_tags_are_ignored(): void {
		foreach ( [ 'v99', 'latest', 'v1.0.0-beta', '1.0.0' ] as $tag ) {
			$this->transients    = [];
			$this->next_response = $this->release_with_assets( $tag, [] );
			$this->assertFalse( ( new GitHubUpdater( '' ) )->check_update( false, [ 'Version' => '0.7.0' ], BK_BASENAME ), $tag );
		}
	}

	public function test_offers_asset_api_url_when_token_set(): void {
		$this->next_response = $this->release_response( 'v0.6.0' );
		$updater = new GitHubUpdater( 'ghp_test_token' );
		$update  = $updater->check_update( false, [ 'Version' => '0.5.3' ], BK_BASENAME );

		$this->assertIsArray( $update );
		$this->assertSame(
			'https://api.github.com/repos/brendanoconnellwp/boardkit/releases/assets/42',
			$update['package']
		);
	}

	public function test_authorize_download_scopes_headers_to_our_assets(): void {
		$updater = new GitHubUpdater( 'ghp_test_token' );

		$ours = $updater->authorize_download( [], 'https://api.github.com/repos/brendanoconnellwp/boardkit/releases/assets/42' );
		$this->assertSame( 'Bearer ghp_test_token', $ours['headers']['Authorization'] );
		$this->assertSame( 'application/octet-stream', $ours['headers']['Accept'] );

		// Any other URL — including other GitHub URLs — is untouched.
		$other = $updater->authorize_download( [ 'headers' => [] ], 'https://api.github.com/repos/someone/else/releases/assets/1' );
		$this->assertArrayNotHasKey( 'Authorization', $other['headers'] );

		// No token → nothing added even for our URL.
		$no_token = ( new GitHubUpdater( '' ) )->authorize_download( [], 'https://api.github.com/repos/brendanoconnellwp/boardkit/releases/assets/42' );
		$this->assertArrayNotHasKey( 'headers', $no_token );
	}

	public function test_api_failure_is_cached_and_offers_nothing(): void {
		$this->next_response = [ 'code' => 403, 'body' => '{"message":"rate limited"}' ];
		$updater = new GitHubUpdater( '' );

		$this->assertFalse( $updater->check_update( false, [ 'Version' => '0.5.3' ], BK_BASENAME ) );
		$this->assertSame( 1, $this->http_calls );

		// Second check hits the cached failure — no new HTTP call.
		$this->assertFalse( $updater->check_update( false, [ 'Version' => '0.5.3' ], BK_BASENAME ) );
		$this->assertSame( 1, $this->http_calls );
	}

	public function test_success_is_cached_across_checks(): void {
		$this->next_response = $this->release_response( 'v0.6.0' );
		$updater = new GitHubUpdater( '' );

		$updater->check_update( false, [ 'Version' => '0.5.3' ], BK_BASENAME );
		$updater->check_update( false, [ 'Version' => '0.5.3' ], BK_BASENAME );
		$this->assertSame( 1, $this->http_calls );
	}

	private function stub_escaping(): void {
		Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_html__' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
	}

	public function test_plugins_api_passes_through_other_slugs_and_actions(): void {
		$updater = new GitHubUpdater( '' );
		$this->assertFalse( $updater->plugin_information( false, 'plugin_information', (object) [ 'slug' => 'akismet' ] ) );
		$this->assertFalse( $updater->plugin_information( false, 'query_plugins', (object) [ 'slug' => 'boardkit' ] ) );
		$this->assertSame( 0, $this->http_calls );
	}

	public function test_plugins_api_returns_details_for_our_slug(): void {
		$this->stub_escaping();
		$response = $this->release_response( 'v0.7.0' );
		$body     = json_decode( $response['body'], true );
		$body['body']         = "## What's new\n- Term columns\n- See [docs](https://github.com/brendanoconnellwp/boardkit)";
		$body['published_at'] = '2026-10-07T00:00:00Z';
		$response['body']     = (string) json_encode( $body );
		$this->next_response  = $response;

		$info = ( new GitHubUpdater( '' ) )->plugin_information( false, 'plugin_information', (object) [ 'slug' => 'boardkit' ] );

		$this->assertIsObject( $info );
		$this->assertSame( 'boardkit', $info->slug );
		$this->assertSame( '0.7.0', $info->version );
		$this->assertSame( '6.0', $info->requires );
		$this->assertSame( '8.0', $info->requires_php );
		$this->assertSame( '2026-10-07T00:00:00Z', $info->last_updated );
		$this->assertStringEndsWith( 'boardkit-0.7.0.zip', $info->download_link );
		$this->assertStringContainsString( '<h3>', $info->sections['changelog'] );
		$this->assertStringContainsString( '<li>Term columns</li>', $info->sections['changelog'] );
		$this->assertStringContainsString( '<a href="https://github.com/brendanoconnellwp/boardkit"', $info->sections['changelog'] );
		$this->assertNotSame( '', $info->sections['description'] );
	}

	public function test_changelog_markdown_escapes_html(): void {
		$this->stub_escaping();
		$html = GitHubUpdater::markdown_to_html( "<script>alert(1)</script>\n- <img src=x onerror=alert(1)>\n[x](javascript:alert(1))" );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		// Non-http(s) link targets are never turned into anchors.
		$this->assertStringNotContainsString( 'href="javascript', $html );
	}

	public function test_flush_transient_clears_cache(): void {
		$this->transients['bk_github_update'] = [ 'bk_error' => true ];
		( new GitHubUpdater( '' ) )->flush_transient();
		$this->assertArrayNotHasKey( 'bk_github_update', $this->transients );
	}

	public function test_flush_cache_on_plugin_upgrade(): void {
		$this->transients['bk_github_update'] = [ 'tag_name' => 'v0.6.0' ];
		$updater = new GitHubUpdater( '' );

		$updater->flush_cache( null, [ 'type' => 'theme' ] );
		$this->assertArrayHasKey( 'bk_github_update', $this->transients );

		$updater->flush_cache( null, [ 'type' => 'plugin' ] );
		$this->assertArrayNotHasKey( 'bk_github_update', $this->transients );
	}

	// --- Dev-checkout guard (BoardKit addition) ------------------------------

	/** Fresh temp plugin dir; $git = 'dir' | 'file' | null controls the .git entry. */
	private function temp_plugin_dir( ?string $git ): string {
		$dir = sys_get_temp_dir() . '/bk-updater-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir );
		if ( $git === 'dir' ) {
			mkdir( $dir . '/.git' );
		} elseif ( $git === 'file' ) {
			file_put_contents( $dir . '/.git', "gitdir: /elsewhere/.git/worktrees/x\n" );
		}
		return $dir;
	}

	public function test_dev_checkout_never_offers_an_update_and_skips_http(): void {
		$this->next_response = $this->release_response( 'v9.0.0' );
		$updater = new GitHubUpdater( '', $this->temp_plugin_dir( 'dir' ) );

		$this->assertTrue( $updater->is_dev_checkout() );
		$this->assertFalse( $updater->check_update( false, [ 'Version' => '0.1.0' ], BK_BASENAME ) );
		$this->assertSame( 0, $this->http_calls );
	}

	public function test_git_worktree_file_counts_as_dev_checkout(): void {
		$updater = new GitHubUpdater( '', $this->temp_plugin_dir( 'file' ) );
		$this->assertTrue( $updater->is_dev_checkout() );
	}

	public function test_release_install_is_not_a_dev_checkout(): void {
		$this->next_response = $this->release_response( 'v9.0.0' );
		$updater = new GitHubUpdater( '', $this->temp_plugin_dir( null ) );

		$this->assertFalse( $updater->is_dev_checkout() );
		$this->assertIsArray( $updater->check_update( false, [ 'Version' => '0.1.0' ], BK_BASENAME ) );
	}

	public function test_dev_checkout_guard_can_be_disabled_by_filter(): void {
		Monkey\Filters\expectApplied( 'boardkit/updater_skip_dev_checkout' )->andReturn( false );
		$this->next_response = $this->release_response( 'v9.0.0' );
		$updater = new GitHubUpdater( '', $this->temp_plugin_dir( 'dir' ) );

		$this->assertFalse( $updater->is_dev_checkout() );
		$this->assertIsArray( $updater->check_update( false, [ 'Version' => '0.1.0' ], BK_BASENAME ) );
	}
}
