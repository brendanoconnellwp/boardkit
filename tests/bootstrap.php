<?php
declare( strict_types=1 );

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "Run `composer install` first.\n" );
	exit( 1 );
}
require $autoload;

// Make plugin constants available to source files that reference them.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'BK_VERSION' ) ) {
	define( 'BK_VERSION', '0.1.0-test' );
}
if ( ! defined( 'BK_FILE' ) ) {
	define( 'BK_FILE', __DIR__ . '/../boardkit.php' );
}
if ( ! defined( 'BK_DIR' ) ) {
	define( 'BK_DIR', __DIR__ . '/fixtures/plugin/' ); // NOT the repo root: that is a git checkout, which trips the updater dev guard.
}
if ( ! defined( 'BK_URL' ) ) {
	define( 'BK_URL', 'http://example.test/wp-content/plugins/boardkit/' );
}
if ( ! defined( 'BK_BASENAME' ) ) {
	define( 'BK_BASENAME', 'boardkit/boardkit.php' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 ); // WP core time constant, used by GitHubUpdater.
}

// Manual PSR-4 autoloader matching the bootstrap, so tests don't need composer dumpautoload.
spl_autoload_register( static function ( string $class ): void {
	foreach ( [
		'BoardKit\\Tests\\' => __DIR__ . '/',
		'BoardKit\\'        => __DIR__ . '/../src/',
	] as $prefix => $base ) {
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) === 0 ) {
			$rel  = substr( $class, strlen( $prefix ) );
			$path = $base . str_replace( '\\', '/', $rel ) . '.php';
			if ( is_file( $path ) ) {
				require_once $path;
			}
			return;
		}
	}
} );

// Minimal global WP_Post stand-in for unit tests that exercise code type-hinting/branching on
// it (e.g. lane sources). Real WP isn't loaded in the unit suite.
if ( ! class_exists( 'WP_Post' ) ) {
	#[\AllowDynamicProperties]
	class WP_Post {
		public int $ID = 0;
		public string $post_title = '';
		public string $post_date = '';
		public string $post_author = '';
	}
}
if ( ! class_exists( 'WP_User' ) ) {
	#[\AllowDynamicProperties]
	class WP_User {
		public int $ID = 0;
		public string $display_name = '';
		public string $user_email = '';
	}
}
if ( ! class_exists( 'WP_Term' ) ) {
	#[\AllowDynamicProperties]
	class WP_Term {
		public int $term_id = 0;
		public string $name = '';
		public string $taxonomy = '';
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private mixed $data = '' ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}
