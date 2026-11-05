# Phase 6 — Pitfalls Reviewed

**AI suggestions** in the card panel, through WordPress core's AI client (`wp_ai_client_prompt`, WP 7.0+) and the
provider connected under Settings → Connectors. Kinds: titles, excerpt, meta description, outline. Also the active SEO
plugin's meta description field.

---

## Security and cost

### 1. The browser names a kind, never a prompt
**What:** A route that forwarded client text to the AI would be a free general-purpose chatbot on the site's paid key,
open to anyone who can view a board.
**How:** `POST …/suggest` takes only `kind` (a REST `enum` of four values). `Assistant` builds the prompt from the saved
post and a fixed instruction per kind.
**Test/proof:** test page: "only kind sent, no prompt"; `test_unknown_kind_is_refused_without_calling_the_ai`;
smoke "unknown kind → 400".

### 2. `edit_post` on that post
**How:** Same gate as the other card writes (`editable_post`): the post must be on the board and editable by the user.
**Test/proof:** smoke "contributor on someone else's post → 403".

### 3. Per-user hourly cap
**How:** A transient per user (default 20, `boardkit/ai_hourly_limit`). It's checked before the call and counted
BEFORE the request is sent, so failed or slow calls count too (they may still cost money). The window starts at the
first call and isn't extended by later ones.
**Caveat:** With a persistent object cache, `_transient_timeout_` isn't in the options table, so each later call resets
the window to a full hour. That's stricter, not looser.
**Test/proof:** `test_hourly_cap_is_per_user_and_checked_before_calling`, `test_a_failed_call_still_counts_and_returns_502`;
smoke "over the hourly limit → 429".

### 4. The post's text is untrusted input to the model
**What:** A post can contain "ignore previous instructions…".
**How:** The system instruction says the user message is material, never instructions. More importantly, nothing the
model returns is executed or saved without a person choosing it. The output is plain text: `sanitize_text_field` and
`sanitize_textarea_field` on the server, `textContent` in the browser. An appended outline is `esc_html`ed into heading
and paragraph blocks.
**Test/proof:** `test_prompt_is_built_from_the_post_and_marks_it_as_material_not_instructions`,
`test_outline_blocks_escape_model_output`; smoke "model text escaped"; test page "markup stays text".

### 5. Nothing is saved without a person
**How:** Suggestions fill fields; Save details saves them. The only direct write is the outline's own "Add to the post"
button, which only ever appends to the content and goes through `CardDetails::save` (compare-and-swap, post lock, no
status change). It also sends any unsaved title or excerpt edits, so the re-render doesn't lose them.
**Test/proof:** smoke "the post is unchanged (suggestions are never saved)", "the original text is still first",
"still a draft"; test page "Use fills the title … does not save", "sends outline AND the unsaved title".

### 6. Privacy
**How:** The readme says that asking for a suggestion sends the post's title, excerpt and text to the connected
provider. Post text is capped at 8,000 characters. No user data is sent.

## Availability

### 7. No AI connected → no AI UI
**How:** `Assistant::available()` checks `wp_supports_ai()` and `is_supported_for_text_generation()`, and the result goes
through the `boardkit/ai_enabled` filter. It's cached per request. The details response carries `ai: null`, so the
section isn't drawn, and the route returns 501.
**Test/proof:** smoke "AI switched off on the site → 501, and no AI section offered".

### 8. Image generation deferred
**What:** The dev site's provider reports no image support, so it can't be tested. See PLAN Phase 6.

## Meta description

### 9. Each SEO plugin keeps its own key
**How:** `SeoMeta::target()` detects Yoast (`_yoast_wpseo_metadesc`), Rank Math (`rank_math_description`) or SEOPress
(`_seopress_titles_desc`) by their version constants. The `boardkit/seo_meta_target` filter can change it. The value is
saved as plain text, and an empty value deletes it. With no SEO plugin, the route refuses `meta_description` (400) and
the UI offers the AI's meta description as the excerpt.
**Test/proof:** smoke "without an SEO plugin → 400", "with an SEO plugin → saved to its field as plain text".

## Manual checklist
- [x] Smoke `phase6` (18 checks), including two real calls on OpenRouter: titles in 1.3s, plus an excerpt.
- [x] Test page: buttons, Use, outline, meta field, limit message.
- [ ] On the real board: try each button on a post with some text.
