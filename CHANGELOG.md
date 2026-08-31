# Changelog

All notable changes to SmartBulk will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.5] — 2026-08-31

### Added
- **Bulk "Tags" action.** A new *Tags* field in the Bulk Editor with **Add / Remove / Clear**
  operators (per language). *Add* links the given tag(s) to each product, creating any that
  don't exist yet; *Remove* unlinks them; *Clear* removes all tags. Free-text entry with
  autocomplete from the shop's existing tags. Fully undoable from the batch runner and History.
  Available in English and Polish.

### Fixed
- **Category picker showed the wrong tree.** The category list — both the product-selection
  criterion and the "Default category" action — was ordered by depth + name, which grouped
  subcategories of different parents together, so the indented list showed children under the
  wrong parent. It is now returned in true tree order (nested-set `nleft` pre-order), matching
  the back-office category tree.

## [1.0.4] — 2026-08-12

PrestaShop 8 compatibility. The module installed on PS 9 but crashed on PS 8.x on first access
with `ClassNotFoundError` (and, once past that, further PS 9-only API errors). Now verified
installing and rendering on **PS 8.1.5 and PS 9**.

### Fixed
- **Install/boot crash on PrestaShop 8 (`ClassNotFoundError: AdminSmartBulkController` from namespace `SmartBulk\Controller`).**
  Root cause: the release archive omitted `vendor/`, so PrestaShop's
  `ContainerBuilder::loadModulesAutoloader()` had no autoloader to include and the module's
  namespaced `SmartBulk\*` Symfony controllers were never loadable at request time. The generated
  (non-authoritative) Composer autoloader now ships with the module.
- **PS 9-only base controller.** The Symfony controllers extended `PrestaShopAdminController`,
  which does not exist on PS 8. A new `CompatAdminController` extends `PrestaShopAdminController`
  on PS 9 and `FrameworkBundleAdminController` on PS 8.
- **PS 9-only `#[AdminSecurity]` attribute** (absent on PS 8, where it exists only as an annotation)
  is replaced by an explicit `assertAccess()` guard built on the legacy Profile/Tab permission API,
  enforcing the same per-tab read/create/update/delete rights identically on both versions.
- **PS 9-only `ShopContext` dependency injection** in the main controller broke Symfony container
  compilation on PS 8 (type-hinting a non-existent class). Shop context is now resolved through the
  legacy `Context`/`Shop` API available on both versions.

### Changed
- The module version reported to the UI and the `api/ping` endpoint is read from a single source of
  truth (`SmartBulk::VERSION`) instead of a hard-coded `1.0.0` string.

## [1.0.3] — 2026-07-26

A full audit pass (prompted by the community bug report) fixed further issues, plus a UX addition.

### Fixed
- **Content Health / segment filters — PS 9 fatals removed:** the "Missing focus keyphrase" gap and `meta_keywords` column (dropped in PS 8/9), the `short_desc_length` filter (was mapping to a non-existent `product.short_desc` instead of `product_lang.description_short`), and the "Out of stock for N days" filter (referenced a non-existent `stock_available.update_date`). Segment endpoints now wrap errors instead of 500-ing.
- **AI batch could loop forever** when a daily-budget or per-minute rate limit was hit (rate/budget-blocked products never got a run row, so the queue never drained). It now pauses with a message.
- **Dry-run bulk edits** containing an AI-generate action no longer call the paid AI provider.
- **Settings page no longer 500s** if `_COOKIE_KEY_` changes (site move / DB copy) — an undecryptable key degrades to empty so it can be re-entered.
- **"Stop batch" is now enforced** (a stopped batch no longer keeps writing); its final status and undo behave correctly.
- **Undo** restores previously-NULL values as NULL (not `''`); AI **alt-text / tagging** accepts are now undoable and show in History; a feature "clear" is reversible again.
- Clearing a date field writes NULL (was `''` → rejected under STRICT); config export/import no longer drops settings (key-name mismatch); AI batch final status uses cumulative counts; History AI-run product names bind the shop; LIKE searches escape `%`/`_`; scheduler heartbeat throttles via Configuration (not `$_SESSION`); bulk edits bump `date_upd`, keep `link_rewrite` unique per language, and invalidate the Content Health cache for changed products.

