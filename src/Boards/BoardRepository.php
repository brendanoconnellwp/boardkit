<?php
declare( strict_types=1 );

namespace BoardKit\Boards;

/**
 * Board storage (spec §4.1). Pattern from ColumnKit's SettingsRepository:
 *
 * - One option per board, `bk_board_{id}`, autoload off.
 * - An index option, `bk_boards` (list of ids, autoload off), so listing boards never needs a
 *   LIKE scan of the options table.
 * - Every read goes through normalise(), so older or hand-edited shapes are repaired on read
 *   and schema changes never need a migration script.
 * - A per-request cache.
 */
final class BoardRepository {
	public const SCHEMA_VERSION = 1;
	public const INDEX_OPTION   = 'bk_boards';
	public const OPTION_PREFIX  = 'bk_board_';

	/** Lane sources v1 understands. 'columnkit' is accepted so a stored board survives ColumnKit being off. */
	public const LANE_KINDS = [ 'stage', 'status', 'taxonomy', 'author', 'columnkit' ];

	/** Sort fields. 'due' joins in Phase 4. */
	public const SORT_FIELDS = [ 'modified', 'date', 'title', 'menu_order' ];

	public const BUILTIN_FIELDS = [ 'author', 'status', 'assignees', 'due', 'stage', 'terms', 'thumbnail' ];

	/** @var array<string, Board|null> */
	private static array $cache = [];

	public function get( string $id ): ?Board {
		$id = self::sanitize_id( $id );
		if ( $id === '' ) {
			return null;
		}
		if ( array_key_exists( $id, self::$cache ) ) {
			return self::$cache[ $id ];
		}
		$raw   = get_option( self::OPTION_PREFIX . $id, null );
		$board = is_array( $raw ) ? Board::from_normalised( self::normalise( $raw, $id ) ) : null;
		return self::$cache[ $id ] = $board;
	}

	/** @return array<int, string> */
	public function ids(): array {
		$ids = get_option( self::INDEX_OPTION, [] );
		if ( ! is_array( $ids ) ) {
			return [];
		}
		return array_values( array_unique( array_filter( array_map( static fn( $v ) => self::sanitize_id( (string) $v ), $ids ) ) ) );
	}

	/** @return array<int, Board> in index order; ids whose option has gone are skipped. */
	public function all(): array {
		$out = [];
		foreach ( $this->ids() as $id ) {
			$board = $this->get( $id );
			if ( $board !== null ) {
				$out[] = $board;
			}
		}
		return $out;
	}

	/** @return array<int, Board> boards whose post type is $post_type */
	public function for_post_type( string $post_type ): array {
		return array_values( array_filter( $this->all(), static fn( Board $b ): bool => $b->post_type() === $post_type ) );
	}

	/** @param array<string, mixed> $data */
	public function save( array $data ): Board {
		$id = self::sanitize_id( (string) ( $data['id'] ?? '' ) );
		if ( $id === '' ) {
			throw new \InvalidArgumentException( 'Board id is required.' );
		}
		$normalised = self::normalise( $data, $id );
		update_option( self::OPTION_PREFIX . $id, $normalised, false );

		$ids = $this->ids();
		if ( ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
			update_option( self::INDEX_OPTION, $ids, false );
		}
		return self::$cache[ $id ] = Board::from_normalised( $normalised );
	}

	public function delete( string $id ): void {
		$id = self::sanitize_id( $id );
		delete_option( self::OPTION_PREFIX . $id );
		update_option( self::INDEX_OPTION, array_values( array_diff( $this->ids(), [ $id ] ) ), false );
		unset( self::$cache[ $id ] );
	}

	/**
	 * Creates the starter board once, the first time BoardKit runs with no index option at all.
	 * An empty index (every board deleted) is respected: nothing is re-created.
	 */
	public function seed_default(): ?Board {
		if ( get_option( self::INDEX_OPTION, null ) !== null ) {
			return null;
		}
		return $this->save( self::default_board() );
	}

	/** @return array<string, mixed> */
	public static function default_board(): array {
		$lane = static fn( string $value, string $label, string $color ): array => [ 'value' => $value, 'label' => $label, 'color' => $color, 'wip' => 0 ];
		return [
			'id'          => 'editorial',
			'label'       => __( 'Editorial', 'boardkit' ),
			'post_types'  => [ 'post' ],
			'lane_source' => [ 'kind' => 'stage' ],
			'lanes'       => [
				$lane( 'pitch', __( 'Pitch', 'boardkit' ), '#8c8f94' ),
				$lane( 'draft', __( 'Draft', 'boardkit' ), '#2271b1' ),
				$lane( 'in-review', __( 'In Review', 'boardkit' ), '#dba617' ),
				$lane( 'approved', __( 'Approved', 'boardkit' ), '#00a32a' ),
				$lane( 'scheduled', __( 'Scheduled', 'boardkit' ), '#9b51e0' ),
				$lane( 'published', __( 'Published', 'boardkit' ), '#1d2327' ),
			],
			'sort'        => [ 'by' => 'modified', 'dir' => 'desc' ],
			'cards'       => [ 'source' => 'builtin', 'fields' => [ 'author', 'status', 'assignees', 'due' ] ],
			'filters'     => [ 'default' => [] ],
			'calendar'    => [ 'date' => 'post_date' ],
		];
	}

