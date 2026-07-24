<?php
/**
 * SmartBulk — Legacy bridge controller
 *
 * Exists so that `Tab::class_name = 'AdminSmartBulk'` resolves to a real PHP class
 * (PS requirement for legacy tabs). The Symfony router (`config/routes.yml`) declares
 * `_legacy_controller: AdminSmartBulk` on `smartbulk_index`, which causes PS 9 to
 * hand the request off to the Symfony controller BEFORE `initContent()` runs.
 *
 * The explicit redirect below is a safety net for PS 8 setups where the legacy ↔ SF
 * routing bridge doesn't intercept (rare but possible).
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

class AdminSmartBulkController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        $container = SymfonyContainer::getInstance();
        if ($container && $container->has('router')) {
            try {
                $url = $container->get('router')->generate('smartbulk_index');
                Tools::redirectAdmin($url);
                return;
            } catch (\Throwable $e) {
                // Fall through to legacy render below
            }
        }
        parent::initContent();
    }
}
