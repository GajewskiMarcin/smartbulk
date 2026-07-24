# SmartBulk — testing notes

This file lists the manual smoke tests we run before tagging a release, plus
a simple `curl`-based API smoke script for sanity-checking the routing layer.

> No PHPUnit / Behat setup yet. Reasoning: SmartBulk is installed inside a
> running PrestaShop, so meaningful tests need a full PS bootstrap which is
> heavy to wire up. A `make test` story is on the v1.1 wishlist.

---

## Manual smoke checklist

Run after every release-candidate build. Tick items as you go.

### Install

- [ ] Module zip installs cleanly on PS 9.0.x and PS 8.x
- [ ] Sidebar shows **Improve → Secret Sauce → SmartBulk** with the `auto_awesome` icon
- [ ] First open of `/smartbulk` lands on Dashboard with KPI cards (no JS errors in console)
- [ ] Re-installing the module preserves seeded prompts (idempotent seed)

### Settings

- [ ] Pasting an Anthropic API key + clicking "Test connection" returns OK
- [ ] Pasting an OpenAI key + clicking "Test connection" returns OK
- [ ] Saving a daily budget shows up in Dashboard AI spend widget
- [ ] Setting a rate limit of 5/min and triggering 6+ AI runs in 60 s throws "Rate limit reached"
- [ ] Brand tone field saves and round-trips after page reload

### Bulk Editor — happy path

- [ ] **Step 1**: build a tree like `name contains "test" AND missing_meta_title is_true`. The "Matching products" sidebar updates.
- [ ] Save the tree as a segment. Reload the page. Click "Load saved segment ▾" — segment shows up.
- [ ] **Step 2**: load a built-in template (e.g. *Auto-generate SEO*). Two actions appear (`meta_title` + `meta_description`).
- [ ] Type custom actions in another field; save them as a user template via "+ Save current". Verify the new template appears in the dropdown with ⭐.
- [ ] Delete the user template — confirms via ConfirmDialog.
- [ ] **Step 3**: preview shows summary cards, "Needs attention" tab if any flags are present, full diff list with old/new colors.
- [ ] Click **Download CSV** — file downloads with `id_product, name, reference, field, old, new, flags` columns.
- [ ] Tick **Dry-run**, click Apply. Batch lands in History with `mode=dry_run`. Spot-check a product — value didn't actually change. Try Undo on the dry-run batch — should error "Dry-run batches have nothing to undo".
- [ ] Untick Dry-run, Apply for real. Watch the BatchRunner progress bar. **Click Stop batch mid-flight** — confirms via ConfirmDialog, batch ends with status `partial`.
- [ ] Already-applied changes still visible in the product. Undo from History — revert succeeds.

### AI Assistant

(Requires a valid API key.)

- [ ] **Single mode**: pick a product, generate meta_title with the seeded prompt. Output appears in the result card.
- [ ] Click **Accept**. Open the product in PS BO — meta title is updated. Open History — there's a new `ai_batch` row with `has_undoable_changes=true`. Undo from History — title reverts.
- [ ] **Batch mode**: pick a flat segment with 5+ products, start a batch. Approval queue fills as runs come in (poll every 2 s). Approve some, reject others.
- [ ] Click **Accept all** — remaining pending runs flip to accepted; products updated.
- [ ] **Translate task**: pick `Translate — default`, set target_lang=de, target_field=description. Run on one product. Accept — DE description is written, source PL stays intact.
- [ ] **Tagging task**: run *Auto-tagging — default* on a product. Accept — `ps_tag` and `ps_product_tag` get fresh entries (and old tags for that lang are wiped).
- [ ] **Cost estimate**: with a prompt picked, the AI Assistant footer shows `~$X.XX est.` based on the live `/api/ai/estimate` endpoint. Numbers should be in the right order of magnitude (Haiku ≈ $0.001/run for short tasks).

### Content Health

- [ ] **Health → Run scan** finishes in <10 s for a small catalogue. Shows 3 score rings + ranked issues table.
- [ ] Click a problem count — drill-down with paginated table opens. Search by REF/name works.
- [ ] Multi-select 3 products, click **Fix N selected →** — opens Bulk Editor with handoff banner.
- [ ] **Native PS product list**: Health column shows colored badges, top issue + "+N more". Click the column header — sort asc/desc by score works.
- [ ] **Smart fix column**: per-row "✨ Fix with AI" or "📝 Fix in bulk" button visible. Click → opens SmartBulk preselected.
- [ ] **Per-row link** in Actions (3 dots): "Edit in SmartBulk" → opens Bulk Editor for that single product.
- [ ] **Bulk action** dropdown: select 5 products, choose *Edit with SmartBulk* → opens Bulk Editor with the 5 IDs handed off.

