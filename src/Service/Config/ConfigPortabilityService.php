<?php
/**
 * SmartBulk — ConfigPortabilityService
 *
 * Export and import the module's working configuration as a single JSON
 * payload. Bundles: settings (without encrypted API keys), prompts with all
 * versions, saved segments, and schedules. Lets users move setups between
 * stores or back them up before risky edits.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Config;

use Context;
use DateTimeImmutable;
use Db;
use SmartBulk\Repository\PromptRepository;
use SmartBulk\Repository\ScheduleRepository;
use SmartBulk\Repository\SegmentRepository;
use SmartBulk\Service\SettingsService;

final class ConfigPortabilityService
{
    /** Bumped when the export schema changes incompatibly. */
    private const SCHEMA_VERSION = 1;

    /**
     * Settings keys that are safe to round-trip. Encrypted API keys are
     * deliberately excluded — exporting them would create a sneaky way to
     * exfiltrate credentials inside an otherwise innocuous JSON file.
     */
    private const PORTABLE_SETTINGS = [
        'ai_provider',
        'claude_model',
        'openai_model',
        'daily_budget',
        'brand_tone',
        'rate_limit_per_minute',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PromptRepository $promptRepo,
        private readonly SegmentRepository $segmentRepo,
        private readonly ScheduleRepository $scheduleRepo,
    ) {
    }

    /**
     * Build the export payload. Returns plain associative array — caller
     * encodes it to JSON.
     *
     * @return array<string,mixed>
     */
    public function export(): array
    {
        $idShop = (int) Context::getContext()->shop->id;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at'    => (new DateTimeImmutable('now'))->format('c'),
            'shop'           => [
                'id_shop' => $idShop,
                'name'    => (string) Context::getContext()->shop->name,
            ],
            'settings'  => $this->exportSettings(),
            'prompts'   => $this->exportPrompts(),
            'segments'  => $this->exportSegments($idShop),
            'schedules' => $this->exportSchedules($idShop),
        ];
    }

    /**
     * Apply an import payload. Each section is independent: errors in one
     * shouldn't block the others. Result describes what landed and what
     * was skipped/failed so the UI can display a useful summary.
     *
     * @param array<string,mixed> $payload
     * @param array{overwrite_prompts?:bool} $options
     * @return array<string,mixed>
     */
    public function import(array $payload, array $options = []): array
    {
        $overwritePrompts = !empty($options['overwrite_prompts']);
        $report = [
            'settings'  => ['applied' => 0, 'skipped' => 0],
            'prompts'   => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []],
            'segments'  => ['created' => 0, 'errors'  => []],
            'schedules' => ['created' => 0, 'errors'  => []],
        ];

        if (!isset($payload['schema_version']) || (int) $payload['schema_version'] !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException(
                'Incompatible schema_version. Expected ' . self::SCHEMA_VERSION . ', got ' . ($payload['schema_version'] ?? 'missing')
            );
        }

        if (is_array($payload['settings']  ?? null)) $this->importSettings($payload['settings'],  $report['settings']);
        if (is_array($payload['prompts']   ?? null)) $this->importPrompts($payload['prompts'],   $report['prompts'],   $overwritePrompts);
        if (is_array($payload['segments']  ?? null)) $this->importSegments($payload['segments'], $report['segments']);
        if (is_array($payload['schedules'] ?? null)) $this->importSchedules($payload['schedules'], $report['schedules']);

        return $report;
    }

    // ================================================================
    // Export
    // ================================================================

    /** @return array<string,mixed> */
    private function exportSettings(): array
    {
        $all = $this->settings->getAllMasked();
        $out = [];
        foreach (self::PORTABLE_SETTINGS as $k) {
            if (array_key_exists($k, $all)) $out[$k] = $all[$k];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function exportPrompts(): array
    {
        $rows = $this->promptRepo->listPrompts(['task_type' => null, 'search' => null]);
        $out = [];
        foreach ($rows as $r) {
            $detail = $this->promptRepo->findPrompt((int) $r['id_prompt']);
            if (!$detail) continue;
            $versions = $this->promptRepo->listVersions((int) $r['id_prompt']);
            $currentVersionNumber = null;
            foreach ($versions as $v) {
                if ((int) $v['id_prompt_version'] === (int) ($detail['current_version'] ?? 0)) {
                    $currentVersionNumber = (int) $v['version_number'];
                    break;
                }
            }
            $out[] = [
                'slug'                    => (string) $detail['slug'],
                'name'                    => (string) $detail['name'],
                'task_type'               => (string) $detail['task_type'],
                'is_active'               => (bool) $detail['is_active'],
                'current_version_number'  => $currentVersionNumber,
                'versions'                => array_map(static fn ($v) => [
                    'version_number' => (int) $v['version_number'],
                    'system_prompt'  => (string) $v['system_prompt'],
                    'user_prompt'    => (string) $v['user_prompt'],
                    'model'          => (string) $v['model'],
                    'provider'       => (string) $v['provider'],
                    'params'         => $v['params'] ? json_decode((string) $v['params'], true) : null,
                    'notes'          => (string) ($v['notes'] ?? ''),
                ], $versions),
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function exportSegments(int $idShop): array
    {
        $rows = $this->segmentRepo->listForShop($idShop);
        return array_map(static fn ($r) => [
            'name'          => (string) $r['name'],
            'description'   => (string) ($r['description'] ?? ''),
            'conditions'    => $r['conditions'] ? json_decode((string) $r['conditions'], true) : null,
            'combine_logic' => (string) ($r['combine_logic'] ?? 'and'),
        ], $rows);
    }

    /** @return array<int,array<string,mixed>> */
    private function exportSchedules(int $idShop): array
    {
        $rows = $this->scheduleRepo->listForShop($idShop);
        return array_map(static fn ($r) => [
            'name'            => (string) $r['name'],
            'job_type'        => (string) $r['job_type'],
            'job_payload'     => $r['job_payload'] ? json_decode((string) $r['job_payload'], true) : null,
            'cron_expression' => (string) $r['cron_expression'],
            'is_active'       => (bool) $r['is_active'],
        ], $rows);
    }

    // ================================================================
    // Import
    // ================================================================

    /**
     * @param array<string,mixed> $settings
     * @param array<string,int>   $report
     */
    private function importSettings(array $settings, array &$report): void
    {
        $batch = [];
        foreach (self::PORTABLE_SETTINGS as $k) {
            if (!array_key_exists($k, $settings)) {
                $report['skipped']++;
                continue;
            }
            $batch[$k] = $settings[$k];
            $report['applied']++;
        }
        if ($batch) {
            $this->settings->save($batch);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $prompts
     * @param array<string,mixed>            $report
     */
    private function importPrompts(array $prompts, array &$report, bool $overwrite): void
    {
        foreach ($prompts as $p) {
            try {
                if (empty($p['slug']) || empty($p['name']) || empty($p['task_type']) || empty($p['versions'])) {
                    throw new \InvalidArgumentException('Prompt entry missing slug/name/task_type/versions');
                }
                $existing = $this->promptRepo->findPromptBySlug((string) $p['slug']);

                if ($existing !== null && !$overwrite) {
                    $report['skipped']++;
                    continue;
                }

                $idPrompt = $existing !== null
                    ? (int) $existing['id_prompt']
                    : $this->promptRepo->insertPrompt([
                        'slug'      => (string) $p['slug'],
                        'name'      => (string) $p['name'],
                        'task_type' => (string) $p['task_type'],
                    ]);

                if ($existing !== null) {
                    $this->promptRepo->updatePromptName($idPrompt, (string) $p['name']);
                }

                // Add the imported versions on top — keep prior history intact.
                $latestId = null;
                $targetCurrent = (int) ($p['current_version_number'] ?? 0);
                foreach ($p['versions'] as $v) {
                    $idVersion = $this->promptRepo->insertVersion([
                        'id_prompt'      => $idPrompt,
                        'version_number' => $this->promptRepo->nextVersionNumber($idPrompt),
                        'system_prompt'  => (string) ($v['system_prompt'] ?? ''),
                        'user_prompt'    => (string) ($v['user_prompt']   ?? ''),
                        'model'          => (string) ($v['model']         ?? 'claude-haiku-4-5'),
                        'provider'       => (string) ($v['provider']      ?? 'claude'),
                        'params'         => is_array($v['params'] ?? null) ? $v['params'] : null,
                        'notes'          => (string) ($v['notes']         ?? ''),
                    ]);
                    if ($targetCurrent > 0 && (int) ($v['version_number'] ?? 0) === $targetCurrent) {
                        $latestId = $idVersion;
                    }
                    $latestId = $latestId ?? $idVersion;
                }

                if ($latestId !== null) {
                    $this->promptRepo->setCurrentVersion($idPrompt, $latestId);
                }

                $existing !== null ? $report['updated']++ : $report['created']++;
            } catch (\Throwable $e) {
                $report['errors'][] = sprintf('Prompt "%s": %s', (string) ($p['slug'] ?? '?'), $e->getMessage());
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $segments
     * @param array<string,mixed>            $report
     */
    private function importSegments(array $segments, array &$report): void
    {
        $idShop = (int) Context::getContext()->shop->id;
        $idEmployee = Context::getContext()->employee ? (int) Context::getContext()->employee->id : null;
        foreach ($segments as $s) {
            try {
                if (empty($s['name'])) throw new \InvalidArgumentException('Segment missing name');
                $this->segmentRepo->create([
                    'id_shop'     => $idShop,
                    'name'        => (string) $s['name'],
                    'description' => (string) ($s['description'] ?? ''),
                    'conditions'  => is_array($s['conditions'] ?? null) ? $s['conditions'] : ['filters' => []],
                    'created_by'  => $idEmployee,
                ]);
                $report['created']++;
            } catch (\Throwable $e) {
                $report['errors'][] = sprintf('Segment "%s": %s', (string) ($s['name'] ?? '?'), $e->getMessage());
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $schedules
     * @param array<string,mixed>            $report
     */
    private function importSchedules(array $schedules, array &$report): void
    {
        $idShop = (int) Context::getContext()->shop->id;
        $idEmployee = Context::getContext()->employee ? (int) Context::getContext()->employee->id : null;

        foreach ($schedules as $s) {
            try {
                if (empty($s['name']) || empty($s['cron_expression']) || empty($s['job_type'])) {
                    throw new \InvalidArgumentException('Schedule missing required fields');
                }
                // Insert raw — next_run_at is null on import; the next heartbeat reschedules.
                Db::getInstance()->insert('smartbulk_schedule', [
                    'id_shop'         => $idShop,
                    'name'            => pSQL((string) $s['name']),
                    'job_type'        => pSQL((string) $s['job_type']),
                    'job_payload'     => pSQL((string) json_encode($s['job_payload'] ?? null), true),
                    'cron_expression' => pSQL((string) $s['cron_expression']),
                    'is_active'       => !empty($s['is_active']) ? 1 : 0,
                    'last_run_at'     => null,
                    'next_run_at'     => null,
                    'created_by'      => $idEmployee,
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);
                $report['created']++;
            } catch (\Throwable $e) {
                $report['errors'][] = sprintf('Schedule "%s": %s', (string) ($s['name'] ?? '?'), $e->getMessage());
            }
        }
    }
}
