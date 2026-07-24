<?php
/**
 * SmartBulk — MasseditRepository
 *
 * CRUD over smartbulk_massedit. Used as the "batch container" for AI runs:
 * segment_snapshot stores the product IDs and filter spec; each ai_run in the
 * batch references id_massedit.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Repository;

use Db;

final class MasseditRepository
{
    /**
     * @param array{id_shop:int,segment_snapshot?:?array<string,mixed>,action_snapshot?:?array<string,mixed>,mode?:string,status?:string,products_matched?:int,id_employee?:?int} $data
     */
    public function create(array $data): int
    {
        Db::getInstance()->insert('smartbulk_massedit', [
            'id_shop'          => (int) $data['id_shop'],
            'id_segment'       => isset($data['id_segment']) ? (int) $data['id_segment'] : null,
            'id_template'      => null,
            'segment_snapshot' => isset($data['segment_snapshot']) ? pSQL((string) json_encode($data['segment_snapshot']), true) : null,
            'action_snapshot'  => isset($data['action_snapshot'])  ? pSQL((string) json_encode($data['action_snapshot']),  true) : null,
            'mode'             => pSQL($data['mode']   ?? 'apply'),
            'status'           => pSQL($data['status'] ?? 'pending'),
            'products_matched' => (int) ($data['products_matched'] ?? 0),
            'products_changed' => 0,
            'products_failed'  => 0,
            'started_at'       => date('Y-m-d H:i:s'),
            'id_employee'      => isset($data['id_employee']) ? (int) $data['id_employee'] : null,
        ]);
        return (int) Db::getInstance()->Insert_ID();
    }

    /** @return array<string,mixed>|null */
    public function find(int $idMassedit): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'smartbulk_massedit`
             WHERE id_massedit = ' . (int) $idMassedit
        );
        return $row ?: null;
    }

    public function updateStatus(int $idMassedit, string $status): void
    {
        $extra = '';
        if (in_array($status, ['success', 'failed', 'partial', 'undone'], true)) {
            $extra = ', finished_at = NOW()';
        }
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'smartbulk_massedit`
             SET status = "' . pSQL($status) . '"' . $extra . '
             WHERE id_massedit = ' . (int) $idMassedit
        );
    }

    public function incrementCounters(int $idMassedit, int $changed, int $failed): void
    {
        if ($changed === 0 && $failed === 0) return;
        $updates = [];
        if ($changed > 0) $updates[] = 'products_changed = products_changed + ' . $changed;
        if ($failed > 0)  $updates[] = 'products_failed  = products_failed  + ' . $failed;
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'smartbulk_massedit`
             SET ' . implode(', ', $updates) . '
             WHERE id_massedit = ' . (int) $idMassedit
        );
    }

    /**
     * @return int[] Product IDs already processed in this batch (any ai_run exists).
     */
    public function processedProductIds(int $idMassedit): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT id_product FROM `' . _DB_PREFIX_ . 'smartbulk_ai_run`
             WHERE id_massedit = ' . (int) $idMassedit
        );
        return $rows ? array_map(static fn ($r) => (int) $r['id_product'], $rows) : [];
    }

    /**
     * @return array<int,array<string,mixed>> Runs for this batch, newest first.
     */
    public function listRuns(int $idMassedit, ?string $status = null): array
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'smartbulk_ai_run`
                WHERE id_massedit = ' . (int) $idMassedit;
        if ($status !== null) {
            $sql .= ' AND status = "' . pSQL($status) . '"';
        }
        $sql .= ' ORDER BY id_run DESC';
        $rows = Db::getInstance()->executeS($sql);
        return $rows ?: [];
    }

    /** @return int[] ai_run.id_run values in pending state for this batch. */
    public function pendingRunIds(int $idMassedit): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT id_run FROM `' . _DB_PREFIX_ . 'smartbulk_ai_run`
             WHERE id_massedit = ' . (int) $idMassedit . ' AND status = "pending"'
        );
        return $rows ? array_map(static fn ($r) => (int) $r['id_run'], $rows) : [];
    }
}
