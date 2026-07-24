<?php
/**
 * SmartBulk — OpenAI provider (Chat Completions API)
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Ai;

use RuntimeException;
use SmartBulk\Service\SettingsService;

final class OpenAIProvider implements AIProviderInterface
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const TIMEOUT_SEC = 60;

    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    public function getName(): string
    {
        return 'openai';
    }

    /**
     * @param array{model?:string,temperature?:float,max_tokens?:int,top_p?:float} $params
     */
    public function generate(string $systemPrompt, string $userPrompt, array $params): AIResponse
    {
        $apiKey = $this->settings->getSecret(SettingsService::KEY_OPENAI_API_KEY);
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured. Set it in Settings → AI providers.');
        }

        $model = $params['model'] ?? 'gpt-4o-mini';

        $body = [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
        ];
        if (isset($params['max_tokens'])) {
            $body['max_tokens'] = (int) $params['max_tokens'];
        }
        if (isset($params['temperature'])) {
            $body['temperature'] = (float) $params['temperature'];
        }
        if (isset($params['top_p'])) {
            $body['top_p'] = (float) $params['top_p'];
        }

        $start = microtime(true);
        [$status, $response] = $this->httpPost(self::API_URL, $body, [
            'authorization: Bearer ' . $apiKey,
            'content-type: application/json',
        ]);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if ($status < 200 || $status >= 300) {
            $msg = $response['error']['message'] ?? ('HTTP ' . $status);
            throw new RuntimeException('OpenAI API error: ' . $msg);
        }

        $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));

        $usage = $response['usage'] ?? [];
        $tokensIn  = (int) ($usage['prompt_tokens'] ?? 0);
        $tokensOut = (int) ($usage['completion_tokens'] ?? 0);

        return new AIResponse(
            content:    $content,
            tokensIn:   $tokensIn,
            tokensOut:  $tokensOut,
            costUsd:    Pricing::costUsd($model, $tokensIn, $tokensOut),
            latencyMs:  $latencyMs,
            model:      $model,
            provider:   'openai',
            stopReason: $response['choices'][0]['finish_reason'] ?? null,
        );
    }

    /**
     * @param array<string,mixed> $body
     * @param string[]            $headers
     * @return array{0:int,1:array<string,mixed>}
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
            throw new RuntimeException('OpenAI HTTP call failed: ' . $err);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI returned invalid JSON: ' . substr((string) $raw, 0, 200));
        }
        return [$status, $decoded];
    }
}
