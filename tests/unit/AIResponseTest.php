<?php
/**
 * SmartBulk — unit tests for AIResponse::unusableReason() (no PrestaShop needed).
 *
 * Run: php tests/unit/AIResponseTest.php
 * Exits non-zero when any assertion fails.
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

use SmartBulk\Service\Ai\AIResponse;

$failures = 0;
$count = 0;

function check(string $label, bool $ok): void
{
    global $failures, $count;
    $count++;
    if ($ok) {
        printf("  \033[32m✓\033[0m %s\n", $label);
        return;
    }
    $failures++;
    printf("  \033[31m✗\033[0m %s\n", $label);
}

function response(string $content, ?string $stopReason): AIResponse
{
    return new AIResponse($content, 100, 80, 0.001, 500, 'claude-haiku-5-5', 'claude', $stopReason);
}

function reason(string $content, ?string $stopReason): ?string
{
    return response($content, $stopReason)->unusableReason();
}

echo "Usable output\n";
check('Claude end_turn with text', reason('Meta title', 'end_turn') === null);
check('OpenAI stop with text', reason('Meta title', 'stop') === null);
check('text without stop_reason', reason('Meta title', null) === null);

echo "Truncated at max_tokens\n";
$r = reason('', 'max_tokens');
check('empty + max_tokens is unusable', $r !== null);
check('empty + max_tokens mentions thinking', $r !== null && str_contains($r, 'Thinking tokens'));
$r = reason('Partial sentence that got cu', 'max_tokens');
check('partial + max_tokens is unusable', $r !== null);
check('partial + max_tokens says cut off', $r !== null && str_contains($r, 'cut off'));
check('OpenAI length is unusable', reason('Partial', 'length') !== null);

echo "Refusal\n";
check('Claude refusal (empty) is unusable', reason('', 'refusal') !== null);
check('Claude refusal (with text) is unusable', reason('I can\'t help with that.', 'refusal') !== null);
check('OpenAI content_filter is unusable', reason('', 'content_filter') !== null);
check('refusal message names the stop reason', str_contains((string) reason('', 'refusal'), 'stop_reason: refusal'));

echo "Empty for other reasons\n";
check('empty + end_turn is unusable', reason('', 'end_turn') !== null);
check('whitespace-only is unusable', reason("  \n ", 'end_turn') !== null);
check('empty + no stop_reason is unusable', str_contains((string) reason('', null), 'stop_reason: unknown'));

echo "Translation\n";
$tagged = static fn (string $m): string => '[pl] ' . $m;
check('translator is applied to the template', str_starts_with((string) response('', 'refusal')->unusableReason($tagged), '[pl] The model declined'));
check('stop reason filled in after translating', str_contains((string) response('', 'refusal')->unusableReason($tagged), 'stop_reason: refusal'));
// A translation that lost or mangled the placeholder must not throw.
check('damaged translation does not throw', response('', 'refusal')->unusableReason(static fn (string $m): string => '100% %s {stop} zepsute') === '100% %s {stop} zepsute');

// Polish text via the same lookup PrestaShop uses (md5 of the source string);
// coverage of every message is in TranslationsTest.php.
require __DIR__ . '/../../translations/pl.php';
$pl = response('', 'max_tokens')->unusableReason(static function (string $m): string {
    global $_MODULE;
    return $_MODULE['<{smartbulk}prestashop>smartbulk_' . md5($m)] ?? $m;
});
check('Polish message for empty reply at max_tokens', str_starts_with((string) $pl, 'Model wyczerpał limit Max tokens') && str_contains((string) $pl, 'stop_reason: max_tokens'));

printf("\n%d checks, %d failed\n", $count, $failures);
exit($failures === 0 ? 0 : 1);
