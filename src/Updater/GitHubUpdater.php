<?php
declare( strict_types=1 );

namespace BoardKit\Updater;

/**
 * Self-hosted plugin updates from GitHub Releases.
 *
 * Uses WP core's `Update URI` mechanism (WP 5.8+): wp_update_plugins() sees the
 * `Update URI: https://github.com/...` plugin header and fires the
 * `update_plugins_github.com` filter, letting us answer "is there a newer version?"
 * ourselves. When we return a version + package URL, WP shows the native
 * "update available" row and one-click installs the zip — no wp.org listing needed.
 *
 * Release contract (matches .github/workflows/release.yml):
 *   - Tag v{X.Y.Z} → GitHub release with asset boardkit-{X.Y.Z}.zip
 *   - The zip contains a single boardkit/ folder, so in-place upgrades keep the
 *     plugin directory name stable.
 *
 * Private-repo support (token optional):
 *   - Public repo: no config needed; the asset's browser_download_url works anywhere.
 *   - Private repo: define BK_GITHUB_TOKEN in wp-config.php (one fine-grained
 *     read-only PAT, reusable across sites). We then talk to the releases API with
 *     an Authorization header, and use the asset *API* URL as the package (the
 *     browser URL 404s for private repos). `http_request_args` injects the auth +
 *     octet-stream headers on that download. NOTE: private-repo downloads need
 *     WP 6.2+ — earlier Requests versions forwarded the Authorization header to
 *     GitHub's S3 redirect, which S3 rejects.
 *
 * Failure posture: API errors are cached briefly (no hammering GitHub from wp-cron),
 * and we return "no update" — never a broken update offer.
 *
 * Dev-checkout guard (BoardKit addition): when the plugin folder is a git checkout
 * (BK_DIR/.git exists — a directory, or a file for worktrees), no update is ever
 * offered. One-click updating a linked dev checkout would replace the working tree
 * with a release zip and wipe uncommitted work. Override with the
 * `boardkit/updater_skip_dev_checkout` filter (return false) to test updates locally.
 */
final class GitHubUpdater {
	public const REPO      = 'brendanoconnellwp/boardkit';
	public const HOSTNAME  = 'github.com'; // must match the Update URI host in boardkit.php
	private const API_LATEST = 'https://api.github.com/repos/%s/releases/latest';
	private const ASSET_API  = 'https://api.github.com/repos/%s/releases/assets/';
	private const TRANSIENT  = 'bk_github_update';

	public const SLUG         = 'boardkit';
	public const CHECK_ACTION = 'bk_check_updates';
	public const CHECK_NONCE  = 'bk_check_updates';

	private const CACHE_OK_TTL   = 6 * HOUR_IN_SECONDS;
	private const CACHE_FAIL_TTL = HOUR_IN_SECONDS;

	private string $token;

	/** Plugin root used for the dev-checkout guard. Injectable for tests. */
	private string $plugin_dir;

	public function __construct( ?string $token = null, ?string $plugin_dir = null ) {
		$this->token      = $token ?? ( defined( 'BK_GITHUB_TOKEN' ) && is_string( BK_GITHUB_TOKEN ) ? BK_GITHUB_TOKEN : '' );
		$this->plugin_dir = $plugin_dir ?? ( defined( 'BK_DIR' ) ? (string) BK_DIR : '' );
	}

	/**
	 * True when this install is a git checkout and updates should be suppressed.
	 * file_exists (not is_dir) so git worktrees, where .git is a file, count too.
	 */
	public function is_dev_checkout(): bool {
		if ( $this->plugin_dir === '' ) {
			return false;
		}
		$is_checkout = file_exists( rtrim( $this->plugin_dir, '/\\' ) . '/.git' );
		return $is_checkout && (bool) apply_filters( 'boardkit/updater_skip_dev_checkout', true );
	}

