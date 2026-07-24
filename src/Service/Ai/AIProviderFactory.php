<?php
/**
 * SmartBulk — AIProviderFactory
 *
 * Resolves the right provider implementation by name. Uses explicit service IDs
 * so it works with any PS Symfony container configuration.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Ai;

use InvalidArgumentException;

final class AIProviderFactory
{
    public function __construct(
        private readonly ClaudeProvider $claude,
        private readonly OpenAIProvider $openai,
    ) {
    }

    public function get(string $name): AIProviderInterface
    {
        return match ($name) {
            'claude' => $this->claude,
            'openai' => $this->openai,
            default  => throw new InvalidArgumentException("Unknown AI provider: {$name}"),
        };
    }
}
