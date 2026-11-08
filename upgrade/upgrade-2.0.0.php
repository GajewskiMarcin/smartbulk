<?php
/**
 * SmartBulk — upgrade to 2.0.0 (Artur67pl/smartbulk fork)
 *
 * No schema or data changes: prompt params (effort/thinking) live in the
 * existing JSON column and translations ship as files. Present so the
 * upgrade from 1.0.6 is explicit in the module's upgrade history.
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
function upgrade_module_2_0_0($module)
{
    return true;
}
