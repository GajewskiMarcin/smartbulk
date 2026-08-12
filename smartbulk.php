<?php
/**
 * SmartBulk — Advanced bulk product management for PrestaShop with AI assistant
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

// Composer autoload for PSR-4 (SmartBulk\*) — also loaded by PS core, but we require it
// explicitly to avoid ordering issues during install.
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

// Manual fallback autoloader — makes the module work without running
// `composer dump-autoload` on a fresh clone / release zip (vendor/ absent).
// Covers both the PSR-4 `SmartBulk\*` classes in src/ AND the legacy global-namespace
// admin controllers in controllers/admin/ (the composer.json classmap entry).
// Prepended so it is available before the Symfony container compiles module services.
spl_autoload_register(static function (string $class): void {
    // PSR-4: SmartBulk\* -> src/
    if (strncmp($class, 'SmartBulk\\', 10) === 0) {
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
        return;
    }
    // Classmap fallback: legacy admin controllers (global namespace).
    if (strncmp($class, 'AdminSmartBulk', 14) === 0 || strncmp($class, 'AdminSecretSauce', 16) === 0) {
        $path = __DIR__ . '/controllers/admin/' . $class . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
}, true, true);

class SmartBulk extends Module
{
    /** @var string */
    public const MODULE_NAME = 'smartbulk';

    /** @var string Single source of truth for the module version (mirrored in config.xml). */
    public const VERSION = '1.0.4';

    /** @var string[] Hooks to register on install */
    private const HOOKS = [
        'actionProductGridDefinitionModifier',
        'actionProductGridDataModifier',
        'actionProductGridQueryBuilderModifier',
        'actionProductGridFilterFormModifier',
        'actionAdminProductsListingFieldsModifier',
        'actionProductUpdate',
        'actionProductDelete',
        'displayBackOfficeHeader',
        'actionDispatcherBefore',
        'displayAdminProductsExtra',
    ];

    public function __construct()
    {
        $this->name = self::MODULE_NAME;
        $this->tab = 'administration';
        $this->version = self::VERSION;
        $this->author = 'marcingajewski.pl';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('SmartBulk');
        $this->description = $this->l('Advanced bulk product management with AI assistant — mass edit products, generate meta, descriptions and alt text with Claude or OpenAI.');
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
    }

    /**
     * PS module translations — we use the classic $_MODULE system (NOT Symfony XLIFF),
     * which integrates with International > Translations > Installed modules.
     */
    public function isUsingNewTranslationSystem(): bool
    {
        return false;
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        try {
            $ok = (new SmartBulk\Install\DatabaseInstaller())->install()
                && (new SmartBulk\Install\TabsInstaller($this))->install()
                && $this->registerHooks()
                && $this->installDefaultConfig();

            if ($ok) {
                // Seed default prompts (idempotent — skips existing slugs)
                $this->seedDefaultPrompts();
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->_errors[] = 'SmartBulk install failed: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Seed the prompt library with curated starter prompts. Safe to call multiple
     * times — DefaultPrompts::seed() skips slugs that already exist.
     */
    private function seedDefaultPrompts(): void
    {
        try {
            $repo = new SmartBulk\Repository\PromptRepository();
            $service = new SmartBulk\Service\Prompt\PromptService($repo);
            $defaults = new SmartBulk\Service\Prompt\DefaultPrompts($service, $repo);
            $defaults->seed();
        } catch (\Throwable $e) {
            // Non-fatal — module still works without seeded prompts. User can add manually.
        }
    }

    public function uninstall(): bool
    {
        try {
            $tabsUninstalled = (new SmartBulk\Install\TabsInstaller($this))->uninstall();
            // Data is preserved by default so a reinstall keeps prompts/segments/templates.
            // Advanced users can drop tables manually if needed.
            return $tabsUninstalled && parent::uninstall();
        } catch (\Throwable $e) {
            $this->_errors[] = 'SmartBulk uninstall failed: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Remove DB tables on uninstall — call explicitly if user opts in via UI.
     */
    public function removeAllData(): bool
    {
        return (new SmartBulk\Install\DatabaseInstaller())->uninstall();
    }

    /**
     * Returns the React SPA's i18n dictionary, keyed by the JS-side keys
     * (e.g. 'common.save'), values translated through PrestaShop's classic
     * $this->l() so they appear in BO → International → Translations → Installed modules → SmartBulk.
     *
     * @return array<string,string>
     */
    public function getI18nDictionary(): array
    {
        return [
            'nav.dashboard' => $this->l('Dashboard', 'smartbulk'),
            'nav.bulk_editor' => $this->l('Bulk Editor', 'smartbulk'),
            'nav.ai_assistant' => $this->l('AI Assistant', 'smartbulk'),
            'nav.prompts' => $this->l('Prompts', 'smartbulk'),
            'nav.health' => $this->l('Health', 'smartbulk'),
            'nav.history' => $this->l('History', 'smartbulk'),
            'nav.scheduler' => $this->l('Scheduler', 'smartbulk'),
            'nav.settings' => $this->l('Settings', 'smartbulk'),
            'nav.support' => $this->l('Support', 'smartbulk'),
            'common.save' => $this->l('Save', 'smartbulk'),
            'common.cancel' => $this->l('Cancel', 'smartbulk'),
            'common.delete' => $this->l('Delete', 'smartbulk'),
            'common.edit' => $this->l('Edit', 'smartbulk'),
            'common.refresh' => $this->l('Refresh', 'smartbulk'),
            'common.refreshing' => $this->l('⟳ Refreshing…', 'smartbulk'),
            'common.loading' => $this->l('Loading…', 'smartbulk'),
            'common.apply' => $this->l('Apply', 'smartbulk'),
            'common.undo' => $this->l('Undo', 'smartbulk'),
            'common.next' => $this->l('Next', 'smartbulk'),
            'common.back' => $this->l('Back', 'smartbulk'),
            'common.confirm' => $this->l('Confirm', 'smartbulk'),
            'common.close' => $this->l('Close', 'smartbulk'),
            'common.search' => $this->l('Search', 'smartbulk'),
            'common.no_data' => $this->l('No data yet', 'smartbulk'),
            'common.error' => $this->l('Error', 'smartbulk'),
            'common.required' => $this->l('required', 'smartbulk'),
            'common.optional' => $this->l('optional', 'smartbulk'),
            'common.products' => $this->l('Products', 'smartbulk'),
            'common.priority' => $this->l('Priority', 'smartbulk'),
            'common.action' => $this->l('Action', 'smartbulk'),
            'common.status' => $this->l('Status', 'smartbulk'),
            'common.when' => $this->l('When', 'smartbulk'),
            'common.operation' => $this->l('Operation', 'smartbulk'),
            'common.changed' => $this->l('changed', 'smartbulk'),
            'common.failed' => $this->l('failed', 'smartbulk'),
            'common.total' => $this->l('total', 'smartbulk'),
            'common.preview' => $this->l('Preview', 'smartbulk'),
            'common.unknown' => $this->l('Unknown', 'smartbulk'),
            'common.fetching' => $this->l('Fetching…', 'smartbulk'),
            'common.processing' => $this->l('Processing…', 'smartbulk'),
            'common.empty' => $this->l('(empty)', 'smartbulk'),
            'common.just_now' => $this->l('just now', 'smartbulk'),
            'common.min_ago' => $this->l('min ago', 'smartbulk'),
            'common.h_ago' => $this->l('h ago', 'smartbulk'),
            'common.d_ago' => $this->l('d ago', 'smartbulk'),
            'common.never' => $this->l('—', 'smartbulk'),
            'common.in_scope' => $this->l('in scope', 'smartbulk'),
            'common.skipped' => $this->l('skipped', 'smartbulk'),
            'common.unchanged' => $this->l('Unchanged', 'smartbulk'),
            'common.with_warnings' => $this->l('With warnings', 'smartbulk'),
            'common.changed_label' => $this->l('Changed', 'smartbulk'),
            'common.no_change' => $this->l('No change', 'smartbulk'),
            'severity.high' => $this->l('High', 'smartbulk'),
            'severity.medium' => $this->l('Medium', 'smartbulk'),
            'severity.low' => $this->l('Low', 'smartbulk'),
            'severity.clean' => $this->l('Clean', 'smartbulk'),
            'status.pending' => $this->l('pending', 'smartbulk'),
            'status.running' => $this->l('running', 'smartbulk'),
            'status.success' => $this->l('success', 'smartbulk'),
            'status.partial' => $this->l('partial', 'smartbulk'),
            'status.failed' => $this->l('failed', 'smartbulk'),
            'status.undone' => $this->l('undone', 'smartbulk'),
            'status.accepted' => $this->l('accepted', 'smartbulk'),
            'status.rejected' => $this->l('rejected', 'smartbulk'),
            'dashboard.welcome' => $this->l('Welcome back', 'smartbulk'),
            'dashboard.subtitle_loading' => $this->l('Loading…', 'smartbulk'),
            'dashboard.last_scan' => $this->l('Last scan', 'smartbulk'),
            'dashboard.run_ai_batch' => $this->l('Run AI batch', 'smartbulk'),
            'dashboard.new_bulk_edit' => $this->l('+ New bulk edit', 'smartbulk'),
            'dashboard.kpi.products' => $this->l('Products', 'smartbulk'),
            'dashboard.kpi.products_active_in_shop' => $this->l('{n} active in current shop', 'smartbulk'),
            'dashboard.kpi.health_score' => $this->l('Health score', 'smartbulk'),
            'dashboard.kpi.ai_today' => $this->l('AI runs today', 'smartbulk'),
            'dashboard.kpi.ai_today_spent' => $this->l('${spent} / ${budget} budget', 'smartbulk'),
            'dashboard.kpi.ai_today_no_budget' => $this->l('${spent} (no budget set)', 'smartbulk'),
            'dashboard.kpi.active_jobs' => $this->l('Active jobs', 'smartbulk'),
            'dashboard.kpi.active_jobs_running' => $this->l('{n} batch{plural} running', 'smartbulk'),
            'dashboard.kpi.active_jobs_idle' => $this->l('No running batches', 'smartbulk'),
            'dashboard.health_strong' => $this->l('Strong', 'smartbulk'),
            'dashboard.health_attention' => $this->l('Needs attention', 'smartbulk'),
            'dashboard.health_critical' => $this->l('Critical', 'smartbulk'),
            'dashboard.issues.title' => $this->l('Content Health issues', 'smartbulk'),
            'dashboard.issues.subtitle' => $this->l('Top problems affecting SEO and discoverability', 'smartbulk'),
            'dashboard.issues.no_issues' => $this->l('✓ All scanned checks pass. Run another health scan in the Health tab.', 'smartbulk'),
            'dashboard.issues.fix_with_ai' => $this->l('✨ Fix with AI', 'smartbulk'),
            'dashboard.issues.bulk_edit' => $this->l('Bulk edit →', 'smartbulk'),
            'dashboard.issues.view_all' => $this->l('View all →', 'smartbulk'),
            'dashboard.activity.title' => $this->l('Recent activity', 'smartbulk'),
            'dashboard.activity.empty' => $this->l('No batches yet — your runs will show up here.', 'smartbulk'),
            'dashboard.activity.view_history' => $this->l('View history →', 'smartbulk'),
            'dashboard.spend.title' => $this->l('AI spend today', 'smartbulk'),
            'dashboard.spend.budget_reached' => $this->l('⚠ Daily budget reached', 'smartbulk'),
            'dashboard.spend.no_budget' => $this->l('No daily budget set — see Settings.', 'smartbulk'),
            'dashboard.spend.this_month' => $this->l('This month', 'smartbulk'),
            'dashboard.spend.last_30d_runs' => $this->l('Last 30 d runs', 'smartbulk'),
            'dashboard.spend.avg_cost' => $this->l('Avg cost / run', 'smartbulk'),
            'dashboard.spend.percent_used' => $this->l('{pct}% of daily budget', 'smartbulk'),
            'dashboard.trend.title' => $this->l('Health trend', 'smartbulk'),
            'dashboard.trend.no_data' => $this->l('No snapshots yet — run a Content Health scan to start tracking.', 'smartbulk'),
            'dashboard.trend.need_more' => $this->l('Need at least 2 scans to plot a trend. Run another scan tomorrow.', 'smartbulk'),
            'dashboard.quick.title' => $this->l('Quick actions', 'smartbulk'),
            'dashboard.quick.bulk_edit' => $this->l('⚙️ New bulk edit', 'smartbulk'),
            'dashboard.quick.ai_batch' => $this->l('✨ Run AI batch', 'smartbulk'),
            'dashboard.quick.health_scan' => $this->l('💊 Run health scan', 'smartbulk'),
            'dashboard.quick.schedule' => $this->l('⏰ Schedule a job', 'smartbulk'),
            'dashboard.quick.prompts' => $this->l('📝 Edit prompts', 'smartbulk'),
            'dashboard.tip.title' => $this->l('💡 Tip', 'smartbulk'),
            'dashboard.tip.body' => $this->l('{n} products: {label}.', 'smartbulk'),
            'dashboard.tip.cost' => $this->l('Estimated AI cost ≈ ${cost}.', 'smartbulk'),
            'dashboard.tip.fix_now' => $this->l('Fix now →', 'smartbulk'),
            'dashboard.tip.loading' => $this->l('Loading…', 'smartbulk'),
            'issue.missing_meta_title' => $this->l('Missing meta title', 'smartbulk'),
            'issue.missing_meta_description' => $this->l('Missing meta description', 'smartbulk'),
            'issue.meta_title_too_short' => $this->l('Meta title < 30 chars', 'smartbulk'),
            'issue.meta_title_too_long' => $this->l('Meta title > 60 chars', 'smartbulk'),
            'issue.meta_desc_too_short' => $this->l('Meta description < 70 chars', 'smartbulk'),
            'issue.meta_desc_too_long' => $this->l('Meta description > 160 chars', 'smartbulk'),
            'issue.missing_link_rewrite' => $this->l('Missing friendly URL', 'smartbulk'),
            'issue.missing_short_desc' => $this->l('Missing short description', 'smartbulk'),
            'issue.missing_description' => $this->l('Missing long description', 'smartbulk'),
            'issue.short_desc_too_short' => $this->l('Short description < 80 chars', 'smartbulk'),
            'issue.description_too_short' => $this->l('Long description < 300 chars', 'smartbulk'),
            'issue.missing_main_image' => $this->l('No product images', 'smartbulk'),
            'issue.missing_alt_text' => $this->l('Images without alt text', 'smartbulk'),
            'issue.missing_reference' => $this->l('Missing reference (SKU)', 'smartbulk'),
            'issue.missing_ean' => $this->l('Missing EAN/GTIN barcode', 'smartbulk'),
            'issue.hint.missing_meta_title' => $this->l('Run AI generate on meta_title for these products.', 'smartbulk'),
            'issue.hint.missing_meta_description' => $this->l('Run AI generate or "Generate from short description".', 'smartbulk'),
            'issue.hint.meta_title_too_short' => $this->l('Rewrite or regenerate via AI to fill to ~55 chars.', 'smartbulk'),
            'issue.hint.meta_title_too_long' => $this->l('Will be truncated in Google SERP — trim to ≤ 60.', 'smartbulk'),
            'issue.hint.meta_desc_too_short' => $this->l('Expand to 120–160 chars for better SERP CTR.', 'smartbulk'),
            'issue.hint.meta_desc_too_long' => $this->l('Trimming reduces truncation risk.', 'smartbulk'),
            'issue.hint.missing_link_rewrite' => $this->l('Use "Regenerate friendly URLs" template.', 'smartbulk'),
            'issue.hint.missing_short_desc' => $this->l('AI-generate short_desc from name + features.', 'smartbulk'),
            'issue.hint.missing_description' => $this->l('AI-generate long_desc from product context.', 'smartbulk'),
            'issue.hint.short_desc_too_short' => $this->l('Shallow short-desc hurts category-page conversion.', 'smartbulk'),
            'issue.hint.description_too_short' => $this->l('Product pages with thin content rank poorly.', 'smartbulk'),
            'issue.hint.missing_main_image' => $this->l('Upload images manually — not a bulk-editable field.', 'smartbulk'),
            'issue.hint.missing_alt_text' => $this->l('Enable alt text AI generation (roadmap).', 'smartbulk'),
            'issue.hint.missing_reference' => $this->l('Set unique reference per product. Not safe in bulk set.', 'smartbulk'),
            'issue.hint.missing_ean' => $this->l('Required by Google Merchant Center for most categories.', 'smartbulk'),
            'bulk.title' => $this->l('Bulk Editor', 'smartbulk'),
            'bulk.steps.filter' => $this->l('Filter products', 'smartbulk'),
            'bulk.steps.actions' => $this->l('Define actions', 'smartbulk'),
            'bulk.steps.preview' => $this->l('Preview diff', 'smartbulk'),
            'bulk.steps.apply' => $this->l('Apply', 'smartbulk'),
            'bulk.load_segment' => $this->l('Load saved segment ▾', 'smartbulk'),
            'bulk.save_segment' => $this->l('Save as segment', 'smartbulk'),
            'bulk.next_actions' => $this->l('Next: actions →', 'smartbulk'),
            'bulk.next_preview' => $this->l('Next: preview →', 'smartbulk'),
            'bulk.computing' => $this->l('Computing…', 'smartbulk'),
            'bulk.apply_to_n' => $this->l('🚀 Apply to {n} products', 'smartbulk'),
            'bulk.dry_run_to_n' => $this->l('🧪 Dry-run on {n} products', 'smartbulk'),
            'bulk.dry_run_label' => $this->l('Dry-run (no writes)', 'smartbulk'),
            'bulk.dry_run_tooltip' => $this->l('Dry-run logs every change to History without writing to products.', 'smartbulk'),
            'bulk.starting' => $this->l('Starting…', 'smartbulk'),
            'bulk.handoff_banner' => $this->l('From Content Health: {label}', 'smartbulk'),
            'bulk.handoff_clear' => $this->l('Clear — build filter manually', 'smartbulk'),
            'bulk.products_in_scope' => $this->l('{n} products in scope', 'smartbulk'),
            'bulk.add_conditions_or_all' => $this->l('Add conditions or leave empty for all products', 'smartbulk'),
            'bulk.active_actions' => $this->l('{n} active action(s)', 'smartbulk'),
            'bulk.warn_ai_no_prompt' => $this->l('⚠ An AI generate action is missing a prompt selection', 'smartbulk'),
            'bulk.warn_copyfrom_no_source' => $this->l('⚠ A Copy-from action is missing a source field', 'smartbulk'),
            'bulk.handoff_status' => $this->l('{n} products (handoff) · {actions} active action(s)', 'smartbulk'),
            'bulk.preview_status' => $this->l('{willChange} of {total} will change', 'smartbulk'),
            'bulk.preview_status_sampled' => $this->l('all {total} will be processed - {n} analyzed', 'smartbulk'),
            'bulk.preview_attention' => $this->l('{n} need attention', 'smartbulk'),
            'bulk.stop_batch' => $this->l('■ Stop batch', 'smartbulk'),
            'bulk.resume_batch' => $this->l('▶ Resume', 'smartbulk'),
            'bulk.resuming' => $this->l('Resuming…', 'smartbulk'),
            'bulk.stopping' => $this->l('Stopping…', 'smartbulk'),
            'bulk.undo_batch' => $this->l('↶ Undo batch', 'smartbulk'),
            'bulk.undoing' => $this->l('Undoing…', 'smartbulk'),
            'bulk.back_to_editor' => $this->l('← Back to Bulk Editor', 'smartbulk'),
            'bulk.progress_title' => $this->l('Progress', 'smartbulk'),
            'bulk.progress_subtitle' => $this->l('{done} of {total} processed · {remaining} remaining', 'smartbulk'),
            'bulk.percent_complete' => $this->l('{pct}% complete', 'smartbulk'),
            'bulk.auto_process' => $this->l('Auto-process', 'smartbulk'),
            'bulk.process_next' => $this->l('Process next {n}', 'smartbulk'),
            // ---- Operators & groups (new: features) ----
            'bulk.op.add' => $this->l('Add value', 'smartbulk'),
            'bulk.group.features' => $this->l('Features', 'smartbulk'),
            // ---- Feature value picker ----
            'bulk.feature.loading' => $this->l('Loading features…', 'smartbulk'),
            'bulk.feature.load_failed' => $this->l('Failed to load features.', 'smartbulk'),
            'bulk.feature.pick_feature' => $this->l('— pick a feature —', 'smartbulk'),
            'bulk.feature.pick_value' => $this->l('— pick a value —', 'smartbulk'),
            'bulk.feature.from_library' => $this->l('From library', 'smartbulk'),
            'bulk.feature.custom_value' => $this->l('Custom value', 'smartbulk'),
            'bulk.feature.custom_placeholder' => $this->l('Type a custom value', 'smartbulk'),
            'bulk.feature.clear_warn' => $this->l('All values of {feature} assigned to each matching product will be removed.', 'smartbulk'),
            'bulk.feature.hint' => $this->l('Each matching product will get {feature} = {value}.', 'smartbulk'),
            'bulk.feature.hint_custom' => $this->l('A custom feature value will be created.', 'smartbulk'),
            'bulk.feature.hint_multi' => $this->l('PrestaShop allows multiple values for the same feature, so existing values are kept.', 'smartbulk'),
            'bulk.section_filter_title' => $this->l('Filter conditions', 'smartbulk'),
            'bulk.section_filter_subtitle' => $this->l('Combine conditions with AND/OR. Use groups for nested logic.', 'smartbulk'),
            'bulk.section_actions_title' => $this->l('Step 2 · Define actions', 'smartbulk'),
            'bulk.section_actions_subtitle' => $this->l('Add one entry per field. Empty value → skipped.', 'smartbulk'),
            'bulk.section_review_title' => $this->l('Step 3 · Review changes', 'smartbulk'),
            'bulk.section_review_subtitle' => $this->l('{willChange} of {total} products will change', 'smartbulk'),
            'bulk.review_subtitle_sampled' => $this->l('Apply will process all {total} products - preview analyzed a {n} sample', 'smartbulk'),
            'bulk.scope_label' => $this->l('Scope:', 'smartbulk'),
            'bulk.scope_single' => $this->l('Current shop only', 'smartbulk'),
            'bulk.scope_group' => $this->l('Current shop group', 'smartbulk'),
            'bulk.scope_all' => $this->l('All shops', 'smartbulk'),
            'ai.title' => $this->l('AI Assistant', 'smartbulk'),
            'ai.subtitle' => $this->l('Generate product content with Claude or OpenAI', 'smartbulk'),
            'ai.choose_operation' => $this->l('1. Choose operation', 'smartbulk'),
            'ai.prompt_for' => $this->l('2. Prompt for {task}', 'smartbulk'),
            'ai.scope' => $this->l('3. Scope', 'smartbulk'),
            'ai.no_prompts' => $this->l('No prompts for this task type yet — create one in Prompt Library.', 'smartbulk'),
            'ai.n_prompts_available' => $this->l('{n} prompt{plural} available', 'smartbulk'),
            'ai.field_prompt' => $this->l('Prompt', 'smartbulk'),
            'ai.field_target_field' => $this->l('Target field', 'smartbulk'),
            'ai.field_target_field_hint' => $this->l('Which product field the output will be written to.', 'smartbulk'),
            'ai.field_target_lang' => $this->l('Target language', 'smartbulk'),
            'ai.field_target_lang_hint' => $this->l('Destination lang — prompt reads source from scope\'s current lang.', 'smartbulk'),
            'ai.target_use_default' => $this->l('— use prompt default —', 'smartbulk'),
            'ai.target_same_as_source' => $this->l('— same as source —', 'smartbulk'),
            'ai.scope_subtitle' => $this->l('Pick products to run for', 'smartbulk'),
            'ai.scope_n_products' => $this->l('{n} products', 'smartbulk'),
            'ai.scope_est_cost' => $this->l('~${cost} est.', 'smartbulk'),
            'ai.scope_pick_first' => $this->l('Pick a scope first', 'smartbulk'),
            'ai.scope_pick_product' => $this->l('Pick a product', 'smartbulk'),
            'ai.scope_generate_once' => $this->l('Generate once for #{id}', 'smartbulk'),
            'ai.generate' => $this->l('Generate', 'smartbulk'),
            'ai.generating' => $this->l('Generating…', 'smartbulk'),
            'ai.start_batch' => $this->l('Start batch', 'smartbulk'),
            'ai.regenerate' => $this->l('↻ Regenerate', 'smartbulk'),
            'ai.regenerating' => $this->l('Regenerating…', 'smartbulk'),
            'ai.accept' => $this->l('✓ Accept', 'smartbulk'),
            'ai.accept_apply' => $this->l('✓ Accept & apply', 'smartbulk'),
            'ai.reject' => $this->l('✗ Reject', 'smartbulk'),
            'ai.note_no_autoapply' => $this->l('Auto-apply is not available for {task} yet. Accept will mark the run as accepted but won\'t write to the product.', 'smartbulk'),
            'ai.test_result' => $this->l('Test result', 'smartbulk'),
            'ai.generated_output' => $this->l('Generated output', 'smartbulk'),
            'ai.tokens_in' => $this->l('in', 'smartbulk'),
            'ai.tokens_out' => $this->l('out', 'smartbulk'),
            'ai.batch_subtitle' => $this->l('{label} · {prompt} · {total} products', 'smartbulk'),
            'ai.batch_running' => $this->l('Batch running…', 'smartbulk'),
            'ai.batch_done' => $this->l('✓ All processed — review below and accept / reject', 'smartbulk'),
            'ai.batch_accept_all' => $this->l('✓ Accept all', 'smartbulk'),
            'ai.batch_reject_all' => $this->l('✗ Reject all', 'smartbulk'),
            'ai.batch_back' => $this->l('← Back to AI Assistant', 'smartbulk'),
            'task.meta_title' => $this->l('Meta title', 'smartbulk'),
            'task.meta_description' => $this->l('Meta description', 'smartbulk'),
            'task.short_desc' => $this->l('Short description', 'smartbulk'),
            'task.long_desc' => $this->l('Long description', 'smartbulk'),
            'task.translate' => $this->l('Translate', 'smartbulk'),
            'task.alt_text' => $this->l('Image alt text', 'smartbulk'),
            'task.seo_rewrite' => $this->l('SEO rewrite', 'smartbulk'),
            'task.tagging' => $this->l('Auto-tagging', 'smartbulk'),
            'task.custom' => $this->l('Custom', 'smartbulk'),
            'scope.single' => $this->l('🧪 Single (test)', 'smartbulk'),
            'scope.segment' => $this->l('📋 Segment', 'smartbulk'),
            'scope.custom' => $this->l('🔧 Custom filter', 'smartbulk'),
            'scope.test_product' => $this->l('Test product', 'smartbulk'),
            'scope.test_hint' => $this->l('Quick 1-off generation to check prompt quality (costs ~$0.003)', 'smartbulk'),
            'scope.builtin_segments' => $this->l('Built-in (ready to use)', 'smartbulk'),
            'scope.your_segments' => $this->l('Your saved segments', 'smartbulk'),
            'scope.no_saved_segments' => $this->l('No saved segments yet.', 'smartbulk'),
            'scope.save_as_segment' => $this->l('Save as segment', 'smartbulk'),
            'health.title' => $this->l('Content Health', 'smartbulk'),
            'health.subtitle_initial' => $this->l('Quality audit of product data', 'smartbulk'),
            'health.subtitle_scanned' => $this->l('{n} active products scanned', 'smartbulk'),
            'health.run_scan' => $this->l('↻ Run scan', 'smartbulk'),
            'health.scanning' => $this->l('⟳ Scanning…', 'smartbulk'),
            'health.empty_shop' => $this->l('No active products in this shop. Nothing to scan.', 'smartbulk'),
            'health.no_products_match' => $this->l('No products match the search.', 'smartbulk'),
            'health.fix_all' => $this->l('Fix all {n} →', 'smartbulk'),
            'health.fix_selected' => $this->l('Fix {n} selected →', 'smartbulk'),
            'health.no_issues' => $this->l('No issues', 'smartbulk'),
            'health.clean' => $this->l('✓ Clean', 'smartbulk'),
            'health.show_all_groups' => $this->l('Show all groups', 'smartbulk'),
            'health.problems_in_group' => $this->l('{group} problems ({n})', 'smartbulk'),
            'health.all_problems' => $this->l('All problems ({n})', 'smartbulk'),
            'health.search_placeholder' => $this->l('Search by product ID, name, or REF…', 'smartbulk'),
            'health.preview_no_value' => $this->l('(empty)', 'smartbulk'),
            'health.no_anomalies' => $this->l('✓ No anomalies detected.', 'smartbulk'),
            'health.previous' => $this->l('← Previous', 'smartbulk'),
            'health.next' => $this->l('Next →', 'smartbulk'),
            'health.page_of' => $this->l('Page {p} of {pages} · {total} total', 'smartbulk'),
            'health.group.seo' => $this->l('SEO', 'smartbulk'),
            'health.group.content' => $this->l('Content', 'smartbulk'),
            'health.group.codes' => $this->l('Product codes', 'smartbulk'),
            'health.col.id' => $this->l('ID', 'smartbulk'),
            'health.col.name' => $this->l('Name', 'smartbulk'),
            'health.col.reference' => $this->l('Reference', 'smartbulk'),
            'health.col.current_value' => $this->l('Current value', 'smartbulk'),
            'history.title' => $this->l('History', 'smartbulk'),
            'history.refresh' => $this->l('↻ Refresh', 'smartbulk'),
            'history.refreshing' => $this->l('⟳ Refreshing…', 'smartbulk'),
            'history.resume' => $this->l('▶ Resume', 'smartbulk'),
            'history.applied_changes' => $this->l('Applied changes', 'smartbulk'),
            'history.changes_word' => $this->l('changes', 'smartbulk'),
            'history.failures_word' => $this->l('failures', 'smartbulk'),
            'history.showing_first' => $this->l('Showing first {n} of {total} change rows — see the summary above for full totals.', 'smartbulk'),
            'settings.bulk_title' => $this->l('Bulk processing', 'smartbulk'),
            'settings.bulk_subtitle' => $this->l('How many products are processed per request when applying a bulk edit. Higher = faster, but each request takes longer.', 'smartbulk'),
            'settings.bulk_chunk' => $this->l('Products per batch', 'smartbulk'),
            'history.empty' => $this->l('No operations recorded yet.', 'smartbulk'),
            'history.no_match' => $this->l('No rows match the current filters.', 'smartbulk'),
            'history.subtitle' => $this->l('{n} operation{plural} recorded', 'smartbulk'),
            'history.col.kind' => $this->l('Kind', 'smartbulk'),
            'history.col.status' => $this->l('Status', 'smartbulk'),
            'history.col.summary' => $this->l('Summary', 'smartbulk'),
            'history.col.changed_failed' => $this->l('Changed / Failed', 'smartbulk'),
            'history.col.started' => $this->l('Started', 'smartbulk'),
            'history.col.employee' => $this->l('Employee', 'smartbulk'),
            'history.col.actions' => $this->l('Actions', 'smartbulk'),
            'history.undo_batch' => $this->l('↶ Undo', 'smartbulk'),
            'history.undoing' => $this->l('Undoing…', 'smartbulk'),
            'history.kind.bulk' => $this->l('📝 Bulk', 'smartbulk'),
            'history.kind.ai' => $this->l('🤖 AI', 'smartbulk'),
            'history.filter.all_kinds' => $this->l('All kinds', 'smartbulk'),
            'history.filter.bulk' => $this->l('Bulk edits', 'smartbulk'),
            'history.filter.ai' => $this->l('AI batches', 'smartbulk'),
            'history.filter.all_statuses' => $this->l('All statuses', 'smartbulk'),
            'history.search_placeholder' => $this->l('Search by ID, summary, or employee…', 'smartbulk'),
            'history.page_of' => $this->l('Page {p} of {pages}', 'smartbulk'),
            'scheduler.title' => $this->l('Scheduler', 'smartbulk'),
            'scheduler.subtitle' => $this->l('{n} schedule{plural} configured', 'smartbulk'),
            'scheduler.add' => $this->l('+ Add schedule', 'smartbulk'),
            'scheduler.empty' => $this->l('No schedules yet. Click + Add schedule to create one.', 'smartbulk'),
            'scheduler.run_now' => $this->l('▶ Run now', 'smartbulk'),
            'scheduler.col.on' => $this->l('On', 'smartbulk'),
            'scheduler.col.name' => $this->l('Name', 'smartbulk'),
            'scheduler.col.type' => $this->l('Type', 'smartbulk'),
            'scheduler.col.schedule' => $this->l('Schedule', 'smartbulk'),
            'scheduler.col.next_run' => $this->l('Next run', 'smartbulk'),
            'scheduler.col.last_run' => $this->l('Last run', 'smartbulk'),
            'scheduler.kind.ai' => $this->l('🤖 AI', 'smartbulk'),
            'scheduler.kind.bulk' => $this->l('📝 Bulk', 'smartbulk'),
            'scheduler.crontab.title' => $this->l('Reliable scheduling needs an external cron', 'smartbulk'),
            'scheduler.crontab.body' => $this->l('SmartBulk fires due jobs whenever you open any back-office page (every ~60 s). For nightly jobs you should add this to your server\'s crontab:', 'smartbulk'),
            'scheduler.crontab.copy' => $this->l('Copy', 'smartbulk'),
            'scheduler.crontab.copied' => $this->l('✓ Copied', 'smartbulk'),
            'scheduler.modal.new' => $this->l('New schedule', 'smartbulk'),
            'scheduler.modal.edit' => $this->l('Edit schedule #{id}', 'smartbulk'),
            'scheduler.modal.name' => $this->l('Name', 'smartbulk'),
            'scheduler.modal.name_placeholder' => $this->l('e.g. Nightly meta-title regen for low-CTR products', 'smartbulk'),
            'scheduler.modal.job_type' => $this->l('Job type', 'smartbulk'),
            'scheduler.modal.job_ai' => $this->l('🤖 AI batch (run a prompt against a segment)', 'smartbulk'),
            'scheduler.modal.job_bulk' => $this->l('📝 Bulk edit (apply a saved Action Template against a segment)', 'smartbulk'),
            'scheduler.modal.prompt' => $this->l('Prompt', 'smartbulk'),
            'scheduler.modal.prompt_hint' => $this->l('Pick a prompt from the Prompt Library.', 'smartbulk'),
            'scheduler.modal.segment' => $this->l('Segment', 'smartbulk'),
            'scheduler.modal.segment_hint' => $this->l('Saved segment in flat format (created from AI Assistant). Tree-format segments aren\'t yet supported here.', 'smartbulk'),
            'scheduler.modal.no_segments' => $this->l('No compatible segments yet. Save one from AI Assistant → Custom filter → Save segment.', 'smartbulk'),
            'scheduler.modal.template' => $this->l('Action template', 'smartbulk'),
            'scheduler.modal.template_hint' => $this->l('Pick a built-in or saved template.', 'smartbulk'),
            'scheduler.modal.template_none' => $this->l('Loading templates…', 'smartbulk'),
            'scheduler.modal.template_pick' => $this->l('— pick template —', 'smartbulk'),
            'scheduler.modal.bulk_dry_run' => $this->l('Dry-run only (log changes, don\'t write to products)', 'smartbulk'),
            'scheduler.modal.target_field' => $this->l('Target field (optional)', 'smartbulk'),
            'scheduler.modal.target_lang' => $this->l('Target language (optional)', 'smartbulk'),
            'scheduler.modal.target_lang_hint' => $this->l('For translate prompts.', 'smartbulk'),
            'scheduler.modal.auto_accept' => $this->l('Auto-accept generated outputs (write to product without manual review)', 'smartbulk'),
            'scheduler.modal.auto_accept_warn' => $this->l('⚠ With auto-accept on, AI output is applied straight to products. Use only with prompts you\'ve reviewed.', 'smartbulk'),
            'scheduler.modal.enabled' => $this->l('Enabled', 'smartbulk'),
            'scheduler.modal.create' => $this->l('Create schedule', 'smartbulk'),
            'scheduler.modal.save_changes' => $this->l('Save changes', 'smartbulk'),
            'scheduler.modal.saving' => $this->l('Saving…', 'smartbulk'),
            'scheduler.cron.kind' => $this->l('Schedule', 'smartbulk'),
            'scheduler.cron.hourly' => $this->l('Hourly', 'smartbulk'),
            'scheduler.cron.daily' => $this->l('Daily', 'smartbulk'),
            'scheduler.cron.weekly' => $this->l('Weekly', 'smartbulk'),
            'scheduler.cron.monthly' => $this->l('Monthly', 'smartbulk'),
            'scheduler.cron.custom' => $this->l('Custom (cron)', 'smartbulk'),
            'scheduler.cron.at_minute' => $this->l('at minute', 'smartbulk'),
            'scheduler.cron.at_time' => $this->l('at', 'smartbulk'),
            'scheduler.cron.on_day' => $this->l('on day', 'smartbulk'),
            'scheduler.cron.on_dow' => $this->l('on', 'smartbulk'),
            'scheduler.cron.custom_placeholder' => $this->l('min hour day-of-month month day-of-week', 'smartbulk'),
            'scheduler.cron.cron_label' => $this->l('Cron:', 'smartbulk'),
            'scheduler.modal.prompt_pick' => $this->l('— pick prompt —', 'smartbulk'),
            'scheduler.modal.segment_pick' => $this->l('— pick segment —', 'smartbulk'),
            'scheduler.modal.target_field_default' => $this->l('— use prompt default —', 'smartbulk'),
            'scheduler.modal.target_field_hint' => $this->l('Override prompt\'s default target field.', 'smartbulk'),
            'scheduler.modal.target_lang_default' => $this->l('— same as source —', 'smartbulk'),
            'scheduler.modal.bulk_segment_hint' => $this->l('Saved segment determines which products the template runs against.', 'smartbulk'),
            'scheduler.toast.deleted' => $this->l('Schedule deleted', 'smartbulk'),
            'scheduler.toast.delete_failed' => $this->l('Delete failed', 'smartbulk'),
            'scheduler.toast.toggle_failed' => $this->l('Toggle failed', 'smartbulk'),
            'scheduler.toast.batch_started' => $this->l('Started: batch #{id} ({n} products)', 'smartbulk'),
            'scheduler.toast.run_finished' => $this->l('Run finished', 'smartbulk'),
            'scheduler.toast.run_failed' => $this->l('Run failed', 'smartbulk'),
            'scheduler.toast.updated' => $this->l('Schedule updated', 'smartbulk'),
            'scheduler.toast.created' => $this->l('Schedule created', 'smartbulk'),
            'scheduler.toast.save_failed' => $this->l('Save failed', 'smartbulk'),
            'settings.title' => $this->l('Settings', 'smartbulk'),
            'settings.subtitle' => $this->l('AI providers, brand tone, budgets, data retention', 'smartbulk'),
            'settings.save' => $this->l('Save settings', 'smartbulk'),
            'settings.reset' => $this->l('Reset', 'smartbulk'),
            'settings.saving' => $this->l('Saving…', 'smartbulk'),
            'settings.ai_providers' => $this->l('AI Providers', 'smartbulk'),
            'settings.ai_providers_subtitle' => $this->l('Configure your default model + API keys', 'smartbulk'),
            'settings.brand_tone' => $this->l('Brand tone', 'smartbulk'),
            'settings.brand_tone_hint' => $this->l('2-3 sentences on your voice/style. Injected into prompts as {brand_tone}.', 'smartbulk'),
            'settings.daily_budget' => $this->l('Daily budget (USD)', 'smartbulk'),
            'settings.daily_budget_hint' => $this->l('Hard cap; AI runs throw an exception when reached. 0 = no limit.', 'smartbulk'),
            'settings.rate_limit' => $this->l('Rate limit (per minute)', 'smartbulk'),
            'settings.rate_limit_hint' => $this->l('Prevents 429s from providers on big batches. 0 = no limit.', 'smartbulk'),
            'settings.retention_days' => $this->l('Retention (days)', 'smartbulk'),
            'settings.retention_subtitle' => $this->l('How long to keep AI run history before auto-pruning', 'smartbulk'),
            'settings.config_portability' => $this->l('Configuration portability', 'smartbulk'),
            'settings.config_subtitle' => $this->l('Export your settings, prompts, saved segments and schedules to a JSON file. API keys are never exported.', 'smartbulk'),
            'settings.config_long_subtitle' => $this->l('Export your settings, prompts, saved segments and schedules to a JSON file — useful for backups or moving between shops. API keys are never exported.', 'smartbulk'),
            'settings.export_config' => $this->l('⬇ Export config', 'smartbulk'),
            'settings.exporting' => $this->l('Exporting…', 'smartbulk'),
            'settings.import_config' => $this->l('⬆ Import config…', 'smartbulk'),
            'settings.overwrite_prompts' => $this->l('Overwrite existing prompts (adds new version on top)', 'smartbulk'),
            'settings.import_report' => $this->l('Import report', 'smartbulk'),
            'settings.loading' => $this->l('Loading...', 'smartbulk'),
            'settings.load_failed' => $this->l('Failed to load settings', 'smartbulk'),
            'settings.load_error' => $this->l('Could not fetch settings from the server. Check your session and try again.', 'smartbulk'),
            'settings.ai_providers_hint' => $this->l('API keys are encrypted at rest. They never leave this install.', 'smartbulk'),
            'settings.default_provider' => $this->l('Default provider', 'smartbulk'),
            'settings.default_provider_hint' => $this->l('Used when a prompt doesn\'t specify one', 'smartbulk'),
            'settings.claude_key' => $this->l('Claude API key', 'smartbulk'),
            'settings.claude_key_stored' => $this->l('Key stored. Type a new one to replace, or clear.', 'smartbulk'),
            'settings.claude_key_get' => $this->l('Get yours at console.anthropic.com', 'smartbulk'),
            'settings.openai_key' => $this->l('OpenAI API key', 'smartbulk'),
            'settings.openai_key_stored' => $this->l('Key stored.', 'smartbulk'),
            'settings.openai_key_get' => $this->l('Get yours at platform.openai.com', 'smartbulk'),
            'settings.clear_key' => $this->l('Clear key', 'smartbulk'),
            'settings.brand_tone_title' => $this->l('Brand tone of voice', 'smartbulk'),
            'settings.brand_tone_subtitle' => $this->l('Prepended to every AI prompt so generated content matches your brand', 'smartbulk'),
            'settings.brand_tone_placeholder' => $this->l('Describe how the AI should write for your brand...', 'smartbulk'),
            'settings.brand_tone_example' => $this->l('Example: "Professional, concise, no buzzwords. For automotive parts — keep terms like DAF, Scania, Euro 6 unchanged."', 'smartbulk'),
            'settings.budget_title' => $this->l('Budget & rate limits', 'smartbulk'),
            'settings.budget_subtitle' => $this->l('Protect against runaway costs and API throttling', 'smartbulk'),
            'settings.daily_budget_hint_short' => $this->l('AI runs stop queuing when this is reached', 'smartbulk'),
            'settings.rate_limit_hint_short' => $this->l('0 = no limit', 'smartbulk'),
            'settings.mask_prices' => $this->l('Mask prices when sending to AI', 'smartbulk'),
            'settings.mask_prices_hint' => $this->l('Replaces product prices with placeholders in prompts', 'smartbulk'),
            'settings.retention_title' => $this->l('Data retention', 'smartbulk'),
            'settings.import_confirm_title' => $this->l('Import this configuration?', 'smartbulk'),
            'settings.import_contains' => $this->l('The file contains: {counts}.', 'smartbulk'),
            'settings.import_overwrite_warn' => $this->l('⚠ Existing prompts with matching slugs will get a new version on top.', 'smartbulk'),
            'settings.import_keys_note' => $this->l('API keys are never imported — re-enter them in Provider keys.', 'smartbulk'),
            'settings.import_button' => $this->l('Import', 'smartbulk'),
            'settings.bundle.settings' => $this->l('{n} settings', 'smartbulk'),
            'settings.bundle.prompts' => $this->l('{n} prompts', 'smartbulk'),
            'settings.bundle.segments' => $this->l('{n} segments', 'smartbulk'),
            'settings.bundle.schedules' => $this->l('{n} schedules', 'smartbulk'),
            'settings.bundle.empty' => $this->l('nothing recognizable', 'smartbulk'),
            'settings.report.settings' => $this->l('Settings: {applied} applied · {skipped} skipped', 'smartbulk'),
            'settings.report.prompts' => $this->l('Prompts: {created} created · {updated} updated · {skipped} skipped', 'smartbulk'),
            'settings.report.segments' => $this->l('Segments: {created} created', 'smartbulk'),
            'settings.report.schedules' => $this->l('Schedules: {created} created', 'smartbulk'),
            'settings.report.errors' => $this->l('Errors:', 'smartbulk'),
            'settings.toast.saved' => $this->l('Settings saved', 'smartbulk'),
            'settings.toast.save_failed' => $this->l('Save failed', 'smartbulk'),
            'settings.toast.export_failed' => $this->l('Export failed', 'smartbulk'),
            'settings.toast.exported' => $this->l('Configuration exported', 'smartbulk'),
            'settings.toast.imported' => $this->l('Configuration imported', 'smartbulk'),
            'settings.toast.import_failed' => $this->l('Import failed', 'smartbulk'),
            'settings.toast.invalid_json' => $this->l('File is not valid JSON', 'smartbulk'),
            'prompts.title' => $this->l('Prompt Library', 'smartbulk'),
            'prompts.subtitle' => $this->l('Manage prompts and their versions', 'smartbulk'),
            'prompts.new_prompt' => $this->l('+ New prompt', 'smartbulk'),
            'prompts.no_prompts' => $this->l('No prompts yet — create one to get started.', 'smartbulk'),
            'prompts.search_placeholder' => $this->l('Search prompts…', 'smartbulk'),
            'prompts.filter_task' => $this->l('Filter by task type', 'smartbulk'),
            'prompts.versions' => $this->l('Versions', 'smartbulk'),
            'prompts.create' => $this->l('Create', 'smartbulk'),
            'prompts.creating' => $this->l('Creating…', 'smartbulk'),
            'prompts.save_new_version' => $this->l('Save as new version', 'smartbulk'),
            'prompts.activate_version' => $this->l('Activate this version', 'smartbulk'),
            'support.title' => $this->l('Support', 'smartbulk'),
            'support.coffee' => $this->l('☕ Buy Me a Coffee', 'smartbulk'),
            'support.github' => $this->l('🐙 GitHub', 'smartbulk'),
            'support.docs' => $this->l('📖 Documentation', 'smartbulk'),
            'confirm.undo_batch_title' => $this->l('Undo batch #{id}?', 'smartbulk'),
            'confirm.undo_batch_msg' => $this->l('This will revert {n} product change{plural} to the values stored before the batch ran. This operation itself cannot be undone.', 'smartbulk'),
            'confirm.undo_batch_yes' => $this->l('Undo batch', 'smartbulk'),
            'confirm.undo_batch_no' => $this->l('Keep changes', 'smartbulk'),
            'confirm.delete_prompt_title' => $this->l('Delete prompt?', 'smartbulk'),
            'confirm.stop_batch_title' => $this->l('Stop this batch?', 'smartbulk'),
            'confirm.stop_batch_msg' => $this->l('Already-processed products stay applied (and you can still undo afterwards). The remaining {n} won\'t be touched.', 'smartbulk'),
            'confirm.stop_batch_yes' => $this->l('Stop batch', 'smartbulk'),
            'confirm.delete_segment_title' => $this->l('Delete saved segment?', 'smartbulk'),
            'confirm.delete_segment_msg' => $this->l('The segment filter definition will be removed. Products themselves are not affected.', 'smartbulk'),
            'confirm.delete_schedule_title' => $this->l('Delete schedule "{name}"?', 'smartbulk'),
            'confirm.delete_schedule_msg' => $this->l('It will stop firing immediately. Past runs and their batches stay in History.', 'smartbulk'),
            'confirm.reject_pending_title' => $this->l('Reject pending runs?', 'smartbulk'),
            'confirm.reject_pending_msg' => $this->l('Reject all pending runs in this batch. Generated outputs will be discarded.', 'smartbulk'),
            'confirm.reject_all' => $this->l('Reject all', 'smartbulk'),
            'toast.saved' => $this->l('Saved', 'smartbulk'),
            'toast.deleted' => $this->l('Deleted', 'smartbulk'),
            'toast.applied' => $this->l('Applied', 'smartbulk'),
            'toast.rejected' => $this->l('Rejected', 'smartbulk'),
            'toast.generation_failed' => $this->l('Generation failed', 'smartbulk'),
            'common.yes' => $this->l('Yes', 'smartbulk'),
            'common.no' => $this->l('No', 'smartbulk'),
            'prompts.count' => $this->l('{n} prompts', 'smartbulk'),
            'prompts.subtitle_default' => $this->l('Versioned AI prompts — edit, test, compare', 'smartbulk'),
            'prompts.all_task_types' => $this->l('All task types', 'smartbulk'),
            'prompts.load_failed' => $this->l('Failed to load prompts', 'smartbulk'),
            'prompts.empty_hint' => $this->l('No prompts yet. Click + New prompt.', 'smartbulk'),
            'prompts.select_or_create' => $this->l('Select a prompt from the list, or create a new one.', 'smartbulk'),
            'prompts.loading_prompt' => $this->l('Loading prompt…', 'smartbulk'),
            'prompts.confirm_delete_title' => $this->l('Delete prompt?', 'smartbulk'),
            'prompts.confirm_delete_msg' => $this->l('Delete "{name}" and all its versions. This cannot be undone.', 'smartbulk'),
            'prompts.toast_deleted' => $this->l('Prompt deleted', 'smartbulk'),
            'prompts.toast_delete_failed' => $this->l('Delete failed', 'smartbulk'),
            'prompts.toast_created' => $this->l('Prompt created', 'smartbulk'),
            'prompts.toast_create_failed' => $this->l('Create failed', 'smartbulk'),
            'prompts.toast_name_updated' => $this->l('Name updated', 'smartbulk'),
            'prompts.toast_rename_failed' => $this->l('Rename failed', 'smartbulk'),
            'prompts.toast_version_saved' => $this->l('New version saved & set as current', 'smartbulk'),
            'prompts.toast_save_failed' => $this->l('Save failed', 'smartbulk'),
            'prompts.toast_activated' => $this->l('Version activated', 'smartbulk'),
            'prompts.toast_activate_failed' => $this->l('Activate failed', 'smartbulk'),
            'prompts.section_create' => $this->l('Create new prompt', 'smartbulk'),
            'prompts.section_create_sub' => $this->l('A v1 will be created with your initial content', 'smartbulk'),
            'prompts.field_name' => $this->l('Name', 'smartbulk'),
            'prompts.field_name_placeholder' => $this->l('e.g. Meta description — automotive', 'smartbulk'),
            'prompts.field_task_type' => $this->l('Task type', 'smartbulk'),
            'prompts.field_provider' => $this->l('Provider', 'smartbulk'),
            'prompts.field_model' => $this->l('Model', 'smartbulk'),
            'prompts.provider_claude' => $this->l('Claude (Anthropic)', 'smartbulk'),
            'prompts.provider_claude_short' => $this->l('Claude', 'smartbulk'),
            'prompts.provider_openai' => $this->l('OpenAI', 'smartbulk'),
            'prompts.field_system' => $this->l('System prompt', 'smartbulk'),
            'prompts.field_system_placeholder' => $this->l('You are an SEO copywriter...', 'smartbulk'),
            'prompts.field_user' => $this->l('User prompt template', 'smartbulk'),
            'prompts.field_user_placeholder' => $this->l('Generate a meta description for: {name}, {category}...', 'smartbulk'),
            'prompts.field_temperature' => $this->l('Temperature', 'smartbulk'),
            'prompts.temp_hint' => $this->l('0 = deterministic, 1 = creative', 'smartbulk'),
            'prompts.field_max_tokens' => $this->l('Max tokens', 'smartbulk'),
            'prompts.max_tokens_hint' => $this->l('Output length cap', 'smartbulk'),
            'prompts.field_changelog' => $this->l('Changelog (for the new version)', 'smartbulk'),
            'prompts.changelog_hint' => $this->l('What changed and why?', 'smartbulk'),
            'prompts.changelog_placeholder' => $this->l('e.g. Tightened the character limit. Switched model to Haiku for cost.', 'smartbulk'),
            'prompts.delete_btn' => $this->l('🗑 Delete', 'smartbulk'),
            'prompts.save_name' => $this->l('Save name', 'smartbulk'),
            'prompts.dirty_msg' => $this->l('● Unsaved changes — saving creates a new version.', 'smartbulk'),
            'prompts.clean_msg' => $this->l('No content changes since current version. Saving will create a duplicate version (useful for locking in a changelog note).', 'smartbulk'),
            'prompts.version_history' => $this->l('Version history', 'smartbulk'),
            'prompts.versions_count' => $this->l('{n} version{plural}', 'smartbulk'),
            'prompts.current_v' => $this->l('current: v{v}', 'smartbulk'),
            'prompts.badge_current' => $this->l('current', 'smartbulk'),
            'prompts.set_current' => $this->l('Set as current', 'smartbulk'),
            'prompts.click_to_insert' => $this->l('Click to insert at end', 'smartbulk'),
            'support.page_title' => $this->l('Support SmartBulk', 'smartbulk'),
            'support.page_subtitle' => $this->l('Free and open source — by marcingajewski.pl', 'smartbulk'),
            'support.coffee_title' => $this->l('Buy me a coffee', 'smartbulk'),
            'support.coffee_text' => $this->l('If SmartBulk saves you time, consider supporting development. Every coffee buys another evening of feature work.', 'smartbulk'),
            'support.coffee_btn' => $this->l('☕ Support on Buy Me a Coffee', 'smartbulk'),
            'support.github_title' => $this->l('GitHub repository', 'smartbulk'),
            'support.github_text' => $this->l('Report issues, request features, or contribute. The module is open source under the AFL-3.0 license.', 'smartbulk'),
            'support.github_btn' => $this->l('Open on GitHub →', 'smartbulk'),
            'support.docs_title' => $this->l('Documentation', 'smartbulk'),
            'support.docs_text' => $this->l('Installation guide, feature overview, roadmap, and architecture docs are in the repository README.', 'smartbulk'),
            'support.docs_btn' => $this->l('Read the docs →', 'smartbulk'),
            'support.footer' => $this->l('SmartBulk v{v} · PrestaShop 8 & 9 · PHP 8.1+', 'smartbulk'),
            'scope.build_new_in_custom' => $this->l('Build new ones in the "Custom filter" tab', 'smartbulk'),
            'scope.no_saved_segments_long' => $this->l('No saved segments yet. Build a custom filter and save it to reuse here.', 'smartbulk'),
            'scope.badge_builtin' => $this->l('Built-in', 'smartbulk'),
            'scope.badge_saved' => $this->l('Saved', 'smartbulk'),
            'scope.n_filters' => $this->l('{n} filter{plural}', 'smartbulk'),
            'scope.delete_segment_title' => $this->l('Delete segment', 'smartbulk'),
            'scope.save_filter_as_segment' => $this->l('Save this filter as a segment', 'smartbulk'),
            'scope.save_segment_placeholder' => $this->l('e.g. DAF products without meta', 'smartbulk'),
            'scope.save_segment_btn' => $this->l('Save segment', 'smartbulk'),
            'scope.saved_label' => $this->l('Saved: {name}', 'smartbulk'),
            'scope.save_failed' => $this->l('Save failed', 'smartbulk'),
            'filter.categories' => $this->l('Categories', 'smartbulk'),
            'filter.categories_hint' => $this->l('Products in any selected category', 'smartbulk'),
            'filter.all_categories' => $this->l('All categories', 'smartbulk'),
            'filter.brands' => $this->l('Brands', 'smartbulk'),
            'filter.brands_hint' => $this->l('Products from any selected manufacturer', 'smartbulk'),
            'filter.all_brands' => $this->l('All brands', 'smartbulk'),
            'filter.suppliers' => $this->l('Suppliers', 'smartbulk'),
            'filter.all_suppliers' => $this->l('All suppliers', 'smartbulk'),
            'filter.tags' => $this->l('Tags', 'smartbulk'),
            'filter.any_tags' => $this->l('Any tags', 'smartbulk'),
            'filter.features' => $this->l('Features', 'smartbulk'),
            'filter.features_hint' => $this->l('Multiple rows combine with AND ("Euro ∈ {6} AND Compat = DAF XF"). Values within a row combine with OR.', 'smartbulk'),
            'filter.pick_feature' => $this->l('— Pick feature —', 'smartbulk'),
            'filter.any_value' => $this->l('Any value', 'smartbulk'),
            'filter.pick_feature_first' => $this->l('Pick a feature first', 'smartbulk'),
            'filter.no_values' => $this->l('No values', 'smartbulk'),
            'filter.remove_feature' => $this->l('Remove this feature', 'smartbulk'),
            'filter.add_another_feature' => $this->l('+ Add another feature', 'smartbulk'),
            'filter.content_gaps' => $this->l('Content gaps', 'smartbulk'),
            'filter.content_gaps_hint' => $this->l('Match products missing any of the selected data', 'smartbulk'),
            'filter.status' => $this->l('Status', 'smartbulk'),
            'filter.in_stock' => $this->l('In stock', 'smartbulk'),
            'filter.out_of_stock' => $this->l('Out of stock', 'smartbulk'),
            'filter.active' => $this->l('Active', 'smartbulk'),
            'filter.inactive' => $this->l('Inactive', 'smartbulk'),
            'filter.price_min' => $this->l('Price ≥', 'smartbulk'),
            'filter.price_min_hint' => $this->l('Min price, tax excl.', 'smartbulk'),
            'filter.price_max' => $this->l('Price ≤', 'smartbulk'),
            'filter.price_max_hint' => $this->l('Max price, tax excl.', 'smartbulk'),
            'filter.added_last_n_days' => $this->l('Added in last N days', 'smartbulk'),
            'filter.added_last_n_days_hint' => $this->l('0 = any time', 'smartbulk'),
            'filter.product_ids' => $this->l('Specific product IDs', 'smartbulk'),
            'filter.product_ids_hint' => $this->l('Comma-separated list, e.g. 12, 15, 301', 'smartbulk'),
            'filter.product_ids_placeholder' => $this->l('e.g. 12, 15, 301', 'smartbulk'),
            'match.title' => $this->l('Matching products', 'smartbulk'),
            'match.counting' => $this->l('Counting…', 'smartbulk'),
            'match.matching' => $this->l('matching', 'smartbulk'),
            'match.loading_sample' => $this->l('Loading sample…', 'smartbulk'),
            'match.no_match' => $this->l('No products match yet.', 'smartbulk'),
            'match.live_count_hint' => $this->l('Live count updates as you edit conditions.', 'smartbulk'),
            'cond.query_preview' => $this->l('Query preview', 'smartbulk'),
            'cond.no_conditions' => $this->l('(no conditions yet — matches ALL products)', 'smartbulk'),
            'cond.remove_group_tooltip' => $this->l('Remove group', 'smartbulk'),
            'cond.remove_group_btn' => $this->l('✕ Remove group', 'smartbulk'),
            'cond.add_condition' => $this->l('+ Add condition', 'smartbulk'),
            'cond.add_group' => $this->l('+ Add group', 'smartbulk'),
            'cond.pick_field' => $this->l('— Pick field —', 'smartbulk'),
            'cond.pick_field_first' => $this->l('Pick a field first', 'smartbulk'),
            'cond.in_language' => $this->l('In language:', 'smartbulk'),
            'cond.any_current_lang' => $this->l('any (current)', 'smartbulk'),
            'cond.remove_condition' => $this->l('Remove condition', 'smartbulk'),
            'cond.no_value_needed' => $this->l('(no value needed)', 'smartbulk'),
            'cond.from' => $this->l('From', 'smartbulk'),
            'cond.to' => $this->l('To', 'smartbulk'),
            'cond.character_count' => $this->l('(character count)', 'smartbulk'),
            'cond.chars' => $this->l('chars', 'smartbulk'),
            'cond.value' => $this->l('Value', 'smartbulk'),
            'cond.n_days' => $this->l('N days', 'smartbulk'),
            'cond.no_tax' => $this->l('No tax', 'smartbulk'),
            'cond.pick_tax_rules' => $this->l('Pick tax rules…', 'smartbulk'),
            'cond.pick_categories' => $this->l('Pick categories…', 'smartbulk'),
            'cond.pick_brands' => $this->l('Pick brands…', 'smartbulk'),
            'cond.pick_suppliers' => $this->l('Pick suppliers…', 'smartbulk'),
            'cond.pick_tags' => $this->l('Pick tags…', 'smartbulk'),
            'cond.pick_attributes' => $this->l('Pick attributes…', 'smartbulk'),
            'product_picker.clear' => $this->l('Clear', 'smartbulk'),
            'product_picker.search_placeholder' => $this->l('Search product by name, ID, or reference...', 'smartbulk'),
            'product_picker.searching' => $this->l('Searching…', 'smartbulk'),
            'product_picker.search_failed' => $this->l('Failed to search', 'smartbulk'),
            'product_picker.no_products' => $this->l('No products found', 'smartbulk'),
            'approval.filter_all' => $this->l('all', 'smartbulk'),
            'approval.filter_pending' => $this->l('pending', 'smartbulk'),
            'approval.filter_accepted' => $this->l('accepted', 'smartbulk'),
            'approval.filter_rejected' => $this->l('rejected', 'smartbulk'),
            'approval.filter_failed' => $this->l('failed', 'smartbulk'),
            'approval.reject_all_pending' => $this->l('✗ Reject all pending ({n})', 'smartbulk'),
            'approval.accept_all_pending' => $this->l('✓ Accept all pending ({n})', 'smartbulk'),
            'approval.no_match' => $this->l('No runs match the current filter.', 'smartbulk'),
            'approval.collapse' => $this->l('Collapse', 'smartbulk'),
            'approval.expand' => $this->l('Expand', 'smartbulk'),
            'approval.unnamed_product' => $this->l('(unnamed product)', 'smartbulk'),
            'approval.error_label' => $this->l('Error', 'smartbulk'),
            'prompt_picker.loading' => $this->l('Loading prompts…', 'smartbulk'),
            'prompt_picker.empty' => $this->l('No prompts defined yet. Go to Prompts to create one.', 'smartbulk'),
            'prompt_picker.select_prompt' => $this->l('— select prompt —', 'smartbulk'),
            'prompt_picker.suggested' => $this->l('Suggested: {label}', 'smartbulk'),
            'prompt_picker.other_prompts' => $this->l('Other prompts', 'smartbulk'),
            'prompt_picker.all_prompts' => $this->l('All prompts', 'smartbulk'),
            'prompt_picker.version_label' => $this->l('Version:', 'smartbulk'),
            'prompt_picker.always_current' => $this->l('Always use current', 'smartbulk'),
            'prompt_picker.pin_to' => $this->l('Pin to v{n} ({model})', 'smartbulk'),
            'tpl_picker.group_user' => $this->l('⭐ Your templates', 'smartbulk'),
            'tpl_picker.group_seo' => $this->l('SEO', 'smartbulk'),
            'tpl_picker.group_stock' => $this->l('Stock', 'smartbulk'),
            'tpl_picker.group_basic' => $this->l('Basic', 'smartbulk'),
            'tpl_picker.group_content' => $this->l('Content', 'smartbulk'),
            'tpl_picker.group_ai' => $this->l('AI-powered', 'smartbulk'),
            'tpl_picker.toast_saved' => $this->l('Template saved', 'smartbulk'),
            'tpl_picker.toast_save_failed' => $this->l('Save failed', 'smartbulk'),
            'tpl_picker.toast_deleted' => $this->l('Template deleted', 'smartbulk'),
            'tpl_picker.toast_delete_failed' => $this->l('Delete failed', 'smartbulk'),
            'tpl_picker.confirm_delete_title' => $this->l('Delete', 'smartbulk'),
            'tpl_picker.confirm_delete_msg' => $this->l('Built-in templates aren\'t affected. This only removes your saved template.', 'smartbulk'),
            'tpl_picker.close_templates' => $this->l('Close templates', 'smartbulk'),
            'tpl_picker.load_template' => $this->l('📋 Load template ▾', 'smartbulk'),
            'tpl_picker.templates' => $this->l('Templates', 'smartbulk'),
            'tpl_picker.adds_actions' => $this->l('Adds the actions into the editor. You can still edit them.', 'smartbulk'),
            'tpl_picker.save_current' => $this->l('+ Save current', 'smartbulk'),
            'tpl_picker.template_name_placeholder' => $this->l('Template name (e.g. Q4 SEO refresh)', 'smartbulk'),
            'tpl_picker.description_placeholder' => $this->l('Description (optional)', 'smartbulk'),
            'tpl_picker.save_template' => $this->l('Save template', 'smartbulk'),
            'tpl_picker.delete_tpl_tooltip' => $this->l('Delete this template', 'smartbulk'),
            'preview.flag.truncated' => $this->l('Truncated', 'smartbulk'),
            'preview.flag.pattern_mismatch' => $this->l('Invalid format', 'smartbulk'),
            'preview.flag.below_min_clamped' => $this->l('Clamped to min', 'smartbulk'),
            'preview.flag.empty_old' => $this->l('Empty source', 'smartbulk'),
            'preview.flag.conflict_gt' => $this->l('Exceeds related', 'smartbulk'),
            'preview.flag.same_value_batch' => $this->l('Duplicate across batch', 'smartbulk'),
            'preview.flag.fallback_to_name' => $this->l('Used name as fallback', 'smartbulk'),
            'preview.flag.empty_source' => $this->l('Source empty', 'smartbulk'),
            'preview.flag.ai_generate' => $this->l('Generated by AI at apply', 'smartbulk'),
            'preview.flag.copy_from_invalid' => $this->l('Invalid source field', 'smartbulk'),
            'preview.flag_label' => $this->l('Flag', 'smartbulk'),
            'preview.field_label' => $this->l('Field', 'smartbulk'),
            'preview.truncated_warn' => $this->l('Counts cover all {total} products - the list below shows the first {n} rows.', 'smartbulk'),
            'preview.tab_attention' => $this->l('Needs attention', 'smartbulk'),
            'preview.tab_all' => $this->l('Browse all', 'smartbulk'),
            'preview.tab_summary' => $this->l('Per-field summary', 'smartbulk'),
            'preview.download_csv' => $this->l('⬇ Download CSV', 'smartbulk'),
            'preview.search_placeholder' => $this->l('Search by ID / name / REF…', 'smartbulk'),
            'preview.row_all' => $this->l('All ({n})', 'smartbulk'),
            'preview.row_changed' => $this->l('Changed ({n})', 'smartbulk'),
            'preview.row_unchanged' => $this->l('Unchanged ({n})', 'smartbulk'),
            'preview.row_with_warnings' => $this->l('With warnings ({n})', 'smartbulk'),
            'preview.reset_filters' => $this->l('Reset filters', 'smartbulk'),
            'preview.no_anomalies' => $this->l('✓ No anomalies detected across {n} products.', 'smartbulk'),
            'preview.card_in_scope' => $this->l('In scope', 'smartbulk'),
            'preview.card_will_change' => $this->l('Will change', 'smartbulk'),
            'preview.card_no_change' => $this->l('No change', 'smartbulk'),
            'preview.card_analyzed' => $this->l('Analyzed', 'smartbulk'),
            'preview.card_will_change_sampled' => $this->l('Will change (sample)', 'smartbulk'),
            'preview.card_no_change_sampled' => $this->l('No change (sample)', 'smartbulk'),
            'preview.card_with_warnings' => $this->l('With warnings', 'smartbulk'),
            'preview.filter_by_warning' => $this->l('Filter by warning:', 'smartbulk'),
            'preview.filter_by_field' => $this->l('Filter by field:', 'smartbulk'),
            'preview.no_field_stats' => $this->l('No field stats — no fields changed.', 'smartbulk'),
            'preview.col_field' => $this->l('Field', 'smartbulk'),
            'preview.col_changes' => $this->l('Changes', 'smartbulk'),
            'preview.col_avg_len' => $this->l('Avg len (old → new)', 'smartbulk'),
            'preview.col_over_limit' => $this->l('Over limit', 'smartbulk'),
            'preview.col_clamped' => $this->l('Clamped', 'smartbulk'),
            'preview.col_invalid' => $this->l('Invalid format', 'smartbulk'),
            'preview.duplicate_values' => $this->l('Duplicate values across batch ({n} group{plural}):', 'smartbulk'),
            'preview.empty' => $this->l('(empty)', 'smartbulk'),
            'preview.n_products' => $this->l('{n} products', 'smartbulk'),
            'preview.more_count' => $this->l('+{n} more', 'smartbulk'),
            'preview.unnamed' => $this->l('(unnamed)', 'smartbulk'),
            'preview.no_change_inline' => $this->l('· no change', 'smartbulk'),
            'preview.chars_change' => $this->l('{old} → {new} chars', 'smartbulk'),
            'preview.prev' => $this->l('← Previous', 'smartbulk'),
            'preview.next' => $this->l('Next →', 'smartbulk'),
            'preview.page_of' => $this->l('Page {p} of {t}', 'smartbulk'),
            'action_sidebar.title' => $this->l('Action summary', 'smartbulk'),
            'action_sidebar.n_active' => $this->l('{n} active', 'smartbulk'),
            'action_sidebar.empty' => $this->l('No actions yet. Use Load template or + Add field to start.', 'smartbulk'),
            'action_sidebar.prompt_n' => $this->l('prompt #{n}', 'smartbulk'),
            'action_sidebar.no_prompt' => $this->l('⚠ no prompt picked', 'smartbulk'),
            'action_sidebar.no_source' => $this->l('⚠ no source picked', 'smartbulk'),
            'action_sidebar.lang_n' => $this->l('lang {n}', 'smartbulk'),
            'action_sidebar.all_langs' => $this->l('all langs', 'smartbulk'),
            'lang_tabs.target_language' => $this->l('Target language:', 'smartbulk'),
            'lang_tabs.all_n' => $this->l('All ({n})', 'smartbulk'),
            'bulk.save_segment_disabled' => $this->l('Add at least one condition to save', 'smartbulk'),
        ];
    }

    private function registerHooks(): bool
    {
        foreach (self::HOOKS as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }
        return true;
    }

    private function installDefaultConfig(): bool
    {
        // Per-shop-group defaults. Keys follow SMARTBULK_* convention.
        $defaults = [
            'SMARTBULK_AI_PROVIDER'        => 'claude',
            'SMARTBULK_AI_DAILY_BUDGET'    => '25',
            'SMARTBULK_AI_RATE_LIMIT'      => '30',
            'SMARTBULK_AI_MASK_PRICES'     => '0',
            'SMARTBULK_AI_BRAND_TONE'      => '',
            'SMARTBULK_DATA_RETENTION_DAYS' => '90',
        ];
        foreach ($defaults as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }
        return true;
    }

    /**
     * Redirect module configuration link to the new React SPA.
     */
    public function getContent()
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminSmartBulk')
        );
    }

    // -----------------------------------------------------------------
    // Hook handlers — registered at install, implementations grow per feature.
    // -----------------------------------------------------------------

    public function hookDisplayBackOfficeHeader(): string
    {
        // Soft-heartbeat: every BO page load fires due schedules in-process,
        // throttled per session so it doesn't hammer the DB. External cron
        // is still recommended for unattended scheduling — see Scheduler page.
        try {
            // Throttle via Configuration, not $_SESSION — under the PS9 Symfony BO the
            // native session store isn't $_SESSION, so the throttle would never persist
            // and runDue() would fire on every page load.
            $now = time();
            $last = (int) \Configuration::getGlobalValue('SMARTBULK_HEARTBEAT_AT');
            if ($now - $last >= 60) {
                \Configuration::updateGlobalValue('SMARTBULK_HEARTBEAT_AT', (string) $now);
                /** @var \SmartBulk\Service\Schedule\ScheduleService|null $svc */
                $svc = $this->get('SmartBulk\Service\Schedule\ScheduleService');
                if ($svc !== null) $svc->runDue();
            }
        } catch (\Throwable $e) {
            // Heartbeat is best-effort — never let it break BO rendering.
            \PrestaShopLogger::addLog('SmartBulk heartbeat: ' . $e->getMessage(), 1, null, 'SmartBulk');
        }
        return '';
    }

    public function hookActionDispatcherBefore(array $params): void
    {
    }

    public function hookActionProductGridDefinitionModifier(array $params): void
    {
        // Two enhancements to the native PS product grid:
        //   1. Bulk action "Edit with SmartBulk" — multi-row → handoff to Bulk Editor.
        //   2. Per-row link "Edit in SmartBulk" — single-row → handoff to Bulk Editor.
        try {
            /** @var \PrestaShop\PrestaShop\Core\Grid\Definition\GridDefinitionInterface $definition */
            $definition = $params['definition'] ?? null;
            if ($definition === null) return;

            // ---- Bulk action ---------------------------------------------------
            if (class_exists('\PrestaShop\PrestaShop\Core\Grid\Action\Bulk\Type\SubmitBulkAction')) {
                $cls = '\PrestaShop\PrestaShop\Core\Grid\Action\Bulk\Type\SubmitBulkAction';
                $action = new $cls('smartbulk_edit_selected');
                if (method_exists($action, 'setName'))    $action->setName('Edit with SmartBulk');
                if (method_exists($action, 'setOptions')) $action->setOptions([
                    'submit_route'  => 'smartbulk_grid_handoff',
                    'submit_method' => 'POST',
                ]);
                if (method_exists($action, 'setIcon'))    $action->setIcon('edit');
                $definition->getBulkActions()->add($action);
            }

            // ---- Health column (powered by smartbulk_health_product cache) ----
            // Renders the SQL-built HTML badge. Sorts by the numeric alias.
            if (class_exists('\PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\HtmlColumn')) {
                $colCls = '\PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\HtmlColumn';
                /** @var object $healthCol */
                $healthCol = new $colCls('smartbulk_health');
                if (method_exists($healthCol, 'setName')) $healthCol->setName('Health');
                if (method_exists($healthCol, 'setOptions')) {
                    $healthCol->setOptions([
                        'field'     => 'smartbulk_score',
                        'sortable'  => true,
                        'clickable' => false,
                    ]);
                }
                $columns = $definition->getColumns();
                try {
                    if (method_exists($columns, 'addBefore')) {
                        $columns->addBefore('actions', $healthCol);
                    } elseif (method_exists($columns, 'add')) {
                        $columns->add($healthCol);
                    }
                } catch (\Throwable $e) {
                    if (method_exists($columns, 'add')) $columns->add($healthCol);
                }

                // Adjacent Smart-fix column with the per-row CTA button.
                /** @var object $smartFixCol */
                $smartFixCol = new $colCls('smartbulk_smart_fix');
                if (method_exists($smartFixCol, 'setName'))    $smartFixCol->setName('Smart fix');
                if (method_exists($smartFixCol, 'setOptions')) {
                    $smartFixCol->setOptions([
                        'field'     => 'smartbulk_smart_fix',
                        'sortable'  => false,
                        'clickable' => false,
                    ]);
                }
                try {
                    if (method_exists($columns, 'addBefore')) {
                        $columns->addBefore('actions', $smartFixCol);
                    } elseif (method_exists($columns, 'add')) {
                        $columns->add($smartFixCol);
                    }
                } catch (\Throwable $e) {
                    if (method_exists($columns, 'add')) $columns->add($smartFixCol);
                }
            }

            // ---- Per-row link action ------------------------------------------
            // Find the actions column ("actions" or last column of type ActionColumn) and
            // append a LinkRowAction pointing at the handoff route with productId=…
            if (class_exists('\PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\LinkRowAction')) {
                $rowCls = '\PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\LinkRowAction';
                /** @var object $rowAction */
                $rowAction = new $rowCls('smartbulk_edit_one');
                if (method_exists($rowAction, 'setName')) $rowAction->setName('Edit in SmartBulk');
                if (method_exists($rowAction, 'setIcon')) $rowAction->setIcon('edit');
                if (method_exists($rowAction, 'setOptions')) $rowAction->setOptions([
                    'route'             => 'smartbulk_grid_handoff',
                    'route_param_name'  => 'productId',
                    'route_param_field' => 'id_product',
                ]);

                // Reach into the "actions" column to append our row action.
                $columns = $definition->getColumns();
                if (method_exists($columns, 'getById')) {
                    try {
                        $actionsColumn = $columns->getById('actions');
                        $opts = $actionsColumn->getOptions();
                        if (isset($opts['actions']) && method_exists($opts['actions'], 'add')) {
                            $opts['actions']->add($rowAction);
                            $actionsColumn->setOptions($opts);
                        }
                    } catch (\Throwable $e) {
                        // Some grids don't expose the column by id 'actions'; ignore.
                    }
                }
            }
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('SmartBulk grid hook: ' . $e->getMessage(), 1, null, 'SmartBulk');
        }
    }

    public function hookActionProductGridDataModifier(array $params): void
    {
        // Data is added via the query builder hook below (immutable RecordCollection
        // in PS 9 means we can't mutate rows here — the official pattern is to
        // addSelect() in the QueryBuilder so the column field maps automatically).
    }

    public function hookActionProductGridQueryBuilderModifier(array $params): void
    {
        // Add Health column data: pre-rendered HTML badge + top-issue label,
        // plus a numeric alias for sorting. We build the HTML in SQL so the
        // Doctrine row already carries renderable markup (HtmlColumn).
        try {
            /** @var \Doctrine\DBAL\Query\QueryBuilder|null $qb */
            $qb = $params['search_query_builder'] ?? null;
            if ($qb === null) return;

            $idShop = (int) \Context::getContext()->shop->id;
            $idLang = (int) \Context::getContext()->language->id;
            $tableName = _DB_PREFIX_ . 'smartbulk_health_product';
            $alias = 'sbhp';

            $qb->leftJoin(
                'p',
                $tableName,
                $alias,
                $alias . '.id_product = p.id_product AND '
                . $alias . '.id_shop = ' . $idShop . ' AND '
                . $alias . '.id_lang = ' . $idLang
            );

            // Concatenated single-quoted strings keep PHP off the JSON paths.
            $scoreExpr = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(' . $alias . ".issues, '" . '$.composite_score' . "')) AS UNSIGNED)";
            $topExpr   = 'JSON_UNQUOTE(JSON_EXTRACT(' . $alias . ".issues, '" . '$.top_issue.label' . "'))";
            $topIdExpr = 'JSON_UNQUOTE(JSON_EXTRACT(' . $alias . ".issues, '" . '$.top_issue.id' . "'))";
            $countExpr = 'IFNULL(JSON_LENGTH(JSON_EXTRACT(' . $alias . ".issues, '" . '$.all_issues' . "')), 0)";
            // Full ordered list pre-formatted as " • " for the badge tooltip.
            $allTextExpr = 'IFNULL(JSON_UNQUOTE(JSON_EXTRACT(' . $alias . ".issues, '" . '$.all_issues_text' . "')), '')";

            // HTML badge: color-coded pill + top issue + "+N more" hint when
            // the product has more than one detected problem.
            $html = '
                CASE
                    WHEN ' . $scoreExpr . ' IS NULL THEN
                        \'<span style="color:#94a3b8;font-size:11px">—</span>\'
                    ELSE CONCAT(
                        \'<div style="line-height:1.2"><span style="display:inline-block;min-width:32px;text-align:center;padding:2px 8px;border-radius:10px;font-weight:600;color:#fff;background:\',
                        CASE
                            WHEN ' . $scoreExpr . ' >= 80 THEN \'#10b981\'
                            WHEN ' . $scoreExpr . ' >= 50 THEN \'#f59e0b\'
                            ELSE \'#ef4444\'
                        END,
                        \'">\', ' . $scoreExpr . ', \'</span>\',
                        IFNULL(
                            CONCAT(
                                \'<div style="font-size:10px;color:#64748b;margin-top:3px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="\',
                                REPLACE(' . $allTextExpr . ', \'"\', \'&quot;\'),
                                \'">\',
                                ' . $topExpr . ',
                                CASE
                                    WHEN ' . $countExpr . ' > 1
                                    THEN CONCAT(\' <span style="color:#94a3b8">+\', ' . $countExpr . ' - 1, \' more</span>\')
                                    ELSE \'\'
                                END,
                                \'</div>\'
                            ),
                            \'\'
                        ),
                        \'</div>\'
                    )
                END AS smartbulk_score';
            $qb->addSelect(trim($html));

            // Numeric alias for sortable column. When the user clicks the
            // Health header, PS sets searchCriteria.orderBy = 'smartbulk_score'
            // (matching the column field). That would order by the rendered
            // HTML string — useless. Override with the numeric alias instead.
            $qb->addSelect($scoreExpr . ' AS smartbulk_score_num');
            $criteria = $params['search_criteria'] ?? null;
            if ($criteria !== null && method_exists($criteria, 'getOrderBy') && $criteria->getOrderBy() === 'smartbulk_score') {
                $way = method_exists($criteria, 'getOrderWay') ? (string) $criteria->getOrderWay() : 'desc';
                $way = strtolower($way) === 'asc' ? 'ASC' : 'DESC';
                if (method_exists($qb, 'resetQueryPart')) {
                    $qb->resetQueryPart('orderBy');
                }
                $qb->orderBy('smartbulk_score_num', $way);
                // NULLs (= no cache row yet) sink to the bottom of the list.
                $qb->addOrderBy('p.id_product', 'ASC');
            }

            // Smart-fix link: pre-render the right handoff button based on the
            // top issue id. We resolve a real admin base URL once in PHP and
            // inject it as a SQL string literal so the rendered <a> works in
            // any back-office folder layout.
            $router = $this->get('router');
            try {
                $aiBase   = $router !== null ? $router->generate('smartbulk_ai_handoff') : '';
                $bulkBase = $router !== null ? $router->generate('smartbulk_grid_handoff') : '';
            } catch (\Throwable $e) {
                $aiBase = $bulkBase = '';
            }
            $sqlEsc = static fn (string $s): string => "'" . str_replace("'", "''", $s) . "'";
            $aiBaseLit   = $sqlEsc($aiBase);
            $bulkBaseLit = $sqlEsc($bulkBase);

            $aiFixIssues   = "'missing_meta_title','missing_meta_description','missing_short_desc','missing_description','missing_alt_text','meta_title_too_short','meta_desc_too_short'";
            $bulkFixIssues = "'meta_title_too_long','meta_desc_too_long','missing_link_rewrite'";

            $smartFixHtml = '
                CASE
                    WHEN ' . $topIdExpr . ' IS NULL THEN \'\'
                    WHEN ' . $topIdExpr . ' IN (' . $aiFixIssues . ') THEN
                        CONCAT(
                            \'<a href="\', ' . $aiBaseLit . ', \'?productId=\', p.id_product, \'&taskType=\',
                            CASE ' . $topIdExpr . '
                                WHEN \'missing_meta_title\'       THEN \'meta_title\'
                                WHEN \'meta_title_too_short\'     THEN \'meta_title\'
                                WHEN \'missing_meta_description\' THEN \'meta_description\'
                                WHEN \'meta_desc_too_short\'      THEN \'meta_description\'
                                WHEN \'missing_short_desc\'       THEN \'short_desc\'
                                WHEN \'missing_description\'      THEN \'long_desc\'
                                WHEN \'missing_alt_text\'         THEN \'alt_text\'
                                ELSE \'\'
                            END,
                            \'" target="_blank" style="display:inline-block;padding:4px 10px;font-size:11px;font-weight:500;color:#fff;background:#6366f1;border-radius:4px;text-decoration:none">✨ Fix with AI</a>\'
                        )
                    WHEN ' . $topIdExpr . ' IN (' . $bulkFixIssues . ') THEN
                        CONCAT(
                            \'<a href="\', ' . $bulkBaseLit . ', \'?productId=\', p.id_product, \'" target="_blank" style="display:inline-block;padding:4px 10px;font-size:11px;font-weight:500;color:#fff;background:#475569;border-radius:4px;text-decoration:none">📝 Fix in bulk</a>\'
                        )
                    ELSE
                        \'<span style="color:#94a3b8;font-size:11px">Edit fields →</span>\'
                END AS smartbulk_smart_fix';
            $qb->addSelect(trim($smartFixHtml));
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('SmartBulk grid query: ' . $e->getMessage(), 1, null, 'SmartBulk');
        }
    }

    public function hookActionProductGridFilterFormModifier(array $params): void
    {
    }

    public function hookActionAdminProductsListingFieldsModifier(array $params): void
    {
    }

    public function hookActionProductUpdate(array $params): void
    {
        try {
            $idProduct = (int) ($params['id_product'] ?? ($params['object']->id ?? 0));
            if ($idProduct <= 0) return;
            $ctx = \Context::getContext();
            $idShop = (int) $ctx->shop->id;
            /** @var \SmartBulk\Service\Health\ProductHealthScorer|null $scorer */
            $scorer = $this->get('SmartBulk\Service\Health\ProductHealthScorer');
            if ($scorer === null) return;

            // Recompute for every active language so the grid shows fresh data
            // regardless of which lang the BO is currently rendered in.
            $langs = \Language::getLanguages(true, $idShop) ?: [];
            if (!$langs) $langs = [['id_lang' => (int) $ctx->language->id]];
            foreach ($langs as $l) {
                $scorer->recompute($idProduct, $idShop, (int) $l['id_lang']);
            }
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('SmartBulk product update hook: ' . $e->getMessage(), 1, null, 'SmartBulk');
        }
    }

    /**
     * Renders the SmartBulk Health panel inside the "Modules" tab of the
     * native PrestaShop product form. Shows composite + per-group scores,
     * the full list of detected issues with severity, and per-issue actions
     * that hand the product off to AI Assistant / Bulk Editor.
     */
    public function hookDisplayAdminProductsExtra(array $params): string
    {
        try {
            $idProduct = (int) ($params['id_product'] ?? 0);
            if ($idProduct <= 0) return '';

            $ctx = \Context::getContext();
            $idShop = (int) $ctx->shop->id;
            $idLang = (int) $ctx->language->id;

            /** @var \SmartBulk\Service\Health\ProductHealthScorer|null $scorer */
            $scorer = $this->get('SmartBulk\Service\Health\ProductHealthScorer');
            if ($scorer === null) return '';

            // Compute fresh — cache miss on this product is fine, we just compute now.
            $snap = $scorer->compute($idProduct, $idShop, $idLang);
            if ($snap === null) return '';

            $smartbulkUrl = $this->context->link->getAdminLink('AdminSmartBulk');

            // PS 9: use the DI Twig environment with the @Modules namespace —
            // legacy Module::fetch() mangles module-prefixed paths in this hook.
            /** @var \Twig\Environment|null $twig */
            $twig = $this->get('twig');
            /** @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface|null $router */
            $router = $this->get('router');
            if ($twig === null || $router === null) return '';

            // Pre-build per-issue fix URLs so the template stays presentational.
            $issueMeta = $this->resolveIssueMeta();
            foreach ($issueMeta as $issueId => &$meta) {
                if ($meta['fixable_by'] === 'ai' && !empty($meta['task_type'])) {
                    $meta['fix_url'] = $router->generate('smartbulk_ai_handoff', [
                        'productId' => $idProduct,
                        'taskType'  => $meta['task_type'],
                    ]);
                } elseif ($meta['fixable_by'] === 'bulk') {
                    $meta['fix_url'] = $router->generate('smartbulk_grid_handoff', [
                        'productId' => $idProduct,
                    ]);
                } else {
                    $meta['fix_url'] = null;
                }
            }
            unset($meta);

            return $twig->render('@Modules/smartbulk/views/templates/admin/product_health_panel.html.twig', [
                'snap'          => $snap,
                'id_product'    => $idProduct,
                'smartbulk_url' => $smartbulkUrl,
                'issue_meta'    => $issueMeta,
            ]);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('SmartBulk product extra hook: ' . $e->getMessage(), 1, null, 'SmartBulk');
            return '';
        }
    }

    /**
     * Maps internal issue ids to human label + group + AI task type used for
     * the per-issue action button. Mirrors ContentHealthService::problems().
     *
     * @return array<string,array{label:string,group:string,severity:string,fixable_by:string,task_type:?string,bulk_field:?string}>
     */
    private function resolveIssueMeta(): array
    {
        return [
            'missing_meta_title'        => ['label' => 'Missing meta title',         'group' => 'seo',     'severity' => 'high',   'fixable_by' => 'ai',   'task_type' => 'meta_title',       'bulk_field' => 'meta_title'],
            'missing_meta_description'  => ['label' => 'Missing meta description',   'group' => 'seo',     'severity' => 'high',   'fixable_by' => 'ai',   'task_type' => 'meta_description', 'bulk_field' => 'meta_description'],
            'meta_title_too_short'      => ['label' => 'Meta title < 30 chars',      'group' => 'seo',     'severity' => 'medium', 'fixable_by' => 'ai',   'task_type' => 'meta_title',       'bulk_field' => 'meta_title'],
            'meta_title_too_long'       => ['label' => 'Meta title > 60 chars',     'group' => 'seo',     'severity' => 'medium', 'fixable_by' => 'bulk', 'task_type' => null,               'bulk_field' => 'meta_title'],
            'meta_desc_too_short'       => ['label' => 'Meta description < 70',     'group' => 'seo',     'severity' => 'medium', 'fixable_by' => 'ai',   'task_type' => 'meta_description', 'bulk_field' => 'meta_description'],
            'meta_desc_too_long'        => ['label' => 'Meta description > 160',    'group' => 'seo',     'severity' => 'low',    'fixable_by' => 'bulk', 'task_type' => null,               'bulk_field' => 'meta_description'],
            'missing_link_rewrite'      => ['label' => 'Missing friendly URL',       'group' => 'seo',     'severity' => 'high',   'fixable_by' => 'bulk', 'task_type' => null,               'bulk_field' => 'link_rewrite'],
            'missing_short_desc'        => ['label' => 'Missing short description', 'group' => 'content', 'severity' => 'high',   'fixable_by' => 'ai',   'task_type' => 'short_desc',       'bulk_field' => 'description_short'],
            'missing_description'       => ['label' => 'Missing long description',  'group' => 'content', 'severity' => 'medium', 'fixable_by' => 'ai',   'task_type' => 'long_desc',        'bulk_field' => 'description'],
            'short_desc_too_short'      => ['label' => 'Short description < 80',    'group' => 'content', 'severity' => 'low',    'fixable_by' => 'ai',   'task_type' => 'short_desc',       'bulk_field' => 'description_short'],
            'description_too_short'     => ['label' => 'Long description < 300',    'group' => 'content', 'severity' => 'low',    'fixable_by' => 'ai',   'task_type' => 'long_desc',        'bulk_field' => 'description'],
            'missing_main_image'        => ['label' => 'No product images',          'group' => 'content', 'severity' => 'high',   'fixable_by' => 'manual', 'task_type' => null,             'bulk_field' => null],
            'missing_alt_text'          => ['label' => 'Images without alt text',   'group' => 'content', 'severity' => 'medium', 'fixable_by' => 'ai',   'task_type' => 'alt_text',         'bulk_field' => null],
            'missing_reference'         => ['label' => 'Missing reference (SKU)',   'group' => 'codes',   'severity' => 'high',   'fixable_by' => 'manual', 'task_type' => null,             'bulk_field' => null],
            'missing_ean'               => ['label' => 'Missing EAN/GTIN',          'group' => 'codes',   'severity' => 'medium', 'fixable_by' => 'manual', 'task_type' => null,             'bulk_field' => null],
        ];
    }

    public function hookActionProductDelete(array $params): void
    {
        try {
            $idProduct = (int) ($params['id_product'] ?? ($params['object']->id ?? 0));
            if ($idProduct <= 0) return;
            $ctx = \Context::getContext();
            /** @var \SmartBulk\Service\Health\ProductHealthScorer|null $scorer */
            $scorer = $this->get('SmartBulk\Service\Health\ProductHealthScorer');
            if ($scorer === null) return;
            // Wipe the cache for this product across all shops/langs since the product is gone.
            $scorer->delete($idProduct, (int) $ctx->shop->id);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('SmartBulk product delete hook: ' . $e->getMessage(), 1, null, 'SmartBulk');
        }
    }
}
