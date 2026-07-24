<?php
/**
 * SmartBulk — PromptRenderer
 *
 * Expands {placeholders} in system + user prompts using a context map.
 * Unknown placeholders are kept literally so the author notices missing data.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Ai;

final class PromptRenderer
{
    /**
     * @param array<string,string> $context
     * @return array{system:string,user:string}
     */
    public function render(string $systemPrompt, string $userPrompt, array $context): array
    {
        return [
            'system' => $this->expand($systemPrompt, $context),
            'user'   => $this->expand($userPrompt, $context),
        ];
    }

    /** @param array<string,string> $context */
    private function expand(string $template, array $context): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_][a-z0-9_]*)\}/i',
            static function (array $match) use ($context): string {
                $key = strtolower($match[1]);
                return array_key_exists($key, $context) ? (string) $context[$key] : $match[0];
            },
            $template
        );
    }
}