### Added
- **Resume** button in the batch runner (and History) for stopped/interrupted bulk batches — continues from where it left off (cursor-based).

## [1.0.2] — 2026-07-26

### Fixed
- **Content Health → list affected products** produced an SQL syntax error — the display `LEFT JOIN`s were emitted *after* the `WHERE`. `fromForProblem()` now returns FROM and WHERE separately and `listProducts()` assembles them in valid order (FROM → JOINs → WHERE). (Thanks to the community reviewer on PS 9.1.4 / MariaDB.)
- **AI cost estimate / sample-product pick** produced `LIMIT 1 LIMIT 1` — the query carried an explicit `LIMIT 1` while PrestaShop's `getValue()` appends its own. Removed the explicit `LIMIT`.
- **Meta title / any AI task** raised `Undefined property: Product::$meta_keywords` on PS 8.x/9.x (the field was removed from the Product model). Reads are now guarded with `property_exists()` and degrade to an empty `{focus_keyphrase}`.

### Changed
- Fallback autoloader now also resolves the legacy admin controllers (`controllers/admin/`), so a fresh clone / release zip works without running `composer dump-autoload`.

## [1.0.1] — 2026-07-25

### Added
- **Configurable batch size** — Settings → Bulk processing → "Products per batch" (10–1000, default 100). The bulk runner reads it per apply.
- **Resume** button in History for interrupted (`running`/`pending`) batches — jumps back into the runner and continues from where it stopped.
- **Per-field summary** in History batch detail (changed/failed counts per field), instead of loading thousands of raw log rows.

### Changed
- **Bulk-edit preview now counts changes across the full scope** (batched), not a 5,000-product sample — the "Will change" tile, header, footer and Apply button all show the same real number. Above 100k products the count is extrapolated (flagged) to avoid timeouts.
- **Cursor-based batch progress** — `processNext` advances an offset into the product snapshot instead of re-diffing the whole log each chunk (O(1) per chunk; no slowdown as a large batch nears the end).
- Larger default processing chunk (100, was 10) and the hard 50-per-request cap removed (safety ceiling 1000); progress polling eases off (2s → 5s) on large batches.
- History batch detail loads at most 100 sample rows (was 2000).

### Fixed
- Products that resulted in no change were being reprocessed on every chunk (the batch could never finish past them). The cursor advances regardless of change outcome.

### Database
- Added `smartbulk_massedit.processed_offset`. Existing installs are migrated by `upgrade/upgrade-1.0.1.php` (guarded — safe to re-run).

## [1.0.0-alpha] — 2026-04-22

### Added
- Initial MVP skeleton.
- Module installs under `Improve → Secret Sauce → SmartBulk` (compatible with WiseBlock and other Secret Sauce modules).
- 13 database tables for segments, action templates, massedit history, prompts with versioning, AI runs, job queue, scheduler, content health.
- Symfony admin controller renders React SPA shell (`views/templates/admin/shell.html.twig`).
- React 18 + Vite + Tailwind + TanStack Query frontend in `views/js/app/`.
- 9-section navigation: Dashboard, Bulk Editor, AI Assistant, Prompts, Health, History, Scheduler, Settings, Support.
- Fully working Dashboard view with KPI tiles, content health issue preview, recent activity, AI budget widget.
- Fully working Support view with Buy Me a Coffee, GitHub, and docs links.
- English + Polish translations (classic `$_MODULE` system — integrates with International → Translations → Installed modules).
- Clean install / uninstall (Secret Sauce parent preserved if other modules still attached).
