# Phase 4 — Pitfalls Reviewed

**Editorial fields:** assignees (`_bk_assignees`) and due dates (`_bk_due`) with their REST
routes, the editor meta box (stage, assignees, due date, recent activity), the filter bar, and
sorting by due date.

---

## Security

### 1. "Assigned to me" must be an equality match, not LIKE (phase "done when")
**What:** Storing assignees as one serialised array would force `LIKE '%"5"%'`. That's slow, and it matches the wrong users (5 also matches 15 or 50).
**How:** One meta row per user (`add_post_meta( …, false )`). `Filters::apply()` adds `[ 'key' => '_bk_assignees', 'value' => '5', 'compare' => '=' ]`.
**Test/proof:** `test_assigned_to_me_is_meta_equality_never_like`. Smoke `phase4` captures the real SQL through `posts_request` and checks for `meta_value = '<id>'` with no `LIKE`.

### 2. Assignees must be able to edit the post (phase "done when")
**What:** If the picker were the only check, a hand-made request could assign anyone, for example to get a post onto someone's "assigned to me" list.
**How:** `Assignees::set()` refuses the **whole** request (400 `bk_bad_assignee`) if any user doesn't exist or can't `edit_post` this post. Nothing is saved. The meta box uses the same function.
**Test/proof:** `test_assigning_someone_who_cannot_edit_the_post_is_refused_and_nothing_changes`; smoke "editor + author on an admin post → 400", "…and nothing was saved", "meta box: ineligible assignee refused".

### 3. Field writes need edit_post and are scoped to the board
**How:** The assignee and due routes sit under `/boards/{id}/cards/{post_id}/…` (the spec had `/cards/{id}/…`), so they pass the same gate as moves. The post must belong to the board's post type and not be trashed (404), and the user needs `edit_post` (403).
**Test/proof:** smoke "contributor can't change assignees on an admin post (403)".

### 4. The people list exposes little
**What:** The assignee picker and the author filter need a list of users. `GET …/people` must not become a way to read user records.
**How:** It returns only `id`, `name` and `avatar`, for users holding the post type's mapped `edit_posts` (at most 20, with optional search). Anyone who can view the board can call it, the same audience that sees author names on cards.
**Test/proof:** smoke "people returns only id, name, avatar". In the test page, a name containing `<script>` displayed as text.

### 5. Meta box: nonce, edit_post, and no re-entry
**How:** `save()` returns early during BoardKit's own writes (`MoveService::is_moving()`), on autosaves and revisions, without a valid nonce, without `edit_post`, and for post types with no board. `register_post_meta` adds an `auth_callback` requiring `edit_post` and keeps both keys out of REST.
**Test/proof:** smoke "meta box: bad nonce changes nothing". The smoke test also fires a real `save_post` through a status move while `$_POST` holds a meta-box payload, and the due date isn't overwritten.

### 6. The meta box's stage control never publishes
**What:** On the board, Ready, Scheduled and Published also change the WordPress status. In the editor, a dropdown that published a post when someone clicked "Save draft" would be a nasty surprise.
**How:** `MetaBox::save_stage()` sets the stage term only. The editor's own Publish and Schedule controls handle status.

## Data

### 7. No due date means no row
**How:** `DueDate::set( '' )` deletes the row instead of storing `''`. Dates must be real `YYYY-MM-DD` (`checkdate`).
**Test/proof:** `test_due_date_validation_and_clearing_deletes_the_row`; smoke "clear due → row deleted".

### 8. Conflicts on fields
**What:** Meta writes don't bump `post_modified`, the same as stage moves.
**How:** Both routes take `previous`, what the client last saw. If it doesn't match what's stored, the route returns 409 with the current card, and the board redraws it with a warning.
**Test/proof:** smoke "stale previous → 409" for both routes.

## Performance / SQL

### 9. Undated posts last, in both directions (PLAN §0 item 6)
**What:** Ordering by a meta clause puts NULLs first on ASC.
**How:** `Query\DueSort` hooks `posts_clauses` only for queries carrying `bk_due_sort`. It LEFT JOINs `_bk_due` and orders by `(MIN(value) IS NULL), MIN(value) <dir>, ID`. `GROUP BY ID` with `MIN()` keeps one row per post even with duplicate due rows, and is valid under `ONLY_FULL_GROUP_BY`. The direction is one of two literals, never input.
**Test/proof:** `test_due_sort_only_touches_flagged_queries_and_puts_nulls_last` (including an injection attempt in the direction); smoke "due ASC" and "due DESC" against real MySQL.

### 10. Filters apply to counts too
**How:** `CardQuery::counts()` takes the same `Filters` as the card pages, so a filtered lane shows its filtered count.
**Test/proof:** smoke "lane counts follow the filter".

## UI

### 11. The ⋯ menu was clipped in short lanes
**What:** The menu lived inside the card, inside the lane's scrolling list. With a filter leaving one card in a lane, the list was so short that the menu was clipped both ways and hidden behind the lane header, where it couldn't be clicked. Found in the test page.
**How:** The menu is now appended to `<body>`, `position: fixed`, and placed by `placeMenu()`: below the button and right-aligned, or above it if there's no room, always on screen. It closes on scroll or resize instead of drifting.
**Test/proof:** test page: with the Overdue filter on, the only card in Pitch opens its menu fully on screen, and Assign… can be clicked.

### 12. Edit actions only for editable cards
**How:** Assign… and Set due date… appear only when the card has a safe edit URL, which `CardPresenter` sets only if the user can `edit_post`. The server checks again.

## Manual checklist
- [x] Role matrix for assignees and due dates: smoke `phase4` (25 checks).
- [x] Filter bar, assign form, due form and the floating menu in the test page with a faked `apiFetch`.
- [ ] The real board in wp-admin: assign, due date, each filter, and a bookmarked filtered URL.
- [ ] The meta box in the block editor: stage, assignees, due date, recent activity. In the block editor, a refused assignee only shows its notice on the next full page load (classic meta boxes save in the background).