	/**
	 * Repair any stored or submitted shape into the current schema. Never throws: unknown or
	 * malformed parts fall back to safe defaults.
	 *
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 */
	public static function normalise( array $in, string $id ): array {
		$id    = self::sanitize_id( $id );
		$label = isset( $in['label'] ) && is_string( $in['label'] ) && trim( $in['label'] ) !== '' ? sanitize_text_field( $in['label'] ) : $id;

		// v1: one post type per board. Keep the array shape for later (PLAN §1).
		$types = isset( $in['post_types'] ) && is_array( $in['post_types'] ) ? $in['post_types'] : [];
		$type  = sanitize_key( (string) ( reset( $types ) ?: 'post' ) );

		$source = isset( $in['lane_source'] ) && is_array( $in['lane_source'] ) ? $in['lane_source'] : [];
		$kind   = isset( $source['kind'] ) && in_array( $source['kind'], self::LANE_KINDS, true ) ? $source['kind'] : 'stage';
		$lane_source = [ 'kind' => $kind ];
		if ( $kind === 'taxonomy' ) {
			$lane_source['taxonomy'] = sanitize_key( (string) ( $source['taxonomy'] ?? '' ) );
		}
		if ( $kind === 'columnkit' ) {
			$lane_source['set']    = sanitize_key( (string) ( $source['set'] ?? 'default' ) );
			$lane_source['column'] = sanitize_key( (string) ( $source['column'] ?? '' ) );
		}

		$lanes = [];
		$seen  = [];
		foreach ( isset( $in['lanes'] ) && is_array( $in['lanes'] ) ? $in['lanes'] : [] as $lane ) {
			if ( ! is_array( $lane ) ) {
				continue;
			}
			$value = self::sanitize_lane_value( (string) ( $lane['value'] ?? '' ) );
			if ( $value === '' || isset( $seen[ $value ] ) ) {
				continue; // A lane value must be unique: it's how a card finds its lane.
			}
			$seen[ $value ] = true;
			$color          = isset( $lane['color'] ) && is_string( $lane['color'] ) ? (string) sanitize_hex_color( $lane['color'] ) : '';
			$lanes[]        = [
				'value' => $value,
				'label' => isset( $lane['label'] ) && is_string( $lane['label'] ) && $lane['label'] !== '' ? sanitize_text_field( $lane['label'] ) : $value,
				'color' => $color,
				'wip'   => max( 0, (int) ( $lane['wip'] ?? 0 ) ),
			];
		}

		$sort = isset( $in['sort'] ) && is_array( $in['sort'] ) ? $in['sort'] : [];
		$by   = in_array( $sort['by'] ?? '', self::SORT_FIELDS, true ) ? $sort['by'] : 'modified';
		$dir  = ( $sort['dir'] ?? '' ) === 'asc' ? 'asc' : 'desc';

		$cards_in = isset( $in['cards'] ) && is_array( $in['cards'] ) ? $in['cards'] : [];
		if ( ( $cards_in['source'] ?? '' ) === 'columnkit' ) {
			$cards = [ 'source' => 'columnkit', 'set' => sanitize_key( (string) ( $cards_in['set'] ?? 'default' ) ) ];
		} else {
			$fields = isset( $cards_in['fields'] ) && is_array( $cards_in['fields'] ) ? $cards_in['fields'] : [ 'author', 'status' ];
			$cards  = [
				'source' => 'builtin',
				'fields' => array_values( array_intersect( self::BUILTIN_FIELDS, array_map( 'strval', $fields ) ) ),
			];
			if ( in_array( 'terms', $cards['fields'], true ) ) {
				$cards['taxonomy'] = sanitize_key( (string) ( $cards_in['taxonomy'] ?? 'category' ) );
			}
		}

		$calendar_in = isset( $in['calendar'] ) && is_array( $in['calendar'] ) ? $in['calendar'] : [];
		$calendar    = [ 'date' => sanitize_key( (string) ( $calendar_in['date'] ?? 'post_date' ) ) ?: 'post_date' ];

		$filters_in = isset( $in['filters']['default'] ) && is_array( $in['filters']['default'] ) ? $in['filters']['default'] : [];

		return [
			'schema'      => self::SCHEMA_VERSION,
			'id'          => $id,
			'label'       => $label,
			'post_types'  => [ $type ],
			'lane_source' => $lane_source,
			'lanes'       => $lanes,
			'sort'        => [ 'by' => $by, 'dir' => $dir ],
			'cards'       => $cards,
			'filters'     => [ 'default' => array_map( 'sanitize_text_field', array_filter( $filters_in, 'is_string' ) ) ],
			'calendar'    => $calendar,
		];
	}

	public static function sanitize_id( string $id ): string {
		return substr( sanitize_key( $id ), 0, 40 );
	}

	/** Lane values double as term slugs and status names: lowercase a-z, 0-9, '-' and '_'. */
	public static function sanitize_lane_value( string $value ): string {
		return substr( sanitize_key( $value ), 0, 60 );
	}

	/** Test helper. */
	public static function reset_cache(): void {
		self::$cache = [];
	}
}
