<?php
declare( strict_types=1 );

namespace BoardKit;

use BoardKit\Admin\Assets;
use BoardKit\Admin\BoardPage;
use BoardKit\Admin\ListTableLink;
use BoardKit\Admin\Menu;
use BoardKit\Boards\BoardRepository;
use BoardKit\Editorial\StageTaxonomy;
use BoardKit\Lanes\LaneSourceRegistry;
use BoardKit\Lanes\StageSource;
use BoardKit\Lanes\StatusSource;
use BoardKit\Moves\MoveService;
use BoardKit\Query\CardPresenter;
use BoardKit\Query\CardQuery;
use BoardKit\Query\Visibility;
use BoardKit\Rest\BoardsController;
use BoardKit\Rest\CardsController;
use BoardKit\Updater\GitHubUpdater;

/**
 * Plugin root. Owns service construction and hook registration.
 *
 * Builds the updater, board storage, lane sources, queries, REST and admin services
 * here (see PLAN.md §2).
 */
final class Plugin {
	private static ?self $instance = null;

	private bool $booted = false;

	private BoardRepository $boards;
	private LaneSourceRegistry $sources;
	private CardQuery $cards;
	private MoveService $moves;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return; // Idempotent: a second boot() must not double-register hooks.
		}
		$this->booted = true;

		// Self-updates from GitHub releases. Not is_admin()-gated: wp_update_plugins()
		// runs from wp-cron too, and the filter must be present there.
		( new GitHubUpdater() )->register_hooks();

		$this->boards  = new BoardRepository();
		$this->sources = new LaneSourceRegistry();
		$this->sources->register( new StageSource() );
		$this->sources->register( new StatusSource() );
		$this->cards = new CardQuery( $this->sources, new Visibility() );

		( new StageTaxonomy( $this->boards ) )->register_hooks();
		// After the taxonomy exists (init 5): create the starter board once, and its stage terms.
		add_action( 'init', [ $this, 'seed' ], 6 );

		// REST registers on rest_api_init only, so it's safe outside is_admin().
		$presenter   = new CardPresenter( $this->sources );
		$this->moves = new MoveService( $this->sources );
		( new BoardsController( $this->boards, $this->cards, $presenter ) )->register_hooks();
		( new CardsController( $this->boards, $this->moves, $presenter ) )->register_hooks();

		if ( is_admin() ) {
			$page = new BoardPage( $this->boards );
			$menu = new Menu( $this->boards, $page );
			$menu->register_hooks();
			( new Assets( $menu, $page ) )->register_hooks();
			( new ListTableLink( $this->boards ) )->register_hooks();
		}
	}

	/** Starter board on first run (no `bk_boards` option at all), with its stage terms. */
	public function seed(): void {
		$board = $this->boards->seed_default();
		if ( $board !== null ) {
			StageTaxonomy::ensure_terms( $board );
		}
	}

	public function boards(): BoardRepository {
		return $this->boards;
	}

	public function moves(): MoveService {
		return $this->moves;
	}

	public function lane_sources(): LaneSourceRegistry {
		return $this->sources;
	}

	public function card_query(): CardQuery {
		return $this->cards;
	}

	public function is_booted(): bool {
		return $this->booted;
	}
}
