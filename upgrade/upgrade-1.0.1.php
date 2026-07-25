<?php
/**
 * SmartBulk — upgrade to 1.0.1
 *
 * Adds smartbulk_massedit.processed_offset — a cursor into the batch's product-id
 * snapshot. Replaces the old O(n^2) "SELECT DISTINCT + array_diff" progress tracking
 * with an O(1) offset, and enables resuming interrupted batches.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_0_1($module)
{
    $prefix = _DB_PREFIX_;
    $table  = $prefix . 'smartbulk_massedit';

    $exists = Db::getInstance()->executeS(
        "SHOW COLUMNS FROM `{$table}` LIKE 'processed_offset'"
    );
    if (empty($exists)) {
        Db::getInstance()->execute(
            "ALTER TABLE `{$table}`
             ADD COLUMN `processed_offset` INT(11) NOT NULL DEFAULT 0 AFTER `products_failed`"
        );
        // Best-effort backfill for batches processed before this column existed:
        // assume everything already counted (changed+failed) was consumed in order.
        Db::getInstance()->execute(
            "UPDATE `{$table}`
             SET `processed_offset` = `products_changed` + `products_failed`
             WHERE `status` IN ('running','pending','partial','success','failed')"
        );
    }

    return true;
}
