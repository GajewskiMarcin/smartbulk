<?php
/**
 * SmartBulk — PromptRepository
 *
 * CRUD over smartbulk_prompt + smartbulk_prompt_version.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Repository;

use Db;
use DbQuery;

final class PromptRepository
{
    private string $promptTable;
    private string $versionTable;

    public function __construct()
    {
        $this->promptTable  = _DB_PREFIX_ . 'smartbulk_prompt';
        $this->versionTable = _DB_PREFIX_ . 'smartbulk_prompt_version';
    }

    // ----------------------- Reads -----------------------

    /**
     * @param array{task_type?:string,search?:string,id_shop?:?int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function listPrompts(array $filters = []): array
    {
        $q = new DbQuery();
        $q->select('p.*, v.version_number AS current_version_number, v.model AS current_model, v.provider AS current_provider, v.created_at AS current_version_created_at')
          ->from('smartbulk_prompt', 'p')
          ->leftJoin('smartbulk_prompt_version', 'v', 'p.current_version = v.id_prompt_version')
          ->orderBy('p.task_type ASC, p.name ASC');

        if (!empty($filters['task_type'])) {
            $q->where('p.task_type = "' . pSQL($filters['task_type']) . '"');
        }
        if (!empty($filters['search'])) {
            $term = pSQL($filters['search']);
            $q->where('(p.name LIKE "%' . $term . '%" OR p.slug LIKE "%' . $term . '%")');
        }

        $rows = Db::getInstance()->executeS($q);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string,mixed>|null */
    public function findPrompt(int $id): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . $this->promptTable . '` WHERE id_prompt = ' . (int) $id
        );
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findPromptBySlug(string $slug): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . $this->promptTable . '` WHERE slug = "' . pSQL($slug) . '"'
        );
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listVersions(int $idPrompt): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . $this->versionTable . '`
             WHERE id_prompt = ' . (int) $idPrompt . '
             ORDER BY version_number DESC'
        );
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string,mixed>|null */
    public function findVersion(int $idVersion): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . $this->versionTable . '` WHERE id_prompt_version = ' . (int) $idVersion
        );
        return $row ?: null;
    }

    public function nextVersionNumber(int $idPrompt): int
    {
        $max = (int) Db::getInstance()->getValue(
            'SELECT MAX(version_number) FROM `' . $this->versionTable . '`
             WHERE id_prompt = ' . (int) $idPrompt
        );
        return $max + 1;
    }

    // ----------------------- Writes -----------------------

    /**
     * @param array{slug:string,name:string,task_type:string,id_shop?:?int,created_by?:?int} $data
     */
    public function insertPrompt(array $data): int
    {
        Db::getInstance()->insert('smartbulk_prompt', [
            'slug'            => pSQL($data['slug']),
            'name'            => pSQL($data['name']),
            'task_type'       => pSQL($data['task_type']),
            'id_shop'         => isset($data['id_shop']) ? (int) $data['id_shop'] : null,
            'is_active'       => 1,
            'current_version' => null,
            'created_by'      => isset($data['created_by']) ? (int) $data['created_by'] : null,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        return (int) Db::getInstance()->Insert_ID();
    }

    public function updatePromptName(int $idPrompt, string $name): void
    {
        Db::getInstance()->update(
            'smartbulk_prompt',
            ['name' => pSQL($name)],
            'id_prompt = ' . (int) $idPrompt
        );
    }

    public function setCurrentVersion(int $idPrompt, int $idVersion): void
    {
        Db::getInstance()->update(
            'smartbulk_prompt',
            ['current_version' => (int) $idVersion],
            'id_prompt = ' . (int) $idPrompt
        );
    }

    public function deletePrompt(int $idPrompt): void
    {
        Db::getInstance()->delete('smartbulk_prompt_version', 'id_prompt = ' . (int) $idPrompt);
        Db::getInstance()->delete('smartbulk_prompt', 'id_prompt = ' . (int) $idPrompt);
    }

    /**
     * @param array{id_prompt:int,version_number:int,parent_version?:?int,system_prompt:string,user_prompt:string,model:string,provider:string,params?:?array<string,mixed>,notes?:string,created_by?:?int} $data
     */
    public function insertVersion(array $data): int
    {
        Db::getInstance()->insert('smartbulk_prompt_version', [
            'id_prompt'      => (int) $data['id_prompt'],
            'version_number' => (int) $data['version_number'],
            'parent_version' => isset($data['parent_version']) ? (int) $data['parent_version'] : null,
            'system_prompt'  => pSQL($data['system_prompt'], true),
            'user_prompt'    => pSQL($data['user_prompt'], true),
            'model'          => pSQL($data['model']),
            'provider'       => pSQL($data['provider']),
            'params'         => isset($data['params']) ? pSQL((string) json_encode($data['params'])) : null,
            'notes'          => isset($data['notes']) ? pSQL($data['notes'], true) : null,
            'created_by'     => isset($data['created_by']) ? (int) $data['created_by'] : null,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        return (int) Db::getInstance()->Insert_ID();
    }
}