	public function register_hooks(): void {
		// Registered unconditionally — update checks run from wp-cron and wp-admin both.
		add_filter( 'update_plugins_' . self::HOSTNAME, [ $this, 'check_update' ], 10, 3 );
		add_filter( 'http_request_args', [ $this, 'authorize_download' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'flush_cache' ], 10, 2 );
		add_filter( 'plugins_api', [ $this, 'plugin_information' ], 20, 3 );
		add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );
		add_action( 'admin_post_' . self::CHECK_ACTION, [ $this, 'handle_check_now' ] );
		add_action( 'admin_notices', [ $this, 'render_checked_notice' ] );
		// WP's "Check again" (Dashboard → Updates, force-check) deletes core's update_plugins
		// site transient; mirror that so a forced check reaches GitHub instead of serving our
		// cached (possibly failed) lookup for up to 6 more hours.
		add_action( 'delete_site_transient_update_plugins', [ $this, 'flush_transient' ] );
	}

	/** Drop the cached release lookup so the next check hits the GitHub API fresh. */
	public function flush_transient(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * `update_plugins_github.com` callback. Fires for EVERY plugin whose Update URI
	 * host is github.com, so the basename gate matters — never answer for someone
	 * else's plugin.
	 *
	 * @param array|false          $update      Existing answer from another filter (usually false).
	 * @param array<string, mixed> $plugin_data Parsed plugin headers.
	 * @param string               $plugin_file Plugin basename, e.g. "boardkit/boardkit.php".
	 * @return array|false
	 */
	public function check_update( $update, array $plugin_data, string $plugin_file ) {
		if ( $plugin_file !== BK_BASENAME ) {
			return $update;
		}
		if ( $this->is_dev_checkout() ) {
			return $update; // Never offer a zip over a git working tree.
		}

		$release = $this->get_latest_release();
		if ( $release === null ) {
			return $update;
		}

		$new_version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
		$installed   = (string) ( $plugin_data['Version'] ?? '0' );
		if ( $new_version === '' || version_compare( $new_version, $installed, '<=' ) ) {
			return $update;
		}

		$package = $this->pick_package( $release, $new_version );
		if ( $package === '' ) {
			return $update; // Release exists but has no usable zip — offer nothing.
		}

		return [
			'id'      => self::HOSTNAME . '/' . self::REPO,
			'slug'    => self::SLUG,
			'plugin'  => $plugin_file,
			'version' => $new_version,
			'url'     => (string) ( $release['html_url'] ?? 'https://github.com/' . self::REPO ),
			'package' => $package,
		];
	}

	/**
	 * Choose the download URL for the release zip.
	 *
	 * Only the asset named exactly boardkit-{version}.zip (what the release workflow
	 * builds) is accepted — no "first .zip" fallback, so a hand-uploaded zip can't
	 * sidestep CI. With a token (private repo) the asset API URL is used — the browser
	 * URL is not accessible for private repos. Otherwise the browser URL must point at
	 * this repo's own release downloads.
	 *
	 * @param array<string, mixed> $release
	 */
	private function pick_package( array $release, string $version ): string {
		$assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : [];
		$chosen = null;
		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && (string) ( $asset['name'] ?? '' ) === 'boardkit-' . $version . '.zip' ) {
				$chosen = $asset;
				break;
			}
		}
		if ( $chosen === null ) {
			return '';
		}
		if ( $this->token !== '' ) {
			$id = isset( $chosen['id'] ) && is_scalar( $chosen['id'] ) ? (string) (int) $chosen['id'] : '';
			return $id !== '' ? sprintf( self::ASSET_API, self::REPO ) . $id : '';
		}
		$url = (string) ( $chosen['browser_download_url'] ?? '' );
		return str_starts_with( $url, 'https://github.com/' . self::REPO . '/releases/download/' ) ? $url : '';
	}

	/**
	 * Latest release from the GitHub API, cached in a transient. Null on any failure
	 * (and the failure itself is cached briefly so twice-daily cron + admin page loads
	 * can't hammer a rate-limited or unreachable API).
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_latest_release(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return empty( $cached['bk_error'] ) ? $cached : null;
		}

		$headers = [
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		];
		if ( $this->token !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		$response = wp_remote_get(
			sprintf( self::API_LATEST, self::REPO ),
			[ 'headers' => $headers, 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( self::TRANSIENT, [ 'bk_error' => true ], self::CACHE_FAIL_TTL );
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		// Only plain vX.Y.Z tags are releases; anything else is ignored rather than offered.
		if ( ! is_array( $body ) || ! is_string( $body['tag_name'] ?? null )
			|| ! preg_match( '/^v\d+\.\d+\.\d+$/', $body['tag_name'] ) ) {
			set_transient( self::TRANSIENT, [ 'bk_error' => true ], self::CACHE_FAIL_TTL );
			return null;
		}

		// Cache only the fields we use — the full release payload is bulky.
		$slim = [
			'tag_name'     => (string) $body['tag_name'],
			'html_url'     => (string) ( $body['html_url'] ?? '' ),
			'body'         => is_string( $body['body'] ?? null ) ? $body['body'] : '',
			'published_at' => is_string( $body['published_at'] ?? null ) ? $body['published_at'] : '',
			'assets'       => [],
		];
		foreach ( (array) ( $body['assets'] ?? [] ) as $asset ) {
			if ( is_array( $asset ) ) {
				$slim['assets'][] = [
					'id'                   => (int) ( $asset['id'] ?? 0 ),
					'name'                 => (string) ( $asset['name'] ?? '' ),
					'browser_download_url' => (string) ( $asset['browser_download_url'] ?? '' ),
				];
			}
		}
		set_transient( self::TRANSIENT, $slim, (int) apply_filters( 'boardkit/update_cache_ttl', self::CACHE_OK_TTL ) );
		return $slim;
	}

	/**
	 * `http_request_args` — add auth headers ONLY on downloads of OUR release assets
	 * (private-repo mode). The prefix match is strict so the token never rides along
	 * on any other request WP makes.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function authorize_download( array $args, string $url ): array {
		if ( $this->token === '' || ! str_starts_with( $url, sprintf( self::ASSET_API, self::REPO ) ) ) {
			return $args;
		}
		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = [];
		}
		$args['headers']['Authorization'] = 'Bearer ' . $this->token;
		$args['headers']['Accept']        = 'application/octet-stream';
		return $args;
	}

	/**
	 * After any plugin install/update completes, drop the cache so the row clears
	 * immediately instead of showing a stale "update available" for hours.
	 *
	 * @param mixed                $upgrader
	 * @param array<string, mixed> $options
	 */
	public function flush_cache( $upgrader, array $options ): void {
		if ( ( $options['type'] ?? '' ) === 'plugin' ) {
			delete_transient( self::TRANSIENT );
		}
	}

	/**
	 * `plugins_api` — powers the "View details" / "View version x.y.z details" thickbox on the
	 * Plugins and Updates screens. Without it WP asks wp.org about slug "boardkit" and shows
	 * "Plugin not found". Answers only for our slug + the plugin_information action.
	 *
	 * @param false|object|array $result
	 * @param object|array       $args
	 * @return false|object|array
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}
		$slug = is_object( $args ) ? ( $args->slug ?? '' ) : ( is_array( $args ) ? ( $args['slug'] ?? '' ) : '' );
		if ( $slug !== self::SLUG ) {
			return $result;
		}

		$release = $this->get_latest_release();
		$version = $release !== null ? ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' ) : '';
		if ( $version === '' ) {
			$version = defined( 'BK_VERSION' ) ? (string) BK_VERSION : '';
		}
		$notes = $release !== null ? (string) ( $release['body'] ?? '' ) : '';

		$info                = new \stdClass();
		$info->name          = 'BoardKit';
		$info->slug          = self::SLUG;
		$info->version       = $version;
		$info->author        = '<a href="https://github.com/brendanoconnellwp">Brendan</a>';
		$info->homepage      = 'https://github.com/' . self::REPO;
		$info->download_link = $release !== null ? $this->pick_package( $release, $version ) : '';
		$info->requires      = '6.0';
		$info->requires_php  = '8.0';
		$info->last_updated  = $release !== null ? (string) ( $release['published_at'] ?? '' ) : '';
		$info->sections      = [
			'description' => '<p>' . esc_html( $this->short_description() ) . '</p>',
			'changelog'   => $notes !== ''
				? self::markdown_to_html( $notes )
				: '<p>' . esc_html__( 'See the GitHub releases page for release notes.', 'boardkit' ) . '</p>',
		];
		return $info;
	}

	/** Short description from readme.txt (first non-header line after the === title ===). */
	private function short_description(): string {
		$fallback = 'A Kanban board and editorial calendar for WordPress content.';
		$file     = defined( 'BK_DIR' ) ? BK_DIR . 'readme.txt' : '';
		if ( $file === '' || ! is_readable( $file ) ) {
			return $fallback;
		}
		$lines     = preg_split( '/\R/', (string) file_get_contents( $file ) ) ?: [];
		$in_header = false;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( str_starts_with( $line, '===' ) ) {
				$in_header = true;
				continue;
			}
			if ( ! $in_header || $line === '' || preg_match( '/^[A-Za-z ]+:\s/', $line ) === 1 ) {
				continue; // Before the title, blank, or a "Key: value" header line.
			}
			if ( str_starts_with( $line, '==' ) ) {
				break;
			}
			return $line;
		}
		return $fallback;
	}

	/**
	 * Minimal, safe Markdown → HTML for GitHub release notes. Every line is HTML-escaped FIRST,
	 * so markup in a release body can never reach wp-admin as live HTML; only the constructs we
	 * emit ourselves (h3/h4, ul/li, p, strong, code, http(s) links) appear in the output.
	 */
	public static function markdown_to_html( string $md ): string {
		$out     = [];
		$in_list = false;
		foreach ( preg_split( '/\R/', $md ) ?: [] as $raw ) {
			$line = rtrim( $raw );
			if ( preg_match( '/^\s*[-*+]\s+(.*)$/', $line, $m ) === 1 ) {
				if ( ! $in_list ) {
					$out[]   = '<ul>';
					$in_list = true;
				}
				$out[] = '<li>' . self::inline_markdown( $m[1] ) . '</li>';
				continue;
			}
			if ( $in_list ) {
				$out[]   = '</ul>';
				$in_list = false;
			}
			if ( trim( $line ) === '' ) {
				continue;
			}
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) === 1 ) {
				// The details thickbox reads best with h3/h4 whatever the source depth.
				$level = strlen( $m[1] ) <= 2 ? 3 : 4;
				$out[] = "<h{$level}>" . self::inline_markdown( $m[2] ) . "</h{$level}>";
				continue;
			}
			$out[] = '<p>' . self::inline_markdown( $line ) . '</p>';
		}
		if ( $in_list ) {
			$out[] = '</ul>';
		}
		return implode( "\n", $out );
	}

	private static function inline_markdown( string $text ): string {
		$text = esc_html( $text );
		// [label](https://…) — http(s) targets only; anything else stays literal text.
		$text = (string) preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			static fn( array $m ): string => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( html_entity_decode( $m[2], ENT_QUOTES ) ),
				$m[1]
			),
			$text
		);
		$text = (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		return (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
	}

	/**
	 * "Check for updates" link on our row of the Plugins screen.
	 *
	 * @param array<int|string, string> $links
	 * @return array<int|string, string>
	 */
	public function row_meta( array $links, $file ): array {
		if ( $file !== BK_BASENAME || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		$url     = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::CHECK_ACTION ), self::CHECK_NONCE );
		$links[] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Check for updates', 'boardkit' ) );
		return $links;
	}

	/** admin-post handler: bust both caches, re-run the update check, bounce back to Plugins. */
	public function handle_check_now(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'boardkit' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::CHECK_NONCE );

		delete_transient( self::TRANSIENT );
		delete_site_transient( 'update_plugins' );
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . WPINC . '/update.php';
		}
		wp_update_plugins();

		wp_safe_redirect( add_query_arg( 'bk_update_checked', '1', self_admin_url( 'plugins.php' ) ) );
		exit;
	}

	/** Confirmation after a manual check. Fixed text + cached version only — no request data echoed. */
	public function render_checked_notice(): void {
		global $pagenow;
		if ( $pagenow !== 'plugins.php' || ! isset( $_GET['bk_update_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$release = $this->get_latest_release();
		$latest  = $release !== null ? ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' ) : '';
		$msg     = $latest !== ''
			/* translators: %s: latest released version */
			? sprintf( __( 'BoardKit: checked GitHub — the latest release is %s.', 'boardkit' ), $latest )
			: __( 'BoardKit: could not reach GitHub to check for updates. Try again later.', 'boardkit' );
		printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $msg ) );
	}
}
