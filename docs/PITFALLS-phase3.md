# Phase 3 — Pitfalls Reviewed

**Moving cards:** `MoveService` (shared rules), `LaneSource::move()` for stage and status,
`POST boardkit/v1/boards/{id}/cards/{post_id}/move`, the activity log, and three equivalent ways
to move a card in `board.js`: drag, keyboard and the ⋯ menu.

---

## Security

### 1. The server re-checks everything, every time (spec §7.2)
**What:** The UI only offers allowed lanes. A hand-made request can ask for anything.
**How:** `MoveService::move()` runs, in order: post exists, belongs to the board's post type and isn't trashed (404); `edit_post` (403); target is one of the board's lanes and never Unsorted (400); conflict check (409); then the source's own rule and the write.
**Test/proof:** `MoveServiceTest` covers each rule (17 tests); smoke `phase3` runs 31 checks.

### 2. A Contributor can't publish by dragging (phase "done when")
**What:** `wp_update_post()` does no capability checks. Anything that can call it can publish.
**How:** `StatusSource::move()` requires the post type's mapped `publish_posts` to move into `publish`, `future` or `private` (all three publish), and `edit_published_posts` to move out of `publish`. Moving into `future` needs a future `post_date_gmt`, otherwise 400 `bk_needs_date`.
**Test/proof:** `test_contributor_cannot_publish_schedule_or_make_private_by_dragging`; smoke "contributor → publish is 403", "contributor post still a draft".

### 3. Mapped capabilities, never literals (rule 5)
**How:** Status rules read `get_post_type_object()->cap->…`, and stage rules read `get_taxonomy( 'bk_stage' )->cap->assign_terms`. The whole of `MoveServiceTest` runs against `capability_type: article`, so any literal `publish_posts` would fail it.

### 4. `wp_set_object_terms()` with a slug creates terms
**What:** Passing a slug that has no term silently creates a new term named after the slug. A crafted request, or a renamed lane, would pollute `bk_stage`.
**How:** `StageSource::move()` looks the term up by slug and calls `ensure_terms()` if it's missing. It then assigns by integer term ID with `append = false`, which also fixes posts that somehow have two stage terms.
**Test/proof:** the stub in `MoveServiceTest` asserts an int ID and `append === false`; smoke "no stray stage terms created".

### 5. A 409 must not serialise a WP_Post
**What:** `MoveService` puts the current post into the 409's error data so the client can redraw it. If that went into the REST response unchanged, it would expose every column, including `post_password`.
**How:** `CardsController::with_card()` swaps the post for a card (the same plain-text shape as every other card) and only if the user can `edit_post` it.
**Test/proof:** smoke "409 does not leak a WP_Post".

## Correctness

### 6. Conflicts are detected on the lane, not the timestamp (PLAN §0 item 2)
**What:** Stage, taxonomy and meta writes don't change `post_modified_gmt`, so a timestamp check would let two people move the same card without either noticing.
**How:** The client sends `from`, the lane it last saw. `lane_of( $post ) !== $from` → 409 with the current card. For sources that write the post row (status, author) the client's `modified` is compared as well.
**Notes:**
- A new draft's `post_modified_gmt` is `0000-00-00 00:00:00` until its first update. That's core behaviour: `wp_insert_post` copies `post_date_gmt`, which is zero for drafts. Comparing still works because both sides are zero.
- `post_modified_gmt` has one-second resolution. Two edits within the same second look identical. The lane check doesn't depend on it.
**Test/proof:** `test_lane_changed_since_client_loaded_is_409_with_post`, `test_stage_move_ignores_modified_but_status_move_checks_it`; smoke "stale lane → 409", "status move with stale modified → 409".

### 7. Moving to 'future' must keep the date
**What:** `wp_update_post()` turns `future` with a past date into `publish`. A badly formed update could also drop the date.
**How:** The stored `post_date` and `post_date_gmt` are passed through explicitly. Publishing a scheduled post goes live now, as core's Publish button does.
**Test/proof:** `test_future_needs_a_future_date`, `test_publishing_a_scheduled_post_goes_live_now`; smoke "scheduled date kept".

### 8. No re-entry into BoardKit's own save_post handlers (rule 6)
**How:** `MoveService::is_moving()` is true only while a source writes. Phase 4's stage meta box returns early on it. This flag replaces unhooking: it survives exceptions (`finally`), and nested writes count correctly.
**Test/proof:** `test_is_moving_is_true_only_during_the_write`; smoke "is_moving() is false again".

### 9. Dragging toward a lane that refuses drops moved the card somewhere else
**What:** SortableJS leaves the card in the last lane that *accepted* it while the pointer passed over. Dragging from Pitch towards Unsorted, which refuses drops, crossed Draft, and the card was moved to Draft without the user meaning to.
**How:** `onEnd` checks the lane under the release point (`elementFromPoint`, touch-aware). If it isn't the lane Sortable chose, the card goes back and nothing is sent.
**Test/proof:** a real mouse drag in a test page with a faked `apiFetch` (Playwright). Before the fix the card went to Draft and a POST was sent; after it, the card stays put and no request is made.

## Accessibility (spec §5.5)

### 10. Three equivalent ways to move, one code path
**How:** Drag, keyboard and the ⋯ → "Move to …" menu all call `moveCard()`. Keyboard: Space picks up; ←/→ chooses among the lanes the card may enter (plus its own, which cancels); Space or Enter drops; Escape cancels. Each step is announced with `wp.a11y.speak()`, focus stays on the card after a move, and a hidden help line (`aria-describedby`) explains Space.
**Below 782px:** SortableJS is turned off and the menu is the way to move. It re-checks when the viewport changes.
**Menu:** `role="menu"` with `menuitem`s, focus goes to the first item, ↑/↓/Home/End move between items, Escape returns focus to the ⋯ button, and Tab closes it. The ⋯ button is at least 24×24 px (WCAG 2.2 target size). The menu opens upwards near the bottom of a lane's scroll area so it isn't clipped.
**Test/proof:** real key presses and clicks in the test page (Playwright): pick up, choose a lane, drop, cancel, the menu path, and Escape returning focus.

### 11. Optimistic UI must undo exactly
**How:** `moveCard()` remembers the original list and index, moves the card and updates both lane counts and the "No cards" placeholders. On an error it puts everything back. On a 409 it redraws the card from the server's data in its real lane and shows a warning ("Updated by someone else — refreshed").
**Test/proof:** test page: a refused move puts the card back in its exact position with counts restored. A conflict moves it to the lane the server reports, with the new title.
**Open:** in the first test-page run, one card's position and one lane count ended up wrong after a keyboard move followed by a refused move and a conflict. Six replays of the same sequence, with synthetic events and real input, never reproduced it. Watch for it in manual testing.

## Manual checklist
- [x] Role matrix for moves (Admin, Editor, Author, Contributor, Subscriber, logged out): smoke `phase3`.
- [x] Drag, keyboard and menu in the test page with a faked `apiFetch`.
- [ ] The real board in wp-admin while logged in: drag, keyboard, menu, and two browser windows moving the same card (the 409 path).
- [ ] Screen reader pass (NVDA): pick-up and drop announcements. Planned for Phase 8.
- [ ] REST nonce: `rest_do_request()` in smoke tests bypasses cookie auth, so check in the browser that a move without the `X-WP-Nonce` header gets a 401 or 403.
