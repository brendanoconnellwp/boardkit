# BoardKit — Build Plan

How to build `spec.md` (v0.1), in what order, and the gaps to fix before coding. References like
§7.2 point at `spec.md`. Assertions about ColumnKit below were checked against `../columnkit` at
v0.7.1 (commit `6e888b0`) on 2026-11-04.

---

## 0. Spec corrections (do these first)

What checking `../columnkit` turned up. Items 1–5 change the design. Items 6–8 are small fixes.

| # | Spec says | Actual | Change |
|---|---|---|---|
| 1 | Phase 0 ships as **ColumnKit 0.7.0** | ColumnKit is already at **0.7.1**. `v0.7.0` is taken. | Ship `Api` as **ColumnKit 0.8.0**. In §3.1 and §9, change 0.7.0 → 0.8.0. |
| 2 | §7.3: detect conflicts with `post_modified_gmt` | Stage (`wp_set_object_terms`), assignee, due and ACF/meta writes **don't change `post_modified`**. Two people dragging the same card into different stage lanes would both succeed without either seeing the other. | **Compare-and-swap on the field value.** The client sends `from` (the lane, due date or date it last saw). The server returns 409 if `lane_of($post) !== from`. Keep `modified` as a second check for status and author moves. |
| 3 | §3.1: `Api::save()` returns `true` or a `WP_Error` | `EditableColumn::save_value()` returns `void` and **fails silently**. For example, `PostStatusColumn` just `return`s when a contributor tries to publish. `Api::save()` would report `true` and the UI would show a move that never happened. | After saving, `Api::save()` re-reads `get_raw_value()`. If the value didn't change to the target, return `WP_Error('ck_not_saved', …, 403)`. Add an `ApiTest` for this case. |
| 4 | §3.1: `Api::save()` takes a `$set_id` | `ajax_inline_save` passes the set through `SetResolver::allowed()`, because views can be limited to certain roles. | `Api::sets()`, `columns()` and `save()` must all resolve through `SetResolver::allowed()` / `visible_sets()` for the **current user**. Otherwise BoardKit becomes a way around role-restricted views. |
| 5 | §3.3: any editable select column can be a lane source | `PostStatusColumn` is editable as a `select`, so it would be offered as a `columnkit` lane source. Its rules are weaker than §7.2: it has no `edit_published_posts` check for moving out of `publish` and no future-date check for `future`. | `Integrations\ColumnKit` excludes column types that duplicate a native source (`post_status`, and author or taxonomy columns if they ever become select-editable). Those boards use the native `status`, `author` or `taxonomy` source instead. |
| 6 | §8: due-date sort uses `EXISTS` + `OR NOT EXISTS`, ordered by the named clause | MySQL sorts `NULL` **first** on `ASC`. ColumnKit's `MetaSortSql` docblock says the same. The suggested query puts undated posts at the *top*. | Add a `posts_clauses` filter scoped to the query: `ORDER BY ({alias}.meta_value IS NULL), {alias}.meta_value ASC`. Pin it with a smoke test, since a unit test can't run SQL. |
| 7 | §3.1: "the value sanitised" | `ajax_inline_save` uses `sanitize_text_field` and then switches to `sanitize_textarea_field` for `textarea` inputs. | The shared save routine (§2 below) takes the **unsanitised** value and sanitises it according to the input type. |
| 8 | §2: updater "about 220 lines", workflow "about 100" | 410 and 152 lines | Wording only. Copying them is still the right call. |
| 9 | §3.1 `Api::save()` and §4.3 `LaneSource::move()` return `true\|\WP_Error` | The standalone `true` type is **PHP 8.2+**. Both plugins support PHP 8.0, so it's a fatal parse error on 8.0 and 8.1. | Declare `bool\|\WP_Error` and document "never false". Done in ColumnKit 0.8.0. Do the same for `LaneSource`. |

---

## 1. Decisions on the open questions (§13)

