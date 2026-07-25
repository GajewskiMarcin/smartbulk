# SmartBulk

> Advanced bulk product management for PrestaShop, with an AI assistant.

**Status:** v1.0.1
**Author:** [marcingajewski.pl](https://marcingajewski.pl)
**License:** [AFL-3.0](LICENSE.md)
**PrestaShop:** 8.x and 9.x
**PHP:** 8.1+

---

## What it does

SmartBulk replaces manual, one-by-one product maintenance with a **segment → action → preview → apply** workflow, plus an **AI assistant** that can generate meta titles, descriptions, alt texts, translations, and tags using Claude or OpenAI.

### Four pillars

1. **Bulk Editor** — filter products with nested AND/OR conditions (incl. content-gap filters: "missing meta description", "short desc < 80 chars", "no main image"), define actions (set / append / prepend / replace / math / clear / copy from / generate from name / AI generate), preview the before/after diff at scale, apply with full undo.
2. **AI Assistant** — Claude / OpenAI, prompt library with versioning, single-product test runs and segment-wide batches with human approval queue, real cost estimate before generation, daily budget + per-minute rate limit.
3. **Content Health** — quality audit of your catalogue. Per-product score (0–100) and per-group breakdown (SEO / Content / Codes), drill-down to affected products, one-click "Fix with AI" or "Fix in bulk" handoff. Native PrestaShop product grid gets a **Health column** and a **Smart fix** button per row.
4. **Automation** — Scheduler (cron-like) to run AI batches or bulk edits on a recurring schedule, JSON config import/export for moving setups between shops, dry-run mode for safe testing on production data.

---

## Quick start

After [installing](#installation), here's the typical first run:

1. **Settings → AI Providers** — paste your Claude or OpenAI API key (encrypted at rest with AES-256-GCM, never logged, never exported).
2. **Settings → Brand tone** — describe your brand in 2-3 sentences. The AI uses this as a `{brand_tone}` placeholder.
3. **Health → Run scan** — wait ~5 s, see the catalogue audit. Score per group, ranked issues with severity + fix hints.
4. Click any **"Fix with AI"** button — drops you into AI Assistant with the right prompt + product preselected. Hit **Generate** → review → **Accept**. Output is written straight to the product field.
5. Repeat for the segment-wide flow: pick a built-in preset like *"Missing meta titles"*, run a batch on 200 products, accept-all when satisfied.

---

## Features

### Bulk Editor

- Nested AND/OR condition builder with **40+ filterable fields** including content gaps, behavior signals, dates, taxonomy
- **30+ editable fields** with field-specific validation: `min_value` (no negative prices), `char_limit` (60 for meta_title, 160 for meta_description, with truncation warnings), `pattern` validation (EAN-13: 13 digits, UPC: 12 digits, ISBN-10/13), required-format hints
- **Lookup dropdowns** for relation fields (brand, supplier, category, tax rule)
- **Action templates** — 10 built-in templates ("Auto-generate SEO", "Reset stock to zero", "Strip markdown asterisks", etc.) plus user-defined templates you can save & reuse
- **Generate from name / short description** — slugify, truncate, fallback chain
- **Copy from another field** — e.g. set `meta_title = name`
- **AI generate** as a bulk action — pick a prompt from your library, runs against every product in the batch
- **Preview at scale** — full pre-apply analysis: `will change / no change / with warnings` summary, "Needs attention" tab (only flagged rows), browse all with pagination, CSV export
- **Anomaly detection** — `truncated`, `pattern_mismatch`, `below_min_clamped`, `empty_old`, `same_value_batch` (warns when "set" applies same SKU to 100 products), `conflict_gt`
- **Dry-run mode** — log every change to History without writing to products
- **Undo** — every batch (Bulk and AI accepts) can be reverted

### AI Assistant

- **Claude (Haiku 4.5 / Sonnet 4.6 / Opus 4.7)** and **OpenAI (gpt-4o-mini, gpt-4o)** providers
- 8 task types with auto-apply: `meta_title`, `meta_description`, `short_desc`, `long_desc`, `translate`, `alt_text`, `seo_rewrite`, `tagging`
- **Prompt Library** with versioning — every change creates a new version, can pin runs to specific versions
- **Scope picker**: single product (test), saved segment, custom condition tree
- **Real cost estimate** — renders the prompt against a sample product, counts tokens, multiplies by per-1k rate of the chosen model
- **Daily budget** + **per-minute rate limit** to prevent surprise bills or 429s
- **Approval queue** — review every generated output before it's written to the product (or enable auto-accept for trusted prompts)

### Content Health

- 15 quality checks across 3 groups (SEO / Content / Codes)
- Per-product cache: score updates on every product save
- **Drill-down** with paginated table, search, multi-select, "Fix N selected"
- **In-product Health panel** — opens in the native PrestaShop product form's "Modules" tab. Shows composite score, per-group breakdown, full issue list with severity + per-issue fix buttons
- **Health trend** sparkline on Dashboard — composite score over the last 30 scans

### Native PrestaShop integration

- **Bulk action** in product list dropdown: *Edit with SmartBulk* — sends selected products to Bulk Editor
- **Per-row link** in Actions column: *Edit in SmartBulk* — single product handoff
- **Health column** with color-coded badge (green ≥80, amber 50–79, red <50) + top issue label + "+N more" hint
- **Smart fix column** with per-row CTA button — fioletowy "✨ Fix with AI" or szary "📝 Fix in bulk"
- **Tax rule** as both a filter and an editable field
- Updates `image_lang.legend` (alt text) and `tag` / `product_tag` (tagging) tables — not just simple field-write tasks

### Scheduler

- Cron-driven jobs of two kinds: `ai_batch` (run a prompt against a segment on a schedule) and `bulk_edit` (apply a saved Action Template against a segment)
- 5 schedule presets (hourly / daily / weekly / monthly / custom cron) with reverse-parse for the form
- **In-process heartbeat** runs due jobs every ~60 s of BO activity (no extra config required)
- **External cron URL** for unattended scheduling: `* * * * * curl -s "<heartbeat URL>"`
- "Run now" button for manual trigger; full History entry per run

### Configuration portability

- **Export** settings (without API keys), prompts (with all versions), saved segments, schedules to one JSON file
- **Import** with optional "overwrite existing prompts" flag — useful for backups, cross-shop copy, dev → prod sync

---

## Installation

### Option A — install from release zip (recommended)

1. Download the latest release from GitHub releases.
2. In PS BO: **Modules → Module Manager → Upload a module** → upload the zip.
3. Click **Install**.
4. In sidebar: **Improve → Secret Sauce → SmartBulk** → configure.

### Option B — install from source (for developers)

```bash
cd modules/
git clone https://github.com/GajewskiMarcin/smartbulk.git smartbulk
cd smartbulk
composer install --no-dev
cd views/js/app
npm install
npm run build
```

Then install the module in PS BO as in Option A.

### Optional — set up an external cron for the Scheduler

For schedules to fire 24/7 (instead of only when BO is open), add this to your server's crontab:

```cron
* * * * * curl -s "https://your-shop.com/admin-XYZ/modules/smartbulk/api/schedules/heartbeat" > /dev/null
```

Replace `admin-XYZ` with your actual admin folder name.

---

## Configuration

### AI providers

**SmartBulk → Settings → AI Providers**

- **Default provider**: Claude or OpenAI
- **Claude API key** (recommended for PL/EN content): https://console.anthropic.com/
- **OpenAI API key**: https://platform.openai.com/api-keys
- **Daily budget** (USD): hard cap; AI runs throw an exception when reached
- **Rate limit** (runs per minute): prevents 429s from providers on big batches
- **Brand tone**: 2-3 sentences on your voice/style; injected into prompts as `{brand_tone}`

Keys are encrypted at rest with AES-256-GCM (key derived via HKDF from `_COOKIE_KEY_`). Never logged, never exported in config bundles.

### Prompts

**SmartBulk → Prompts**

The module ships with **8 seeded prompts** (English) covering all task types. Edit them, fork them into new versions, or create your own from scratch using these placeholders:

| Placeholder | Meaning |
|-------------|---------|
| `{name}` | Product name |
| `{category}` | Default category name |
| `{brand}` | Manufacturer name |
| `{features}` | "Color: red; Size: M; …" |
| `{short_description}` | Stripped HTML |
| `{description}` | Stripped HTML |
| `{focus_keyphrase}` | Reads `meta_keywords` (PS uses it as focus keyphrase proxy) |
| `{existing_text}` | For translate/rewrite tasks |
| `{target_lang}` / `{source_lang}` | ISO codes |
| `{brand_tone}` | From Settings |
| `{reference}` / `{price}` / `{id_product}` | Misc context |

---

## Architecture

**Stack:**
- PHP 8.1+ (PSR-4 namespace `SmartBulk\`)
- Symfony admin controllers (PS 9 native, PS 8 via legacy bridge)
- React 18 + Vite + Tailwind 3 (scoped under `.smartbulk-scope`)
- TanStack Query for server state
- TanStack Table for grid views
- React Router (hash mode for in-BO routing)
- Anthropic / OpenAI SDKs via lightweight HTTP clients

**Database:**
- 13 tables, all prefixed `smartbulk_`
- Per-product health snapshots cache
- Versioned prompts + AI runs with token/cost tracking
- Massedit log enables full undo for both Bulk and AI write operations

**Translations:**
- Classic `$_MODULE` format (not XLIFF) — see Polish reference module *Secret Sauce* for the pattern
- Default language: English; Polish included

---

## Development

```bash
cd views/js/app
npm install
npm run dev       # Vite dev server at :5173 (uses mock bootstrap)
npm run build     # → dist/{smartbulk.js, style.css}
npm run typecheck
```

The build output (`dist/`) is committed so end users don't need Node.js.

To add a new field to the bulk editor: add an entry to `src/Service/Bulk/FieldDefinitions.php` (`type`, `lang`, `operators`, optional `min_value` / `char_limit` / `pattern` / `lookup`). The frontend ActionBuilder picks it up automatically.

To add a new content-health check: add a problem entry in `src/Service/Health/ContentHealthService::problems()` and the corresponding SUM/CASE expression in `scan()`.

---

## Troubleshooting

**Cache permissions** after deploy on PS 9: `chmod -R 777 var/cache var/logs` (dev only — use ACL on prod).

**OPcache** holds onto stale PHP after deploy: clear it via PrestaShop's "Clear cache" button or restart php-fpm.

**Health column shows "—" everywhere**: run a Content Health scan once. The grid reads from the `smartbulk_health_product` cache table which is empty until the first scan.

**Module appears in product form's Modules tab but is empty**: Twig namespace `@Modules` may not be available in your PS minor version — file an issue with your PS version.

**Schedule not firing**: confirm BO is being accessed (heartbeat fires on BO page loads), or set up the external cron URL in Scheduler view.

---

## Support

- ☕ [Buy Me a Coffee](https://buymeacoffee.com/marcingajewski) — if SmartBulk saves you time
- 🐙 [GitHub Issues](https://github.com/GajewskiMarcin/smartbulk/issues) — bug reports & feature requests

---

## License

Academic Free License 3.0 — see [LICENSE.md](LICENSE.md).

© 2026 marcingajewski.pl
