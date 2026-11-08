<?php
/**
 * SmartBulk — Claude model capability table
 *
 * Single source of truth for which request fields each Claude model accepts
 * (sampling params, output_config.effort, thinking). Mirrored in
 * views/js/app/src/lib/prompts.ts (CLAUDE_MODEL_CAPS) — keep both in sync.
 *
 * Unknown model ids get the conservative entry: no effort, no thinking
 * override, no temperature/top_p. Leaving a field out is always accepted by
 * the API; sending one the model does not support returns a 400.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Ai;

final class ClaudeModelCapabilities
{
    /** Effort levels in ascending order. */
    public const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * Highest effort at which thinking may be switched off on models where
     * thinking is on by default (xhigh/max + off returns a 400).
     */
    public const THINKING_OFF_MAX_EFFORT = 'high';

    /** Thinking is on by default and can be switched off (effort ≤ high). */
    public const THINKING_DEFAULT_ON = 'default_on';
    /** Thinking is always on — any off value returns a 400. */
    public const THINKING_ALWAYS_ON = 'always_on';
    /** Thinking is off by default; {type: adaptive} switches it on. */
    public const THINKING_DEFAULT_OFF = 'default_off';
    /** Thinking cannot be configured from SmartBulk (not offered in the editor). */
    public const THINKING_NONE = 'none';

    /**
     * id => [
     *   effort          => accepted output_config.effort levels ([] = do not send effort),
     *   default_effort  => what the API uses when effort is omitted (null if no effort),
     *   thinking        => one of the THINKING_* modes,
     *   thinking_off    => thinking.type that switches thinking off (null if it can't be switched off),
     *   temperature     => whether temperature/top_p are forwarded,
     * ]
     *
     * Source: Anthropic docs as read 2026-10-26 (not exercised against the API).
     */
    private const MODELS = [
        // Thinking only via {type: enabled, budget_tokens} (adaptive is rejected) —
        // not offered in the editor. No effort support.
        'claude-haiku-4-5' => [
            'effort'         => [],
            'default_effort' => null,
            'thinking'       => self::THINKING_NONE,
            'thinking_off'   => null,
            'temperature'    => true,
        ],
        'claude-haiku-5-5' => [
            'effort'         => self::EFFORT_LEVELS,
            'default_effort' => 'medium',
            'thinking'       => self::THINKING_DEFAULT_ON,
            'thinking_off'   => 'disabled',
            'temperature'    => false,
        ],
        // xhigh is not available on Sonnet 4.6.
        'claude-sonnet-4-6' => [
            'effort'         => ['low', 'medium', 'high', 'max'],
            'default_effort' => 'high',
            'thinking'       => self::THINKING_DEFAULT_OFF,
            'thinking_off'   => 'disabled',
            'temperature'    => true,
        ],
        'claude-sonnet-5' => [
            'effort'         => self::EFFORT_LEVELS,
            'default_effort' => 'high',
            'thinking'       => self::THINKING_DEFAULT_ON,
            'thinking_off'   => 'disabled',
            'temperature'    => false,
        ],
        // {type: disabled} is a 400 here; between_tools is the off switch.
        'claude-sonnet-5-5' => [
            'effort'         => self::EFFORT_LEVELS,
            'default_effort' => 'high',
            'thinking'       => self::THINKING_DEFAULT_ON,
            'thinking_off'   => 'between_tools',
            'temperature'    => false,
        ],
        // No temperature: Anthropic's migration guide for Opus 4.7 says
        // "The temperature, top_p, and top_k parameters are no longer accepted
        // on Claude Opus 4.7. Requests that include them return a 400 error."
        // (The previous regex still forwarded them.)
        'claude-opus-4-7' => [
            'effort'         => self::EFFORT_LEVELS,
            'default_effort' => 'high',
            'thinking'       => self::THINKING_DEFAULT_OFF,
            'thinking_off'   => 'disabled',
            'temperature'    => false,
        ],
        // temperature: rule carried over from our earlier patches; not
        // confirmed in Anthropic's docs for Opus 5 / 5.5.
        'claude-opus-5' => [
            'effort'         => self::EFFORT_LEVELS,
            'default_effort' => 'high',
            'thinking'       => self::THINKING_DEFAULT_ON,
            'thinking_off'   => 'disabled',
            'temperature'    => false,
        ],
        'claude-opus-5-5' => [
            'effort'         => self::EFFORT_LEVELS,
            'default_effort' => 'medium',
            'thinking'       => self::THINKING_ALWAYS_ON,
            'thinking_off'   => null,
            'temperature'    => false,
        ],
    ];

    private const UNKNOWN = [
        'effort'         => [],
        'default_effort' => null,
        'thinking'       => self::THINKING_NONE,
        'thinking_off'   => null,
        'temperature'    => false,
    ];

    /**
     * @return array{effort:string[],default_effort:?string,thinking:string,thinking_off:?string,temperature:bool}
     */
    public static function forModel(string $model): array
    {
        return self::MODELS[$model] ?? self::UNKNOWN;
    }

    public static function isKnown(string $model): bool
    {
        return isset(self::MODELS[$model]);
    }

    /**
     * Request-body fields derived from prompt params for the given model:
     * temperature, top_p, output_config.effort and thinking. Anything the
     * model does not accept is dropped rather than sent.
     *
     * params.effort   — one of EFFORT_LEVELS; absent/'' = API default.
     * params.thinking — 'adaptive' (on) or 'disabled' (off; mapped to the
     *                   model's own off switch); absent/'' = API default.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function requestFields(string $model, array $params): array
    {
        $caps = self::forModel($model);
        $fields = [];

        if ($caps['temperature'] && isset($params['temperature'])) {
            $fields['temperature'] = (float) $params['temperature'];
        }
        if ($caps['temperature'] && isset($params['top_p'])) {
            $fields['top_p'] = (float) $params['top_p'];
        }

        $effort = is_string($params['effort'] ?? null) ? $params['effort'] : '';
        if (!in_array($effort, $caps['effort'], true)) {
            $effort = '';
        }
        if ($effort !== '') {
            $fields['output_config'] = ['effort' => $effort];
        }

        $thinking = is_string($params['thinking'] ?? null) ? $params['thinking'] : '';
        if ($thinking === 'between_tools') {
            $thinking = 'disabled';
        }
        $type = self::thinkingType($caps, $thinking, $effort !== '' ? $effort : $caps['default_effort']);
        if ($type !== null) {
            $fields['thinking'] = ['type' => $type];
        }

        return $fields;
    }

    /**
     * @param array{effort:string[],default_effort:?string,thinking:string,thinking_off:?string,temperature:bool} $caps
     */
    private static function thinkingType(array $caps, string $thinking, ?string $effectiveEffort): ?string
    {
        switch ($caps['thinking']) {
            case self::THINKING_DEFAULT_OFF:
                // Off is already the default, so only "on" needs sending.
                return $thinking === 'adaptive' ? 'adaptive' : null;
            case self::THINKING_DEFAULT_ON:
                // On is already the default, so only "off" needs sending —
                // and only where the API accepts it (effort ≤ high). The editor
                // blocks the other combination; drop it here rather than 400.
                if ($thinking === 'disabled' && $caps['thinking_off'] !== null
                    && self::effortRank($effectiveEffort) <= self::effortRank(self::THINKING_OFF_MAX_EFFORT)
                ) {
                    return $caps['thinking_off'];
                }
                return null;
            default:
                // THINKING_ALWAYS_ON / THINKING_NONE: never send a thinking field.
                return null;
        }
    }

    private static function effortRank(?string $effort): int
    {
        $i = $effort === null ? false : array_search($effort, self::EFFORT_LEVELS, true);
        return $i === false ? 0 : (int) $i;
    }
}