| Q | Decision | Why |
|---|---|---|
| 1. Several post types per board? | **One per board in v1.** Keep the `post_types` array. `normalise()` cuts it to `[0]`. | Lane sources, taxonomies and capabilities all depend on the post type. Several types multiply the §7.2 rules. |
| 2–6 | Leave for v2. Nothing in v1 should block them. | Keep the board schema versioned. `_bk_log` already gives notifications a place to hook in. |

**Changed after trying v0.3 (2026-11-04, decided by the user):**

- **Stage lanes can also set the WordPress status.** The spec (§4.2) kept stage and status fully separate. In use, that was confusing: the starter board had lanes called Draft, Scheduled and Published that changed nothing in WordPress. Now each stage lane has an optional `status`. The starter board is Pitch → Writing → Editing → Ready (`pending`) → Scheduled (`future`) → Published (`publish`). Every status change goes through `Moves\StatusChange`, so the §7.2 publish rules apply to stage boards too. Lanes without a status stay stage-only, so moving a published post to "Writing" doesn't unpublish it.
- **Scheduling asks for a date on the board.** Dropping a card into a lane that sets `future` opens a date-and-time picker on the card, in site time with the timezone shown. Cancelling puts the card back. Previously this waited for the calendar (Phase 6).
- **Existing starter boards migrate on read** (schema 1 → 2), but only if their lanes are exactly the old starter lanes. Lane values (the stage term slugs) are kept, so posts stay where they are. An edited board is never changed, and nothing on it starts publishing.

Other decisions this plan makes:

- **Store hand-picked lane order on the board**, not on terms. Term order means term meta and another query.
- **Cards are rendered on the server as JSON, not HTML.** REST returns card data. `board.js` builds the DOM with `textContent` and escaped attributes. The one exception: ColumnKit fields return `Api::render()` HTML, which is already escaped, and it's placed in a known wrapper. This keeps the XSS surface to one documented spot.
- **Count all lanes in one query** for `stage`/`taxonomy` (a grouped term-relationship count). For `status`, use `wp_count_posts()`. For `author`, run a grouped `COUNT` on `post_author`. Users without `edit_others_posts` get counts computed from the merged ID list (§8).

---

## 2. Architecture

```
            wp-admin (board.js / calendar.js, SortableJS, wp.apiFetch, wp.a11y)
                                     │  REST boardkit/v1  (nonce via apiFetch)
                                     ▼
 ┌──────────────────────────── BoardKit\Rest ────────────────────────────┐
 │ BoardsController   CardsController (move/assignees/due/date)   CalendarController │
 └───────┬───────────────────────┬───────────────────────────────┬──────┘
         │                       │                               │
         ▼                       ▼                               ▼
  Boards\BoardRepository   Moves\MoveService ◄── one place for §7.2 rules 1–3, 5, 6 + CAS (§0.2)
  (bk_board_*, bk_boards,        │                    + ActivityLog write
   normalise(), req cache)       ▼
         │               Lanes\LaneSourceRegistry ─► Stage | Status | Taxonomy | Author | ColumnKit
         │                       │                    (each: rule 4 + save)            │
         ▼                       ▼                                                     ▼
  Query\CardQuery ──────► WP_Query (per lane, 50, visibility merge, due sort)   Integrations\ColumnKit
  Query\CardPresenter ──► card JSON (built-in fields | ColumnKit render)  ───────►  ColumnKit\Api v1
                                                                         (only file that touches it)
 Editorial\  StageTaxonomy · Assignees · DueDate · ActivityLog · MetaBox (save_post, unhooked in moves)
 Admin\      Menu · BoardPage · CalendarPage · SettingsPage(admin-post) · BoardExporter · Assets
             ListTableLink (views_edit-{pt}) · DashboardWidget
 Updater\    GitHubUpdater (copied and renamed)
```

**`Moves\MoveService`** is the one structural addition to the spec's file tree. Without it, the
board-move, calendar-date and due endpoints would each repeat rules 1–3, 5 and 6 and the
conflict check. The spec's own reasoning (one copy of each security check) argues for one place.
`LaneSource::move()` then handles only the source-specific rule 4 and the write.

