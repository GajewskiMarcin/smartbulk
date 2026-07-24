<?php
/**
 * SmartBulk — Claude (Anthropic) provider
 *
 * Calls Anthropic's Messages API. Uses curl (always available in PS) for portability.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Ai;

use RuntimeException;
use SmartBulk\Service\SettingsService;

final class ClaudeProvider implements AIProviderInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const TIMEOUT_SEC = 60;

    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    public function getName(): string
    {
        return 'claude';
    }

    /**
     * @param array{model?:string,temperature?:float,max_tokens?:int,top_p?:float} $params
     */
    public function generate(string $systemPrompt, string $userPrompt, array $params): AIResponse
    {
        $apiKey = $this->settings->getSecret(SettingsService::KEY_CLAUDE_API_KEY);
        if ($apiKey === '') {
            throw new RuntimeException('Claude API key is not configured. Set it in Settings → AI providers.');
        }

        $model = $params['model'] ?? 'claude-haiku-4-5';

        $body = [
            'model'      => $model,
            'max_tokens' => (int) ($params['max_tokens'] ?? 512),
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];
        if (isset($params['temperature'])) {
            $body['temperature'] = (float) $params['temperature'];
        }
        if (isset($params['top_p'])) {
            $body['top_p'] = (float) $params['top_p'];
        }

        $start = microtime(true);
        [$status, $response] = $this->httpPost(self::API_URL, $body, [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . self::API_VERSION,
            'content-type: application/json',
        ]);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if ($status < 200 || $status >= 300) {
            $msg = $response['error']['message'] ?? ('HTTP ' . $status);
            throw new RuntimeException('Claude API error: ' . $msg);
        }

        $content = '';
        foreach (($response['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= $block['text'] ?? '';
            }
        }
        $content = trim($content);

        $usage = $response['usage'] ?? [];
        $tokensIn  = (int) ($usage['input_tokens'] ?? 0);
        $tokensOut = (int) ($usage['output_tokens'] ?? 0);

        return new AIResponse(
            content:    $content,
            tokensIn:   $tokensIn,
            tokensOut:  $tokensOut,
            costUsd:    Pricing::costUsd($model, $tokensIn, $tokensOut),
            latencyMs:  $latencyMs,
            model:      $model,
            provider:   'claude',
            stopReason: $response['stop_reason'] ?? null,
        );
    }

    /**
     * @param array<string,mixed> $body
     * @param string[]            $headers
     * @return array{0:int,1:array<string,mixed>} [http_status, decoded_json]
     */
    private function httpPost(string $url, array $body, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Claude HTTP call failed: ' . $err);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Claude returned invalid JSON: ' . substr((string) $raw, 0, 200));
        }
        return [$status, $decoded];
    }
}
