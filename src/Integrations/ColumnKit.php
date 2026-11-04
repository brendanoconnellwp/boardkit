<?php
declare( strict_types=1 );

namespace BoardKit\Integrations;

/**
 * The ONLY class that talks to ColumnKit (spec §3.2). Every call into `ColumnKit\Api` goes
 * through here, so a future Api v2 changes one file.
 *
 * Phase 1: just records that ColumnKit announced a compatible Api via `columnkit/loaded`.
 * Phase 7 adds the lane source and card-field wiring.
 */
final class ColumnKit {
	/** Minimum `ColumnKit\Api::VERSION` this adapter speaks. */
	public const MIN_API_VERSION = 1;

	private static bool $enabled = false;

	/** Called from the `columnkit/loaded` listener in boardkit.php. */
	public static function enable(): bool {
		self::$enabled = true;
		return true;
	}

	public static function is_enabled(): bool {
		return self::$enabled;
	}

	/** Test helper: forget a previous enable(). */
	public static function reset(): void {
		self::$enabled = false;
	}
}
