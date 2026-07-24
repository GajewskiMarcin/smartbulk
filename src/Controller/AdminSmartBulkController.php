<?php
/**
 * SmartBulk — Symfony admin controller (SPA shell)
 *
 * Renders the React SPA shell. All routing inside SmartBulk happens client-side
 * via React Router; API endpoints live under /smartbulk/api/* (separate controllers).
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Controller;

use PrestaShop\PrestaShop\Core\Context\ShopContext;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminSmartBulkController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function indexAction(Request $request, ShopContext $shopContext): Response
    {
        $bootstrap = [
            'module' => [
                'name'    => 'smartbulk',
                'version' => '1.0.0',
            ],
            'shop' => $this->resolveShop($shopContext),
            'user' => $this->resolveUser(),
            'currency' => $this->resolveCurrency(),
            'links' => [
                'api'          => $this->generateUrl('smartbulk_api_ping'),
                'buy_a_coffee' => 'https://buymeacoffee.com/marcingajewski',
                'github'       => 'https://github.com/GajewskiMarcin/smartbulk',
            ],
            'assets_version' => $this->resolveAssetsVersion(),
            // Translations for the React SPA — keyed flat strings.
            'i18n'           => $this->loadI18n(),
            // Handoff payloads set by *HandoffAction one request earlier.
            'grid_handoff'   => $this->popGridHandoff($request),
            'ai_handoff'     => $this->popAiHandoff($request),
        ];

        return $this->render('@Modules/smartbulk/views/templates/admin/shell.html.twig', [
            'bootstrap'  => $bootstrap,
            'layoutTitle' => 'SmartBulk',
        ]);
    }

    /**
     * @return array{id:int,ids:int[],is_all:bool,is_group:bool,is_single:bool}
     */
    private function resolveShop(ShopContext $shopContext): array
    {
        $id = 0;
        $ids = [];
        $isSingle = true;
        $isGroup = false;
        $isAll = false;

        try {
            if (method_exists($shopContext, 'getId')) {
                $id = (int) $shopContext->getId();
            }
            if (method_exists($shopContext, 'getShopConstraint')) {
                $constraint = $shopContext->getShopConstraint();
                if ($constraint !== null) {
                    if (method_exists($constraint, 'forAllShops')) {
                        $isAll = (bool) $constraint->forAllShops();
                    }
                    if (method_exists($constraint, 'getShopGroupId') && $constraint->getShopGroupId() !== null) {
                        $isGroup = true;
                        $isSingle = false;
                    }
                    if ($isAll) {
                        $isSingle = false;
                    }
                }
            }
        } catch (\Throwable $e) {
            // swallow — fall back to defaults already set above
        }

        if ($id > 0) {
            $ids = [$id];
        }

        return [
            'id'        => $id,
            'ids'       => $ids,
            'is_all'    => $isAll,
            'is_group'  => $isGroup,
            'is_single' => $isSingle,
        ];
    }

    /**
     * Simple health-check endpoint for the SPA to confirm backend reachability.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function pingAction(): Response
    {
        return $this->json([
            'ok'      => true,
            'module'  => 'smartbulk',
            'version' => '1.0.0',
            'time'    => gmdate('c'),
        ]);
    }

    /**
     * Best-effort user info extraction. PS 8/9 expose this slightly differently,
     * so we fall back to the legacy Context if modern DI contextual helpers aren't available.
     *
     * @return array{name:string,id:int}
     */
    /**
     * Receives a single-product → AI Assistant handoff (e.g. from the Health
     * panel in the product form's Modules tab). Stores productId + taskType
     * in session; indexAction() pops it via popAiHandoff() on the next request.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function aiHandoffAction(Request $request): RedirectResponse
    {
        $productId = (int) $request->query->get('productId', 0);
        $taskType  = (string) $request->query->get('taskType', '');
        if ($productId > 0 && $request->hasSession()) {
            $request->getSession()->set('smartbulk_ai_handoff', [
                'product_id' => $productId,
                'task_type'  => $taskType,
                'created_at' => time(),
            ]);
        }
        return $this->redirectToRoute('smartbulk_index');
    }

    /**
     * @return array{product_id:int,task_type:string}|null
     */
    private function popAiHandoff(Request $request): ?array
    {
        if (!$request->hasSession()) return null;
        $session = $request->getSession();
        $payload = $session->get('smartbulk_ai_handoff');
        if (!is_array($payload) || empty($payload['product_id'])) return null;
        $session->remove('smartbulk_ai_handoff');
        if (!empty($payload['created_at']) && (time() - (int) $payload['created_at']) > 300) {
            return null;
        }
        return [
            'product_id' => (int) $payload['product_id'],
            'task_type'  => (string) ($payload['task_type'] ?? ''),
        ];
    }

    /**
     * Receives the bulk action submission from the native PS product grid.
     * Stores the selected product IDs in session and redirects to the SPA;
     * indexAction() picks them up via popGridHandoff() on the next request.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function gridHandoffAction(Request $request): RedirectResponse
    {
        $ids = $this->extractGridIds($request);
        if ($request->hasSession()) {
            $request->getSession()->set('smartbulk_grid_handoff', [
                'product_ids' => $ids,
                'created_at'  => time(),
            ]);
        }
        return $this->redirectToRoute('smartbulk_index');
    }

    /**
     * @return int[]
     */
    private function extractGridIds(Request $request): array
    {
        // Single-product link from a row action arrives as ?productId=NN.
        $singleId = (int) $request->query->get('productId', 0);
        if ($singleId > 0) return [$singleId];

        $candidates = [
            (array) $request->request->all('product_bulk'),
            (array) $request->request->all('product_grid_bulk'),
            (array) $request->query->all('product_bulk'),
        ];
        foreach ($candidates as $arr) {
            if (!empty($arr)) {
                return array_values(array_unique(array_filter(
                    array_map('intval', $arr),
                    static fn (int $id) => $id > 0
                )));
            }
        }
        return [];
    }

    /**
     * @return array{product_ids:int[]}|null
     */
    private function popGridHandoff(Request $request): ?array
    {
        if (!$request->hasSession()) return null;
        $session = $request->getSession();
        $payload = $session->get('smartbulk_grid_handoff');
        if (!is_array($payload) || empty($payload['product_ids'])) return null;
        // Single-shot: clear it so a refresh doesn't re-trigger the handoff.
        $session->remove('smartbulk_grid_handoff');
        // Drop stale handoffs older than 5 minutes — defensive in case
        // the user navigated away after submitting.
        if (!empty($payload['created_at']) && (time() - (int) $payload['created_at']) > 300) {
            return null;
        }
        return ['product_ids' => array_values(array_map('intval', $payload['product_ids']))];
    }

    /**
     * Load the SPA's translation bundle for the current admin language.
     * Falls back to English if a localized file is missing. The whole
     * dictionary travels in the bootstrap so the frontend doesn't need
     * a separate request to render its first frame.
     *
     * @return array<string,string>
     */
    private function loadI18n(): array
    {
        // Pull strings via the module's getI18nDictionary() — every value goes
        // through $this->l() so it ends up in BO → International → Translations
        // → Installed modules → SmartBulk and the active locale's translations/<iso>.php
        // is honoured automatically by PrestaShop's $_MODULE lookup.
        try {
            /** @var \Module|false $module */
            $module = \Module::getInstanceByName('smartbulk');
            if ($module && method_exists($module, 'getI18nDictionary')) {
                /** @var array<string,string> $dict */
                $dict = $module->getI18nDictionary();
                return $dict;
            }
        } catch (\Throwable $e) { /* fall through to empty dict */ }
        return [];
    }

    /**
     * Cache-busting token derived from compiled bundle mtime — changes every
     * deploy so the browser refetches fresh JS/CSS without manual hard-reload.
     */
    private function resolveAssetsVersion(): string
    {
        $jsPath = _PS_MODULE_DIR_ . 'smartbulk/views/js/app/dist/smartbulk.js';
        if (is_file($jsPath)) {
            $mtime = @filemtime($jsPath);
            if ($mtime !== false) return (string) $mtime;
        }
        return '1.0.0';
    }

    private function resolveUser(): array
    {
        $name = '';
        $id = 0;

        if (class_exists(\Context::class)) {
            $ctx = \Context::getContext();
            if ($ctx && $ctx->employee && $ctx->employee->id) {
                $id = (int) $ctx->employee->id;
                $name = trim((string) $ctx->employee->firstname . ' ' . (string) $ctx->employee->lastname)
                    ?: (string) $ctx->employee->email;
            }
        }

        return ['name' => $name, 'id' => $id];
    }

    /**
     * Default shop currency for price display in the SPA.
     *
     * @return array{symbol:string,iso_code:string,format:string}
     */
    private function resolveCurrency(): array
    {
        $symbol = '€';
        $iso = 'EUR';
        $format = '{sign}{amount}';
        try {
            $ctx = \Context::getContext();
            if ($ctx && $ctx->currency) {
                $symbol = (string) ($ctx->currency->sign ?: $symbol);
                $iso    = (string) ($ctx->currency->iso_code ?: $iso);
                // PrestaShop currency format: 1=left, 2=right (e.g. "1,234.56 zł"),
                // 3=left-with-space, 4=right-with-space, 5=right-no-space.
                $fmt = (int) ($ctx->currency->format ?? 1);
                $format = match ($fmt) {
                    2       => '{amount} {sign}',
                    3       => '{sign} {amount}',
                    4, 5    => '{amount}{sign}',
                    default => '{sign}{amount}',
                };
            }
        } catch (\Throwable $e) { /* fall through to EUR defaults */ }
        return ['symbol' => $symbol, 'iso_code' => $iso, 'format' => $format];
    }
}
