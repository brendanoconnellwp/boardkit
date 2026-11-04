<?php
declare( strict_types=1 );

namespace BoardKit\Editorial;

use BoardKit\Moves\MoveService;
use WP_Error;

/**
 * Due date (spec §4.5): `_bk_due`, 'Y-m-d', one row — and NO row when there's no due date
 * (never ''), so "has a due date" is EXISTS and the due sort can put undated posts last.
 */
final class DueDate {
	/** @return string|WP_Error The saved date ('' = cleared). */
	public static function set( \WP_Post $post, string $date ): string|WP_Error {
		$date = trim( $date );
		if ( $date !== '' && ! self::valid( $date ) ) {
			return MoveService::error( 'bk_bad_date', __( 'Use a real date, YYYY-MM-DD.', 'boardkit' ), 400 );
		}
		$current = Meta::due( $post->ID );
		if ( $current === $date ) {
			return $date;
		}
		if ( $date === '' ) {
			delete_post_meta( $post->ID, Meta::DUE );
		} else {
			update_post_meta( $post->ID, Meta::DUE, $date );
		}
		ActivityLog::add( $post->ID, 'due', $current, $date );
		return $date;
	}

	public static function valid( string $date ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}
}
