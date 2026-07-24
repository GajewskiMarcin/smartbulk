<?php
/**
 * SmartBulk — AiRunRepository (smartbulk_ai_run + smartbulk_ai_cache)
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Repository;

use Db;

final class AiRunRepository
{
    /**
     * @param array{id_prompt_version:int,id_massedit?:?int,id_product:int,id_lang?:?int,id_shop:int,input_context?:?array<string,mixed>,output:string,tokens_in:int,tokens_out:int,cost_usd:float,latency_ms:int,status:string,error?:?string} $data
     */
    public function insert(array $data): int
    {
        Db::getInstance()->insert('smartbulk_ai_run', [
            'id_prompt_version' => (int) $data['id_prompt_version'],
            'id_massedit'       => isset($data['id_massedit']) ? (int) $data['id_massedit'] : null,
            'id_product'        => (int) $data['id_product'],
            'id_lang'           => isset($data['id_lang']) ? (int) $data['id_lang'] : null,
            'id_shop'           => (int) $data['id_shop'],
            'input_context'     => isset($data['input_context']) ? pSQL((string) json_encode($data['input_context']), true) : null,
            'output'            => pSQL((string) $data['output'], true),
            'tokens_in'         => (int) $data['tokens_in'],
            'tokens_out'        => (int) $data['tokens_out'],
            'cost_usd'          => (float) $data['cost_usd'],
            'latency_ms'        => (int) $data['latency_ms'],
            'status'            => pSQL($data['status']),
            'error'             => isset($data['error']) && $data['error'] !== null
                                    ? pSQL((string) $data['error'], true) : null,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
        return (int) Db::getInstance()->Insert_ID();
    }

    /** @return array<string,mixed>|null */
    public function find(int $idRun): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'smartbulk_ai_run`
             WHERE id_run = ' . (int) $idRun
        );
        return $row ?: null;
    }

    public function updateStatus(int $idRun, string $status): void
    {
        Db::getInstance()->update(
            'smartbulk_ai_run',
            ['status' => pSQL($status)],
            'id_run = ' . (int) $idRun
        );
    }

    /** @return int Daily spend in USD (rounded). */
    public function getDailySpendUsd(int $idShop, string $date): float
    {
        $val = Db::getInstance()->getValue(
            'SELECT SUM(cost_usd) FROM `' . _DB_PREFIX_ . 'smartbulk_ai_run`
             WHERE id_shop = ' . (int) $idShop . '
               AND DATE(created_at) = "' . pSQL($date) . '"
               AND status <> "failed"'
        );
        return (float) ($val ?: 0);
    }
}
