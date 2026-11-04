# Phase 1 — Pitfalls Reviewed

**Plugin skeleton:** main file, autoloader, `Plugin` singleton, the GitHub self-updater copied from
ColumnKit (spec §11), release + CI workflows, `uninstall.php`, test harness.

The updater is ColumnKit's `src/Updater/GitHubUpdater.php` (Phase 10) with BoardKit identifiers.
Its security reasoning carries over unchanged and is restated here so this repo stands alone.

---

## Security

### 1. The update filter fires for every github.com-hosted plugin — gate on basename
**What:** `update_plugins_{$hostname}` is keyed by hostname only. With ColumnKit and BoardKit both installed, *both* updaters receive *both* plugins' checks. Answering for the other one would push the wrong zip onto it.
**How:** `check_update()` returns the incoming value unless `$plugin_file === BK_BASENAME`.
**Test/proof:** `GitHubUpdaterTest::test_other_plugins_pass_through_without_http`; smoke `phase1` "foreign plugin passthrough".

### 2. The token must never ride along on unrelated requests
**What:** `http_request_args` is global; a loose URL match would attach `BK_GITHUB_TOKEN` to every HTTP request WP makes.
**How:** `authorize_download()` adds headers only with a token AND a strict prefix match on `https://api.github.com/repos/brendanoconnellwp/boardkit/releases/assets/`.
**Test/proof:** `test_authorize_download_scopes_headers_to_our_assets`.

### 3. Copied code keeping ColumnKit's identifiers
**What:** A stray `CK_BASENAME`, `ck_github_update` transient or `columnkit-{v}.zip` asset name in the copy would make BoardKit answer ColumnKit's update checks, share (and clobber) its cache, or never find its own zip. Nothing fails loudly; updates just go wrong. During the copy a blanket `CK_`→`BK_` replace also turned `CHECK_ACTION` into `CHEBK_ACTION`.
**How:** Every identifier renamed, including internal ones (`bk_error` cache marker, `bk_update_checked` query arg). CI job `rename-guard` fails the build on `\bCK_`, `\bck_`, `ColumnKit\Updater`, `brendanoconnellwp/columnkit` or `columnkit-<version>` in the copied files. Word boundaries matter: `check_update` and `pick_package` contain `ck_` mid-word.
**Test/proof:** `.github/workflows/ci.yml` → `rename-guard`.

### 4. Release notes are untrusted markup
**What:** The "View details" changelog renders the GitHub release body inside wp-admin.
**How:** `markdown_to_html()` escapes every line first and emits only its own tags; links are http(s) only.
**Test/proof:** `test_changelog_markdown_escapes_html`.

## Robustness

### 5. Never offer an update over a git checkout (new in BoardKit)
**What:** The dev site links `wp-content/plugins/boardkit` to the working repo. A one-click update there would delete the folder and unzip a release over it, wiping uncommitted work and `.git`.
**How:** `GitHubUpdater::is_dev_checkout()`: if `BK_DIR/.git` exists, `check_update()` returns early with no HTTP request. `file_exists`, not `is_dir`, so git worktrees (where `.git` is a file) count. Release zips never contain `.git` (the release workflow excludes it), so real installs are unaffected. To exercise updates locally, `add_filter( 'boardkit/updater_skip_dev_checkout', '__return_false' )`.
**Test/proof:** `test_dev_checkout_never_offers_an_update_and_skips_http`, `test_git_worktree_file_counts_as_dev_checkout`, `test_release_install_is_not_a_dev_checkout`, `test_dev_checkout_guard_can_be_disabled_by_filter`. The unit bootstrap points `BK_DIR` at `tests/fixtures/plugin/` precisely because the repo root *is* a checkout.

### 6. Never hammer the API from cron
**What:** `wp_update_plugins()` runs from cron and several admin screens; the unauthenticated GitHub API allows 60 requests/hour/IP.
**How:** Success cached 6h (filter `boardkit/update_cache_ttl`), failures cached 1h (`bk_error` marker). Core's "Check again" clears our transient too.
**Test/proof:** `test_api_failure_is_cached_and_offers_nothing`, `test_success_is_cached_across_checks`.

### 7. A broken release degrades to "no update"
**What:** A release with no CI-built zip, a non-semver tag or a garbled API body must not produce an offer WP then fails to install.
**How:** Only an asset named exactly `boardkit-{version}.zip`, downloaded from this repo's release URL, is ever offered. Tags must match `vX.Y.Z`.
**Test/proof:** `test_no_fallback_to_a_differently_named_zip`, `test_download_url_must_be_this_repos_release_download`, `test_non_semver_tags_are_ignored`.

### 8. The zip's folder name is the upgrade
**What:** WP replaces the plugin directory on update. A zip whose root isn't exactly `boardkit/` installs as a second, inactive plugin.
**How:** The release workflow stages into a literal `boardkit/` folder; GitHub's source archives are never offered.

### 9. Listening for ColumnKit early enough
**What:** ColumnKit announces its Api with `columnkit/loaded` during its `plugins_loaded` (priority 10) boot. Plugin files load alphabetically, so `boardkit.php` loads before `columnkit.php`, but a listener added *inside* BoardKit's own `plugins_loaded` callback could still run after ColumnKit has fired.
**How:** The listener is added at file scope in `boardkit.php`. Detection is by hook only, never `class_exists` at load time (spec §3.2).
**Test/proof:** smoke `phase1` "columnkit/loaded listener registered" and adapter state.

## Uninstall

### 10. Editorial data is content, not settings
**What:** Deleting stage terms and `_bk_*` meta on uninstall would destroy work people did.
**How:** Default uninstall removes only `bk_board_*` (through the `bk_boards` index, never a `LIKE 'bk_%'` sweep), `bk_boards`, `bk_version` and the update transient. Editorial data goes only if `bk_delete_all_data` is set. Runs per site on multisite.

## PHP versions

### 11. Runtime floor 8.0, test floor 8.1
**What:** The plugin header says PHP 8.0, but PHPUnit 10 needs 8.1.
**How:** CI lints every file on 8.0 and runs the suite on 8.1 and 8.3. `composer.json` pins `config.platform.php` to 8.1.0 so the lock file resolves the same on every machine.

## Manual checklist
- [ ] Smoke `tests/smoke/phase1-skeleton.php` on the LocalWP site
- [ ] First tagged release `v0.1.0` builds `boardkit-0.1.0.zip` with a `boardkit/` root and no dev files
- [ ] On a non-git install of 0.1.0, publishing 0.1.1 shows the update row
