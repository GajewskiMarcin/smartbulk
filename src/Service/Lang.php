<?php
/**
 * SmartBulk — translations for messages built in PHP (errors, summaries)
 *
 * Goes through the module's legacy translations (translations/<iso>.php),
 * like the SPA dictionary in smartbulk.php, so a message follows the
 * back-office employee's language. Placeholders use the SPA's {name} style.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service;

final class Lang
{
    /**
     * Translate $message and fill its {placeholders}. Outside PrestaShop
     * (unit tests) the English text is returned.
     *
     * Keep source strings free of apostrophes: PrestaShop hashes them
     * escaped, so translations/<iso>.php keys would have to match that.
     *
     * @param array<string,string|int|float> $params
     */
    public static function t(string $message, array $params = []): string
    {
        $module = self::module();
        $text = $module ? $module->l($message, 'smartbulk') : $message;
        // l() HTML-escapes its result; these messages are sent as JSON text.
        $text = htmlspecialchars_decode($text, ENT_QUOTES);
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    private static function module(): ?\Module
    {
        static $module = false;
        if ($module === false) {
            $found = class_exists(\Module::class) ? \Module::getInstanceByName('smartbulk') : false;
            $module = $found ?: null;
        }
        return $module;
    }
}
