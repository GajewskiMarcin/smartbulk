<?php
/**
 * SmartBulk — ScheduleRepository
 *
 * CRUD over smartbulk_schedule. Each row defines a cron-driven job
 * (bulk_edit or ai_batch) that fires on next_run_at when active.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Repository;

use Db;

final class ScheduleRepository
{
    /**
     * @param array{id_shop:int,name:string,job_type:string,job_payload:array<string,mixed>,cron_expression:string,is_active?:int,next_run_at?:?string,created_by?:?int} $data
     */
    public function create(array $data): int
    {
        Db::getInstance()->insert('smartbulk_schedule', [
            'id_shop'         => (int) $data['id_shop'],
            'name'            => pSQL((string) $data['name']),
            'job_type'        => pSQL((string) $data['job_type']),
            'job_payload'     => pSQL((string) json_encode($data['job_payload']), true),
            'cron_expression' => pSQL((string) $data['cron_expression']),
            'is_active'       => (int) ($data['is_active'] ?? 1),
            'last_run_at'     => null,
            'next_run_at'     => $data['next_run_at'] !== null ? pSQL((string) $data['next_run_at']) : null,
            'created_by'      => $data['created_by'] !== null ? (int) $data['created_by'] : null,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        return (int) Db::getInstance()->Insert_ID();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $set = [];
        if (isset($data['name']))            $set[] = "name = '" . pSQL((string) $data['name']) . "'";
        if (isset($data['job_payload']))     $set[] = "job_payload = '" . pSQL((string) json_encode($data['job_payload']), true) . "'";
        if (isset($data['cron_expression'])) $set[] = "cron_expression = '" . pSQL((string) $data['cron_expression']) . "'";
        if (isset($data['is_active']))       $set[] = "is_active = " . (int) $data['is_active'];
        if (array_key_exists('next_run_at', $data)) {
            $set[] = $data['next_run_at'] === null ? "next_run_at = NULL" : "next_run_at = '" . pSQL((string) $data['next_run_at']) . "'";
        }
        if (array_key_exists('last_run_at', $data)) {
            $set[] = $data['last_run_at'] === null ? "last_run_at = NULL" : "last_run_at = '" . pSQL((string) $data['last_run_at']) . "'";
        }
        if (!$set) return;

        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'smartbulk_schedule` SET '
            . implode(', ', $set)
            . ' WHERE id_schedule = ' . (int) $id
        );
    }

    public function delete(int $id): void
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'smartbulk_schedule`
             WHERE id_schedule = ' . (int) $id
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'smartbulk_schedule`
             WHERE id_schedule = ' . (int) $id
        );
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listForShop(int $idShop): array
    {
        return Db::getInstance()->executeS(
            'SELECT s.*, e.firstname AS emp_firstname, e.lastname AS emp_lastname, e.email AS emp_email
             FROM `' . _DB_PREFIX_ . 'smartbulk_schedule` s
             LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON e.id_employee = s.created_by
             WHERE s.id_shop = ' . (int) $idShop . '
             ORDER BY s.is_active DESC, s.next_run_at ASC, s.id_schedule DESC'
        ) ?: [];
    }

    /**
     * Active jobs whose next_run_at is in the past. Caller invokes them in turn.
     * @return array<int,array<string,mixed>>
     */
    public function listDue(): array
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'smartbulk_schedule`
             WHERE is_active = 1
               AND next_run_at IS NOT NULL
               AND next_run_at <= NOW()
             ORDER BY next_run_at ASC
             LIMIT 50'
        ) ?: [];
    }
}
