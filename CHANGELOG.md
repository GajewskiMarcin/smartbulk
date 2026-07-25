# Changelog

All notable changes to SmartBulk will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
