<?php
declare( strict_types=1 );

namespace BoardKit\Tests\Unit;

use Brain\Monkey\Functions;

/** Shared Brain Monkey stubs for the unit suite. Call after Monkey\setUp(). */
final class WpStubs {
	/** @var array<string, mixed> */
	public static array $options = [];
	/** @var array<string, bool|null> */
	public static array $autoload = [];

	public static function options(): void {
		self::$options  = [];
		self::$autoload = [];
		Functions\when( 'get_option' )->alias( static fn( $name, $default = false ) => array_key_exists( $name, self::$options ) ? self::$options[ $name ] : $default );
		Functions\when( 'update_option' )->alias( static function ( $name, $value, $autoload = null ) {
			self::$options[ $name ]  = $value;
			self::$autoload[ $name ] = $autoload;
			return true;
		} );
		Functions\when( 'delete_option' )->alias( static function ( $name ) {
			unset( self::$options[ $name ] );
			return true;
		} );
	}

	public static function sanitizers(): void {
		Functions\stubs( [
			'__'                  => static fn( $s ) => $s,
			'sanitize_key'        => static fn( $k ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ),
			'sanitize_text_field' => static fn( $s ) => trim( strip_tags( (string) $s ) ),
			'sanitize_hex_color'  => static fn( $c ) => preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $c ) ? $c : null,
		] );
	}

	/**
	 * get_post_type_object() returning core's default mapped caps for 'post', or a custom
	 * capability_type ('article' → edit_articles …) to prove caps are never hard-coded.
	 */
	public static function post_type( string $capability_type = 'post' ): void {
		$plural = $capability_type . 's';
		$cap    = (object) [
			'edit_posts'          => "edit_{$plural}",
			'edit_others_posts'   => "edit_others_{$plural}",
			'publish_posts'       => "publish_{$plural}",
			'edit_published_posts' => "edit_published_{$plural}",
		];
		Functions\when( 'get_post_type_object' )->justReturn( (object) [ 'cap' => $cap, 'labels' => (object) [ 'name' => 'Posts' ] ] );
	}

	public static function statuses(): void {
		Functions\when( 'get_post_stati' )->justReturn( [ 'publish' => 'publish', 'future' => 'future', 'draft' => 'draft', 'pending' => 'pending', 'private' => 'private' ] );
	}
}
