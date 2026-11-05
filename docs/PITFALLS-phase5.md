# Phase 5 — Pitfalls Reviewed

**Card panel:** a side panel on the board with a Details tab (title, excerpt, featured image, lane) and a Write tab
(the real post editor in an iframe), plus "+ Add card" under each lane. Added at the user's request on 2026-11-04;
board settings moved to Phase 7.

---

## Security

### 1. The panel never publishes
**What:** A panel that could change status would bypass the lane rules (`publish_posts`, dates) built in Phase 3.
**How:** `CardDetails::save()` passes only `post_title` and `post_excerpt` to `wp_update_post`, never status, date or
content. The featured image goes through `set_post_thumbnail`. Lane changes in the panel call the same `moveCard()`
as dragging, so they hit `MoveService`.
**Test/proof:** `test_details_write_only_title_and_excerpt_slashed_never_status_or_content`. In the smoke test, the
status is still draft after a save.

### 2. New cards can't be created straight into a publishing lane
**How:** `LaneSource::new_post_status()` allows only lanes that set no status, or set draft or pending. A stage lane
also needs `assign_terms`, and the post type needs `create_posts` (its mapped capability). Anything else gets a 400
before `wp_insert_post` runs. If placing the new post in its lane fails, the post is deleted again.
**Test/proof:** `test_create_refuses_a_publishing_lane_before_inserting` (`wp_insert_post` is never called) and
`test_a_card_that_cant_be_placed_is_removed_again`. Smoke: "adding straight into Published is refused (400) and
creates nothing", and "subscriber can't add cards (403)".

### 3. Kses still applies
**How:** Titles and excerpts go through `wp_update_post`, so core's `title_save_pre` and `excerpt_save_pre` kses
filters run for users without `unfiltered_html`, exactly as in the editor.
**Test/proof:** smoke "kses ran for a contributor (no `<script>`)". The browser shows every value with
`textContent` or `.value`. The test page's `<img onerror>` title stayed text.

### 4. Featured image: only an image the user can see
**How:** `featured_media` must be an attachment, an image (`wp_attachment_is_image`) and readable by the user
(`read_post`). It's checked before anything is written, so a bad image doesn't leave half a save. The media modal is
enqueued only for users with `upload_files`, because its library is listed through admin-ajax, which needs that cap.
**Test/proof:** `test_featured_image_must_be_an_image_attachment_and_bad_one_writes_nothing`; smoke "a non-image
attachment is refused (400)".

### 5. The editor in an iframe is the normal editor
**How:** The Write tab loads `post.php?action=edit&bk_panel=1`. `PanelEditor` only adds CSS that hides the admin menu,
toolbar, footer and the editor's back button, and keeps the flag across a classic-editor save redirect. Permissions,
locks, autosave and revisions are core's. Framing works because it's the same origin, which core's
`X-Frame-Options: SAMEORIGIN` allows.
**Test/proof:** smoke "classic save redirect keeps `bk_panel` only when it came from the panel".

## Data

### 6. `wp_update_post` / `wp_insert_post` unslash their input
**What:** Passing raw values silently strips backslashes: `C:\temp` is saved as `C:temp`.
**How:** Both calls get `wp_slash()`ed arrays.
**Test/proof:** unit (the slashed value reaches `wp_update_post`); smoke "title exact (slashes kept)" with
`A pitch \ with "quotes"`.

### 7. No lost updates
- **Someone else is in the editor:** `wp_check_post_lock()` → 409 `bk_locked` naming them. The panel shows a notice
  and disables Save. Without this, their next editor save would silently overwrite the panel's change.
- **The post changed since the panel loaded:** compare-and-swap on `post_modified_gmt`. Title and excerpt live on the
  post row, so the timestamp is a real version here, unlike stage and meta writes. The 409 carries the current card
  and details, and the panel redraws with a warning.
- **Unsaved work in the panel itself:** closing, switching tab, opening another card, Escape and leaving the page all go
  through `guard()`, which offers Save and continue / Discard changes / Keep editing. For the block editor, "Save" calls
  `core/editor` `savePost()` and waits for meta boxes. The classic editor can't be saved from outside, so the user is
  asked to save it first.
**Test/proof:** smoke "editor lock held by another user → 409 `bk_locked`" and "stale modified → 409 with the current
card and details". The test page with a fake editor covered: dirty editor → close asks first; Save and continue saves
once, then closes; Discard switches tab without saving; unsaved details → Escape asks first.

### 8. The card catches up after writing
**How:** Closing the Write tab refetches the card, because the editor may have changed the title, image or stage. If the
stage changed, the card moves lanes, and a trashed post is taken off the board. If the editor leaves the post (trash,
or a link elsewhere), the panel closes and the board reloads.

## UI / accessibility

### 9. Focus
**What:** Found in the test page. "Keep editing" removed the button that had focus, so focus dropped to `<body>` and
Escape stopped working.
**How:** `guard()` remembers what had focus inside the panel and puts it back. The panel is a labelled, non-modal
`role="dialog"`, so the board stays usable. It opens with focus on Close, and Escape closes it and returns focus to the
card. The tabs are `role="tab"` with arrow keys.

### 10. Editor class names change between WordPress versions
**What:** The editor's back button is `.editor-header__back-button` in WP 7.1, while older versions used
`*-fullscreen-mode-close`.
**How:** All of them are hidden. If one is missed, the button still works: clicking it navigates the iframe away, which
closes the panel (item 8).

## Manual checklist
- [x] Role matrix for create, details, lock and image: smoke `phase5` (27 checks).
- [x] Panel, guard, add card, deep link and phone width in the test page (fake `apiFetch`, fake editor).
- [x] **The real block editor in the panel** (user confirmed 2026-11-05) (needs a login): it fills the panel with no admin chrome, saves work,
  "Save and continue" works, and the post lock is released when the panel closes.
- [ ] The classic editor in the panel (with Classic Editor active): a save keeps the panel layout.
