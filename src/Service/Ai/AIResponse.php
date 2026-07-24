<?php
/**
 * SmartBulk — AIResponse value object
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Ai;

final class AIResponse
{
    public function __construct(
        public readonly string $content,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly float $costUsd,
        public readonly int $latencyMs,
        public readonly string $model,
        public readonly string $provider,
        public readonly ?string $stopReason = null,
    ) {
    }
}
