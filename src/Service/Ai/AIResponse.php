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

    /*
     * Messages for unusable replies; {stop_reason} is filled in after translating.
     * Plain str_replace, not sprintf, so a damaged translation can't throw.
     * Polish versions live in translations/pl.php. Keep them free of
     * apostrophes and HTML characters: PrestaShop hashes and escapes them.
     */
    public const MSG_REFUSED = 'The model declined to answer (stop_reason: {stop_reason}). Nothing was applied.';
    public const MSG_NO_TEXT_AT_LIMIT = 'The model hit the Max tokens limit before writing any text (stop_reason: {stop_reason}). '
        . 'Thinking tokens count toward the limit — raise Max tokens in the prompt, or lower effort / switch thinking off.';
    public const MSG_CUT_OFF = 'The output was cut off at the Max tokens limit (stop_reason: {stop_reason}); the partial text was not applied. '
        . 'Raise Max tokens in the prompt.';
    public const MSG_EMPTY = 'The model returned no text (stop_reason: {stop_reason}). Nothing was applied.';

    /**
     * Why this response must not be applied, or null when it's usable.
     *
     * Covers both providers' stop reasons: a refusal (Claude `refusal`,
     * OpenAI `content_filter`), output cut off at the token limit (Claude
     * `max_tokens`, OpenAI `length`) — with thinking on, the whole limit can
     * go to thinking and leave no text — and an empty reply for any other reason.
     *
     * @param (callable(string):string)|null $translate Applied to the message template before the stop reason is filled in.
     */
    public function unusableReason(?callable $translate = null): ?string
    {
        $stop = $this->stopReason ?? 'unknown';
        $empty = trim($this->content) === '';

        if (in_array($stop, ['refusal', 'content_filter'], true)) {
            $template = self::MSG_REFUSED;
        } elseif (in_array($stop, ['max_tokens', 'length'], true)) {
            $template = $empty ? self::MSG_NO_TEXT_AT_LIMIT : self::MSG_CUT_OFF;
        } elseif ($empty) {
            $template = self::MSG_EMPTY;
        } else {
            return null;
        }

        return str_replace('{stop_reason}', $stop, $translate ? $translate($template) : $template);
    }
}
