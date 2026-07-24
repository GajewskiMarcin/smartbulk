# Changelog

All notable changes to SmartBulk will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
