<?php
/**
 * SmartBulk — SettingsService
 *
 * Reads/writes module configuration via PrestaShop's Configuration API. Transparently
 * encrypts API keys on write and decrypts on read. Returns masked keys for UI.
 *
 * Keys follow the SMARTBULK_* prefix convention.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service;

use Configuration;
use SmartBulk\Service\Security\ApiKeyVault;

final class SettingsService
{
    // Non-secret keys — stored as-is
    public const KEY_AI_PROVIDER        = 'SMARTBULK_AI_PROVIDER';
    public const KEY_AI_DAILY_BUDGET    = 'SMARTBULK_AI_DAILY_BUDGET';
    public const KEY_AI_RATE_LIMIT      = 'SMARTBULK_AI_RATE_LIMIT';
    public const KEY_AI_MASK_PRICES     = 'SMARTBULK_AI_MASK_PRICES';
    public const KEY_AI_BRAND_TONE      = 'SMARTBULK_AI_BRAND_TONE';
    public const KEY_DATA_RETENTION     = 'SMARTBULK_DATA_RETENTION_DAYS';

    // Secret keys — encrypted via ApiKeyVault
    public const KEY_CLAUDE_API_KEY     = 'SMARTBULK_CLAUDE_API_KEY_ENC';
    public const KEY_OPENAI_API_KEY     = 'SMARTBULK_OPENAI_API_KEY_ENC';

    /** @var string[] Keys whose stored value is encrypted and must be decrypted on read. */
    private const SECRET_KEYS = [
        self::KEY_CLAUDE_API_KEY,
        self::KEY_OPENAI_API_KEY,
    ];

    public function __construct(
        private readonly ApiKeyVault $vault,
    ) {
    }

    /**
     * Returns the full settings snapshot. Secret keys are returned MASKED for safe UI display.
     * Use `getSecret()` when the plaintext is needed (e.g. outbound API calls).
     *
     * @return array<string,mixed>
     */
    public function getAllMasked(): array
    {
        return [
            'ai_provider'     => (string) Configuration::get(self::KEY_AI_PROVIDER, 'claude'),
            'daily_budget'    => (float)  Configuration::get(self::KEY_AI_DAILY_BUDGET, 25),
            'rate_limit'      => (int)    Configuration::get(self::KEY_AI_RATE_LIMIT, 30),
            'mask_prices'     => (bool)   Configuration::get(self::KEY_AI_MASK_PRICES, 0),
            'brand_tone'      => (string) Configuration::get(self::KEY_AI_BRAND_TONE, ''),
            'retention_days'  => (int)    Configuration::get(self::KEY_DATA_RETENTION, 90),
            'claude_api_key'  => $this->getMaskedSecret(self::KEY_CLAUDE_API_KEY),
            'openai_api_key'  => $this->getMaskedSecret(self::KEY_OPENAI_API_KEY),
            'has_claude_key'  => $this->hasSecret(self::KEY_CLAUDE_API_KEY),
            'has_openai_key'  => $this->hasSecret(self::KEY_OPENAI_API_KEY),
        ];
    }

    /**
     * Persist settings. Secret keys with value `null` are left unchanged (allows
     * saving other fields without re-entering keys). Secret keys with value `''`
     * explicitly clear the stored key.
     *
     * @param array<string,mixed> $input
     */
    public function save(array $input): void
    {
        if (isset($input['ai_provider'])) {
            $provider = in_array($input['ai_provider'], ['claude', 'openai'], true) ? $input['ai_provider'] : 'claude';
            Configuration::updateValue(self::KEY_AI_PROVIDER, $provider);
        }
        if (isset($input['daily_budget'])) {
            Configuration::updateValue(self::KEY_AI_DAILY_BUDGET, (float) $input['daily_budget']);
        }
        if (isset($input['rate_limit'])) {
            Configuration::updateValue(self::KEY_AI_RATE_LIMIT, max(0, (int) $input['rate_limit']));
        }
        if (array_key_exists('mask_prices', $input)) {
            Configuration::updateValue(self::KEY_AI_MASK_PRICES, (int) (bool) $input['mask_prices']);
        }
        if (array_key_exists('brand_tone', $input)) {
            Configuration::updateValue(self::KEY_AI_BRAND_TONE, (string) $input['brand_tone']);
        }
        if (isset($input['retention_days'])) {
            Configuration::updateValue(self::KEY_DATA_RETENTION, max(1, (int) $input['retention_days']));
        }

        // Secret keys
        foreach ([
            self::KEY_CLAUDE_API_KEY => 'claude_api_key',
            self::KEY_OPENAI_API_KEY => 'openai_api_key',
        ] as $configKey => $inputKey) {
            if (!array_key_exists($inputKey, $input)) {
                continue; // not submitted — leave unchanged
            }
            $value = $input[$inputKey];
            if ($value === null) {
                continue;
            }
            if ($value === '') {
                Configuration::updateValue($configKey, '');
                continue;
            }
            Configuration::updateValue($configKey, $this->vault->encrypt((string) $value));
        }
    }

    public function getSecret(string $key): string
    {
        if (!in_array($key, self::SECRET_KEYS, true)) {
            return '';
        }
        $raw = (string) Configuration::get($key, '');
        if ($raw === '') {
            return '';
        }
        return $this->vault->decrypt($raw);
    }

    public function hasSecret(string $key): bool
    {
        return $this->getSecret($key) !== '';
    }

    private function getMaskedSecret(string $key): string
    {
        $plain = $this->getSecret($key);
        return $plain === '' ? '' : $this->vault->mask($plain);
    }
}
