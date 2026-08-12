<?php
/**
 * SmartBulk — History API
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Controller\Api;

use InvalidArgumentException;
use SmartBulk\Controller\CompatAdminController;
use SmartBulk\Service\History\HistoryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class HistoryController extends CompatAdminController
{
    public function listAction(Request $request, HistoryService $history): JsonResponse
    {
        $this->assertAccess('read');
        try {
            $res = $history->listBatches([
                'kind'   => (string) $request->query->get('kind', 'all'),
                'status' => (string) $request->query->get('status', 'all'),
                'limit'  => (int) $request->query->get('limit', 20),
                'offset' => (int) $request->query->get('offset', 0),
            ]);
            return new JsonResponse(['ok' => true, 'items' => $res['items'], 'total' => $res['total']]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function detailAction(int $id, HistoryService $history): JsonResponse
    {
        $this->assertAccess('read');
        try {
            return new JsonResponse(['ok' => true, 'batch' => $history->detail($id)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
