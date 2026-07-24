<?php
/**
 * SmartBulk — SegmentRepository
 *
 * CRUD over smartbulk_segment. Stores user-saved filter combinations.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Repository;

use Db;

final class SegmentRepository
{
    /**
     * @param array{id_shop:int,name:string,description?:?string,conditions:array<string,mixed>,combine_logic?:string,created_by?:?int} $data
     */
    public function create(array $data): int
    {
        Db::getInstance()->insert('smartbulk_segment', [
            'id_shop'       => (int) $data['id_shop'],
            'name'          => pSQL($data['name']),
            'description'   => isset($data['description']) ? pSQL((string) $data['description'], true) : null,
            'combine_logic' => pSQL($data['combine_logic'] ?? 'and'),
            'conditions'    => pSQL((string) json_encode($data['conditions']), true),
            'created_by'    => isset($data['created_by']) ? (int) $data['created_by'] : null,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        return (int) Db::getInstance()->Insert_ID();
    }

    /** @return array<int,array<string,mixed>> */
    public function listForShop(int $idShop): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'smartbulk_segment`
             WHERE id_shop = ' . (int) $idShop . '
             ORDER BY name ASC'
        );
        return $rows ?: [];
    }

    /** @return array<string,mixed>|null */
    public function find(int $idSegment): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'smartbulk_segment`
             WHERE id_segment = ' . (int) $idSegment
        );
        return $row ?: null;
    }

    public function delete(int $idSegment): void
    {
        Db::getInstance()->delete('smartbulk_segment', 'id_segment = ' . (int) $idSegment);
    }

    public function rename(int $idSegment, string $name): void
    {
        Db::getInstance()->update(
            'smartbulk_segment',
            ['name' => pSQL($name), 'updated_at' => date('Y-m-d H:i:s')],
            'id_segment = ' . (int) $idSegment
        );
    }
}
