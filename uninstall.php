<?php
/**
 * Uninstall — remove BoardKit's configuration. Editorial data (stage terms, assignees, due dates,
 * activity log) is KEPT unless the site opted in via the `bk_delete_all_data` option (off by
 * default, spec §11): it is content people wrote, not plugin settings.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Remove one site's BoardKit data. Runs per blog on multisite (boards are per site, spec §1).
 */
$bk_uninstall_site = static function () use ( $wpdb ): void {
	// Boards: delete through the bk_boards index — never a broad LIKE 'bk_%' sweep, which could
	// hit other plugins that share the prefix.
	$ids = get_option( 'bk_boards', [] );
	if ( is_array( $ids ) ) {
		foreach ( $ids as $id ) {
			if ( is_string( $id ) && $id !== '' && $id === sanitize_key( $id ) ) {
				delete_option( 'bk_board_' . $id );
			}
		}
	}

	$delete_all = (bool) get_option( 'bk_delete_all_data', false );

	foreach ( [ 'bk_boards', 'bk_version', 'bk_delete_all_data' ] as $option ) {
		delete_option( $option );
	}
	delete_transient( 'bk_github_update' );

	if ( ! $delete_all ) {
		return;
	}

	// Opted in: editorial data too. The taxonomy isn't registered during uninstall (the plugin
	// isn't loaded), so remove terms with direct queries scoped to exactly `bk_stage`.
	$tt_ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'bk_stage' ) );
	if ( $tt_ids ) {
		$in       = implode( ',', array_map( 'intval', $tt_ids ) );
		$term_ids = $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$in})" );
		if ( $term_ids ) {
			$tin = implode( ',', array_map( 'intval', $term_ids ) );
			// Only terms no other taxonomy still uses.
			$wpdb->query( "DELETE t FROM {$wpdb->terms} t LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id IN ({$tin}) AND tt.term_id IS NULL" );
			$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ({$tin})" );
		}
	}

	// Exactly the editorial meta keys BoardKit writes (spec §4.5, §7.4).
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_bk_assignees', '_bk_due', '_bk_log')" );
};

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		$bk_uninstall_site();
		restore_current_blog();
	}
} else {
	$bk_uninstall_site();
}
