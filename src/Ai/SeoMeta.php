<?php
declare( strict_types=1 );

namespace BoardKit\Ai;

/**
 * Where a post's meta description lives (PLAN Phase 6). WordPress has no field for it: each SEO
 * plugin keeps its own post meta. With none active there's no meta field, and the AI's meta
 * description suggestion is offered for the excerpt instead (many themes and tools use it).
 */
final class SeoMeta {
	public const MAX = 320;

	/** @return array{key: string, plugin: string}|null the active SEO plugin's meta-description key */
	public static function target(): ?array {
		$found = null;
		if ( defined( 'WPSEO_VERSION' ) ) {
			$found = [ 'key' => '_yoast_wpseo_metadesc', 'plugin' => 'Yoast SEO' ];
		} elseif ( defined( 'RANK_MATH_VERSION' ) ) {
			$found = [ 'key' => 'rank_math_description', 'plugin' => 'Rank Math' ];
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$found = [ 'key' => '_seopress_titles_desc', 'plugin' => 'SEOPress' ];
		}
		/**
		 * Filters where BoardKit reads and writes a post's meta description.
		 *
		 * @param array{key: string, plugin: string}|null $found null = no SEO plugin, use the excerpt.
		 */
		$found = apply_filters( 'boardkit/seo_meta_target', $found );
		return is_array( $found ) && isset( $found['key'], $found['plugin'] ) && $found['key'] !== '' ? $found : null;
	}

	public static function read( int $post_id ): string {
		$t = self::target();
		return $t ? (string) get_post_meta( $post_id, $t['key'], true ) : '';
	}

	/** Plain text only; '' deletes the row. Caller has checked edit_post. */
	public static function write( int $post_id, string $value ): bool {
		$t = self::target();
		if ( ! $t ) {
			return false;
		}
		$value = trim( sanitize_textarea_field( $value ) );
		if ( $value === '' ) {
			delete_post_meta( $post_id, $t['key'] );
			return true;
		}
		update_post_meta( $post_id, $t['key'], wp_slash( $value ) );
		return true;
	}
}