**Testability split.** Brain Monkey can't run `WP_Query`. So `CardQuery` has a pure
`build_args(board, lane, filters, user): array`, which gets unit tests, and a thin `run()`, which
gets smoke tests. Lane sources split the same way: `query_args_for_lane()` and the permission
branches of `move()` are unit tested, and the writes are smoke tested.

### Request flow: moving a card
1. `board.js` moves the card optimistically, then sends `POST /boards/editorial/cards/42/move {to, from, modified}`.
2. `permission_callback`: the user is logged in and can view the board (§5.2). This is only a cheap
   gate. The real checks happen in step 3.
3. `MoveService`: board exists and post type matches (404) → `edit_post` (403) → `to` is in the
   board's lanes (400) → **CAS**: `lane_of === from` (409, with the current card) → `source->move()` (rule 4
   plus the write, with `MetaBox` unhooked) → `ActivityLog::add()`.
4. Returns `{card, lane_counts}`. On an error, the client rolls back, shows the notice and calls `wp.a11y.speak()`.

---

## 3. Phase plan

Each phase ends with: unit tests green, `tests/smoke/phaseN-*.php` passing on LocalWP,
`docs/PITFALLS-phaseN.md`, a version bump and a changelog entry (§9). Smoke scripts follow ColumnKit's
`check()` pattern and run with `localwp-wp --site="…" eval-file`.

### Phase 0 — ColumnKit `Api` v1 (in the **ColumnKit** repo, released as 0.8.0)
- Pull the post branch of `EditManager::ajax_inline_save()` (lines 105–158) out into
  `Support\InlineSaver::save_post_column( string $screen_key, string $set_id, string $col_id, int $post_id, string $unsanitised ): array|WP_Error`
  (returns `{html, raw}`). Both the AJAX handler and `Api::save()` call it. Keep `core_*` field handling in the
  AJAX path only, since BoardKit doesn't need it.
- Add `src/Api.php` from §3.1 with the fixes in §0, items 3, 4 and 7. Fire `do_action( 'columnkit/loaded', Api::VERSION )` at the end of `Plugin::boot()`.
- Tests: the existing `InlineSaveSecurityTest` and `EditManagerTest` still pass unchanged. That
  proves the move didn't change behaviour. New `ApiTest` cases: unknown column → `''`/null; not editable → null/400;
  no `edit_post` → 403; a role-restricted set falls back to an allowed set; a silent no-op → `ck_not_saved`; boolean → `['1'=>Yes,'0'=>No]`.
- **Risk:** this is a security-sensitive refactor in another repo. Review it on its own, before any BoardKit code depends on it.

### Phase 1 — Skeleton (v0.1.0)
- `boardkit.php`: constants, autoloader, activation hook (`bk_version`, autoload off), `plugins_loaded` boot.
  Register the `columnkit/loaded` listener **at file scope**, before ColumnKit's priority-10 boot.
- `src/Plugin.php` singleton. Copy `GitHubUpdater`, its test, `release.yml` and the test harness with the §11 renames.
  Add a grep check that fails CI if `CK_`, `ck_` or `columnkit` turns up in a copied file. The renames are easy to miss.
- `uninstall.php` as in §11, with the "delete all data" option off by default.
- **Done when:** the plugin activates, the updater finds `v0.1.0`, and `composer test` is green.

### Phase 2 — Read-only board (v0.2.0)
Build in this order, since each step depends on the one before:
1. `Boards\Board` (value object) + `BoardRepository` (`get`, `all`, `save`, `delete`, `bk_boards` index,
   `normalise()` including the one-post-type cut, in-request cache). Add one hard-coded default board for testing.
2. `Editorial\StageTaxonomy` (register with the §4.2 flags, `ensure_terms(board)`).
3. `Lanes\LaneSource` + `StageSource` + `StatusSource` + `LaneSourceRegistry`. Only read methods at this point.
4. `Query\CardQuery`: per-lane args, visibility merge (two ID-only queries, then `post__in`), `cache_users()`,
   counts (§1), "Unsorted" lane (`NOT IN` all lane values / `NOT EXISTS` taxonomy).
