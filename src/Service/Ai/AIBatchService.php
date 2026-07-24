<?php
/**
 * SmartBulk — AIBatchService
 *
 * Batch orchestration: create a batch of products + a single prompt, then process
 * them in chunks (driven by the client polling /process-next). Each processed
 * product yields an ai_run with status=pending awaiting user approval.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Ai;

use Context;
use InvalidArgumentException;
use SmartBulk\Repository\MasseditRepository;
use SmartBulk\Repository\PromptRepository;

final class AIBatchService
{
    public function __construct(
        private readonly MasseditRepository $masseditRepo,
        private readonly PromptRepository $promptRepo,
        private readonly AIService $ai,
    ) {
    }

    /**
     * @param int[] $productIds
     * @param array<string,mixed>|null $filterSpec  For snapshot / audit only
     * @return array<string,mixed>  Batch status
     */
    public function createBatch(
        int $idPrompt,
        int $idLang,
        array $productIds,
        ?int $idVersion = null,
        ?array $filterSpec = null,
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

        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $productIds = array_values(array_filter($productIds, static fn (int $id) => $id > 0));
        if (!$productIds) {
            throw new InvalidArgumentException('Empty product list');
        }

        $ctx = Context::getContext();
        $idShop = (int) $ctx->shop->id;
        $idEmployee = $ctx->employee ? (int) $ctx->employee->id : null;

        $idBatch = $this->masseditRepo->create([
            'id_shop'          => $idShop,
            'segment_snapshot' => [
                'product_ids'       => $productIds,
                'filter_spec'       => $filterSpec,
                'id_lang'           => $idLang,
                'id_prompt'         => $idPrompt,
                'id_prompt_version' => $versionId,
                'target_field'      => $targetField,
                'target_lang'       => $targetLang,
            ],
            'action_snapshot'  => [
                'kind'              => 'ai_batch',
                'task_type'         => (string) $prompt['task_type'],
                'prompt_name'       => (string) $prompt['name'],
                'id_prompt'         => $idPrompt,
                'id_prompt_version' => $versionId,
            ],
            'mode'             => 'apply',
            'status'           => 'pending',
            'products_matched' => count($productIds),
            'id_employee'      => $idEmployee,
        ]);

        return $this->getStatus($idBatch);
    }

    /**
     * Process up to $limit unprocessed products from the batch.
     *
     * @return array<string,mixed> Batch status including the runs produced this turn.
     */
    public function processNext(int $idBatch, int $limit): array
    {
        $batch = $this->masseditRepo->find($idBatch);
        if ($batch === null) {
            throw new InvalidArgumentException("Batch {$idBatch} not found");
        }

        if ($batch['status'] === 'pending') {
            $this->masseditRepo->updateStatus($idBatch, 'running');
        }

        $snapshot = $batch['segment_snapshot'] ? json_decode((string) $batch['segment_snapshot'], true) : [];
        if (!is_array($snapshot) || empty($snapshot['product_ids'])) {
            $this->masseditRepo->updateStatus($idBatch, 'failed');
            throw new InvalidArgumentException('Batch has no product list');
        }

        $idLang     = (int) ($snapshot['id_lang'] ?? Context::getContext()->language->id);
        $idPrompt   = (int) ($snapshot['id_prompt'] ?? 0);
        $idVersion  = (int) ($snapshot['id_prompt_version'] ?? 0);
        $targetField = isset($snapshot['target_field']) && $snapshot['target_field'] !== null ? (string) $snapshot['target_field'] : null;
        $targetLang  = isset($snapshot['target_lang'])  && $snapshot['target_lang']  !== null ? (int)    $snapshot['target_lang']  : null;
        $allIds      = array_map('intval', $snapshot['product_ids']);

        $processed = $this->masseditRepo->processedProductIds($idBatch);
        $pending   = array_values(array_diff($allIds, $processed));

        $chunk = array_slice($pending, 0, max(1, min(20, $limit)));
        $runsThisTurn = [];
        $failed = 0;
        $done = 0;

        foreach ($chunk as $idProduct) {
            try {
                $run = $this->ai->generate($idPrompt, $idProduct, $idLang, $idVersion, $idBatch, $targetField, $targetLang);
                $runsThisTurn[] = $run;
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                $runsThisTurn[] = [
                    'id_product' => $idProduct,
                    'status'     => 'failed',
                    'error'      => $e->getMessage(),
                ];
            }
        }

        $this->masseditRepo->incrementCounters($idBatch, $done, $failed);

        $remaining = count($pending) - count($chunk);
        if ($remaining <= 0) {
            $finalStatus = $failed > 0 && $done > 0 ? 'partial' : ($failed > 0 ? 'failed' : 'success');
            $this->masseditRepo->updateStatus($idBatch, $finalStatus);
        }

        $status = $this->getStatus($idBatch);
        $status['runs_this_turn'] = $runsThisTurn;
        return $status;
    }

    /** @return array<string,mixed> */
    public function getStatus(int $idBatch): array
    {
        $batch = $this->masseditRepo->find($idBatch);
        if ($batch === null) {
            throw new InvalidArgumentException("Batch {$idBatch} not found");
        }

        $snapshot = $batch['segment_snapshot'] ? json_decode((string) $batch['segment_snapshot'], true) : [];
        $action   = $batch['action_snapshot']  ? json_decode((string) $batch['action_snapshot'],  true) : [];

        $allIds = is_array($snapshot) ? array_map('intval', $snapshot['product_ids'] ?? []) : [];
        $processed = $this->masseditRepo->processedProductIds($idBatch);
        $runs = $this->masseditRepo->listRuns($idBatch);

        $counts = ['pending' => 0, 'accepted' => 0, 'rejected' => 0, 'failed' => 0];
        foreach ($runs as $r) {
            $s = (string) $r['status'];
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }

        $totalCost = 0.0;
        foreach ($runs as $r) {
            $totalCost += (float) $r['cost_usd'];
        }

        return [
            'id_batch'  => (int) $batch['id_massedit'],
            'status'    => (string) $batch['status'],
            'task_type' => (string) ($action['task_type'] ?? ''),
            'prompt'    => [
                'id_prompt'         => (int) ($action['id_prompt'] ?? 0),
                'id_prompt_version' => (int) ($action['id_prompt_version'] ?? 0),
                'name'              => (string) ($action['prompt_name'] ?? ''),
            ],
            'id_lang'        => (int) ($snapshot['id_lang'] ?? 0),
            'total'          => count($allIds),
            'done'           => count($processed),
            'remaining'      => max(0, count($allIds) - count($processed)),
            'counts'         => $counts,
            'total_cost_usd' => round($totalCost, 6),
            'started_at'     => $batch['started_at'],
            'finished_at'    => $batch['finished_at'] ?? null,
        ];
    }

    /**
     * Accept up to $limit pending runs in the batch. Chunked on purpose — a single
     * synchronous "accept all" on 200+ products easily blows past LiteSpeed/PHP
     * timeouts and holds locks. Frontend loops calling this until remaining=0.
     *
     * @return array{accepted:int,failed:int,errors:array<int,string>,remaining:int}
     */
    public function acceptAll(int $idBatch, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $pendingIds = array_slice($this->masseditRepo->pendingRunIds($idBatch), 0, $limit);
        $accepted = 0;
        $failed = 0;
        $errors = [];
        foreach ($pendingIds as $idRun) {
            try {
                $this->ai->accept($idRun);
                $accepted++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[$idRun] = $e->getMessage();
            }
        }
        $remaining = count($this->masseditRepo->pendingRunIds($idBatch));
        return ['accepted' => $accepted, 'failed' => $failed, 'errors' => $errors, 'remaining' => $remaining];
    }

    /**
     * Reject up to $limit pending runs in the batch (chunked, same reason as acceptAll).
     *
     * @return array{rejected:int,remaining:int}
     */
    public function rejectAll(int $idBatch, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $pendingIds = array_slice($this->masseditRepo->pendingRunIds($idBatch), 0, $limit);
        $rejected = 0;
        foreach ($pendingIds as $idRun) {
            try {
                $this->ai->reject($idRun);
                $rejected++;
            } catch (\Throwable $e) {
                // skip — rejection should be cheap; if it fails we just retry next chunk
            }
        }
        $remaining = count($this->masseditRepo->pendingRunIds($idBatch));
        return ['rejected' => $rejected, 'remaining' => $remaining];
    }

    /** @return array<int,array<string,mixed>> */
    public function listRuns(int $idBatch, ?string $status = null): array
    {
        $runs = $this->masseditRepo->listRuns($idBatch, $status);
        return array_map([$this, 'normalizeRun'], $runs);
    }

    /** @param array<string,mixed> $r */
    private function normalizeRun(array $r): array
    {
        $ctx = $r['input_context'] ? json_decode((string) $r['input_context'], true) : null;
        return [
            'id_run'     => (int) $r['id_run'],
            'status'     => (string) $r['status'],
            'id_product' => (int) $r['id_product'],
            'id_lang'    => (int) $r['id_lang'],
            'output'     => (string) $r['output'],
            'tokens_in'  => (int) $r['tokens_in'],
            'tokens_out' => (int) $r['tokens_out'],
            'cost_usd'   => (float) $r['cost_usd'],
            'latency_ms' => (int) $r['latency_ms'],
            'error'      => $r['error'] ?? null,
            'created_at' => $r['created_at'],
            'product_name' => is_array($ctx) ? (string) ($ctx['name'] ?? '') : '',
        ];
    }
}
