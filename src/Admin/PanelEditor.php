<?php
declare( strict_types=1 );

namespace BoardKit\Admin;

/**
 * The card panel's Write tab loads the normal post editor in an iframe with `bk_panel=1`
 * (PLAN Phase 5). This class only changes how it LOOKS there: no admin menu, toolbar, footer
 * or "back to Posts" button, so the editor fills the panel. Nothing about saving, permissions
 * or locks changes: it is the real editor.
 *
 * Framing is same-origin, which core's X-Frame-Options: SAMEORIGIN allows.
 */
final class PanelEditor {
	public const FLAG = 'bk_panel';

	public function register_hooks(): void {
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_action( 'admin_head', [ $this, 'styles' ] );
		// Classic editor: a save reloads the page, and the redirect would drop the flag.
		add_filter( 'redirect_post_location', [ $this, 'keep_flag' ] );
	}

	public static function active(): bool {
		global $pagenow;
		return isset( $_GET[ self::FLAG ] ) && $_GET[ self::FLAG ] === '1' && in_array( $pagenow ?? '', [ 'post.php', 'post-new.php' ], true );
	}

	public function body_class( string $classes ): string {
		return self::active() ? $classes . ' bk-in-panel' : $classes;
	}

	public function styles(): void {
		if ( ! self::active() ) {
			return;
		}
		?>
		<style id="boardkit-panel-editor">
			html.wp-toolbar { padding-top: 0 !important; }
			.bk-in-panel #wpadminbar,
			.bk-in-panel #adminmenumain,
			.bk-in-panel #wpfooter,
			.bk-in-panel #screen-meta-links,
			.bk-in-panel .editor-header__back-button,
			.bk-in-panel .edit-post-fullscreen-mode-close,
			.bk-in-panel .editor-fullscreen-mode-close { display: none !important; }
			.bk-in-panel #wpcontent { margin-left: 0 !important; padding-left: 12px; }
			.bk-in-panel .interface-interface-skeleton { left: 0 !important; top: 0 !important; }
		</style>
		<?php
	}

	public function keep_flag( string $location ): string {
		$referer = isset( $_POST['_wp_http_referer'] ) && is_string( $_POST['_wp_http_referer'] ) ? wp_unslash( $_POST['_wp_http_referer'] ) : '';
		if ( $referer !== '' && preg_match( '/[?&]' . self::FLAG . '=1(&|$)/', $referer ) ) {
			return add_query_arg( self::FLAG, '1', $location );
		}
		return $location;
	}
}
