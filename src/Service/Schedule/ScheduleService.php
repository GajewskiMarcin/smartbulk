<?php
/**
 * SmartBulk — ScheduleService
 *
 * Cron-driven jobs. Supports a deliberately small subset of cron expressions
 * because users pick frequency from a dropdown ("hourly", "daily HH:MM",
 * "weekly DAY HH:MM", "monthly DAY HH:MM") plus a free-form 5-field cron for
 * power users. Heartbeat fires due jobs and reschedules them.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Schedule;

use Context;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SmartBulk\Repository\ScheduleRepository;
use SmartBulk\Service\Ai\AIBatchService;
use SmartBulk\Service\Bulk\BulkEditorService;
use SmartBulk\Service\Segment\SegmentService;

final class ScheduleService
{
    public function __construct(
        private readonly ScheduleRepository $repo,
        private readonly BulkEditorService $bulkService,
        private readonly AIBatchService $aiBatch,
        private readonly SegmentService $segments,
    ) {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(?int $idShop = null): array
    {
        $idShop = $idShop ?? (int) Context::getContext()->shop->id;
        $rows = $this->repo->listForShop($idShop);
        return array_map([$this, 'normalize'], $rows);
    }

    /**
     * @param array{name:string,job_type:string,job_payload:array<string,mixed>,cron_expression:string,is_active?:bool} $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $this->validate($data);
        $cron = (string) $data['cron_expression'];
        $next = $this->computeNextRun($cron, new DateTimeImmutable('now'));

        $ctx = Context::getContext();
        $id = $this->repo->create([
            'id_shop'         => (int) $ctx->shop->id,
            'name'            => trim((string) $data['name']),
            'job_type'        => (string) $data['job_type'],
            'job_payload'     => $data['job_payload'],
            'cron_expression' => $cron,
            'is_active'       => !empty($data['is_active']) ? 1 : 0,
            'next_run_at'     => $next->format('Y-m-d H:i:s'),
            'created_by'      => $ctx->employee ? (int) $ctx->employee->id : null,
        ]);

        $row = $this->repo->find($id);
        return $row ? $this->normalize($row) : [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(int $id, array $data): array
    {
        $row = $this->repo->find($id);
        if ($row === null) throw new InvalidArgumentException("Schedule {$id} not found");

        $patch = [];
        if (isset($data['name']))            $patch['name'] = trim((string) $data['name']);
        if (isset($data['job_payload']))     $patch['job_payload'] = (array) $data['job_payload'];
        if (isset($data['cron_expression'])) {
            $patch['cron_expression'] = (string) $data['cron_expression'];
            // Re-compute next_run when cron changes.
            $next = $this->computeNextRun($patch['cron_expression'], new DateTimeImmutable('now'));
            $patch['next_run_at'] = $next->format('Y-m-d H:i:s');
        }
        if (array_key_exists('is_active', $data)) {
            $patch['is_active'] = !empty($data['is_active']) ? 1 : 0;
            // When re-enabling, make sure next_run is in the future.
            if ($patch['is_active'] === 1 && empty($patch['cron_expression'])) {
                $next = $this->computeNextRun((string) $row['cron_expression'], new DateTimeImmutable('now'));
                $patch['next_run_at'] = $next->format('Y-m-d H:i:s');
            }
        }

        $this->repo->update($id, $patch);
        $row = $this->repo->find($id);
        return $row ? $this->normalize($row) : [];
    }

    public function delete(int $id): void
    {
        $this->repo->delete($id);
    }

    /**
     * Run all due jobs (next_run_at <= now). Called by the heartbeat endpoint.
     *
     * @return array<int,array<string,mixed>> Per-job result.
     */
    public function runDue(): array
    {
        $due = $this->repo->listDue();
        $results = [];
        foreach ($due as $row) {
            $results[] = $this->runRow($row, false);
        }
        return $results;
    }

    /** Run a single schedule on demand (manual trigger from the UI). */
    public function runNow(int $id): array
    {
        $row = $this->repo->find($id);
        if ($row === null) throw new InvalidArgumentException("Schedule {$id} not found");
        return $this->runRow($row, true);
    }

    // =====================================================================
    // Internals
    // =====================================================================

    /** @param array<string,mixed> $row */
    private function runRow(array $row, bool $manual): array
    {
        $id = (int) $row['id_schedule'];
        $now = new DateTimeImmutable('now');
        $jobType = (string) $row['job_type'];
        $payload = $row['job_payload'] ? (array) json_decode((string) $row['job_payload'], true) : [];
        $idShop  = (int) $row['id_shop'];

        $resultSummary = [
            'id_schedule' => $id,
            'name'        => (string) $row['name'],
            'job_type'    => $jobType,
            'manual'      => $manual,
            'started_at'  => $now->format('c'),
            'status'      => 'pending',
        ];

        try {
            if ($jobType === 'bulk_edit') {
                $batch = $this->runBulkJob($payload, $idShop);
                $resultSummary['status']    = 'started';
                $resultSummary['id_batch']  = $batch['id_batch'] ?? null;
                $resultSummary['products']  = $batch['total'] ?? null;
            } elseif ($jobType === 'ai_batch') {
                $batch = $this->runAiJob($payload, $idShop);
                $resultSummary['status']    = 'started';
                $resultSummary['id_batch']  = $batch['id_batch'] ?? null;
                $resultSummary['products']  = $batch['total'] ?? null;
            } else {
                throw new InvalidArgumentException("Unknown job_type '{$jobType}'");
            }
        } catch (\Throwable $e) {
            $resultSummary['status'] = 'failed';
            $resultSummary['error']  = $e->getMessage();
        }

        // Schedule next run regardless of success — failures shouldn't stop the cron.
        // Manual runs leave next_run_at alone (so the schedule keeps its cadence).
        $patch = ['last_run_at' => $now->format('Y-m-d H:i:s')];
        if (!$manual) {
            $next = $this->computeNextRun((string) $row['cron_expression'], $now);
            $patch['next_run_at'] = $next->format('Y-m-d H:i:s');
        }
        $this->repo->update($id, $patch);

        return $resultSummary;
    }

    /**
     * Bulk edit job payload shape:
     *   { actions: [...], id_lang?: int, product_ids?: int[], filter_spec?: {...} }
     * Resolves products via SegmentService when filter_spec is present.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function runBulkJob(array $payload, int $idShop): array
    {
        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
        if (!$actions) throw new InvalidArgumentException('Bulk job has no actions');

        $idLang = isset($payload['id_lang']) ? (int) $payload['id_lang'] : null;

        $productIds = [];
        if (isset($payload['product_ids']) && is_array($payload['product_ids'])) {
            $productIds = array_map('intval', $payload['product_ids']);
        } elseif (isset($payload['filter_spec']) && is_array($payload['filter_spec'])) {
            $productIds = $this->segments->listProductIds($payload['filter_spec']);
        }

        if (!$productIds) throw new InvalidArgumentException('Bulk job resolved to 0 products');

        $mode = (string) ($payload['mode'] ?? 'apply');
        if (!in_array($mode, ['apply', 'dry_run'], true)) $mode = 'apply';

        $batch = $this->bulkService->createBatch(
            $productIds,
            $actions,
            $idLang,
            is_array($payload['filter_spec'] ?? null) ? $payload['filter_spec'] : null,
            $mode
        );

        // Eagerly process the entire batch in one heartbeat — schedules are
        // expected to be small/medium-sized and we don't want them to sit
        // half-done waiting for the next heartbeat.
        $batchId = (int) ($batch['id_batch'] ?? 0);
        if ($batchId > 0) {
            $remaining = (int) ($batch['remaining'] ?? 0);
            $guard = 200;
            while ($remaining > 0 && $guard-- > 0) {
                $batch = $this->bulkService->processNext($batchId, 50);
                $remaining = (int) ($batch['remaining'] ?? 0);
            }
        }
        return $batch;
    }

    /**
     * AI batch job payload shape:
     *   { id_prompt: int, id_version?: int, id_lang?: int, product_ids?:int[], filter_spec?:{...},
     *     target_field?: string, target_lang?: int, auto_accept?: bool }
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function runAiJob(array $payload, int $idShop): array
    {
        $idPrompt  = (int) ($payload['id_prompt']  ?? 0);
        $idVersion = isset($payload['id_version']) ? (int) $payload['id_version'] : null;
        $idLang    = (int) ($payload['id_lang']    ?? Context::getContext()->language->id);

        if ($idPrompt <= 0) throw new InvalidArgumentException('AI job missing id_prompt');

        $productIds = [];
        if (isset($payload['product_ids']) && is_array($payload['product_ids'])) {
            $productIds = array_map('intval', $payload['product_ids']);
        } elseif (isset($payload['filter_spec']) && is_array($payload['filter_spec'])) {
            $productIds = $this->segments->listProductIds($payload['filter_spec']);
        }
        if (!$productIds) throw new InvalidArgumentException('AI job resolved to 0 products');

        $targetField = isset($payload['target_field']) && $payload['target_field'] !== '' ? (string) $payload['target_field'] : null;
        $targetLang  = isset($payload['target_lang'])  && $payload['target_lang']  !== '' ? (int)    $payload['target_lang']  : null;

        $batch = $this->aiBatch->createBatch(
            $idPrompt, $idLang, $productIds, $idVersion,
            is_array($payload['filter_spec'] ?? null) ? $payload['filter_spec'] : null,
            $targetField, $targetLang
        );

        $batchId = (int) ($batch['id_batch'] ?? 0);
        if ($batchId > 0) {
            $remaining = (int) ($batch['remaining'] ?? 0);
            $guard = 200;
            while ($remaining > 0 && $guard-- > 0) {
                $batch = $this->aiBatch->processNext($batchId, 5);
                $remaining = (int) ($batch['remaining'] ?? 0);
            }
        }

        // If the schedule asked for auto_accept, accept all at the end.
        if (!empty($payload['auto_accept']) && $batchId > 0) {
            $this->aiBatch->acceptAll($batchId);
        }
        return $batch;
    }

    /** @param array<string,mixed> $row */
    private function normalize(array $row): array
    {
        $payload = $row['job_payload'] ? json_decode((string) $row['job_payload'], true) : null;
        $employee = '';
        if (!empty($row['emp_firstname']) || !empty($row['emp_lastname'])) {
            $employee = trim((string) $row['emp_firstname'] . ' ' . (string) $row['emp_lastname']);
        } elseif (!empty($row['emp_email'])) {
            $employee = (string) $row['emp_email'];
        }
        return [
            'id_schedule'     => (int) $row['id_schedule'],
            'name'            => (string) $row['name'],
            'id_shop'         => (int) $row['id_shop'],
            'job_type'        => (string) $row['job_type'],
            'job_payload'     => is_array($payload) ? $payload : null,
            'cron_expression' => (string) $row['cron_expression'],
            'is_active'       => (bool) $row['is_active'],
            'last_run_at'     => $row['last_run_at'] ?? null,
            'next_run_at'     => $row['next_run_at'] ?? null,
            'created_by'      => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'employee_name'   => $employee,
            'created_at'      => (string) $row['created_at'],
            'human_schedule'  => $this->humanizeCron((string) $row['cron_expression']),
        ];
    }

    /** @param array<string,mixed> $data */
    private function validate(array $data): void
    {
        if (empty($data['name']))            throw new InvalidArgumentException('Name is required');
        if (empty($data['cron_expression'])) throw new InvalidArgumentException('Cron expression is required');
        if (empty($data['job_type']) || !in_array($data['job_type'], ['bulk_edit', 'ai_batch'], true)) {
            throw new InvalidArgumentException("job_type must be 'bulk_edit' or 'ai_batch'");
        }
        if (!isset($data['job_payload']) || !is_array($data['job_payload'])) {
            throw new InvalidArgumentException('job_payload is required');
        }
        // Smoke-test the cron expression — throws if unparseable.
        $this->computeNextRun((string) $data['cron_expression'], new DateTimeImmutable('now'));
    }

    // =====================================================================
    // Cron parser (5-field: minute hour dayOfMonth month dayOfWeek)
    // Supports: '*', integer, '*/N' (step), 'A,B,C' (list).
    // No range syntax (a-b) for now — covers all common use cases.
    // =====================================================================

    public function computeNextRun(string $expr, DateTimeImmutable $from): DateTimeImmutable
    {
        $fields = preg_split('/\s+/', trim($expr)) ?: [];
        if (count($fields) !== 5) {
            throw new InvalidArgumentException("Cron expression must have 5 fields, got '{$expr}'");
        }
        [$minF, $hourF, $domF, $monF, $dowF] = $fields;
        $tz = $from->getTimezone();

        // Start from next minute (we never schedule "now" in the past).
        $cursor = $from
            ->setTime((int) $from->format('H'), (int) $from->format('i'), 0)
            ->modify('+1 minute');

        $allowedMin   = $this->matchSet($minF,  0, 59);
        $allowedHour  = $this->matchSet($hourF, 0, 23);
        $allowedDom   = $this->matchSet($domF,  1, 31);
        $allowedMonth = $this->matchSet($monF,  1, 12);
        $allowedDow   = $this->matchSet($dowF,  0, 6);

        // Search up to 366 days ahead — handles even rare cron expressions.
        $guard = 366 * 24 * 60;
        while ($guard-- > 0) {
            $minute  = (int) $cursor->format('i');
            $hour    = (int) $cursor->format('G');
            $dom     = (int) $cursor->format('j');
            $month   = (int) $cursor->format('n');
            // Cron convention: 0 = Sunday … 6 = Saturday. PHP 'w' matches.
            $dow     = (int) $cursor->format('w');

            $monthOk = isset($allowedMonth[$month]);
            $hourOk  = isset($allowedHour[$hour]);
            $minOk   = isset($allowedMin[$minute]);
            // Cron rule: if both DOM and DOW are restricted, it's an OR (matches either).
            // If exactly one is *, only the restricted one is tested.
            $domStar = $domF === '*';
            $dowStar = $dowF === '*';
            if ($domStar && $dowStar) {
                $dayOk = true;
            } elseif (!$domStar && !$dowStar) {
                $dayOk = isset($allowedDom[$dom]) || isset($allowedDow[$dow]);
            } else {
                $dayOk = ($domStar  || isset($allowedDom[$dom]))
                      && ($dowStar  || isset($allowedDow[$dow]));
            }

            if ($monthOk && $dayOk && $hourOk && $minOk) {
                return $cursor->setTimezone(new DateTimeZone(date_default_timezone_get()));
            }
            $cursor = $cursor->modify('+1 minute');
        }
        throw new InvalidArgumentException("Could not find next run for cron '{$expr}' within 1 year");
    }

    /**
     * Expand a single cron field into a set of allowed integers.
     * @return array<int,bool>  keys are allowed values
     */
    private function matchSet(string $field, int $min, int $max): array
    {
        $out = [];
        $parts = explode(',', $field);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if ($p === '*') {
                for ($i = $min; $i <= $max; $i++) $out[$i] = true;
                continue;
            }
            if (str_starts_with($p, '*/')) {
                $step = (int) substr($p, 2);
                if ($step <= 0) throw new InvalidArgumentException("Invalid step '{$p}'");
                for ($i = $min; $i <= $max; $i += $step) $out[$i] = true;
                continue;
            }
            if (!ctype_digit($p)) throw new InvalidArgumentException("Cron field token '{$p}' is not numeric");
            $v = (int) $p;
            if ($v < $min || $v > $max) throw new InvalidArgumentException("Cron value {$v} out of range [{$min},{$max}]");
            $out[$v] = true;
        }
        return $out;
    }

    /** Translate a small cron grammar into human-friendly strings. */
    private function humanizeCron(string $expr): string
    {
        $fields = preg_split('/\s+/', trim($expr)) ?: [];
        if (count($fields) !== 5) return $expr;
        [$min, $hour, $dom, $mon, $dow] = $fields;
        $pad2 = static fn ($v) => str_pad((string) $v, 2, '0', STR_PAD_LEFT);

        // every N minutes
        if ($mon === '*' && $dom === '*' && $dow === '*' && str_starts_with($min, '*/') && $hour === '*') {
            return 'Every ' . substr($min, 2) . ' minutes';
        }
        // hourly
        if ($mon === '*' && $dom === '*' && $dow === '*' && $hour === '*' && ctype_digit($min)) {
            return 'Hourly at :' . $pad2($min);
        }
        // daily HH:MM
        if ($mon === '*' && $dom === '*' && $dow === '*' && ctype_digit($hour) && ctype_digit($min)) {
            return 'Daily at ' . $pad2($hour) . ':' . $pad2($min);
        }
        // weekly DOW HH:MM
        if ($mon === '*' && $dom === '*' && $dow !== '*' && ctype_digit($hour) && ctype_digit($min)) {
            $names = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            return 'Weekly ' . $names[(int) $dow] . ' at ' . $pad2($hour) . ':' . $pad2($min);
        }
        // monthly DOM HH:MM
        if ($mon === '*' && $dow === '*' && $dom !== '*' && ctype_digit($hour) && ctype_digit($min)) {
            return 'Monthly day ' . $dom . ' at ' . $pad2($hour) . ':' . $pad2($min);
        }
        return $expr;
    }
}
