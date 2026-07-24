<?php
/**
 * SmartBulk — HistoryService
 *
 * Unified view over past operations: both bulk-edit batches and AI batches
 * share the `smartbulk_massedit` table; their kind is stored inside
 * `action_snapshot.kind`. Detail endpoint auto-routes to the correct log
 * table (massedit_log for bulk, ai_run for AI).
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\History;

use Db;
use InvalidArgumentException;

final class HistoryService
{
    /**
     * @param array{kind?:string,status?:string,limit?:int,offset?:int} $filters
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function listBatches(array $filters = []): array
    {
        $prefix = _DB_PREFIX_;
        $limit  = max(1, min(100, (int) ($filters['limit']  ?? 20)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $kind   = (string) ($filters['kind']   ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');

        $where = ['1=1'];
        if (in_array($status, ['pending','running','success','partial','failed','undone'], true)) {
            $where[] = "b.status = '" . pSQL($status) . "'";
        }
        // Kind filter works against the JSON snapshot. Bulk records set kind='bulk_edit',
        // AI records set kind='ai_batch'. Anything else is grouped under "other".
        if ($kind === 'bulk') {
            $where[] = "JSON_EXTRACT(b.action_snapshot, '$.kind') = '\"bulk_edit\"'";
        } elseif ($kind === 'ai') {
            $where[] = "JSON_EXTRACT(b.action_snapshot, '$.kind') = '\"ai_batch\"'";
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `{$prefix}smartbulk_massedit` b WHERE {$whereSql}"
        );

        if ($total === 0) return ['items' => [], 'total' => 0];

        $rows = Db::getInstance()->executeS(
            "SELECT b.id_massedit, b.id_shop, b.status, b.mode, b.products_matched, b.products_changed, b.products_failed,
                    b.started_at, b.finished_at, b.id_employee,
                    b.action_snapshot, b.segment_snapshot,
                    e.firstname AS emp_firstname, e.lastname AS emp_lastname, e.email AS emp_email,
                    EXISTS(SELECT 1 FROM `{$prefix}smartbulk_massedit_log` l
                           WHERE l.id_massedit = b.id_massedit AND l.status = 'changed') AS has_log
             FROM `{$prefix}smartbulk_massedit` b
             LEFT JOIN `{$prefix}employee` e ON e.id_employee = b.id_employee
             WHERE {$whereSql}
             ORDER BY b.started_at DESC, b.id_massedit DESC
             LIMIT {$offset}, {$limit}"
        ) ?: [];

        $items = [];
        foreach ($rows as $r) {
            $items[] = $this->normalizeRow($r);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string,mixed>
     */
    public function detail(int $idBatch): array
    {
        $prefix = _DB_PREFIX_;
        $row = Db::getInstance()->getRow(
            "SELECT b.*, e.firstname AS emp_firstname, e.lastname AS emp_lastname, e.email AS emp_email
             FROM `{$prefix}smartbulk_massedit` b
             LEFT JOIN `{$prefix}employee` e ON e.id_employee = b.id_employee
             WHERE b.id_massedit = " . (int) $idBatch
        );
        if (!$row) {
            throw new InvalidArgumentException("Batch {$idBatch} not found");
        }

        $batch = $this->normalizeRow($row);
        $batch['action_snapshot'] = $row['action_snapshot'] ? json_decode((string) $row['action_snapshot'], true) : null;
        $batch['segment_snapshot'] = $row['segment_snapshot'] ? json_decode((string) $row['segment_snapshot'], true) : null;

        if ($batch['kind'] === 'bulk_edit') {
            $batch['logs'] = $this->loadBulkLogs($idBatch);
        } elseif ($batch['kind'] === 'ai_batch') {
            $batch['runs'] = $this->loadAiRuns($idBatch);
            // For AI batches that had accepts, show the resulting field changes
            // too so the undo button drill-down shows what will be reverted.
            $logs = $this->loadBulkLogs($idBatch);
            if (!empty($logs)) $batch['logs'] = $logs;
        } else {
            $batch['logs'] = [];
        }

        return $batch;
    }

    // ================================================================
    // Internals
    // ================================================================

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function normalizeRow(array $r): array
    {
        $action = is_string($r['action_snapshot'] ?? null) ? json_decode((string) $r['action_snapshot'], true) : null;
        $segment = is_string($r['segment_snapshot'] ?? null) ? json_decode((string) $r['segment_snapshot'], true) : null;

        $kind = is_array($action) ? (string) ($action['kind'] ?? '') : '';
        if ($kind === '') $kind = 'bulk_edit'; // legacy default

        $employeeName = '';
        if (!empty($r['emp_firstname']) || !empty($r['emp_lastname'])) {
            $employeeName = trim((string) $r['emp_firstname'] . ' ' . (string) $r['emp_lastname']);
        } elseif (!empty($r['emp_email'])) {
            $employeeName = (string) $r['emp_email'];
        }

        return [
            'id_batch'         => (int) $r['id_massedit'],
            'id_shop'          => (int) ($r['id_shop'] ?? 0),
            'kind'             => $kind,
            'status'           => (string) $r['status'],
            'mode'             => (string) ($r['mode'] ?? 'apply'),
            'products_matched' => (int) ($r['products_matched'] ?? 0),
            'products_changed' => (int) ($r['products_changed'] ?? 0),
            'products_failed'  => (int) ($r['products_failed'] ?? 0),
            'started_at'       => (string) ($r['started_at'] ?? ''),
            'finished_at'      => $r['finished_at'] ?? null,
            'employee_id'      => $r['id_employee'] !== null ? (int) $r['id_employee'] : null,
            'employee_name'    => $employeeName,
            'summary'          => $this->summaryFor($kind, $action),
            'scope_summary'    => $this->scopeSummaryFor($segment),
            'has_undoable_changes' => (bool) ($r['has_log'] ?? false),
        ];
    }

    /** @param array<string,mixed>|null $action */
    private function summaryFor(string $kind, ?array $action): string
    {
        if (!is_array($action)) return '—';
        if ($kind === 'bulk_edit') {
            $n = is_array($action['actions'] ?? null) ? count($action['actions']) : 0;
            $fields = [];
            foreach ($action['actions'] ?? [] as $a) {
                if (!empty($a['field'])) $fields[] = (string) $a['field'];
            }
            $label = $n === 1 ? '1 field' : "{$n} fields";
            if ($fields) $label .= ' · ' . implode(', ', array_slice($fields, 0, 3)) . (count($fields) > 3 ? '…' : '');
            return $label;
        }
        if ($kind === 'ai_batch') {
            $prompt = is_array($action['prompt'] ?? null) ? ((string) ($action['prompt']['name'] ?? '')) : '';
            $taskType = is_array($action['prompt'] ?? null) ? (string) ($action['prompt']['task_type'] ?? '') : '';
            return $prompt !== '' ? "Prompt: {$prompt}" . ($taskType ? " ({$taskType})" : '') : 'AI batch';
        }
        return '—';
    }

    /** @param array<string,mixed>|null $seg */
    private function scopeSummaryFor(?array $seg): string
    {
        if (!is_array($seg)) return '—';
        if (!empty($seg['product_ids']) && is_array($seg['product_ids'])) {
            return count($seg['product_ids']) . ' products';
        }
        if (!empty($seg['filter_spec'])) return 'Filter spec';
        return '—';
    }

    /** @return array<int,array<string,mixed>> */
    private function loadBulkLogs(int $idBatch): array
    {
        $prefix = _DB_PREFIX_;
        $rows = Db::getInstance()->executeS(
            "SELECT id_log, id_product, id_lang, field, old_value, new_value, status, error, changed_at
             FROM `{$prefix}smartbulk_massedit_log`
             WHERE id_massedit = " . (int) $idBatch . "
             ORDER BY id_log ASC
             LIMIT 2000"
        ) ?: [];
        return array_map(static fn (array $r) => [
            'id_log'     => (int) $r['id_log'],
            'id_product' => (int) $r['id_product'],
            'id_lang'    => $r['id_lang'] !== null ? (int) $r['id_lang'] : null,
            'field'      => (string) $r['field'],
            'old'        => (string) ($r['old_value'] ?? ''),
            'new'        => (string) ($r['new_value'] ?? ''),
            'status'     => (string) $r['status'],
            'error'      => $r['error'] !== null ? (string) $r['error'] : null,
            'changed_at' => (string) $r['changed_at'],
        ], $rows);
    }

    /** @return array<int,array<string,mixed>> */
    private function loadAiRuns(int $idBatch): array
    {
        $prefix = _DB_PREFIX_;
        $rows = Db::getInstance()->executeS(
            "SELECT r.id_run, r.id_product, r.id_lang, r.status, r.output,
                    r.tokens_in, r.tokens_out, r.cost_usd, r.latency_ms, r.error, r.created_at,
                    pl.name AS product_name
             FROM `{$prefix}smartbulk_ai_run` r
             LEFT JOIN `{$prefix}product_lang` pl
                 ON pl.id_product = r.id_product AND pl.id_lang = r.id_lang
             WHERE r.id_massedit = " . (int) $idBatch . "
             GROUP BY r.id_run
             ORDER BY r.id_run ASC
             LIMIT 2000"
        ) ?: [];
        return array_map(static fn (array $r) => [
            'id_run'       => (int) $r['id_run'],
            'id_product'   => (int) $r['id_product'],
            'product_name' => (string) ($r['product_name'] ?? ''),
            'id_lang'      => $r['id_lang'] !== null ? (int) $r['id_lang'] : null,
            'status'       => (string) $r['status'],
            'output'       => (string) ($r['output'] ?? ''),
            'tokens_in'    => $r['tokens_in']  !== null ? (int) $r['tokens_in']  : null,
            'tokens_out'   => $r['tokens_out'] !== null ? (int) $r['tokens_out'] : null,
            'cost_usd'     => $r['cost_usd']   !== null ? (float) $r['cost_usd']   : null,
            'latency_ms'   => $r['latency_ms'] !== null ? (int) $r['latency_ms'] : null,
            'error'        => $r['error'] !== null ? (string) $r['error'] : null,
            'created_at'   => (string) $r['created_at'],
        ], $rows);
    }
}
