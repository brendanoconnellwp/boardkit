<?php
declare( strict_types=1 );

namespace BoardKit\Editorial;

use BoardKit\Boards\Board;
use BoardKit\Boards\BoardRepository;
use BoardKit\Moves\MoveService;

/**
 * Editorial fields on the edit screen (spec §4.2, §4.5, §7.4): a classic meta box in the side
 * column — stage, assignees, due date and recent activity. Classic, so it works inside the
 * block editor's meta box area with no build step.
 *
 * The stage here is STAGE ONLY: it never sets the WordPress status, even for lanes that do on
 * the board. The editor has its own Publish/Schedule controls; a second way to publish from a
 * dropdown that also saves with "Save draft" would surprise people.
 */
final class MetaBox {
	public const NONCE = 'bk_editorial_box';

	public function __construct( private BoardRepository $boards ) {}

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_meta' ], 20 );
		add_action( 'add_meta_boxes', [ $this, 'add' ], 10, 2 );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );
	}

	/** Protected meta, editable only by people who can edit the post; not in REST in v1. */
	public function register_meta(): void {
		$auth = static fn( $allowed, $key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id );
		foreach ( $this->post_types() as $type ) {
			register_post_meta( $type, Meta::ASSIGNEES, [ 'type' => 'integer', 'single' => false, 'show_in_rest' => false, 'auth_callback' => $auth ] );
			register_post_meta( $type, Meta::DUE, [ 'type' => 'string', 'single' => true, 'show_in_rest' => false, 'auth_callback' => $auth ] );
		}
	}

	/** @return array<int, string> */
	private function post_types(): array {
		return array_values( array_unique( array_map( static fn( Board $b ): string => $b->post_type(), $this->boards->all() ) ) );
	}

	/** The stage board for a post type (the first, if several), or null. */
	private function stage_board( string $type ): ?Board {
		foreach ( $this->boards->for_post_type( $type ) as $board ) {
			if ( $board->lane_kind() === 'stage' ) {
				return $board;
			}
		}
		return null;
	}

	public function add( string $post_type, $post ): void {
		if ( ! in_array( $post_type, $this->post_types(), true ) ) {
			return;
		}
		add_meta_box( 'bk-editorial', __( 'Editorial', 'boardkit' ), [ $this, 'render' ], $post_type, 'side', 'default' );
	}

	public function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		$board     = $this->stage_board( $post->post_type );
		$assigned  = Meta::assignees( $post->ID );
		$people    = self::eligible_people( $post, $assigned );
		$due       = Meta::due( $post->ID );

		if ( $board ) {
			$current = ( new \BoardKit\Lanes\StageSource() )->lane_of( $post, $board ) ?? '';
			echo '<p><label for="bk-stage"><strong>' . esc_html__( 'Stage', 'boardkit' ) . '</strong></label><br>';
			echo '<select id="bk-stage" name="bk_stage" class="widefat">';
			echo '<option value="">' . esc_html__( '— None —', 'boardkit' ) . '</option>';
			foreach ( $board->lanes() as $lane ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $lane['value'] ), selected( $current, $lane['value'], false ), esc_html( $lane['label'] ) );
			}
			echo '</select></p>';
		}

		echo '<fieldset><legend><strong>' . esc_html__( 'Assigned to', 'boardkit' ) . '</strong></legend>';
		echo '<input type="hidden" name="bk_assignees_present" value="1">';
		if ( $people === [] ) {
			echo '<p class="description">' . esc_html__( 'Nobody else can edit this post.', 'boardkit' ) . '</p>';
		}
		echo '<div class="bk-box-people" style="max-height:160px;overflow:auto">';
		foreach ( $people as $user ) {
			printf(
				'<label style="display:block"><input type="checkbox" name="bk_assignees[]" value="%d"%s> %s</label>',
				(int) $user->ID,
				checked( in_array( (int) $user->ID, $assigned, true ), true, false ),
				esc_html( $user->display_name )
			);
		}
		echo '</div></fieldset>';

		printf(
			'<p><label for="bk-due"><strong>%s</strong></label><br><input type="date" id="bk-due" name="bk_due" value="%s" class="widefat"></p>',
			esc_html__( 'Due date', 'boardkit' ),
			esc_attr( $due )
		);

		$log = array_slice( ActivityLog::entries( $post->ID ), 0, 5 );
		if ( $log !== [] ) {
			echo '<p><strong>' . esc_html__( 'Recent activity', 'boardkit' ) . '</strong></p><ul style="margin:0">';
			foreach ( $log as $entry ) {
				echo '<li>' . esc_html( self::describe( $entry, $board ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * Users who could be assigned: can edit this post. Bounded so a big site doesn't render
	 * thousands of checkboxes; current assignees always included.
	 *
	 * @param array<int, int> $assigned
	 * @return array<int, \WP_User>
	 */
	public static function eligible_people( \WP_Post $post, array $assigned ): array {
		$type = get_post_type_object( $post->post_type );
		$cap  = $type ? (string) $type->cap->edit_posts : 'edit_posts';
		// 'capability' needs WP 5.9; BoardKit requires 6.0.
		$users = get_users( [ 'number' => 100, 'orderby' => 'display_name', 'capability' => $cap ] );
		foreach ( $assigned as $id ) {
			$u = get_userdata( $id );
			if ( $u ) {
				$users[] = $u;
			}
		}
		$out = [];
		foreach ( $users as $u ) {
			if ( ! isset( $out[ $u->ID ] ) && user_can( $u, 'edit_post', $post->ID ) ) {
				$out[ $u->ID ] = $u;
			}
		}
		return array_values( $out );
	}

	/** @param array{t: int, user: int, field: string, from: string, to: string} $entry */
	public static function describe( array $entry, ?Board $board ): string {
		$who   = get_userdata( $entry['user'] );
		$name  = $who ? $who->display_name : __( 'Someone', 'boardkit' );
		/* translators: %s: human time difference, e.g. "2 hours" */
		$when  = sprintf( __( '%s ago', 'boardkit' ), human_time_diff( $entry['t'] ) );
		$label = static function ( string $value ) use ( $board ): string {
			foreach ( $board ? $board->lanes() : [] as $lane ) {
				if ( $lane['value'] === $value ) {
					return $lane['label'];
				}
			}
			return $value;
		};
		switch ( $entry['field'] ) {
			case 'stage':
				/* translators: 1: lane name, 2: user name, 3: "2 hours ago" */
				return sprintf( __( 'Moved to %1$s by %2$s, %3$s', 'boardkit' ), $label( $entry['to'] ), $name, $when );
			case 'status':
				$obj = get_post_status_object( $entry['to'] );
				/* translators: 1: status name, 2: user name, 3: "2 hours ago" */
				return sprintf( __( 'Status set to %1$s by %2$s, %3$s', 'boardkit' ), $obj ? $obj->label : $entry['to'], $name, $when );
			case 'due':
				/* translators: 1: date or "none", 2: user name, 3: "2 hours ago" */
				return sprintf( __( 'Due date set to %1$s by %2$s, %3$s', 'boardkit' ), $entry['to'] !== '' ? $entry['to'] : __( 'none', 'boardkit' ), $name, $when );
			case 'assignees':
				/* translators: 1: user name, 2: "2 hours ago" */
				return sprintf( __( 'Assignees changed by %1$s, %2$s', 'boardkit' ), $name, $when );
		}
		return '';
	}

	public function save( int $post_id, \WP_Post $post ): void {
		// BoardKit's own writes (board moves) must not re-run this (spec §7.2 rule 6).
		if ( MoveService::is_moving() || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! in_array( $post->post_type, $this->post_types(), true ) ) {
			return;
		}

		$board = $this->stage_board( $post->post_type );
		if ( $board && isset( $_POST['bk_stage'] ) && is_string( $_POST['bk_stage'] ) ) {
			$stage = BoardRepository::sanitize_lane_value( wp_unslash( $_POST['bk_stage'] ) );
			$this->save_stage( $post, $board, $stage );
		}

		if ( isset( $_POST['bk_assignees_present'] ) ) {
			$ids = isset( $_POST['bk_assignees'] ) && is_array( $_POST['bk_assignees'] ) ? array_map( 'absint', wp_unslash( $_POST['bk_assignees'] ) ) : [];
			$result = Assignees::set( $post, $ids );
			if ( $result instanceof \WP_Error ) {
				self::flash( $result->get_error_message() );
			}
		}

		if ( isset( $_POST['bk_due'] ) && is_string( $_POST['bk_due'] ) ) {
			$result = DueDate::set( $post, sanitize_text_field( wp_unslash( $_POST['bk_due'] ) ) );
			if ( $result instanceof \WP_Error ) {
				self::flash( $result->get_error_message() );
			}
		}
	}

	/** Stage only (see class doc). Must be one of the board's lanes; '' removes the stage. */
	private function save_stage( \WP_Post $post, Board $board, string $stage ): void {
		$source  = new \BoardKit\Lanes\StageSource();
		$current = $source->lane_of( $post, $board ) ?? '';
		if ( $stage === $current || ( $stage !== '' && ! $board->has_lane( $stage ) ) ) {
			return;
		}
		$tax = get_taxonomy( StageTaxonomy::TAXONOMY );
		if ( ! $tax || ! current_user_can( $tax->cap->assign_terms ) ) {
			return;
		}
		if ( $stage === '' ) {
			wp_set_object_terms( $post->ID, [], StageTaxonomy::TAXONOMY, false );
		} else {
			$term = get_term_by( 'slug', $stage, StageTaxonomy::TAXONOMY );
			if ( ! $term instanceof \WP_Term ) {
				StageTaxonomy::ensure_terms( $board );
				$term = get_term_by( 'slug', $stage, StageTaxonomy::TAXONOMY );
			}
			if ( ! $term instanceof \WP_Term ) {
				return;
			}
			wp_set_object_terms( $post->ID, [ (int) $term->term_id ], StageTaxonomy::TAXONOMY, false );
		}
		ActivityLog::add( $post->ID, 'stage', $current !== '' ? $current : Board::UNSORTED, $stage !== '' ? $stage : Board::UNSORTED );
	}

	/** One-request notice for a refused field (shown on the next admin page load). */
	private static function flash( string $message ): void {
		set_transient( 'bk_notice_' . get_current_user_id(), $message, 60 );
	}

	public static function render_flash(): void {
		$key = 'bk_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( is_string( $msg ) && $msg !== '' ) {
			delete_transient( $key );
			printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( $msg ) );
		}
	}
}
