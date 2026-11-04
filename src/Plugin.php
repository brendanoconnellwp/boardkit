<?php
declare( strict_types=1 );

namespace BoardKit;

use BoardKit\Updater\GitHubUpdater;

/**
 * Plugin root. Owns service construction and hook registration.
 *
 * Phase 1 registers only the self-updater; later phases add the board, REST and admin services
 * here (see PLAN.md §2).
 */
final class Plugin {
	private static ?self $instance = null;

	private bool $booted = false;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return; // Idempotent: a second boot() must not double-register hooks.
		}
		$this->booted = true;

		// Self-updates from GitHub releases. Not is_admin()-gated: wp_update_plugins()
		// runs from wp-cron too, and the filter must be present there.
		( new GitHubUpdater() )->register_hooks();
	}

	public function is_booted(): bool {
		return $this->booted;
	}
}
