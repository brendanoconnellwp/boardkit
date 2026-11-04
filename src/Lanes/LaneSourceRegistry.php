<?php
declare( strict_types=1 );

namespace BoardKit\Lanes;

use BoardKit\Boards\Board;

/** kind => LaneSource. Later phases (and the ColumnKit integration) register more kinds. */
final class LaneSourceRegistry {
	/** @var array<string, LaneSource> */
	private array $sources = [];

	public function register( LaneSource $source ): void {
		$this->sources[ $source->kind() ] = $source;
	}

	public function get( string $kind ): ?LaneSource {
		return $this->sources[ $kind ] ?? null;
	}

	/**
	 * The source for a board. A board whose source isn't available (e.g. 'columnkit' with
	 * ColumnKit off, or a kind from a later phase) falls back to stage, so it still renders.
	 */
	public function for_board( Board $board ): LaneSource {
		return $this->get( $board->lane_kind() ) ?? $this->get( 'stage' ) ?? new StageSource();
	}

	public function is_available( string $kind ): bool {
		return isset( $this->sources[ $kind ] );
	}
}
