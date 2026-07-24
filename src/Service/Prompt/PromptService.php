<?php
/**
 * SmartBulk — PromptService
 *
 * Domain logic for prompts: create / update / version / activate / delete.
 * Repositories handle storage; this enforces invariants like "every prompt has at
 * least one version" and "current_version always points to an existing version".
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Prompt;

use Context;
use InvalidArgumentException;
use RuntimeException;
use SmartBulk\Repository\PromptRepository;

final class PromptService
{
    public const TASK_TYPES = [
        'meta_title',
        'meta_description',
        'short_desc',
        'long_desc',
        'translate',
        'alt_text',
        'seo_rewrite',
        'tagging',
        'custom',
    ];

    public const PROVIDERS = ['claude', 'openai'];

    public function __construct(
        private readonly PromptRepository $repo,
    ) {
    }

    // ---------------- Reads ----------------

    /**
     * @param array{task_type?:string,search?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function listAll(array $filters = []): array
    {
        $prompts = $this->repo->listPrompts($filters);
        // Decorate with run count per prompt? Could be expensive for many prompts —
        // skip for now, can be added on-demand via a separate stats endpoint.
        return array_map([$this, 'normalizePrompt'], $prompts);
    }

    /**
     * @return array{prompt:array<string,mixed>,versions:array<int,array<string,mixed>>}
     */
    public function getDetail(int $idPrompt): array
    {
        $prompt = $this->repo->findPrompt($idPrompt);
        if ($prompt === null) {
            throw new InvalidArgumentException("Prompt {$idPrompt} not found");
        }
        $versions = array_map([$this, 'normalizeVersion'], $this->repo->listVersions($idPrompt));
        return [
            'prompt'   => $this->normalizePrompt($prompt),
            'versions' => $versions,
        ];
    }

    // ---------------- Writes ----------------

    /**
     * Create a new prompt with its initial version (v1) in one transaction-like step.
     *
     * @param array{slug?:string,name:string,task_type:string,system_prompt:string,user_prompt:string,model?:string,provider?:string,params?:array<string,mixed>,notes?:string} $input
     * @return array{prompt:array<string,mixed>,versions:array<int,array<string,mixed>>}
     */
    public function createPrompt(array $input): array
    {
        $this->validateName($input['name'] ?? '');
        $this->validateTaskType($input['task_type'] ?? '');

        $slug = $input['slug'] ?? '';
        if ($slug === '') {
            $slug = $this->generateUniqueSlug($input['name']);
        }
        if ($this->repo->findPromptBySlug($slug) !== null) {
            throw new InvalidArgumentException("Slug '{$slug}' already taken");
        }

        $idEmployee = $this->currentEmployeeId();

        $idPrompt = $this->repo->insertPrompt([
            'slug'       => $slug,
            'name'       => $input['name'],
            'task_type'  => $input['task_type'],
            'created_by' => $idEmployee,
        ]);

        $idVersion = $this->repo->insertVersion([
            'id_prompt'      => $idPrompt,
            'version_number' => 1,
            'parent_version' => null,
            'system_prompt'  => (string) ($input['system_prompt'] ?? ''),
            'user_prompt'    => (string) ($input['user_prompt'] ?? ''),
            'model'          => (string) ($input['model'] ?? 'claude-haiku-4-5'),
            'provider'       => $this->validateProvider($input['provider'] ?? 'claude'),
            'params'         => $input['params'] ?? ['temperature' => 0.4, 'max_tokens' => 256],
            'notes'          => $input['notes'] ?? 'Initial version',
            'created_by'     => $idEmployee,
        ]);

        $this->repo->setCurrentVersion($idPrompt, $idVersion);

        return $this->getDetail($idPrompt);
    }

    public function renamePrompt(int $idPrompt, string $newName): void
    {
        $this->validateName($newName);
        $this->ensurePromptExists($idPrompt);
        $this->repo->updatePromptName($idPrompt, $newName);
    }

    /**
     * Save current edits as a NEW version (forking from current version).
     *
     * @param array{system_prompt:string,user_prompt:string,model?:string,provider?:string,params?:array<string,mixed>,notes?:string} $input
     */
    public function createVersion(int $idPrompt, array $input): array
    {
        $prompt = $this->ensurePromptExists($idPrompt);
        $parentVersionId = $prompt['current_version'] !== null ? (int) $prompt['current_version'] : null;

        $idVersion = $this->repo->insertVersion([
            'id_prompt'      => $idPrompt,
            'version_number' => $this->repo->nextVersionNumber($idPrompt),
            'parent_version' => $parentVersionId,
            'system_prompt'  => (string) ($input['system_prompt'] ?? ''),
            'user_prompt'    => (string) ($input['user_prompt'] ?? ''),
            'model'          => (string) ($input['model'] ?? 'claude-haiku-4-5'),
            'provider'       => $this->validateProvider($input['provider'] ?? 'claude'),
            'params'         => $input['params'] ?? null,
            'notes'          => $input['notes'] ?? '',
            'created_by'     => $this->currentEmployeeId(),
        ]);

        // Promote to current automatically — user can roll back if unhappy
        $this->repo->setCurrentVersion($idPrompt, $idVersion);

        return $this->getDetail($idPrompt);
    }

    public function setCurrentVersion(int $idPrompt, int $idVersion): array
    {
        $this->ensurePromptExists($idPrompt);
        $version = $this->repo->findVersion($idVersion);
        if ($version === null || (int) $version['id_prompt'] !== $idPrompt) {
            throw new InvalidArgumentException('Version does not belong to this prompt');
        }
        $this->repo->setCurrentVersion($idPrompt, $idVersion);
        return $this->getDetail($idPrompt);
    }

    public function deletePrompt(int $idPrompt): void
    {
        $this->ensurePromptExists($idPrompt);
        $this->repo->deletePrompt($idPrompt);
    }

    // ---------------- Helpers ----------------

    /** @return array<string,mixed> */
    private function ensurePromptExists(int $idPrompt): array
    {
        $p = $this->repo->findPrompt($idPrompt);
        if ($p === null) {
            throw new InvalidArgumentException("Prompt {$idPrompt} not found");
        }
        return $p;
    }

    private function validateName(string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('Name must be between 1 and 255 chars');
        }
    }

    private function validateTaskType(string $type): void
    {
        if (!in_array($type, self::TASK_TYPES, true)) {
            throw new InvalidArgumentException("Invalid task_type '{$type}'");
        }
    }

    private function validateProvider(string $provider): string
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException("Invalid provider '{$provider}'");
        }
        return $provider;
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'prompt');
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'prompt';
        }
        $base = substr($base, 0, 50);

        $slug = $base;
        $i = 2;
        while ($this->repo->findPromptBySlug($slug) !== null) {
            $slug = $base . '-' . $i;
            $i++;
            if ($i > 100) {
                throw new RuntimeException('Could not generate a unique slug');
            }
        }
        return $slug;
    }

    private function currentEmployeeId(): ?int
    {
        $ctx = Context::getContext();
        $id = $ctx && $ctx->employee ? (int) $ctx->employee->id : 0;
        return $id > 0 ? $id : null;
    }

    /** @param array<string,mixed> $row */
    private function normalizePrompt(array $row): array
    {
        return [
            'id_prompt'                  => (int) $row['id_prompt'],
            'slug'                       => (string) $row['slug'],
            'name'                       => (string) $row['name'],
            'task_type'                  => (string) $row['task_type'],
            'is_active'                  => (bool) $row['is_active'],
            'current_version'            => isset($row['current_version']) && $row['current_version'] !== null
                ? (int) $row['current_version'] : null,
            'current_version_number'     => isset($row['current_version_number']) && $row['current_version_number'] !== null
                ? (int) $row['current_version_number'] : null,
            'current_model'              => $row['current_model'] ?? null,
            'current_provider'           => $row['current_provider'] ?? null,
            'current_version_created_at' => $row['current_version_created_at'] ?? null,
            'created_at'                 => $row['created_at'],
        ];
    }

    /** @param array<string,mixed> $row */
    private function normalizeVersion(array $row): array
    {
        return [
            'id_prompt_version' => (int) $row['id_prompt_version'],
            'id_prompt'         => (int) $row['id_prompt'],
            'version_number'    => (int) $row['version_number'],
            'parent_version'    => $row['parent_version'] !== null ? (int) $row['parent_version'] : null,
            'system_prompt'     => (string) $row['system_prompt'],
            'user_prompt'       => (string) $row['user_prompt'],
            'model'             => (string) $row['model'],
            'provider'          => (string) $row['provider'],
            'params'            => $row['params'] !== null ? json_decode((string) $row['params'], true) : null,
            'notes'             => (string) ($row['notes'] ?? ''),
            'created_at'        => $row['created_at'],
        ];
    }
}
