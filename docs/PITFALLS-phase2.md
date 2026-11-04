# Phase 2 — Pitfalls Reviewed

**Read-only board:** `BoardRepository` + `normalise()`, the `bk_stage` taxonomy, the `stage` and
`status` lane sources, `CardQuery` (visibility, paging, counts), the REST read routes, the board
screen, and the "Board" link on list tables.

---

## Security

### 1. `post__in => []` means "no restriction"
**What:** A user who can see no posts gets an empty visible-ID list. Passed straight to WP_Query as `post__in => []`, it is *ignored*, and the user sees every post.
**How:** `CardQuery::build_args()` turns an empty set into `post__in => [0]`. When a lane already has a `post__in`, the two are intersected and the same rule applies.
**Test/proof:** `CardQueryTest::test_restricted_user_who_sees_nothing_gets_post_in_zero_not_empty`, `test_visibility_intersects_with_a_lane_post_in`.

### 2. "Posts I could open in the editor" is more than `author = me`
**What:** The spec says limited users see their own posts plus assigned posts. But core's `map_meta_cap` adds more rules: a Contributor can't edit their own *published* post, and an Author can't edit someone else's post even if assigned.
**How:** `Visibility::visible_ids()` merges the two ID-only queries (author = me, `_bk_assignees` = me), then keeps only IDs where `current_user_can( 'edit_post', $id )`. The result is computed once per request and used as `post__in` for every lane and count, so paging stays exact.
**Test/proof:** smoke `phase2` "contributor doesn't see own published post", "author doesn't see a post assigned to them they can't edit", "author lane counts are scoped too".
**Note:** As things stand, an Author or Contributor can never be assigned to a post they can't edit (spec §4.5, enforced in Phase 4). The assigned branch only matters when another plugin grants edit rights through `map_meta_cap`.

### 3. Mapped capabilities, never literals
**What:** Hard-coding `edit_posts` breaks CPTs with a custom `capability_type`. ColumnKit PR #6 fixed this same bug.
**How:** `Visibility::cap()` reads `get_post_type_object()->cap->…` for both `edit_posts` (can view the board) and `edit_others_posts` (unrestricted).
**Test/proof:** `CardQueryTest::test_can_view_board_uses_the_mapped_capability` (a capability_type of `article` → `edit_articles`).

### 4. Post titles in the browser
**What:** Titles are written by authors. They pass through `get_the_title()` (which may add markup) and then into JS.
**How:** `CardPresenter::text()` strips tags and decodes entities. The REST payload is plain text, and `board.js` builds every node with `textContent` / `setAttribute`, never `innerHTML`. Inline config uses `wp_json_encode` with `JSON_HEX_*`. Links are used only if they're `http(s)` (`safeUrl()`). Otherwise the title is a plain `<span>`, not an `<a href="">`.
**Test/proof:** smoke `phase2` "card title is plain text". In a test page, a title of `<img onerror>` rendered as text and the error handler never ran. A `javascript:` edit link was dropped.

### 5. The lane in the URL is checked against the board's own lanes
**What:** `GET …/lanes/{lane}/cards` passes the lane into a `tax_query` or `post_status`. An arbitrary value would let a caller query statuses or terms the board doesn't show.
**How:** `get_cards()` returns 404 unless `$board->has_lane( $lane )` or the lane is the `__unsorted` sentinel. The route regex limits the characters as well.
**Test/proof:** `BoardsControllerTest::test_unknown_lane_is_404_before_any_query`; smoke "unknown lane 404".

### 6. Trash, auto-draft and inherit never appear
**How:** Every query uses `StatusSource::board_statuses()` (what the admin "All" view lists, minus those three).
**Test/proof:** `LaneSourceTest::test_board_statuses_exclude_trash_auto_draft_inherit`; smoke "trashed post never shown".

## Data

### 7. Seeding must not resurrect deleted boards
**What:** Creating the starter board whenever there are no boards would bring "Editorial" back every time someone deletes it.
**How:** `seed_default()` runs only when the `bk_boards` option doesn't exist at all (`get_option(…, null) === null`). An empty array (every board deleted) is respected.
**Test/proof:** `BoardRepositoryTest::test_seed_runs_once_and_respects_an_emptied_index`.

### 8. Lane values must be unique
**What:** A card finds its lane by value. Two lanes with the same value would make one of them unreachable.
**How:** `normalise()` keeps the first lane with a given value and drops later duplicates, along with empty values.
**Test/proof:** `test_normalise_drops_duplicate_and_empty_lane_values`.

### 9. A board whose lane source isn't available still renders
**What:** A board saved with `kind: columnkit` would fatal or show nothing while ColumnKit is off.
**How:** `normalise()` keeps the stored source, so reactivating ColumnKit restores it. `LaneSourceRegistry::for_board()` falls back to `stage`, and the cards then appear in Unsorted. Phase 7 adds a settings notice.

## Performance

### 10. Paging without COUNT(*)
**How:** Card pages use `no_found_rows` and fetch 51 rows; the 51st only tells us there's a next page. Lane counts use one `found_posts` query per lane (`fields => ids`, one row). That's N+1 small queries for N lanes. A grouped count is a later optimisation (PLAN §5).

### 11. Stable paging
**What:** Ordering by `modified` alone gives an unstable order when many posts share a timestamp (imports), so "Load more" could repeat or skip cards.
**How:** `orderby => [ <field> => dir, 'ID' => dir ]`.
**Test/proof:** `CardQueryTest::test_sort_and_stable_tiebreak`.

### 12. One query for users
**How:** `CardPresenter::prime_users()` calls `cache_users()` for every author and assignee on the page before avatars are built.

## Manual checklist
- [x] Role matrix (Admin, Editor, Author, Contributor, Subscriber, logged out): smoke `phase2`.
- [x] Layout at 1280px and 400px, collapsible lanes, Load more announces through `wp.a11y.speak` (test page with a faked `apiFetch`).
- [ ] The real board screen in wp-admin with a logged-in browser (the browser extension wasn't connected for this phase).
- [ ] A CPT with a custom `capability_type` on a live site (unit-tested only).
- [ ] ColumnKit off: BoardKit works the same (no ColumnKit code is used in Phase 2).
