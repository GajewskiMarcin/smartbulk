<?php
/**
 * SmartBulk — AIService
 *
 * High-level orchestrator: load prompt version → load product context → render →
 * call provider → persist run → return result. Accept/reject applies output to
 * the right product field based on the prompt's task_type.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Ai;

use Context;
use InvalidArgumentException;
use Product;
use RuntimeException;
use SmartBulk\Repository\AiRunRepository;
use SmartBulk\Repository\MasseditRepository;
use SmartBulk\Repository\PromptRepository;
use SmartBulk\Service\SettingsService;

final class AIService
{
    public function __construct(
        private readonly PromptRepository $promptRepo,
        private readonly AiRunRepository $runRepo,
        private readonly AIProviderFactory $providers,
        private readonly ProductContextLoader $contextLoader,
        private readonly PromptRenderer $renderer,
        private readonly SettingsService $settings,
        private readonly MasseditRepository $masseditRepo,
    ) {
    }

    /**
     * Task types whose auto-apply is recorded in smartbulk_massedit_log so it
     * can be undone later. Alt-text and tagging aren't field-level writes and
     * don't fit the log's (field, old, new) shape — they're applied but not
     * made reversible via the standard undo path.
     */
    private const UNDOABLE_TASK_TYPES = [
        'meta_title', 'meta_description', 'short_desc', 'long_desc', 'translate', 'seo_rewrite',
    ];

    /**
     * Generate content for a single product + prompt. Result is stored as a
     * pending ai_run and returned — accept/reject applied separately.
     *
     * @param int|null $idBatch When set, the run is attached to a massedit batch.
     * @return array<string,mixed>  Normalized run payload for JSON response
     */
    public function generate(
        int $idPrompt,
        int $idProduct,
        ?int $idLang = null,
        ?int $idVersion = null,
        ?int $idBatch = null,
        ?string $targetField = null,
        ?int $targetLang = null
    ): array {
        $prompt = $this->promptRepo->findPrompt($idPrompt);
        if ($prompt === null) {
            throw new InvalidArgumentException("Prompt {$idPrompt} not found");
        }

        $versionId = $idVersion ?? ($prompt['current_version'] !== null ? (int) $prompt['current_version'] : 0);
        if ($versionId === 0) {
            throw new InvalidArgumentException('Prompt has no active version');
        }
        $version = $this->promptRepo->findVersion($versionId);
        if ($version === null || (int) $version['id_prompt'] !== $idPrompt) {
            throw new InvalidArgumentException('Version does not belong to this prompt');
        }

        $ctx = Context::getContext();
        $lang = $idLang ?? (int) $ctx->language->id;
        $shop = (int) $ctx->shop->id;

        $this->checkBudget($shop);
        $this->checkRateLimit($shop);

        // Prompt params (JSON in prompt_version.params) may define the default
        // target_field / target_lang for tasks that need them (translate, seo_rewrite, tagging).
        $versionParams = [];
        if ($version['params']) {
            $decoded = json_decode((string) $version['params'], true);
            if (is_array($decoded)) $versionParams = $decoded;
        }
        $targetField = $targetField ?? ($versionParams['target_field'] ?? null);
        $targetLang  = $targetLang  ?? (isset($versionParams['target_lang']) ? (int) $versionParams['target_lang'] : null);

        $productCtx = $this->contextLoader->load($idProduct, $lang, $targetLang);
        // Inject global settings into context
        $productCtx['brand_tone'] = (string) $this->settings->getAllMasked()['brand_tone'];

        // Persist accept-time routing info inside input_context (no schema migration).
        // Underscore prefix so it doesn't clash with prompt placeholders.
        if ($targetField !== null) $productCtx['_target_field'] = $targetField;
        if ($targetLang !== null)  $productCtx['_target_lang']  = (string) $targetLang;

        // {existing_text} is filled for tasks that rewrite/translate an existing field.
        $taskType = (string) $prompt['task_type'];
        if (in_array($taskType, ['translate', 'seo_rewrite'], true)) {
            $sourceField = $targetField ?: 'description';
            $productCtx['existing_text'] = (string) ($productCtx[$sourceField] ?? '');
        }

        $rendered = $this->renderer->render(
            (string) $version['system_prompt'],
            (string) $version['user_prompt'],
            $productCtx
        );

        $params = [];
        if ($version['params']) {
            $decoded = json_decode((string) $version['params'], true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }
        $params['model'] = (string) $version['model'];

        $provider = $this->providers->get((string) $version['provider']);

        try {
            $response = $provider->generate($rendered['system'], $rendered['user'], $params);
        } catch (\Throwable $e) {
            // Persist the failed run for audit
            $this->runRepo->insert([
                'id_prompt_version' => $versionId,
                'id_massedit'       => $idBatch,
                'id_product'        => $idProduct,
                'id_lang'           => $lang,
                'id_shop'           => $shop,
                'input_context'     => $productCtx,
                'output'            => '',
                'tokens_in'         => 0,
                'tokens_out'        => 0,
                'cost_usd'          => 0.0,
                'latency_ms'        => 0,
                'status'            => 'failed',
                'error'             => $e->getMessage(),
            ]);
            throw $e;
        }

        $idRun = $this->runRepo->insert([
            'id_prompt_version' => $versionId,
            'id_massedit'       => $idBatch,
            'id_product'        => $idProduct,
            'id_lang'           => $lang,
            'id_shop'           => $shop,
            'input_context'     => $productCtx,
            'output'            => $response->content,
            'tokens_in'         => $response->tokensIn,
            'tokens_out'        => $response->tokensOut,
            'cost_usd'          => $response->costUsd,
            'latency_ms'        => $response->latencyMs,
            'status'            => 'pending',
        ]);

        return [
            'id_run'     => $idRun,
            'status'     => 'pending',
            'output'     => $response->content,
            'tokens_in'  => $response->tokensIn,
            'tokens_out' => $response->tokensOut,
            'cost_usd'   => $response->costUsd,
            'latency_ms' => $response->latencyMs,
            'model'      => $response->model,
            'provider'   => $response->provider,
            'prompt'     => [
                'id_prompt'         => $idPrompt,
                'id_prompt_version' => $versionId,
                'task_type'         => (string) $prompt['task_type'],
                'name'              => (string) $prompt['name'],
            ],
            'product'    => [
                'id_product' => $idProduct,
                'id_lang'    => $lang,
                'name'       => $productCtx['name'],
            ],
            'rendered' => [
                'system' => $rendered['system'],
                'user'   => $rendered['user'],
            ],
        ];
    }

    /**
     * Accept a pending run — write its output to the correct product field
     * based on task_type.
     */
    public function accept(int $idRun): array
    {
        $run = $this->runRepo->find($idRun);
        if ($run === null) {
            throw new InvalidArgumentException("Run {$idRun} not found");
        }
        if ($run['status'] !== 'pending') {
            throw new InvalidArgumentException("Run {$idRun} is not pending (status: {$run['status']})");
        }

        $version = $this->promptRepo->findVersion((int) $run['id_prompt_version']);
        if ($version === null) {
            throw new RuntimeException('Prompt version missing');
        }
        $prompt = $this->promptRepo->findPrompt((int) $version['id_prompt']);
        if ($prompt === null) {
            throw new RuntimeException('Prompt missing');
        }

        $context = is_string($run['input_context'] ?? null)
            ? (json_decode((string) $run['input_context'], true) ?: [])
            : [];

        $applied = $this->applyToProduct(
            (int) $run['id_product'],
            (int) $run['id_lang'],
            (string) $prompt['task_type'],
            (string) $run['output'],
            $context
        );

        $this->runRepo->updateStatus($idRun, 'accepted');

        // Record the change in massedit_log so undo works. AI batches already
        // carry an id_massedit; standalone accepts get a small wrapper batch
        // created on the fly so they show up in History with an undo button.
        $idBatchForLog = (int) ($run['id_massedit'] ?? 0);
        if ($idBatchForLog === 0 && in_array((string) $prompt['task_type'], self::UNDOABLE_TASK_TYPES, true)
            && isset($applied['field'], $applied['lang'], $applied['old'], $applied['new'])
            && $applied['old'] !== $applied['new']
        ) {
            $idBatchForLog = $this->createWrapperBatch(
                (int) $run['id_shop'],
                (int) $run['id_product'],
                (string) $prompt['task_type'],
                (string) $prompt['name'],
                (int) $prompt['id_prompt'],
                (int) $run['id_prompt_version']
            );
            $this->masseditRepo->incrementCounters($idBatchForLog, 1, 0);
            $this->masseditRepo->updateStatus($idBatchForLog, 'success');
        }

        if ($idBatchForLog > 0
            && in_array((string) $prompt['task_type'], self::UNDOABLE_TASK_TYPES, true)
            && isset($applied['field'], $applied['lang'], $applied['old'], $applied['new'])
        ) {
            $this->logFieldChange(
                $idBatchForLog,
                (int) $run['id_product'],
                (int) $applied['lang'],
                (string) $applied['field'],
                (string) $applied['old'],
                (string) $applied['new']
            );
        }

        // Media tasks (alt_text / tagging) write to image_lang / product_tag, not a product
        // field. Log them under a marker field so they show in History and can be undone —
        // undo() restores the snapshot carried in old_value.
        if (in_array((string) $prompt['task_type'], ['alt_text', 'tagging'], true)) {
            if ($idBatchForLog === 0) {
                $idBatchForLog = $this->createWrapperBatch(
                    (int) $run['id_shop'],
                    (int) $run['id_product'],
                    (string) $prompt['task_type'],
                    (string) $prompt['name'],
                    (int) $prompt['id_prompt'],
                    (int) $run['id_prompt_version']
                );
                $this->masseditRepo->incrementCounters($idBatchForLog, 1, 0);
                $this->masseditRepo->updateStatus($idBatchForLog, 'success');
            }
            $field    = (string) $prompt['task_type'] === 'alt_text' ? '_alt_text' : '_tags';
            $snapshot = (string) $prompt['task_type'] === 'alt_text'
                ? ['old_map'  => $applied['old_map']  ?? []]
                : ['old_tags' => $applied['old_tags'] ?? []];
            $this->logFieldChange(
                $idBatchForLog,
                (int) $run['id_product'],
                (int) $run['id_lang'],
                $field,
                json_encode($snapshot) ?: '',
                (string) $run['output']
            );
        }

        return [
            'id_run'   => $idRun,
            'status'   => 'accepted',
            'applied'  => $applied,
            'id_batch' => $idBatchForLog ?: null,
        ];
    }

    private function createWrapperBatch(int $idShop, int $idProduct, string $taskType, string $promptName, int $idPrompt, int $idVersion): int
    {
        $ctx = Context::getContext();
        return $this->masseditRepo->create([
            'id_shop' => $idShop,
            'segment_snapshot' => [
                'product_ids'       => [$idProduct],
                'filter_spec'       => null,
                'id_prompt'         => $idPrompt,
                'id_prompt_version' => $idVersion,
                'source'            => 'ai_manual_accept',
            ],
            'action_snapshot' => [
                'kind'        => 'ai_batch',
                'task_type'   => $taskType,
                'prompt_name' => $promptName,
                'source'      => 'ai_manual_accept',
            ],
            'mode'             => 'apply',
            'status'           => 'running',
            'products_matched' => 1,
            'id_employee'      => $ctx->employee ? (int) $ctx->employee->id : null,
        ]);
    }

    private function logFieldChange(int $idBatch, int $idProduct, int $idLang, string $field, string $old, string $new): void
    {
        \Db::getInstance()->insert('smartbulk_massedit_log', [
            'id_massedit' => $idBatch,
            'id_product'  => $idProduct,
            'id_lang'     => $idLang,
            'field'       => pSQL($field),
            'old_value'   => pSQL($old, true),
            'new_value'   => pSQL($new, true),
            'status'      => 'changed',
            'changed_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function reject(int $idRun): array
    {
        $run = $this->runRepo->find($idRun);
        if ($run === null) {
            throw new InvalidArgumentException("Run {$idRun} not found");
        }
        if ($run['status'] !== 'pending') {
            throw new InvalidArgumentException("Run {$idRun} is not pending");
        }
        $this->runRepo->updateStatus($idRun, 'rejected');
        return ['id_run' => $idRun, 'status' => 'rejected'];
    }

    /**
     * Non-throwing check used by the batch runner to PAUSE (not loop) when a limit
     * is hit. Returns a human message when blocked, or null when a run may proceed.
     */
    public function limitReason(int $idShop): ?string
    {
        try {
            $this->checkBudget($idShop);
            $this->checkRateLimit($idShop);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        return null;
    }

    private function checkBudget(int $idShop): void
    {
        $daily = (float) ($this->settings->getAllMasked()['daily_budget'] ?? 0);
        if ($daily <= 0) {
            return;
        }
        $spent = $this->runRepo->getDailySpendUsd($idShop, date('Y-m-d'));
        if ($spent >= $daily) {
            throw new RuntimeException(sprintf(
                'Daily AI budget reached ($%.2f / $%.2f). Increase it in Settings or wait until tomorrow.',
                $spent, $daily
            ));
        }
    }

    /**
     * Throws when the per-minute rate limit set in Settings is hit. Prevents
     * blasting through to provider 429s on big batches.
     */
    private function checkRateLimit(int $idShop): void
    {
        $limit = (int) ($this->settings->getAllMasked()['rate_limit'] ?? 0);
        if ($limit <= 0) return;

        $count = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smartbulk_ai_run`
             WHERE id_shop = ' . (int) $idShop . '
               AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)'
        );
        if ($count >= $limit) {
            throw new RuntimeException(sprintf(
                'Rate limit reached (%d runs in last 60 s, limit %d). Wait a minute or raise the limit in Settings.',
                $count, $limit
            ));
        }
    }

    /**
     * Write the output to the product field matching the task_type.
     * Returns a description of what was applied.
     *
     * @param array<string,mixed> $context  Original input_context from the run (may carry _target_field / _target_lang overrides).
     * @return array<string,mixed>  Shape depends on task_type:
     *   - field-write: {field, lang, old, new}
     *   - alt_text:    {images_updated, lang, new}
     *   - tagging:     {tags_set, lang, replaced, value}
     */
    private function applyToProduct(int $idProduct, int $idLang, string $taskType, string $output, array $context = []): array
    {
        // -------- Tasks that don't write into product_lang/product row --------
        if ($taskType === 'alt_text') {
            return $this->applyAltText($idProduct, $idLang, $output);
        }
        if ($taskType === 'tagging') {
            return $this->applyTags($idProduct, $idLang, $output);
        }

        // -------- Field-write tasks (share the same mechanics) ----------------
        $product = new Product($idProduct, false, $idLang);
        if (!$product->id) {
            throw new RuntimeException("Product {$idProduct} not found");
        }

        // Explicit override wins over the task_type default.
        $targetField = isset($context['_target_field']) ? (string) $context['_target_field'] : null;
        $targetLang  = isset($context['_target_lang'])
            ? (int) $context['_target_lang']
            : $idLang;

        if ($targetField === null) {
            $targetField = match ($taskType) {
                'meta_title'       => 'meta_title',
                'meta_description' => 'meta_description',
                'short_desc'       => 'description_short',
                'long_desc'        => 'description',
                'translate'        => 'description',   // sensible default; override via prompt params
                'seo_rewrite'      => 'meta_title',    // sensible default; override via prompt params
                default            => throw new RuntimeException("Auto-apply not supported for task_type '{$taskType}'. Copy the output manually."),
            };
        }

        if (!in_array($targetField, ['name', 'meta_title', 'meta_description', 'description_short', 'description', 'link_rewrite'], true)) {
            throw new RuntimeException("Unsupported target_field '{$targetField}' for task_type '{$taskType}'.");
        }

        // Re-load product in the target language so we pick up that lang's current value for the "old" snapshot.
        if ($targetLang !== $idLang) {
            $product = new Product($idProduct, false, $targetLang);
            if (!$product->id) {
                throw new RuntimeException("Product {$idProduct} not found in target lang {$targetLang}");
            }
        }

        $old = (string) ($product->{$targetField} ?? '');

        // Multi-lang update — only touch the chosen language
        $product->{$targetField} = is_array($product->{$targetField}) ? $product->{$targetField} : [];
        $product->{$targetField}[$targetLang] = $output;
        $product->update();

        return ['field' => $targetField, 'lang' => $targetLang, 'old' => $old, 'new' => $output];
    }

    /**
     * Write the AI output as alt-text (image_lang.legend) for every image of the product
     * in the given language.
     *
     * @return array{task:string,images_updated:int,lang:int,new:string}
     */
    private function applyAltText(int $idProduct, int $idLang, string $output): array
    {
        $prefix = _DB_PREFIX_;
        $rows = \Db::getInstance()->executeS(
            "SELECT id_image FROM `{$prefix}image` WHERE id_product = " . (int) $idProduct
        ) ?: [];
        if (!$rows) {
            throw new RuntimeException("Product {$idProduct} has no images — nothing to apply alt text to.");
        }

        $updated = 0;
        $safe = pSQL($output, true);
        $oldMap = []; // id_image => previous legend (null when no row existed) — for undo
        foreach ($rows as $r) {
            $idImage = (int) $r['id_image'];
            $prev = \Db::getInstance()->getValue(
                "SELECT legend FROM `{$prefix}image_lang` WHERE id_image = {$idImage} AND id_lang = " . (int) $idLang
            );
            $oldMap[$idImage] = ($prev === false || $prev === null) ? null : (string) $prev;
            // INSERT ... ON DUPLICATE KEY UPDATE — image_lang row may not exist for this lang yet.
            $ok = \Db::getInstance()->execute(
                "INSERT INTO `{$prefix}image_lang` (id_image, id_lang, legend) VALUES
                    ({$idImage}, " . (int) $idLang . ", '{$safe}')
                 ON DUPLICATE KEY UPDATE legend = '{$safe}'"
            );
            if ($ok) $updated++;
        }

        return ['task' => 'alt_text', 'images_updated' => $updated, 'lang' => $idLang, 'new' => $output, 'old_map' => $oldMap];
    }

    /**
     * Parse AI output into tag list and REPLACE all current tags for this product + lang.
     * Output can be either comma-separated ("fast, blue, waterproof") or a JSON array ("[\"fast\",\"blue\"]").
     *
     * @return array{task:string,tags_set:int,lang:int,replaced:bool,value:string[]}
     */
    private function applyTags(int $idProduct, int $idLang, string $output): array
    {
        $tags = $this->parseTagOutput($output);
        if (!$tags) {
            throw new RuntimeException('AI output did not contain any usable tags.');
        }

        // Replace semantics — wipe existing tags for this product+lang first.
        $prefix = _DB_PREFIX_;
        // Snapshot current tag ids first so undo can restore them.
        $oldTags = array_map(
            static fn ($r) => (int) $r['id_tag'],
            \Db::getInstance()->executeS(
                "SELECT id_tag FROM `{$prefix}product_tag`
                 WHERE id_product = " . (int) $idProduct . " AND id_lang = " . (int) $idLang
            ) ?: []
        );
        \Db::getInstance()->execute(
            "DELETE FROM `{$prefix}product_tag`
             WHERE id_product = " . (int) $idProduct . "
               AND id_lang    = " . (int) $idLang
        );

        \Tag::addTags($idLang, (int) $idProduct, implode(',', $tags), ',');

        return ['task' => 'tagging', 'tags_set' => count($tags), 'lang' => $idLang, 'replaced' => true, 'value' => $tags, 'old_tags' => $oldTags];
    }

    /** @return string[] */
    private function parseTagOutput(string $output): array
    {
        $output = trim($output);
        if ($output === '') return [];

        // Try JSON array first
        if ($output[0] === '[') {
            $decoded = json_decode($output, true);
            if (is_array($decoded)) {
                $tags = array_map(static fn ($v) => trim((string) $v), $decoded);
            } else {
                $tags = [];
            }
        } else {
            // comma / newline / semicolon separated
            $tags = preg_split('/[,;\n]+/', $output) ?: [];
            $tags = array_map('trim', $tags);
        }

        $clean = [];
        $seen  = [];
        foreach ($tags as $t) {
            $t = trim($t, " \t\n\r#*\"'.-");
            if ($t === '') continue;
            if (mb_strlen($t) > 32) $t = mb_substr($t, 0, 32);
            $key = mb_strtolower($t);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $clean[] = $t;
        }
        return array_slice($clean, 0, 20); // sanity cap
    }
}
