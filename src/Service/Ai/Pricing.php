<?php
/**
 * SmartBulk — Pricing lookup
 *
 * Per-million-token USD costs for supported models. Public prices as of 2026-04;
 * update if Anthropic/OpenAI change pricing. Approximate — actual cost includes
 * prompt caching discounts and vision surcharges which we ignore for estimates.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Ai;

final class Pricing
{
    /** @var array<string,array{in:float,out:float}> USD per 1M tokens */
    private const TABLE = [
        'claude-haiku-4-5'  => ['in' => 0.80,  'out' => 4.00],
        'claude-sonnet-4-6' => ['in' => 3.00,  'out' => 15.00],
        'claude-opus-4-7'   => ['in' => 15.00, 'out' => 75.00],
        'gpt-4o-mini'       => ['in' => 0.15,  'out' => 0.60],
        'gpt-4o'            => ['in' => 2.50,  'out' => 10.00],
    ];

    public static function costUsd(string $model, int $tokensIn, int $tokensOut): float
    {
        $rate = self::TABLE[$model] ?? ['in' => 1.0, 'out' => 3.0]; // conservative fallback
        return round(($tokensIn / 1_000_000) * $rate['in'] + ($tokensOut / 1_000_000) * $rate['out'], 6);
    }
}
