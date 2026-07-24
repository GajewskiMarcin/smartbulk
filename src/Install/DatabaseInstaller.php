<?php
/**
 * SmartBulk — Database installer
 *
 * Reads sql/install.sql (schema templates with {PREFIX} and {ENGINE} placeholders)
 * and executes each statement. Same approach for uninstall.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Install;

use Db;
use RuntimeException;

final class DatabaseInstaller
{
    private string $sqlDir;

    public function __construct(?string $sqlDir = null)
    {
        $this->sqlDir = $sqlDir ?? dirname(__DIR__, 2) . '/sql';
    }

    public function install(): bool
    {
        return $this->runSqlFile($this->sqlDir . '/install.sql');
    }

    public function uninstall(): bool
    {
        return $this->runSqlFile($this->sqlDir . '/uninstall.sql');
    }

    private function runSqlFile(string $path): bool
    {
        if (!is_file($path)) {
            throw new RuntimeException("SmartBulk SQL file missing: {$path}");
        }

        $sql = (string) file_get_contents($path);
        $sql = strtr($sql, [
            '{PREFIX}' => _DB_PREFIX_,
            '{ENGINE}' => _MYSQL_ENGINE_,
        ]);

        // Strip SQL line comments (`-- ...`) first so they don't interfere with splitting
        $sql = (string) preg_replace('/^\s*--[^\n]*\n?/m', '', $sql);

        // Split on `;` at end of line / end of file
        $statements = array_filter(
            array_map('trim', preg_split('/;\s*(?:\n|$)/', $sql) ?: []),
            static fn (string $s): bool => $s !== ''
        );

        foreach ($statements as $statement) {
            if (!Db::getInstance()->execute($statement)) {
                throw new RuntimeException(
                    'SmartBulk SQL failed: ' . Db::getInstance()->getMsgError()
                );
            }
        }
        return true;
    }
}