5. `Query\CardPresenter` with built-in fields → card JSON.
6. `Rest\BoardsController` (`GET /boards/{id}`, `GET …/lanes/{lane}/cards`).
7. `Admin\Menu`, `BoardPage`, `Assets` (hook-suffix gated), `ListTableLink`. Read-only `board.js`, and `board.css`.
- Unit tests: `normalise()` migrations, `build_args()` per source and role, the visibility merge, Unsorted args.
- Smoke: Admin, Editor and Author see the right cards. The Author sees their own posts plus posts assigned to them.

### Phase 3 — Moving cards (v0.3.0)
- `Moves\MoveService` + the `move()` write path on Stage and Status, CAS (§0.2), MetaBox unhooking.
- `Editorial\ActivityLog` (`_bk_log`, capped at 50).
- `POST …/move`. In `board.js`: SortableJS drag, the keyboard pick-up/arrow/drop state machine, the ⋯ → *Move to…* menu,
  optimistic UI with rollback, 409 refresh, and `wp.a11y.speak()`.
- Unit tests: every §7.2 branch, including a CPT with a custom `capability_type` (mapped caps) and the contributor → publish case (403).
- **Build the keyboard path first and drag second.** Both call the same `moveCard(cardId, toLane)` function. Then
  the accessibility requirement can't slip to the end.

### Phase 4 — Editorial fields (v0.4.0)
- `Assignees` (one meta row per user, replace = delete + add, each user checked for `edit_post`), `DueDate` (`Y-m-d` or no row).
  `register_post_meta` with `auth_callback`.
- `Editorial\MetaBox`: stage select, assignees, due date, recent log. Nonce and `edit_post` check on `save_post`.
- `POST /cards/{id}/assignees`, `POST /cards/{id}/due` (CAS on the previous value). Filter bar with URL state. Overdue chip.
  Due sort via the `posts_clauses` fix (§0.6).
- Smoke: "assigned to me" uses `meta_query` and no `LIKE`, verified with `$wpdb->last_query`. Undated posts sort last.

### Phase 5 — Card panel (v0.5.0) — added 2026-11-04 at the user's request
Click a card to open a side panel (like a Notion page peek). The board stays usable beside it.
- **Details tab:** title, excerpt, featured image (the core media modal; only for users with `upload_files`), plus the
  card's stage, assignees and due date. Fields appear only if the post type supports them.
  `GET /boards/{id}/cards/{post_id}` returns the details. `POST …/details {title, excerpt, featured_media, modified}`
  goes through `Editorial\CardDetails`:
  - `edit_post` check;
  - compare-and-swap on `post_modified_gmt` (409 + card);
  - refused while another user holds the post lock (`wp_check_post_lock`, 409 `bk_locked`);
  - `wp_slash()` before `wp_update_post` (it unslashes), and the post's status, date and content are never passed;
  - the featured image must be an image attachment the user can read.
- **Write tab:** the real block editor (or classic editor) in an iframe: `post.php?action=edit&bk_panel=1`. Under
  `bk_panel` the editor hides the admin menu and toolbar, nothing else changes. Same origin, so `X-Frame-Options:
  SAMEORIGIN` allows it.
  - Why not a home-made editor: it would lose blocks, autosave, revisions, post locks and plugin fields.
  - Closing the panel or switching tabs with unsaved editor changes offers "Save and close" (`core/editor`
    `savePost`) or "Discard". Never a silent loss.
  - The card is refetched when the panel closes.
- **"+ Add card" in each lane:** `POST /boards/{id}/cards {title, lane}` creates a post of the board's type.
  - Rules: `create_posts` for the post type; only lanes that set no status, or set draft or pending; status lanes
    draft and pending only.
  - The new post is a draft (or pending if the lane says so), with the stage set by term ID. Logged as `created`.
- The panel's state is kept in the URL (`&card=`), and it's a labelled, non-modal `role="dialog"`. Escape closes it
  and returns focus to the card. It's full screen below 782px.

