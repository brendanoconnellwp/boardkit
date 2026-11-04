# BoardKit — Build Spec

A Kanban board and editorial calendar for WordPress content. It's the companion plugin to
[ColumnKit](https://github.com/brendanoconnellwp/columnkit).

> **Status:** draft spec, v0.1. "BoardKit" is a working name. Slug `boardkit`, PHP namespace
> `BoardKit\`, constant prefix `BK_`, option/meta prefix `bk_`. Change them all together if you rename.
>
> **For the agent building this:** ColumnKit is checked out next to this repo at `../columnkit`.
> Read it for patterns and copy the files listed in §11. **Don't edit `../columnkit` from this repo.**
> The one ColumnKit change this spec needs (Phase 0) is made and released in ColumnKit's own repo.

---

## 1. What it is

WordPress shows content as a table. BoardKit adds two more views of the same posts:

- **Board:** cards in lanes, such as *Pitch → Draft → In Review → Approved → Scheduled → Published*.
  Drag a card to another lane and the post is updated.
- **Calendar:** cards on a month or week grid, placed by publish date or any date field. Drag a
  card to another day to reschedule it.

It also adds the editorial fields a board needs: **workflow stage**, **assignees** and **due date**.

### Goals
1. **Works on its own.** With ColumnKit inactive, BoardKit is a complete plugin. Lanes can come
   from core status, stage, any taxonomy or author.
2. **Better with ColumnKit.** When ColumnKit is active, a board can use a ColumnKit **view** (column
   set) as its card fields. Any ColumnKit column that is editable *and* has fixed options can
   become a lane source (for example an ACF select field), and moving a card saves through that
   column's own save path.
3. Matches ColumnKit's engineering bar: strict types, a capability check plus nonce on every write,
   escaped output, PHPUnit + Brain Monkey tests, smoke scripts, and a `PITFALLS-phaseN.md` per phase.
4. **No build step.** Plain PHP, plain CSS, and plain JS (jQuery is allowed, as ColumnKit uses it),
   plus one vendored drag library.

### Non-goals (v1)
- Hand-ordering cards within a lane. Lanes are sorted by a chosen field (§4.4).
- Showing boards on the front end. They're admin only.
- Real-time collaboration (websockets, live cursors). Concurrent moves are handled with conflict
  detection (§7.3) instead.
- Replacing the block editor's own post-status UI.
- Multisite network-wide boards. Boards are per site.

---

## 2. Repo layout: separate repo, not a monorepo

Create **`brendanoconnellwp/boardkit`** as its own repository.

| Reason | Detail |
|---|---|
| Self-updater is one-repo-one-plugin | ColumnKit's `GitHubUpdater` reads `repos/{repo}/releases/latest`. With two plugins in one repo, "latest" would mean whichever plugin released last, and each would offer the other's zip. Fixing that needs tag prefixes, release filtering and a changed workflow, all for no gain. |
| WordPress installs plugins as folders | Each plugin lives in its own `wp-content/plugins/<slug>/`, so one repo per folder maps cleanly. On LocalWP, clone each repo straight into `wp-content/plugins/`. |
| Independent versions and CI | Each plugin has its own version number, changelog, release workflow and test suite. |

**Sharing code:** copy the generic pieces in and rename their namespace (§11). Don't use a shared
Composer package. If two active plugins both bundle the same class name, PHP loads whichever
plugin's copy comes first and silently uses it for both, which gives version-skew bugs that are
hard to trace. The pieces involved are small: the updater is about 220 lines and the release
workflow about 100. For anything that's *ColumnKit's* job (columns, views, rendering, saving),
**call ColumnKit at runtime** through its API (§3) instead of copying it.

Choose a monorepo only if you later decide to merge everything into one "Kit" plugin with modules.

---

## 3. Integration contract with ColumnKit

### 3.1 Phase 0: a small public API in ColumnKit (done in the ColumnKit repo)

BoardKit currently could only reach into ColumnKit's internals (`Plugin::instance()->registry()`,
`EditManager`'s private save logic), which would break whenever ColumnKit is refactored. So first
add a small, versioned facade to ColumnKit: **`src/Api.php`**, shipped as ColumnKit 0.7.0.

```php
namespace ColumnKit;

final class Api {
    public const VERSION = 1; // bump on any breaking change to this class

    /** @return array<string, string> set_id => label */
    public static function sets( string $screen_key ): array;

    /** @return array<int, array<string, mixed>> column entries (id, type, label, settings, format) */
    public static function columns( string $screen_key, string $set_id = 'default' ): array;

    /** Escaped HTML for one value: render() + ColumnPresenter::format(). '' if unknown column. */
    public static function render( array $entry, int $object_id ): string;

    /** Plain text (export value + prefix/suffix). Used for card titles, aria labels. */
    public static function text( array $entry, int $object_id ): string;

    /**
     * Fixed choices if this column can act as a lane source, else null.
     * Non-null only when Editability::is_editable() is true AND
     * get_edit_input_type() is 'select' (uses get_edit_options()) or 'boolean' (['1'=>Yes,'0'=>No]).
     * @return array<string, string>|null value => label
     */
    public static function lane_choices( array $entry ): ?array;

    /** Current raw value (EditableColumn::get_raw_value), '' if not editable. */
    public static function raw_value( array $entry, int $object_id ): string;

    /**
     * Save a value with exactly the checks ck_inline_save applies: Editability gate,
     * current_user_can('edit_post'), the value sanitised, then the column's own save_value()
     * (which keeps its protected-meta rule). Returns true or a WP_Error with a status code.
     */
    public static function save( string $screen_key, string $set_id, string $col_id, int $post_id, string $value ): true|\WP_Error;
}
```

ColumnKit then fires `do_action( 'columnkit/loaded', Api::VERSION )` at the end of `Plugin::boot()`.

`Api::save()` must **move the shared checks out of `EditManager::ajax_inline_save()` into a single
place** that both the AJAX handler and `Api::save()` call, rather than duplicating them. The
reasoning is the same as `Support\Editability`'s: two copies of a security check eventually
disagree. ColumnKit's existing tests must still pass, and new tests should cover `Api`.

### 3.2 How BoardKit detects ColumnKit

```php
add_action( 'columnkit/loaded', fn( int $v ) => $v >= 1 && BoardKit\Integrations\ColumnKit::enable() );
```

- Detect it only through that hook. Don't use `class_exists` at file load, which depends on load
  order. ColumnKit boots on `plugins_loaded` priority 10, so BoardKit must register its listener
  before then (in its main file, or on `plugins_loaded` priority 5).
- If the hook never fires (ColumnKit missing, too old, or inactive), BoardKit stays standalone. No
  admin notice is needed. The "Card fields from ColumnKit view" setting simply doesn't appear.
- Every call into `ColumnKit\Api` goes through one adapter class,
  `BoardKit\Integrations\ColumnKit`, so a future API v2 only changes one file.

### 3.3 What changes when ColumnKit is active

| Feature | Standalone | With ColumnKit |
|---|---|---|
| Card fields | Built-in set: title, author avatar, assignees, due date, stage, terms of one chosen taxonomy, featured image thumbnail | Optionally a ColumnKit view for that post type. Each column becomes a card row via `Api::render()`. |
| Lane sources | Core status, stage, taxonomy, author | Those, plus any column where `Api::lane_choices()` isn't null (ACF select, radio or button group; boolean meta) |
| Saving a move | BoardKit's own save code (§7.2) | For ColumnKit lane sources, `Api::save()` |
| Filters | BoardKit's filter bar | No change in v1. Reusing ColumnKit's `FilterableColumn` filters is a v2 idea (§13). |

---

## 4. Concepts and data model

### 4.1 Board
A saved configuration with these fields:

```php
[
  'schema'      => 1,
  'id'          => 'editorial',          // sanitize_key, unique per site
  'label'       => 'Editorial',
  'post_types'  => ['post'],             // 1..n; v1 UI may restrict to 1
  'lane_source' => ['kind' => 'stage'],  // see 4.3
  'lanes'       => [ ['value' => 'pitch', 'label' => 'Pitch', 'color' => '#…', 'wip' => 0], … ],
  'sort'        => ['by' => 'due', 'dir' => 'asc'],   // see 4.4
  'cards'       => ['source' => 'builtin', 'fields' => ['assignees','due','author']]
                 // or ['source' => 'columnkit', 'set' => 'editorial-view']
  'filters'     => ['default' => ['assignee' => 'me']],
  'calendar'    => ['date' => 'post_date'],            // or 'due', or a ColumnKit date column id
]
```

- Each board is stored in its own option, **`bk_board_{id}`**, with autoload off. This is the same
  approach as ColumnKit's `ck_screen_*` options. An index option, `bk_boards` (list of ids, autoload
  off), avoids `LIKE` scans of the options table.
- Copy ColumnKit's approach of migrating old shapes when they're read (`SettingsRepository::normalise`):
  every read passes through `BoardRepository::normalise()`, so future schema changes never need a
  migration script.
- `wip` sets a work-in-progress limit for a lane, where 0 means no limit. When a lane is over its
  limit it shows a warning only, and nothing is blocked.

### 4.2 Workflow stage: a hidden taxonomy, not a custom post status

**Decision:** store stage in a private taxonomy, **`bk_stage`**. Don't register custom post statuses.

Custom post statuses (`register_post_status`) are only partly supported in WordPress: the block
editor's status and visibility controls don't show them, preview links and "Save draft" handling
treat unknown statuses inconsistently, and editorial plugins that use them have needed years of
workarounds. A taxonomy term avoids all of that:

- The post keeps a normal core status (`draft`, `pending`, `future`, `publish`). Stage is a
  separate value layered on top, for example "In Review" while the post is still `draft`.
- It can be queried with `tax_query`, works on any post type, and is cleaned up by core when a
  post is deleted.
- Registration: `public => false`, `show_ui => false`, `show_in_rest => true` (for the REST
  endpoints), `hierarchical => false`, `rewrite => false`. Assign it to the post types that have a
  stage-based board.
- **One term per post.** Every write uses `wp_set_object_terms( $id, [ $term ], 'bk_stage', false )`,
  which replaces any existing term. If a post somehow has more than one term, the board shows the
  first in lane order and fixes it on the next save.
- Editor UI: a **classic meta box** in the `side` context with one `<select>`. It works inside the
  block editor's meta box area with no build step. Its nonce and `edit_post` check run on `save_post`.
- Stage terms are created when you save a board's lanes. Renaming a lane renames the term (the slug
  stays the same). Deleting a lane needs a "move its cards to…" choice and is refused while cards
  remain in it.

Boards can still use **core `post_status`** as their lane source (§4.3). Moving a card there runs
through core's own status rules (§7.2).

### 4.3 Lane sources

| `kind` | Values | Saved by | Notes |
|---|---|---|---|
| `stage` | `bk_stage` terms, in board lane order | `wp_set_object_terms` (replace) | Default for new boards |
| `status` | Core statuses that apply to the post type, minus `trash`/`auto-draft`/`inherit` | `wp_update_post( ['post_status' => …] )` | Moving into `publish` or `future` needs `publish_posts` for the post type (§7.2) |
| `taxonomy` | Terms of one taxonomy (`taxonomy` key), top level only in v1 | `wp_set_object_terms` (replace for this taxonomy) | Posts with several terms appear in the first matching lane, and the card shows a "+N" chip |
| `author` | Users who can edit this post type (capped list, plus "Other") | `wp_update_post( ['post_author' => …] )` | Needs `edit_others_posts` |
| `columnkit` | `Api::lane_choices( $entry )` | `Api::save()` | Only offered when the integration is enabled (§3.2) |

Each source is a class implementing **`LaneSource`**:

```php
interface LaneSource {
    public function kind(): string;
    /** @return array<string,string> value => default label */
    public function available_values( array $board ): array;
    /** Adds the WP_Query args that put a post in one lane. */
    public function query_args_for_lane( array $board, string $value ): array;
    /** Which lane a post is in, or null if none (it goes to the "Unsorted" lane). */
    public function lane_of( \WP_Post $post, array $board ): ?string;
    /** Check permission and save. Return true or a WP_Error with a status. */
    public function move( \WP_Post $post, string $to, array $board ): true|\WP_Error;
}
```

Every board gets a final, read-only **"Unsorted"** lane for posts that match the board's post
types but none of its lanes. Cards can be dragged out of it but not into it.

### 4.4 Lane sorting
`sort.by` is one of `due`, `modified`, `date`, `title` or `menu_order`. Sorting by `due` puts
posts with no due date last, which needs a two-part meta query (§8).

### 4.5 Editorial fields (post meta)

| Key | Shape | Why it's stored this way |
|---|---|---|
| `_bk_assignees` | **One meta row per user ID**, written with `add_post_meta(..., false)` | Lets `meta_query` match one user ("assigned to me") without `LIKE` matching on a serialised array |
| `_bk_due` | `Y-m-d` string, one row | Sorts correctly as text and as `DATE`. Leave the row out when there's no due date (don't store `''`). |

- The underscore prefix makes them protected meta, so the custom-fields box and REST meta don't show
  them by default. BoardKit exposes them through its own UI and endpoints only.
- `register_post_meta` both keys for board post types with an `auth_callback` that requires
  `edit_post`, and `show_in_rest => false` in v1.
- Assignees must be users who can `edit_post` that post. Check this when saving, not only in the picker.
- With ColumnKit active, these keys become ordinary `post_meta` columns. ColumnKit's existing rule
  that protected meta needs `edit_others_posts` applies to them unchanged.

---

## 5. Admin UI

### 5.1 Where it lives
- A top-level menu, **Boards** (dashicons `dashicons-columns`), listing the boards the user can see.
  Each board is a submenu: `admin.php?page=boardkit&board={id}`. A tab switches between
  `&view=board` and `&view=calendar`.
- **Board settings** (lanes, source, card fields, sorting) is a screen that needs `manage_options`,
  at `admin.php?page=boardkit-settings&board={id}`.
- **A "Board" button on list tables.** For post types with a board, add a link next to the view
  links with the `views_edit-{post_type}` filter. This needs no ColumnKit change and sits alongside
  ColumnKit's view switcher.

### 5.2 Who can view a board
A user can open a board if they can `edit_posts` for **at least one** of its post types. Cards
show only posts the user could open in the editor. That means the same scoping ColumnKit's export
uses: users without `edit_others_posts` get `author = self` *plus* posts they're assigned to (§8).

### 5.3 The board screen
- Lanes are vertical columns that scroll horizontally on desktop. Below 782px (WordPress's
  breakpoint) they stack, each lane collapsible, and moves happen through the card's **"Move to…"**
  menu instead of dragging.
- Lane header: label, count (with "N / WIP" when there's a limit), a colour strip, a collapse
  toggle, and "+ New" (creates a draft already in that lane and opens the editor in a new tab).
- Card: title (links to the edit screen), card fields, assignee avatars, a due-date chip (shown red
  when overdue and not published), and a ⋯ menu with *Open*, *Move to…*, *Assign…*, *Set due…*,
  *View post*.
- Filter bar: search box, assignee (Me / anyone / a specific user), author, taxonomy, overdue only.
  Filters are kept in the URL, so a filtered board can be bookmarked or shared.
- Each lane loads **50 cards**, then "Load more" fetches the next 50 for that lane only (§8).

### 5.4 The calendar screen
- Month view by default, plus a week view. Cards sit on the day of `calendar.date`.
- Dragging a card to another day updates that date. For `post_date` this follows core's rules: a
  future date on a published post makes it `future` (scheduled), and only users with
  `publish_posts` may do that. Due-date moves need only `edit_post`.
- An "Unscheduled" tray to the side lists posts with no date in the chosen field.

### 5.5 Accessibility (required, not a nice-to-have)
- Every drag action has a keyboard and non-drag equivalent: focus a card, press **Space** to pick it
  up, use the arrow keys to choose a lane or day, then **Space** to drop or **Esc** to cancel. The
  ⋯ → *Move to…* menu does the same through a menu.
- Announce each result through `wp.a11y.speak()` (core script `wp-a11y`), for example "Moved
  'Launch post' to In Review".
- Lanes are `role="list"` and cards `role="listitem"`, with sensible `aria-label`s. Focus stays on
  the card after a move.
- Lane colours are decorative only. Text never depends on colour alone (the overdue chip has text
  too), and it has to meet WCAG AA contrast against the lane background.

---

## 6. Front-end implementation

- `assets/board.js`, `assets/calendar.js` and `assets/board.css` are hand-written, like ColumnKit's
  `assets/`. They're enqueued only on BoardKit screens (check the hook suffix, as ColumnKit's
  `Admin\Assets` does).
- Dragging uses **SortableJS** (MIT licence, GPL compatible), vendored as a single minified file at
  `assets/vendor/sortable.min.js` with its licence file. Pin the version in the file header.
- Pass data in with `wp_add_inline_script` and `wp_json_encode( …, JSON_HEX_TAG | JSON_HEX_AMP |
  JSON_HEX_APOS | JSON_HEX_QUOT )`. This is the same rule ColumnKit fixed in PR #1: post titles
  are written by authors and must not be able to break out of the `<script>` tag.
- All requests go through `wp.apiFetch` (core script `wp-api-fetch`), which adds the REST nonce.
- **Optimistic UI:** move the card straight away, then roll it back and show the error notice if
  the server rejects the move.

---

## 7. Server API and security

### 7.1 REST routes (namespace `boardkit/v1`)

Every route has a real `permission_callback`, never `__return_true`.

| Method and route | Purpose | Permission |
|---|---|---|
| `GET /boards/{id}` | Board config plus lane counts | can view the board (§5.2) |
| `GET /boards/{id}/lanes/{lane}/cards?offset=&…filters` | One page of cards for a lane | can view the board; each post filtered by visibility |
| `POST /boards/{id}/cards/{post_id}/move` `{to, modified}` | Move a card to another lane | `edit_post` on that post, plus the source-specific rule (§7.2) |
| `POST /cards/{post_id}/assignees` `{user_ids[]}` | Replace the assignees | `edit_post`; each assignee must be able to `edit_post` (§4.5) |
| `POST /cards/{post_id}/due` `{date \| null}` | Set or clear the due date | `edit_post` |
| `POST /cards/{post_id}/date` `{date}` | Change the calendar date (`post_date`) | `edit_post`, plus `publish_posts` when the result is scheduled or published |
| `GET /calendar/{id}?from=&to=` | Cards in a date range (at most 6 weeks) | can view the board |

Board CRUD goes through `admin-post.php` forms on the settings screen, using nonces and
`manage_options`, the same way ColumnKit's settings page works. It isn't exposed over REST in v1.

### 7.2 Move rules (check them on the server, every time)
1. The `{id}` board exists and the post's type is one of the board's post types. Otherwise 404.
2. `current_user_can( 'edit_post', $post_id )`. Otherwise 403.
3. `{to}` is one of the board's lanes. Never accept any value the source would happen to allow. Otherwise 400.
4. Rules for each source:
   - `status`: moving into `publish` or `future` needs the post type's `cap->publish_posts`. Moving
     **out** of `publish` needs `edit_published_posts`. Any move into `future` requires a
     `post_date` in the future, otherwise 400 with "Set a date first".
   - `author`: needs the post type's `cap->edit_others_posts`, and the target user must be able to
     edit that post type.
   - `taxonomy` and `stage`: need `assign_terms` for that taxonomy.
   - `columnkit`: hand straight to `Api::save()`, which applies ColumnKit's own rules.
5. Use the post type's **mapped** capabilities (`get_post_type_object( $pt )->cap->…`), never literal
   strings. ColumnKit's security pass caught this exact bug for CPTs with a custom `capability_type`
   (PR #6).
6. Any `wp_update_post()` call must not set off BoardKit's own `save_post` handlers again (the stage
   meta box). Unhook them around the call. ColumnKit's `EditableColumn::save_value` docblock
   describes the same pattern.

### 7.3 Two people moving the same card
`move`, `due` and `date` all take the card's `modified` (`post_modified_gmt`) as the client last
saw it. If the post has changed since then, return **409** with the current card, and the UI shows
"Updated by someone else — refreshed". This needs no locking.

### 7.4 Activity log
Write a short history entry in post meta for each move: `_bk_log`, capped at the last 50 entries,
each `{t, user, field, from, to}`. Show it in the stage meta box ("Moved to In Review by Sam, 2h
ago"). This also makes debugging easier.

---

## 8. Performance

- Per lane: `WP_Query` with `posts_per_page = 50`, `no_found_rows = true` (get counts from one
  separate `COUNT` per lane, or one grouped query for `stage`/`taxonomy`), and
  `update_post_meta_cache` / `update_post_term_cache` left on so card rendering hits the cache.
- Prime the user caches for all authors and assignees on the page with `cache_users( $ids )` before
  rendering avatars.
- Visibility scope for users without `edit_others_posts`, "my posts OR posts assigned to me": core
  `WP_Query` can't express an `author` *OR* `meta_query` condition directly. Run two queries limited
  to IDs, merge them, then query by `post__in`. That's cheap at 50 per lane.
- Due-date sort with empty dates last: a `meta_query` with a named `EXISTS` clause plus an
  `OR NOT EXISTS` clause, ordered by the named clause. Write a test that pins down the expected order.
- ColumnKit card fields: limit a board to **6 columns per card**, because each one runs `render()`
  for every card.
- Never load every card at once. The calendar range is limited to 6 weeks.

---

## 9. Build phases

Each phase ends with passing unit tests, a smoke script in `tests/smoke/phaseN-*.php`, a
`docs/PITFALLS-phaseN.md` (same format as ColumnKit's), and a version bump with a changelog entry.

| Phase | Scope | Done when |
|---|---|---|
| **0 (ColumnKit repo)** | Add `ColumnKit\Api` v1 and `columnkit/loaded` (§3.1). Move the shared save checks out of `EditManager` into one place. Release 0.7.0. | ColumnKit's existing tests are green, new `ApiTest` covers each method including the 403 and non-editable paths, and the release is published |
| **1. Skeleton** | Main plugin file, autoloader, `Plugin` singleton, copied `GitHubUpdater` and release workflow (§11), `uninstall.php`, test harness | The plugin activates on LocalWP, the update check finds a `v0.1.0` release, `composer test` is green |
| **2. Read-only board** | `BoardRepository` + `normalise()`, the `bk_stage` taxonomy, `LaneSource` with `stage` and `status`, board screen showing cards in lanes, the "Board" link on list tables, built-in card fields, paging per lane | A board for Posts shows the right cards in the right lanes for an Admin, an Editor and an Author (the Author sees only their own and assigned posts) |
| **3. Moving cards** | Drag and drop, the keyboard/menu equivalent, `POST …/move`, all of §7.2, 409 handling, rollback on error, activity log | Each rule in §7.2 has a test, and a contributor can't publish by dragging |
| **4. Editorial fields** | Assignees and due date (meta, meta box, REST, card UI), the filter bar, "Assigned to me", overdue chip | `_bk_assignees` queries use `meta_query` (no `LIKE`) and assigning a user who can't edit the post is refused |
| **5. Board settings** | Create, rename, duplicate and delete boards; lane editor (labels, colours, WIP limits, reorder); the `taxonomy` and `author` lane sources; sort options | Deleting a lane that still has cards is refused, and settings export/import round-trips the data |
| **6. Calendar** | Month and week views, drag to reschedule, the unscheduled tray, the `post_date` vs due-date rules | Rescheduling a published post into the future needs `publish_posts` and the post becomes `future` |
| **7. ColumnKit enhanced mode** | Card fields from a ColumnKit view; the `columnkit` lane source; saving through `Api::save()` | With an ACF select field as the lane source, dragging updates the ACF value (via `update_field`), and with ColumnKit deactivated the board falls back cleanly to standalone |
| **8. Polish** | Mobile layout, `wp.a11y` announcements checked, empty states, a dashboard widget "My cards: due this week" | An accessibility review using keyboard only and a screen reader passes |

---

## 10. Testing

- **Unit:** PHPUnit 10, Brain Monkey and Mockery, set up exactly like `../columnkit/composer.json`
  and `tests/bootstrap.php`. Test every `LaneSource::move()` permission branch, `normalise()`
  migrations, the 409 check, the assignee validation, and the visibility merge in §8.
- **Smoke:** PHP scripts run against the LocalWP site (`wp eval-file tests/smoke/…`), the same
  pattern as ColumnKit's `tests/smoke/`. They create posts and users with specific roles and check
  the REST responses.
- **Manual checklist in each PITFALLS file:** the role matrix (Admin, Editor, Author, Contributor),
  a CPT with a custom `capability_type`, and both ColumnKit on and off.

---

## 11. What to copy from `../columnkit` (and what to change)

| From ColumnKit | Into BoardKit | Changes |
|---|---|---|
| `columnkit.php` (autoloader, constants, `plugins_loaded` boot) | `boardkit.php` | `BK_*` constants, `BoardKit\` prefix, `Update URI: https://github.com/brendanoconnellwp/boardkit`. Register the `columnkit/loaded` listener here (§3.2). |
| `src/Updater/GitHubUpdater.php` | `src/Updater/GitHubUpdater.php` | Namespace; `REPO`; `TRANSIENT` → `bk_github_update`; `CK_BASENAME` → `BK_BASENAME`; `CK_GITHUB_TOKEN` → `BK_GITHUB_TOKEN`; filter → `boardkit/update_cache_ttl`; asset name `boardkit-{version}.zip`. Copy its tests and `docs/PITFALLS-phase10.md` too. All the security points there apply unchanged. |
| `.github/workflows/release.yml` | same | Plugin file name, constant name, zip and folder name |
| `tests/bootstrap.php`, `phpunit.xml.dist`, `composer.json` dev dependencies | same | Namespace and package name |
| `src/Settings/SettingsRepository.php` (pattern only) | `src/Boards/BoardRepository.php` | Different data shape. Keep the per-entity option with autoload off, `normalise()` on read, and the in-request cache. Replace the `LIKE` scan in `configured_screens()` with the `bk_boards` index. |
| `src/Admin/SettingsExporter.php` (pattern only) | `src/Admin/BoardExporter.php` | Board JSON export/import with allowlisted keys |
| `uninstall.php` | same | Delete `bk_board_*` (through the `bk_boards` index), `bk_boards`, `bk_version`, `bk_github_update`. **Don't** delete `bk_stage` terms or `_bk_*` meta by default: that's editorial data. Offer it as an explicit "delete all BoardKit data" setting that's off by default. |
| `docs/PITFALLS-*.md` format | `docs/PITFALLS-phaseN.md` | — |

**Don't copy** `Columns/`, `ListScreens/`, `Support/Editability.php` or `Support/ColumnPresenter.php`.
That logic belongs to ColumnKit, and BoardKit reaches it through `ColumnKit\Api`.

---

## 12. Proposed file tree

```
boardkit/
├── boardkit.php
├── uninstall.php
├── readme.txt
├── composer.json
├── phpunit.xml.dist
├── .github/workflows/release.yml
├── assets/
│   ├── board.js  calendar.js  board.css
│   └── vendor/sortable.min.js  (+ LICENSE)
├── languages/boardkit.pot
├── src/
│   ├── Plugin.php
│   ├── Boards/        BoardRepository.php  Board.php (value object)
│   ├── Lanes/         LaneSource.php  StageSource.php  StatusSource.php  TaxonomySource.php
│   │                  AuthorSource.php  ColumnKitSource.php  LaneSourceRegistry.php
│   ├── Editorial/     StageTaxonomy.php  Assignees.php  DueDate.php  ActivityLog.php  MetaBox.php
│   ├── Rest/          BoardsController.php  CardsController.php  CalendarController.php
│   ├── Query/         CardQuery.php  (visibility scope, paging, sort)
│   ├── Admin/         Menu.php  BoardPage.php  CalendarPage.php  SettingsPage.php
│   │                  BoardExporter.php  Assets.php  ListTableLink.php  DashboardWidget.php
│   ├── Integrations/  ColumnKit.php  (the only file that touches ColumnKit\Api)
│   └── Updater/       GitHubUpdater.php
├── tests/{bootstrap.php, Unit/, smoke/}
└── docs/PITFALLS-phase*.md
```

---

## 13. Open questions and v2 ideas

1. **One post type or several per board in v1?** The spec allows several, but showing several in
   the board UI makes lane sources harder (taxonomies differ by type). Suggestion: allow only one
   per board in v1 and keep the array shape.
2. **Notifications:** email the assignee when assigned or when a post enters a lane. A per-lane
   "notify on enter" setting through `wp_mail`, with `wp_schedule_single_event` so it doesn't slow
   the request.
3. **Comments on cards:** use WordPress comments with a custom `comment_type` (`bk_note`) on the
   post, kept out of front-end comment queries.
4. **ColumnKit filters on boards:** expose `FilterableColumn::render_filter`/`apply_filter` through
   `Api` v2 so a board's filter bar can reuse a view's filters.
5. **Hand-ordering cards** within a lane: a per-board order stored as `_bk_rank_{board}`
   (fractional-index strings) to avoid renumbering every card.
6. **Suite plugin:** a small "Kit" plugin that installs and updates ColumnKit and BoardKit together,
   if you ever want a single download.
