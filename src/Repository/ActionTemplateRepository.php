<?php
/**
 * SmartBulk — ActionTemplateRepository
 *
 * CRUD over smartbulk_action_template. Stores user-defined bulk-editor
 * action sets so they show alongside the hardcoded ones from
 * Service\Bulk\ActionTemplates.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Repository;

use Db;

final class ActionTemplateRepository
{
    /**
     * @param array{id_shop:int,name:string,description?:string,actions:array<int,array<string,mixed>>,created_by?:?int} $data
     */
    public function create(array $data): int
    {
        Db::getInstance()->insert('smartbulk_action_template', [
            'id_shop'     => (int) $data['id_shop'],
            'name'        => pSQL((string) $data['name']),
            'description' => pSQL((string) ($data['description'] ?? ''), true),
            'actions'     => pSQL((string) json_encode($data['actions']), true),
            'created_by'  => isset($data['created_by']) ? (int) $data['created_by'] : null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        return (int) Db::getInstance()->Insert_ID();
    }

    public function delete(int $idTemplate, int $idShop): void
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'smartbulk_action_template`
             WHERE id_template = ' . (int) $idTemplate . '
               AND id_shop = ' . (int) $idShop
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function listForShop(int $idShop): array
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'smartbulk_action_template`
             WHERE id_shop = ' . (int) $idShop . '
             ORDER BY created_at DESC'
        ) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function find(int $idTemplate, int $idShop): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'smartbulk_action_template`
             WHERE id_template = ' . (int) $idTemplate . '
               AND id_shop = ' . (int) $idShop
        );
        return $row ?: null;
    }
}