### Product form Health panel

- [ ] Open any product in BO → **Modules** tab → SmartBulk card.
- [ ] Composite score shown big with color (green/amber/red), 3 group bars below, full issue list grouped per category with severity badges.
- [ ] **Fix with AI** button on an SEO issue → opens AI Assistant with productId + taskType prefilled. Generate → Accept — product field updates. Open the product form again — issue is gone, score recalculated.
- [ ] **Fix in bulk** button on a length-issue (e.g. *Meta title > 60*) → opens Bulk Editor with that single product preselected.

### Scheduler

- [ ] Create an `ai_batch` schedule: prompt + segment + daily 03:00 + auto_accept. Save.
- [ ] Click **Run now** → wakes batch immediately, shows toast "Started: batch #X (Y products)". History gets a new entry.
- [ ] Create a `bulk_edit` schedule with a saved Action Template + segment + dry-run. Run now. Verify a `mode=dry_run` batch in History.
- [ ] Toggle the active checkbox on a row — `is_active` flips, `next_run_at` recomputed when re-enabled.
- [ ] Set up the external cron URL on a test box (or hit the heartbeat URL once via curl). Verify due jobs fire.

### History

- [ ] All batches (Bulk + AI) listed, filter by kind / status works.
- [ ] Click row arrow → expands. Bulk batches show the field-change log; AI batches show runs (and ALSO logs if any field-write accepts happened).
- [ ] Undo button on a successful Bulk batch reverts changes.
- [ ] Search by ID / employee / summary filters the visible page client-side.
- [ ] Pagination next/previous works.

### Configuration portability

- [ ] **Settings → Configuration portability → Export config** downloads a JSON. Open it: should contain `settings`, `prompts` (with versions), `segments`, `schedules`. Confirm **no API keys** are present.
- [ ] On a fresh shop, **Import config** → tick *Overwrite existing prompts*. Verify report card shows correct created/updated/skipped counts. Spot-check that prompts and schedules now exist.

### Uninstall

- [ ] Click Uninstall in PS Module Manager. DB tables remain (intentional — reinstall preserves work).
- [ ] Reinstall — old prompts and segments still there.

---

## API smoke script

Quick end-to-end check that routing + DI is alive. Runs `curl` against the
heartbeat-style endpoints which don't require write permissions.

`tests/smoke.sh` (run from a logged-in BO session — copy the cookies first):

```bash
#!/usr/bin/env bash
set -euo pipefail

BASE="${SMARTBULK_BASE:-http://localhost:8888/admin-dev/modules/smartbulk/api}"
COOKIE="${SMARTBULK_COOKIE:?Set SMARTBULK_COOKIE to a logged-in BO session cookie}"

curl -fsS -b "$COOKIE" "$BASE/ping"                  | grep -q '"ok":true'  && echo "✓ ping"
curl -fsS -b "$COOKIE" "$BASE/lookups/languages"     | grep -q '"ok":true'  && echo "✓ lookups/languages"
curl -fsS -b "$COOKIE" "$BASE/lookups/field-catalog" | grep -q '"fields"'   && echo "✓ field-catalog"
curl -fsS -b "$COOKIE" "$BASE/bulk/fields"           | grep -q '"fields"'   && echo "✓ bulk/fields"
curl -fsS -b "$COOKIE" "$BASE/bulk/templates"        | grep -q '"templates"'&& echo "✓ bulk/templates"
curl -fsS -b "$COOKIE" "$BASE/health/scan"           | grep -q '"report"'   && echo "✓ health/scan"
curl -fsS -b "$COOKIE" "$BASE/dashboard"             | grep -q '"data"'     && echo "✓ dashboard"
curl -fsS -b "$COOKIE" "$BASE/history?limit=1"       | grep -q '"items"'    && echo "✓ history"
curl -fsS -b "$COOKIE" "$BASE/schedules"             | grep -q '"schedules"'&& echo "✓ schedules"
curl -fsS -b "$COOKIE" "$BASE/segments/presets"      | grep -q '"presets"'  && echo "✓ presets"

echo "All endpoints OK."
```

Get the cookie from your browser's devtools (after logging into BO) and pass
it as `SMARTBULK_COOKIE='PrestaShop-XXX=...; cookie2=...'`.
