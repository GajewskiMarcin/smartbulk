<?php
/**
 * SmartBulk — LookupController
 *
 * Feeds dropdowns in the FilterBuilder: categories, brands, suppliers, features,
 * tags. Read-only, shop + lang aware.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Controller\Api;

use Context;
use Db;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\JsonResponse;

final class LookupController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function categoriesAction(): JsonResponse
    {
        $ctx = Context::getContext();
        $idLang = (int) $ctx->language->id;
        $idShop = (int) $ctx->shop->id;

        // Flat list; include parent for clients that want to build a tree.
        $sql = 'SELECT c.id_category, c.id_parent, cl.name, c.level_depth
                FROM `' . _DB_PREFIX_ . 'category` c
                JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                     ON cl.id_category = c.id_category
                        AND cl.id_lang = ' . $idLang . '
                        AND cl.id_shop = ' . $idShop . '
                JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                     ON cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . '
                WHERE c.active = 1 AND c.id_category > 1
                ORDER BY c.level_depth ASC, cl.name ASC';
        $rows = Db::getInstance()->executeS($sql) ?: [];

        return new JsonResponse([
            'ok'         => true,
            'categories' => array_map(static fn (array $r) => [
                'id_category'  => (int) $r['id_category'],
                'id_parent'    => (int) $r['id_parent'],
                'name'         => (string) $r['name'],
                'level_depth'  => (int) $r['level_depth'],
            ], $rows),
        ]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function brandsAction(): JsonResponse
    {
        $rows = Db::getInstance()->executeS(
            'SELECT m.id_manufacturer, m.name
             FROM `' . _DB_PREFIX_ . 'manufacturer` m
             WHERE m.active = 1
             ORDER BY m.name ASC'
        ) ?: [];
        return new JsonResponse([
            'ok'     => true,
            'brands' => array_map(static fn (array $r) => [
                'id_manufacturer' => (int) $r['id_manufacturer'],
                'name'            => (string) $r['name'],
            ], $rows),
        ]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function suppliersAction(): JsonResponse
    {
        $rows = Db::getInstance()->executeS(
            'SELECT s.id_supplier, s.name
             FROM `' . _DB_PREFIX_ . 'supplier` s
             WHERE s.active = 1
             ORDER BY s.name ASC'
        ) ?: [];
        return new JsonResponse([
            'ok'        => true,
            'suppliers' => array_map(static fn (array $r) => [
                'id_supplier' => (int) $r['id_supplier'],
                'name'        => (string) $r['name'],
            ], $rows),
        ]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function featuresAction(): JsonResponse
    {
        $idLang = (int) Context::getContext()->language->id;
        $rows = Db::getInstance()->executeS(
            'SELECT f.id_feature, fl.name AS feature_name,
                    fv.id_feature_value, fvl.value AS value
             FROM `' . _DB_PREFIX_ . 'feature` f
             JOIN `' . _DB_PREFIX_ . 'feature_lang` fl
                  ON fl.id_feature = f.id_feature AND fl.id_lang = ' . $idLang . '
             LEFT JOIN `' . _DB_PREFIX_ . 'feature_value` fv
                  ON fv.id_feature = f.id_feature AND fv.custom = 0
             LEFT JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl
                  ON fvl.id_feature_value = fv.id_feature_value AND fvl.id_lang = ' . $idLang . '
             ORDER BY fl.name ASC, fvl.value ASC'
        ) ?: [];

        $features = [];
        foreach ($rows as $r) {
            $fid = (int) $r['id_feature'];
            if (!isset($features[$fid])) {
                $features[$fid] = [
                    'id_feature' => $fid,
                    'name'       => (string) $r['feature_name'],
                    'values'     => [],
                ];
            }
            if ($r['id_feature_value'] !== null) {
                $features[$fid]['values'][] = [
                    'id_feature_value' => (int) $r['id_feature_value'],
                    'value'            => (string) $r['value'],
                ];
            }
        }
        return new JsonResponse(['ok' => true, 'features' => array_values($features)]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function attributesAction(): JsonResponse
    {
        $idLang = (int) Context::getContext()->language->id;
        $rows = Db::getInstance()->executeS(
            'SELECT ag.id_attribute_group, agl.name AS group_name,
                    a.id_attribute, al.name AS value
             FROM `' . _DB_PREFIX_ . 'attribute_group` ag
             JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl
                  ON agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = ' . $idLang . '
             LEFT JOIN `' . _DB_PREFIX_ . 'attribute` a
                  ON a.id_attribute_group = ag.id_attribute_group
             LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                  ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
             ORDER BY agl.name ASC, al.name ASC'
        ) ?: [];

        $groups = [];
        foreach ($rows as $r) {
            $gid = (int) $r['id_attribute_group'];
            if (!isset($groups[$gid])) {
                $groups[$gid] = [
                    'id_attribute_group' => $gid,
                    'name'               => (string) $r['group_name'],
                    'values'             => [],
                ];
            }
            if ($r['id_attribute'] !== null) {
                $groups[$gid]['values'][] = [
                    'id_attribute' => (int) $r['id_attribute'],
                    'value'        => (string) $r['value'],
                ];
            }
        }
        return new JsonResponse(['ok' => true, 'attributes' => array_values($groups)]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function languagesAction(): JsonResponse
    {
        $rows = Db::getInstance()->executeS(
            'SELECT id_lang, name, iso_code FROM `' . _DB_PREFIX_ . 'lang` WHERE active = 1 ORDER BY name ASC'
        ) ?: [];
        return new JsonResponse([
            'ok'        => true,
            'languages' => array_map(static fn (array $r) => [
                'id_lang'  => (int) $r['id_lang'],
                'name'     => (string) $r['name'],
                'iso_code' => (string) $r['iso_code'],
            ], $rows),
        ]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function fieldCatalogAction(): JsonResponse
    {
        return new JsonResponse([
            'ok'           => true,
            'fields'       => \SmartBulk\Service\Segment\FieldCatalog::all(),
            'group_labels' => \SmartBulk\Service\Segment\FieldCatalog::groupLabels(),
        ]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function taxRulesAction(): JsonResponse
    {
        $rows = Db::getInstance()->executeS(
            'SELECT trg.id_tax_rules_group, trg.name
             FROM `' . _DB_PREFIX_ . 'tax_rules_group` trg
             WHERE trg.active = 1 AND trg.deleted = 0
             ORDER BY trg.name ASC'
        ) ?: [];
        return new JsonResponse([
            'ok'        => true,
            'tax_rules' => array_map(static fn (array $r) => [
                'id_tax_rules_group' => (int) $r['id_tax_rules_group'],
                'name'               => (string) $r['name'],
            ], $rows),
        ]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function tagsAction(): JsonResponse
    {
        $idLang = (int) Context::getContext()->language->id;
        $rows = Db::getInstance()->executeS(
            'SELECT t.id_tag, t.name
             FROM `' . _DB_PREFIX_ . 'tag` t
             WHERE t.id_lang = ' . $idLang . '
             ORDER BY t.name ASC
             LIMIT 500'
        ) ?: [];
        return new JsonResponse([
            'ok'   => true,
            'tags' => array_map(static fn (array $r) => [
                'id_tag' => (int) $r['id_tag'],
                'name'   => (string) $r['name'],
            ], $rows),
        ]);
    }
}
