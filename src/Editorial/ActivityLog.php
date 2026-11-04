<?php
declare( strict_types=1 );

namespace BoardKit\Editorial;

/**
 * Short per-post history (spec §7.4): `_bk_log`, one meta row holding the last 50 entries,
 * newest first, each {t, user, field, from, to}. update_post_meta doesn't touch post_modified,
 * so logging never looks like an edit to the conflict check.
 */
final class ActivityLog {
	public const MAX = 50;

	public static function add( int $post_id, string $field, string $from, string $to ): void {
		$log = self::entries( $post_id );
		array_unshift( $log, [
			't'     => time(),
			'user'  => get_current_user_id(),
			'field' => $field,
			'from'  => $from,
			'to'    => $to,
		] );
		update_post_meta( $post_id, Meta::LOG, array_slice( $log, 0, self::MAX ) );
	}

	/** @return array<int, array{t: int, user: int, field: string, from: string, to: string}> */
	public static function entries( int $post_id ): array {
		$log = get_post_meta( $post_id, Meta::LOG, true );
		if ( ! is_array( $log ) ) {
			return [];
		}
		$out = [];
		foreach ( $log as $e ) {
			if ( is_array( $e ) && isset( $e['t'], $e['field'] ) ) {
				$out[] = [
					't'     => (int) $e['t'],
					'user'  => (int) ( $e['user'] ?? 0 ),
					'field' => (string) $e['field'],
					'from'  => (string) ( $e['from'] ?? '' ),
					'to'    => (string) ( $e['to'] ?? '' ),
				];
			}
		}
		return $out;
	}
}
