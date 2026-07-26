<?php
/**
 * SmartBulk — AI API controller
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Controller\Api;

use InvalidArgumentException;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use SmartBulk\Service\Ai\AIBatchService;
use SmartBulk\Service\Ai\AIService;
use SmartBulk\Service\Ai\ClaudeProvider;
use SmartBulk\Service\Ai\OpenAIProvider;
use SmartBulk\Service\Segment\SegmentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AiController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function generateAction(Request $request, AIService $service): JsonResponse
    {
        $payload = $this->jsonBody($request);
        $idPrompt   = (int) ($payload['id_prompt']  ?? 0);
        $idProduct  = (int) ($payload['id_product'] ?? 0);
        $idLang     = isset($payload['id_lang']) ? (int) $payload['id_lang'] : null;
        $idVersion  = isset($payload['id_version']) ? (int) $payload['id_version'] : null;
        $targetField = isset($payload['target_field']) && $payload['target_field'] !== '' ? (string) $payload['target_field'] : null;
        $targetLang  = isset($payload['target_lang']) && $payload['target_lang'] !== '' ? (int) $payload['target_lang'] : null;

        if ($idPrompt <= 0 || $idProduct <= 0) {
            return new JsonResponse(['ok' => false, 'error' => 'id_prompt and id_product are required'], 422);
        }

        try {
            $result = $service->generate($idPrompt, $idProduct, $idLang, $idVersion, null, $targetField, $targetLang);
            return new JsonResponse(['ok' => true, 'run' => $result]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function acceptAction(int $id, AIService $service): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true, 'run' => $service->accept($id)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function estimateAction(Request $request, \SmartBulk\Service\Ai\CostEstimator $estimator): JsonResponse
    {
        $payload = $this->jsonBody($request);
        $idPrompt    = (int) ($payload['id_prompt']    ?? 0);
        $idVersion   = isset($payload['id_version']) ? (int) $payload['id_version'] : null;
        $productCount = (int) ($payload['product_count'] ?? 1);
        $sample      = isset($payload['sample_product_id']) ? (int) $payload['sample_product_id'] : null;

        if ($idPrompt <= 0) {
            return new JsonResponse(['ok' => false, 'error' => 'id_prompt is required'], 422);
        }
        try {
            return new JsonResponse(['ok' => true, 'estimate' => $estimator->estimate($idPrompt, $productCount, $sample, $idVersion)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function rejectAction(int $id, AIService $service): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true, 'run' => $service->reject($id)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Test connection to a provider — generates a 1-token response to verify the key works.
     */
    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function testConnectionAction(
        Request $request,
        ClaudeProvider $claude,
        OpenAIProvider $openai
    ): JsonResponse {
        $provider = (string) $request->query->get('provider', 'claude');

        try {
            $resp = match ($provider) {
                'claude' => $claude->generate('Respond with the single word: OK', 'ping', ['model' => 'claude-haiku-4-5', 'max_tokens' => 8]),
                'openai' => $openai->generate('Respond with the single word: OK', 'ping', ['model' => 'gpt-4o-mini', 'max_tokens' => 8]),
                default  => throw new InvalidArgumentException("Unknown provider '{$provider}'"),
            };
            return new JsonResponse([
                'ok'         => true,
                'provider'   => $provider,
                'model'      => $resp->model,
                'response'   => $resp->content,
                'latency_ms' => $resp->latencyMs,
                'cost_usd'   => $resp->costUsd,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    // ================================================================
    // Batch endpoints
    // ================================================================

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function createBatchAction(
        Request $request,
        AIBatchService $batch,
        SegmentService $segments
    ): JsonResponse {
        $payload = $this->jsonBody($request);
        $idPrompt    = (int) ($payload['id_prompt']  ?? 0);
        $idVersion   = isset($payload['id_version']) ? (int) $payload['id_version'] : null;
        $idLang      = (int) ($payload['id_lang'] ?? \Context::getContext()->language->id);
        $targetField = isset($payload['target_field']) && $payload['target_field'] !== '' ? (string) $payload['target_field'] : null;
        $targetLang  = isset($payload['target_lang'])  && $payload['target_lang']  !== '' ? (int)    $payload['target_lang']  : null;

        if ($idPrompt <= 0) {
            return new JsonResponse(['ok' => false, 'error' => 'id_prompt is required'], 422);
        }

        $productIds = [];
        if (isset($payload['product_ids']) && is_array($payload['product_ids'])) {
            $productIds = array_map('intval', $payload['product_ids']);
        } elseif (isset($payload['filter_spec']) && is_array($payload['filter_spec'])) {
            $productIds = $segments->listProductIds($payload['filter_spec']);
        }

        $filterSpec = $payload['filter_spec'] ?? null;

        try {
            $status = $batch->createBatch(
                $idPrompt, $idLang, $productIds, $idVersion,
                is_array($filterSpec) ? $filterSpec : null,
                $targetField, $targetLang
            );
            return new JsonResponse(['ok' => true, 'batch' => $status], 201);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function processNextBatchAction(int $id, Request $request, AIBatchService $batch): JsonResponse
    {
        $limit = max(1, min(20, (int) $request->query->get('limit', 5)));
        try {
            return new JsonResponse(['ok' => true, 'batch' => $batch->processNext($id, $limit)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function getBatchAction(int $id, Request $request, AIBatchService $batch): JsonResponse
    {
        try {
            $status = $batch->getStatus($id);
            $includeRuns = $request->query->get('include_runs') === '1';
            if ($includeRuns) {
                $status['runs'] = $batch->listRuns($id);
            }
            return new JsonResponse(['ok' => true, 'batch' => $status]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function acceptAllBatchAction(int $id, Request $request, AIBatchService $batch): JsonResponse
    {
        try {
            $limit = (int) $request->query->get('limit', '50');
            return new JsonResponse(['ok' => true, 'result' => $batch->acceptAll($id, $limit)]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function rejectAllBatchAction(int $id, Request $request, AIBatchService $batch): JsonResponse
    {
        try {
            $limit = (int) $request->query->get('limit', '100');
            return new JsonResponse(['ok' => true, 'result' => $batch->rejectAll($id, $limit)]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function resumeBatchAction(int $id, AIBatchService $batch): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true, 'batch' => $batch->resume($id)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** @return array<string,mixed> */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
