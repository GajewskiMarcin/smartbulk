<?php
/**
 * SmartBulk — Tabs installer (Secret Sauce + SmartBulk)
 *
 * Follows the Secret Sauce integration convention: a shared "Secret Sauce" parent tab
 * under the IMPROVE section, unowned (module = ''), that other modules can hook into.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Install;

use Context;
use Language;
use Module;
use Tab;

final class TabsInstaller
{
    private Module $module;

    /** Tab(s) owned by this module. Keep in sync with uninstall list. */
    private const OWNED_TABS = [
        'AdminSmartBulk',
    ];

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    public function install(): bool
    {
        $secretSauceId = $this->ensureSecretSauceExists();
        if ($secretSauceId === 0) {
            return false;
        }

        return $this->installTab([
            'class_name' => 'AdminSmartBulk',
            'name'       => 'SmartBulk',
            'id_parent'  => $secretSauceId,
            'icon'       => 'auto_awesome',
        ]);
    }

    public function uninstall(): bool
    {
        foreach (self::OWNED_TABS as $className) {
            $id = (int) Tab::getIdFromClassName($className);
            if ($id > 0) {
                $tab = new Tab($id);
                $tab->delete();
            }
        }

        // Remove Secret Sauce parent ONLY if no other modules still use it.
        $secretSauceId = (int) Tab::getIdFromClassName('AdminSecretSauce');
        if ($secretSauceId > 0) {
            $lang = (int) Context::getContext()->language->id;
            $children = Tab::getTabs($lang, $secretSauceId);
            if (empty($children)) {
                $tab = new Tab($secretSauceId);
                $tab->delete();
            }
        }

        return true;
    }

    /**
     * Ensure the shared "Secret Sauce" parent tab exists under IMPROVE.
     * If another module (e.g. WiseBlock) already installed it, reuse it.
     *
     * @return int Tab ID (0 on failure)
     */
    private function ensureSecretSauceExists(): int
    {
        $existingId = (int) Tab::getIdFromClassName('AdminSecretSauce');
        if ($existingId > 0) {
            return $existingId;
        }

        // Locate IMPROVE parent. Fallback to AdminParentModulesSf if IMPROVE absent.
        $improveId = (int) Tab::getIdFromClassName('IMPROVE');
        if ($improveId === 0) {
            $improveId = (int) Tab::getIdFromClassName('AdminParentModulesSf');
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminSecretSauce';
        $tab->id_parent = $improveId;
        $tab->module = ''; // IMPORTANT: unowned — survives uninstall of any child module
        if (property_exists($tab, 'icon')) {
            $tab->icon = 'science';
        }
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Secret Sauce';
        }
        if (!$tab->add()) {
            return 0;
        }
        return (int) $tab->id;
    }

    /**
     * @param array{class_name:string,name:string,id_parent:int,icon?:string} $data
     */
    private function installTab(array $data): bool
    {
        $existingId = (int) Tab::getIdFromClassName($data['class_name']);
        if ($existingId > 0) {
            // Re-enable + reparent on reinstall
            $tab = new Tab($existingId);
            $tab->active = 1;
            $tab->id_parent = (int) $data['id_parent'];
            return (bool) $tab->save();
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $data['class_name'];
        $tab->module = $this->module->name;
        $tab->id_parent = (int) $data['id_parent'];
        if (isset($data['icon']) && property_exists($tab, 'icon')) {
            $tab->icon = $data['icon'];
        }
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = $data['name'];
        }

        return (bool) $tab->add();
    }
}
