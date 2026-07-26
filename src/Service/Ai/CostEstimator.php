<?php
/**
 * SmartBulk — CostEstimator
 *
 * Estimates AI run cost by rendering the prompt against one sample product
 * and converting prompt-length + expected output-length to token counts.
 * Better than the flat per-model heuristic the UI used before.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Ai;

use Context;
use Db;
use InvalidArgumentException;
use SmartBulk\Repository\PromptRepository;

final class CostEstimator
{
    /** Per-1k-tokens prices in USD (input, output). Conservative defaults. */
    private const MODEL_RATES = [
        // Claude (Anthropic)
        'claude-haiku-4-5'  => ['in' => 0.00025, 'out' => 0.00125],
        'claude-sonnet-4-6' => ['in' => 0.003,   'out' => 0.015],
        'claude-opus-4-7'   => ['in' => 0.015,   'out' => 0.075],
        // OpenAI
        'gpt-4o-mini'       => ['in' => 0.00015, 'out' => 0.0006],
        'gpt-4o'            => ['in' => 0.0025,  'out' => 0.01],
    ];

    /**
     * Typical output token budget per task_type. Used when no exact answer exists.
     */
    private const OUTPUT_BUDGET = [
        'meta_title'       => 20,
        'meta_description' => 60,
        'short_desc'       => 220,
        'long_desc'        => 600,
        'translate'        => 0,    // sized from input
        'alt_text'         => 25,
        'tagging'          => 60,
        'seo_rewrite'      => 30,
        'custom'           => 200,
    ];

    public function __construct(
        private readonly PromptRepository $promptRepo,
        private readonly ProductContextLoader $contextLoader,
        private readonly PromptRenderer $renderer,
    ) {
    }

    /**
     * @return array{
     *   model:string,
     *   input_tokens:int,
     *   output_tokens:int,
     *   cost_per_run_usd:float,
     *   total_cost_usd:float,
     *   product_count:int,
     *   sample_product_id:?int,
     * }
     */
    public function estimate(int $idPrompt, int $productCount, ?int $sampleProductId = null, ?int $idVersion = null): array
    {
        $prompt = $this->promptRepo->findPrompt($idPrompt);
        if ($prompt === null) {
            throw new InvalidArgumentException("Prompt {$idPrompt} not found");
        }
        $versionId = $idVersion ?? ($prompt['current_version'] !== null ? (int) $prompt['current_version'] : 0);
        if ($versionId === 0) throw new InvalidArgumentException('Prompt has no active version');
        $version = $this->promptRepo->findVersion($versionId);
        if ($version === null) throw new InvalidArgumentException('Prompt version not found');

        $model    = (string) $version['model'];
        $taskType = (string) $prompt['task_type'];
        $rates    = self::MODEL_RATES[$model] ?? ['in' => 0.001, 'out' => 0.003];

        // Resolve a sample product to render against. Without one we still
        // estimate based on the bare template text.
        $ctx = Context::getContext();
        $idLang = (int) $ctx->language->id;
        $idProduct = $sampleProductId ?? $this->pickAnyProduct((int) $ctx->shop->id);

        $context = $idProduct !== null ? $this->contextLoader->load($idProduct, $idLang) : [];
        $rendered = $this->renderer->render(
            (string) $version['system_prompt'],
            (string) $version['user_prompt'],
            $context
        );

        // Rough token estimate: ~4 chars = 1 token (works for English+Polish).
        $inputChars = mb_strlen((string) $rendered['system']) + mb_strlen((string) $rendered['user']);
        $inputTokens = (int) ceil($inputChars / 4);

        // Output budget per task_type, fallback ~150.
        $outputBudget = self::OUTPUT_BUDGET[$taskType] ?? 150;
        if ($outputBudget === 0 && $taskType === 'translate') {
            // translate output ≈ input source field length
            $outputBudget = max(50, $inputTokens);
        }

        $costPerRun = round(
            ($inputTokens / 1000) * $rates['in'] + ($outputBudget / 1000) * $rates['out'],
            6
        );

        return [
            'model'             => $model,
            'input_tokens'      => $inputTokens,
            'output_tokens'     => $outputBudget,
            'cost_per_run_usd'  => $costPerRun,
            'total_cost_usd'    => round($costPerRun * max(0, $productCount), 4),
            'product_count'     => max(0, $productCount),
            'sample_product_id' => $idProduct,
        ];
    }

    private function pickAnyProduct(int $idShop): ?int
    {
        // No explicit LIMIT — PrestaShop's getValue() appends "LIMIT 1" itself;
        // adding our own produces "LIMIT 1 LIMIT 1" (SQL syntax error on MariaDB).
        $id = Db::getInstance()->getValue(
            'SELECT id_product FROM `' . _DB_PREFIX_ . 'product_shop`
             WHERE id_shop = ' . (int) $idShop . ' AND active = 1
             ORDER BY id_product ASC'
        );
        return $id !== false && $id !== null ? (int) $id : null;
    }
}