### Phase 6 — AI assists (v0.6.0) — added 2026-11-04
- Only when core's AI client exists (`wp_ai_client_prompt`, WP 7.0+) and a provider is configured. Otherwise there's no UI.
- In the panel: suggest titles, write the excerpt or meta description, outline from the pitch, generate a featured
  image (if the model supports images).
- Suggestions only; the user accepts or edits each one. Needs `edit_post`, with a per-user rate limit (the site pays
  per call).
- Meta description: the excerpt by default, or the active SEO plugin's field when one is detected (Yoast, Rank Math,
  SEOPress).

### Phase 7 — Board settings (v0.7.0)
- `Admin\SettingsPage` (admin-post handlers, `manage_options` + nonce): create, rename, duplicate, delete board; lane editor
  (label, colour, WIP, reorder); sort; card fields.
- `TaxonomySource` (top level only, "+N" chip), `AuthorSource` (capped list plus "Other").
- Deleting a lane: refuse while it has cards, or offer "move cards to…" and move them in batches through `MoveService`.
- `Admin\BoardExporter` with allowlisted keys and an import round trip.
- Check that lane colours meet WCAG AA contrast when they're saved (§5.5). Pick the text colour automatically.

### Phase 8 — Calendar (v0.8.0)
- `Rest\CalendarController` (range capped at 42 days), `POST /cards/{id}/date` through `MoveService`.
- `calendar.js`: month and week grids, drag between days, keyboard equivalent, "Unscheduled" tray.
- `post_date` rules: moving a published post to a future date → `future`, needs `publish_posts`. Draft date moves keep
  the status. Compute timezones with `get_gmt_from_date()`, and include a smoke test that runs with a non-UTC site timezone.

### Phase 9 — ColumnKit enhanced mode (v0.9.0)
- `Integrations\ColumnKit::enable()`: register `ColumnKitSource` and the card-field option.
- `ColumnKitSource` applies the native-source denylist (§0.5). `move()` → `Api::save()` and maps its `WP_Error` status codes.
- Card fields from a view, capped at 6 columns.
- **Fallback:** if a board's `lane_source.kind === 'columnkit'` or `cards.source === 'columnkit'` and ColumnKit isn't loaded,
  `normalise()` reads it as a stage board with built-in cards and **keeps** the stored settings, so reactivating ColumnKit
  restores them. The settings screen shows a notice.
- Smoke: an ACF select lane source updates the field via `update_field`. Deactivating ColumnKit falls back cleanly.

### Phase 10 — Polish (→ 1.0.0)
- Mobile layout below 782px (stacked, collapsible lanes, no drag), empty states, "My cards: due this week" dashboard widget.
- Accessibility review: keyboard only, plus NVDA (Windows) and VoiceOver.
- Full role matrix × {ColumnKit on, off} × {post, custom-capability CPT}, recorded in `PITFALLS-phase10.md`.

---

## 4. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Phase 0 refactor changes ColumnKit's inline-edit security | Med | Existing security tests must pass with no edits. Review separately. Release before BoardKit Phase 7. |
| Silent lost updates on meta and term moves | High if unaddressed | CAS on field value (§0.2) |
| `columnkit` lane source bypasses status rules | Med | Native-source denylist (§0.5) |
| Visibility merge gets slow on large sites | Low | IDs-only queries, `posts_per_page` 50 for each branch. Check with Query Monitor at 10k posts. |
| Keyboard drag left until last | Med | Build it before drag (Phase 3) |
| Copied updater still references `CK_`/`ck_` | Med | CI grep check (Phase 1) |

## 5. Revisit as it grows
- **Hand-ordering:** fractional-index `_bk_rank_{board}` (§13.5). The current sort-by-field design doesn't block it.
- **Counts:** cache them in a short-lived transient for each board and user role, cleared on `set_object_terms` / `transition_post_status`.
- **Live updates:** if conflicts happen often, poll `GET /boards/{id}?since=` every 30s before thinking about websockets.
- **`_bk_log`:** if history becomes a feature, move it to a custom table.
