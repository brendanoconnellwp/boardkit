<?php
declare( strict_types=1 );

namespace BoardKit\Boards;

/**
 * One board's configuration (spec §4.1). Immutable; always built from a normalised array
 * (BoardRepository::normalise), so every getter can trust its shape.
 */
final class Board {
	public const UNSORTED = '__unsorted';

	/** @param array<string, mixed> $data Output of BoardRepository::normalise(). */
	private function __construct( private array $data ) {}

	/** @param array<string, mixed> $normalised */
	public static function from_normalised( array $normalised ): self {
		return new self( $normalised );
	}

	public function id(): string {
		return (string) $this->data['id'];
	}

	public function label(): string {
		return (string) $this->data['label'];
	}

	/** v1: exactly one post type per board (PLAN §1). */
	public function post_type(): string {
		return (string) $this->data['post_types'][0];
	}

	/** @return array<string, mixed> e.g. ['kind' => 'stage'] or ['kind' => 'taxonomy', 'taxonomy' => 'category'] */
	public function lane_source(): array {
		return $this->data['lane_source'];
	}

	public function lane_kind(): string {
		return (string) $this->data['lane_source']['kind'];
	}

	/** @return array<int, array{value: string, label: string, color: string, wip: int}> */
	public function lanes(): array {
		return $this->data['lanes'];
	}

	/** @return array<int, string> lane values in board order */
	public function lane_values(): array {
		return array_map( static fn( array $l ): string => $l['value'], $this->data['lanes'] );
	}

	public function has_lane( string $value ): bool {
		return in_array( $value, $this->lane_values(), true );
	}

	/** @return array{by: string, dir: string} */
	public function sort(): array {
		return $this->data['sort'];
	}

	/** @return array<string, mixed> */
	public function cards(): array {
		return $this->data['cards'];
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return $this->data;
	}
}
