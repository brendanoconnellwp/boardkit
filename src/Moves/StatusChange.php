<?php
declare( strict_types=1 );

namespace BoardKit\Moves;

use WP_Error;

/**
 * Changing a post's WordPress status from a board — the one copy of the rules, used by status
 * boards (every lane is a status) and by stage lanes that also set a status (PLAN §1, decision
 * of 2026-11-04). Uses the post type's MAPPED caps (spec §7.2 rule 5):
 *
 * - into publish / future / private needs publish_posts (all three make the post public or
 *   scheduled — and wp_update_post() itself checks nothing);
 * - out of publish needs edit_published_posts;
 * - into future needs a date in the future: one picked on the board ($local_date, site time),
 *   else the post's own date; otherwise 400 "Pick a date in the future".
 */
final class StatusChange {
	/** Statuses a lane may set. */
	public const SETTABLE = [ 'draft', 'pending', 'future', 'publish', 'private' ];

	private const PUBLISHING = [ 'publish', 'future', 'private' ];

	/** Accepted board date: 'YYYY-MM-DD HH:MM' or 'YYYY-MM-DDTHH:MM' (datetime-local), site time. */
	private const DATE_RE = '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/';

	/** Check, then write. No-op (true) when the post already has that status and no new date. */
	public static function apply( \WP_Post $post, string $to, string $local_date = '' ): bool|WP_Error {
		$fields = self::plan( $post, $to, $local_date );
		if ( $fields instanceof WP_Error ) {
			return $fields;
		}
		if ( $fields === [] ) {
			return true;
		}
		$result = wp_update_post( wp_slash( [ 'ID' => $post->ID ] + $fields ), true );
		if ( $result instanceof WP_Error ) {
			return MoveService::error( 'bk_save_failed', $result->get_error_message(), 500 );
		}
		return true;
	}

	/**
	 * The wp_update_post() fields for this change, [] for nothing to do, or the reason it's refused.
	 *
	 * @return array<string, string>|WP_Error
	 */
	private static function plan( \WP_Post $post, string $to, string $local_date ): array|WP_Error {
		if ( ! in_array( $to, self::SETTABLE, true ) ) {
			return MoveService::error( 'bk_bad_status', __( 'That status cannot be set from a board.', 'boardkit' ), 400 );
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type ) {
			return MoveService::error( 'bk_no_post', __( 'Unknown post type.', 'boardkit' ), 404 );
		}
		$cap  = $type->cap;
		$from = (string) $post->post_status;

		// Nothing to change (same status; for 'future', no new date): done, no checks needed.
		if ( $from === $to && ( $to !== 'future' || $local_date === '' ) ) {
			return [];
		}

		// Permission BEFORE input: someone who may not schedule is told so, not asked for a date.
		if ( in_array( $to, self::PUBLISHING, true ) && ! current_user_can( $cap->publish_posts ) ) {
			return MoveService::error( 'bk_cannot_publish', $to === 'future' ? __( 'You cannot schedule this post.', 'boardkit' ) : __( 'You cannot publish this post.', 'boardkit' ), 403 );
		}
		if ( $from === 'publish' && ! current_user_can( $cap->edit_published_posts ) ) {
			return MoveService::error( 'bk_cannot_unpublish', __( 'You cannot change a published post.', 'boardkit' ), 403 );
		}

		$date_gmt = '';
		if ( $to === 'future' ) {
			$date_gmt = $local_date !== '' ? self::to_gmt( $local_date ) : (string) $post->post_date_gmt;
			if ( $date_gmt === null || $date_gmt === '' || $date_gmt === '0000-00-00 00:00:00' || strtotime( $date_gmt . ' UTC' ) <= time() ) {
				return MoveService::error( 'bk_needs_date', __( 'Pick a date in the future.', 'boardkit' ), 400 );
			}
			if ( $from === 'future' && $date_gmt === (string) $post->post_date_gmt ) {
				return []; // Rescheduled to the same moment.
			}
		}

		$fields = [ 'post_status' => $to ];
		if ( $to === 'future' ) {
			// Pass the date explicitly: wp_update_post() turns 'future' with a past date into 'publish'.
			$fields['post_date_gmt'] = $date_gmt;
			$fields['post_date']     = get_date_from_gmt( $date_gmt );
			$fields['edit_date']     = '1';
		}
		if ( $to === 'publish' && $from === 'future' ) {
			// Publishing a scheduled post goes live now, as core's "Publish" button does.
			$fields['post_date']     = current_time( 'mysql' );
			$fields['post_date_gmt'] = current_time( 'mysql', true );
			$fields['edit_date']     = '1';
		}
		// A draft's never-set ("floating") date otherwise stays floating, so publishing later
		// dates it "now" — core's own behaviour.
		return $fields;
	}

	/** Site-local 'Y-m-d H:i' → GMT 'Y-m-d H:i:s', or null if malformed / not a real date. */
	public static function to_gmt( string $local ): ?string {
		if ( ! preg_match( self::DATE_RE, trim( $local ), $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 ) {
			return null;
		}
		return get_gmt_from_date( sprintf( '%s-%s-%s %s:%s:00', $m[1], $m[2], $m[3], $m[4], $m[5] ) );
	}
}
