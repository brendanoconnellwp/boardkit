<?php
/**
 * Plugin Name:       BoardKit
 * Description:       Kanban board and editorial calendar for WordPress content. Companion to ColumnKit.
 * Version:           0.6.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Brendan
 * License:           GPL-2.0-or-later
 * Text Domain:       boardkit
 * Domain Path:       /languages
 * Update URI:        https://github.com/brendanoconnellwp/boardkit
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BK_VERSION', '0.6.0' );
define( 'BK_FILE', __FILE__ );
define( 'BK_DIR', plugin_dir_path( __FILE__ ) );
define( 'BK_URL', plugin_dir_url( __FILE__ ) );
define( 'BK_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'BoardKit\\';
	if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$path     = BK_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $path ) ) {
		require_once $path;
	}
} );

register_activation_hook( __FILE__, static function (): void {
	add_option( 'bk_version', BK_VERSION, '', 'no' );
} );

// ColumnKit integration (spec §3.2). Registered at FILE SCOPE, not inside a plugins_loaded
// callback: ColumnKit fires `columnkit/loaded` from its own plugins_loaded (priority 10) boot,
// and plugin files load in alphabetical order, so a listener added later could miss the event.
// If the hook never fires (ColumnKit missing, too old, or inactive) BoardKit stays standalone.
add_action(
	'columnkit/loaded',
	static fn( int $api_version ): bool => $api_version >= 1 && \BoardKit\Integrations\ColumnKit::enable()
);

add_action( 'plugins_loaded', static function (): void {
	load_plugin_textdomain(
		'boardkit',
		false,
		dirname( BK_BASENAME ) . '/languages'
	);
	\BoardKit\Plugin::instance()->boot();
} );
