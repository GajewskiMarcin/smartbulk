<?php
/**
 * SmartBulk — DashboardService
 *
 * One-shot aggregator for the dashboard view. Pulls KPIs, top Content-Health
 * issues, recent batch history and AI-spend stats in a single call so the
 * front-end opens with one round trip.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Dashboard;

use Context;
use Db;
use SmartBulk\Service\Health\ContentHealthService;
use SmartBulk\Service\History\HistoryService;
use SmartBulk\Service\SettingsService;

final class DashboardService
{
    public function __construct(
        private readonly ContentHealthService $health,
        private readonly HistoryService $history,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Build full dashboard payload.
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $ctx    = Context::getContext();
        $idShop = (int) $ctx->shop->id;
        $idLang = (int) $ctx->language->id;

        $health = $this->health->scan($idShop, $idLang);
        $aiSpend = $this->aiSpend($idShop);

        // Health composite score = average of group scores. Empty shops score 100.
        $groupScores = array_map(static fn ($g) => (int) $g['score'], $health['groups']);
        $compositeScore = $groupScores ? (int) round(array_sum($groupScores) / count($groupScores)) : 100;

        $topIssues = $this->topIssues($health);
        $recentActivity = $this->recentActivity();
        $jobs = $this->jobsCounts($idShop);

        // Build a contextual tip from the most impactful unresolved problem.
        $tip = $this->buildTip($health);

        $masked = $this->settings->getAllMasked();
        $dailyBudget = (float) ($masked['daily_budget'] ?? 0);

        return [
            'shop'       => ['id_shop' => $idShop, 'id_lang' => $idLang],
            'kpis'       => [
                'products_total' => (int) $health['total'],
                'health_score'   => $compositeScore,
                'ai_today'       => [
                    'runs'      => $aiSpend['today_runs'],
                    'cost_usd'  => $aiSpend['today_cost'],
                    'budget'    => $dailyBudget,
                ],
                'jobs' => $jobs,
            ],
            'ai_spend'        => [
                'daily_budget'  => $dailyBudget,
                'today_runs'    => $aiSpend['today_runs'],
                'today_cost'    => $aiSpend['today_cost'],
                'month_cost'    => $aiSpend['month_cost'],
                'last_30d_runs' => $aiSpend['last_30d_runs'],
                'last_30d_cost' => $aiSpend['last_30d_cost'],
                'avg_cost_per_run' => $aiSpend['last_30d_runs'] > 0
                    ? round($aiSpend['last_30d_cost'] / $aiSpend['last_30d_runs'], 6)
                    : 0.0,
            ],
            'top_issues'      => $topIssues,
            'recent_activity' => $recentActivity,
            'tip'             => $tip,
            'health_groups'   => $health['groups'],
            'last_scan_at'    => $health['generated_at'],
        ];
    }

    // =====================================================================
    // Internals
    // =====================================================================

    /**
     * Top 5 Content-Health problems by count, restricted to high/medium severity
     * so we don't bury the user under low-priority cosmetic issues.
     *
     * @param array<string,mixed> $health
     * @return array<int,array<string,mixed>>
     */
    private function topIssues(array $health): array
    {
        $candidates = array_filter(
            $health['problems'] ?? [],
            static fn ($p) => (int) $p['count'] > 0 && in_array($p['severity'], ['high', 'medium'], true)
        );
        usort($candidates, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_map(
            fn ($p) => [
                'id'         => (string) $p['id'],
                'group'      => (string) $p['group'],
                'label'      => (string) $p['label'],
                'severity'   => (string) $p['severity'],
                'count'      => (int) $p['count'],
                'percent'    => (float) $p['percent'],
                'fix_hint'   => (string) ($p['fix_hint'] ?? ''),
                'fixable_by' => $this->suggestFixer($p),
            ],
            array_slice($candidates, 0, 5)
        );
    }

    /** @param array<string,mixed> $p */
    private function suggestFixer(array $p): string
    {
        // Issues with content_gap mapping to AI-generateable fields → AI Assistant.
        $aiFixable = [
            'missing_meta_title', 'missing_meta_description',
            'missing_short_desc', 'missing_description',
            'missing_alt_text',
            'meta_title_too_short', 'meta_title_too_long',
            'meta_desc_too_short',  'meta_desc_too_long',
        ];
        if (in_array($p['id'], $aiFixable, true)) return 'ai';
        return 'bulk';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recentActivity(): array
    {
        $list = $this->history->listBatches(['limit' => 5, 'offset' => 0]);
        return $list['items'] ?? [];
    }

    /**
     * @return array<string,int>
     */
    private function jobsCounts(int $idShop): array
    {
        $prefix = _DB_PREFIX_;
        $running = (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `{$prefix}smartbulk_massedit`
             WHERE id_shop = {$idShop} AND status IN ('pending','running')"
        );
        $activeSchedules = (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `{$prefix}smartbulk_schedule`
             WHERE id_shop = {$idShop} AND is_active = 1"
        );
        return [
            'running_batches'    => $running,
            'active_schedules'   => $activeSchedules,
        ];
    }

    /**
     * @return array{today_runs:int,today_cost:float,month_cost:float,last_30d_runs:int,last_30d_cost:float}
     */
    private function aiSpend(int $idShop): array
    {
        $prefix = _DB_PREFIX_;
        // Today
        $today = Db::getInstance()->getRow(
            "SELECT COUNT(*) AS runs, COALESCE(SUM(cost_usd),0) AS cost
             FROM `{$prefix}smartbulk_ai_run`
             WHERE id_shop = {$idShop}
               AND DATE(created_at) = CURDATE()
               AND status NOT IN ('rejected')"
        ) ?: ['runs' => 0, 'cost' => 0];

        // Month
        $month = Db::getInstance()->getRow(
            "SELECT COALESCE(SUM(cost_usd),0) AS cost
             FROM `{$prefix}smartbulk_ai_run`
             WHERE id_shop = {$idShop}
               AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND status NOT IN ('rejected')"
        ) ?: ['cost' => 0];

        // Last 30 days
        $last30 = Db::getInstance()->getRow(
            "SELECT COUNT(*) AS runs, COALESCE(SUM(cost_usd),0) AS cost
             FROM `{$prefix}smartbulk_ai_run`
             WHERE id_shop = {$idShop}
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
               AND status NOT IN ('rejected')"
        ) ?: ['runs' => 0, 'cost' => 0];

        return [
            'today_runs'    => (int)   ($today['runs'] ?? 0),
            'today_cost'    => (float) ($today['cost'] ?? 0),
            'month_cost'    => (float) ($month['cost'] ?? 0),
            'last_30d_runs' => (int)   ($last30['runs'] ?? 0),
            'last_30d_cost' => (float) ($last30['cost'] ?? 0),
        ];
    }

    /**
     * Pick one actionable suggestion based on the worst Content-Health gap.
     *
     * @param array<string,mixed> $health
     * @return array<string,mixed>|null
     */
    private function buildTip(array $health): ?array
    {
        // Pick the highest-count high-severity problem.
        $candidates = array_filter(
            $health['problems'] ?? [],
            static fn ($p) => (int) $p['count'] > 0 && $p['severity'] === 'high'
        );
        if (!$candidates) {
            $candidates = array_filter(
                $health['problems'] ?? [],
                static fn ($p) => (int) $p['count'] > 0
            );
            if (!$candidates) return null;
        }
        usort($candidates, static fn ($a, $b) => $b['count'] <=> $a['count']);
        $top = reset($candidates);

        // Rough cost estimate: $0.002/product on Claude Haiku for short content.
        $estCost = round($top['count'] * 0.002, 2);

        return [
            'problem_id' => (string) $top['id'],
            'label'      => (string) $top['label'],
            'count'      => (int)    $top['count'],
            'fix_hint'   => (string) ($top['fix_hint'] ?? ''),
            'est_cost'   => $estCost,
            'fixable_by' => $this->suggestFixer($top),
        ];
    }
}
