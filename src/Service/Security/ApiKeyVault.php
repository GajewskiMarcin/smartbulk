<?php
/**
 * SmartBulk — ApiKeyVault
 *
 * AES-256-GCM encryption for sensitive config values (API keys). The key is derived
 * from PrestaShop's _COOKIE_KEY_ salted with a module-specific constant, so values
 * encrypted on one install cannot be decrypted on another (even if the DB is copied).
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Security;

use RuntimeException;

final class ApiKeyVault
{
    private const CIPHER = 'aes-256-gcm';
    private const MODULE_SALT = 'smartbulk:v1:apikey-vault';
    private const MASK_KEEP_CHARS = 4;

    /**
     * Encrypt a plaintext value. Returns a self-describing token: "enc:v1:<base64>".
     * Empty/null input returns empty string unchanged.
     */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = $this->deriveKey();
        $iv = random_bytes(12); // 96-bit IV, recommended for GCM
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('SmartBulk vault: encryption failed');
        }

        // Payload layout: [IV 12 bytes][tag 16 bytes][ciphertext N bytes]
        return 'enc:v1:' . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a token produced by encrypt(). Returns '' for empty input or if the
     * token is not in our format (so legacy plaintext values pass through unchanged —
     * useful during migration / manual DB edits).
     */
    public function decrypt(string $token): string
    {
        if ($token === '') {
            return '';
        }
        if (strncmp($token, 'enc:v1:', 7) !== 0) {
            // Not an encrypted value — return as-is. Assume plaintext.
            return $token;
        }

        $raw = base64_decode(substr($token, 7), true);
        if ($raw === false || strlen($raw) < 12 + 16) {
            throw new RuntimeException('SmartBulk vault: malformed token');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $key = $this->deriveKey();
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('SmartBulk vault: decryption failed');
        }
        return $plaintext;
    }

    /**
     * Return a display-safe preview of a key (for UI): "sk-abcd…xyz1" form.
     * Never use this for auth — always decrypt first.
     */
    public function mask(string $plaintext): string
    {
        $len = strlen($plaintext);
        if ($len === 0) {
            return '';
        }
        if ($len <= self::MASK_KEEP_CHARS * 2) {
            return str_repeat('•', $len);
        }
        $head = substr($plaintext, 0, self::MASK_KEEP_CHARS);
        $tail = substr($plaintext, -self::MASK_KEEP_CHARS);
        return $head . str_repeat('•', min(8, $len - self::MASK_KEEP_CHARS * 2)) . $tail;
    }

    private function deriveKey(): string
    {
        if (!defined('_COOKIE_KEY_') || _COOKIE_KEY_ === '') {
            throw new RuntimeException('SmartBulk vault: _COOKIE_KEY_ is not available');
        }
        // HKDF-like derivation with fixed salt so this module's vault is isolated
        // from anything else that might derive keys from _COOKIE_KEY_.
        return hash_hkdf('sha256', _COOKIE_KEY_, 32, self::MODULE_SALT);
    }
}
