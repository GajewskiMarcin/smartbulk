<?php
/**
 * SmartBulk — unit tests for the Claude request body (no PrestaShop needed).
 *
 * Run: php tests/unit/ClaudeRequestBodyTest.php
 * Exits non-zero on the first failing assertion.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/../../vendor/autoload.php';

use SmartBulk\Service\Ai\ClaudeModelCapabilities;
use SmartBulk\Service\Ai\ClaudeProvider;

$failures = 0;
$count = 0;

function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures, $count;
    $count++;
    if ($expected === $actual) {
        printf("  \033[32m✓\033[0m %s\n", $label);
        return;
    }
    $failures++;
    printf("  \033[31m✗\033[0m %s\n    expected: %s\n    actual:   %s\n", $label, json_encode($expected), json_encode($actual));
}

/** @param array<string,mixed> $params */
function body(string $model, array $params = []): array
{
    return ClaudeProvider::buildRequestBody('sys', 'user', ['model' => $model] + $params);
}

/** Optional keys present in the body (everything beyond the base fields). */
function extras(array $body): array
{
    return array_diff_key($body, array_flip(['model', 'max_tokens', 'system', 'messages']));
}

$legacy = ['temperature' => 0.4, 'max_tokens' => 80];

echo "Base body\n";
$b = body('claude-haiku-4-5', $legacy);
check('model', 'claude-haiku-4-5', $b['model']);
check('max_tokens', 80, $b['max_tokens']);
check('system', 'sys', $b['system']);
check('messages', [['role' => 'user', 'content' => 'user']], $b['messages']);
check('max_tokens default 512', 512, body('claude-haiku-4-5')['max_tokens']);

echo "Temperature (unchanged behaviour of the old regex for listed models)\n";
check('haiku-4-5 gets temperature', ['temperature' => 0.4], extras(body('claude-haiku-4-5', $legacy)));
check('haiku-4-5 gets top_p', 0.9, body('claude-haiku-4-5', ['top_p' => 0.9])['top_p'] ?? null);
check('sonnet-4-6 gets temperature', ['temperature' => 0.4], extras(body('claude-sonnet-4-6', $legacy)));
foreach (['claude-haiku-5-5', 'claude-sonnet-5', 'claude-sonnet-5-5', 'claude-opus-5', 'claude-opus-5-5'] as $m) {
    check("$m: no temperature/top_p", [], extras(body($m, $legacy + ['top_p' => 0.9])));
}
check('opus-4-7: no temperature (rejected by the API)', [], extras(body('claude-opus-4-7', $legacy)));
check('unknown model: nothing optional', [], extras(body('claude-future-9', $legacy + ['effort' => 'low', 'thinking' => 'adaptive'])));

echo "Effort\n";
check('haiku-4-5: effort dropped', ['temperature' => 0.4], extras(body('claude-haiku-4-5', $legacy + ['effort' => 'low'])));
check('opus-5-5 low', ['output_config' => ['effort' => 'low']], extras(body('claude-opus-5-5', ['effort' => 'low'])));
check('sonnet-5-5 xhigh', ['output_config' => ['effort' => 'xhigh']], extras(body('claude-sonnet-5-5', ['effort' => 'xhigh'])));
check('sonnet-4-6 xhigh dropped', [], extras(body('claude-sonnet-4-6', ['effort' => 'xhigh'])));
check('sonnet-4-6 max kept', ['output_config' => ['effort' => 'max']], extras(body('claude-sonnet-4-6', ['effort' => 'max'])));
check('empty effort = omitted', [], extras(body('claude-opus-5-5', ['effort' => ''])));
check('bogus effort dropped', [], extras(body('claude-opus-5-5', ['effort' => 'turbo'])));
check('non-string effort dropped', [], extras(body('claude-opus-5-5', ['effort' => 3])));

echo "Thinking\n";
check('haiku-5-5 off', ['thinking' => ['type' => 'disabled']], extras(body('claude-haiku-5-5', ['thinking' => 'disabled'])));
check('haiku-5-5 on = default, not sent', [], extras(body('claude-haiku-5-5', ['thinking' => 'adaptive'])));
check('sonnet-5 off at high', ['output_config' => ['effort' => 'high'], 'thinking' => ['type' => 'disabled']],
    extras(body('claude-sonnet-5', ['effort' => 'high', 'thinking' => 'disabled'])));
check('sonnet-5 off at xhigh dropped', ['output_config' => ['effort' => 'xhigh']],
    extras(body('claude-sonnet-5', ['effort' => 'xhigh', 'thinking' => 'disabled'])));
check('opus-5 off at max dropped', ['output_config' => ['effort' => 'max']],
    extras(body('claude-opus-5', ['effort' => 'max', 'thinking' => 'disabled'])));
check('sonnet-5-5 off -> between_tools', ['thinking' => ['type' => 'between_tools']], extras(body('claude-sonnet-5-5', ['thinking' => 'disabled'])));
check('sonnet-5-5 between_tools alias', ['thinking' => ['type' => 'between_tools']], extras(body('claude-sonnet-5-5', ['thinking' => 'between_tools'])));
check('opus-5-5 off never sent', [], extras(body('claude-opus-5-5', ['thinking' => 'disabled'])));
check('opus-5-5 on never sent', [], extras(body('claude-opus-5-5', ['thinking' => 'adaptive'])));
check('sonnet-4-6 on', ['temperature' => 0.4, 'thinking' => ['type' => 'adaptive']],
    extras(body('claude-sonnet-4-6', $legacy + ['thinking' => 'adaptive'])));
check('sonnet-4-6 off = default, not sent', [], extras(body('claude-sonnet-4-6', ['thinking' => 'disabled'])));
check('opus-4-7 on', ['thinking' => ['type' => 'adaptive']], extras(body('claude-opus-4-7', ['thinking' => 'adaptive'])));
check('haiku-4-5 thinking never sent', [], extras(body('claude-haiku-4-5', ['thinking' => 'adaptive'])));

echo "Capability table\n";
check('known', true, ClaudeModelCapabilities::isKnown('claude-opus-5-5'));
check('unknown', false, ClaudeModelCapabilities::isKnown('gpt-4o'));
check('opus-5-5 default effort', 'medium', ClaudeModelCapabilities::forModel('claude-opus-5-5')['default_effort']);
check('haiku-5-5 default effort', 'medium', ClaudeModelCapabilities::forModel('claude-haiku-5-5')['default_effort']);
check('sonnet-5-5 default effort', 'high', ClaudeModelCapabilities::forModel('claude-sonnet-5-5')['default_effort']);

printf("\n%d checks, %d failed\n", $count, $failures);
exit($failures === 0 ? 0 : 1);
