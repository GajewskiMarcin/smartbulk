<?php
/**
 * SmartBulk — version-aware admin controller base.
 *
 * PrestaShop changed the admin controller base class between 8 and 9:
 *   - PS 9: PrestaShopBundle\Controller\Admin\PrestaShopAdminController
 *   - PS 8: PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController (present in 9 too, but deprecated)
 * Extending a fixed one breaks the other, so this file defines CompatAdminController
 * as a subclass of whichever base class exists on the running PrestaShop version.
 * All module controllers extend CompatAdminController.
 *
 * Access control is also version-split at the framework level: PS 9 uses the
 * #[AdminSecurity] PHP attribute (PrestaShopBundle\Security\Attribute\AdminSecurity),
 * PS 8 the @AdminSecurity annotation — neither is understood by the other version.
 * We therefore drop the declarative guard and enforce the same tab permissions
 * explicitly via assertAccess(), which relies only on the legacy Profile/Tab API
 * that is byte-for-byte identical on PS 8 and PS 9.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Controller;

/**
 * Cross-version permission enforcement shared by every SmartBulk controller.
 *
 * Defined once as a trait so the single method body is reused by whichever
 * CompatAdminController base variant is active on the running PrestaShop.
 */
trait SmartBulkAccessTrait
{
    /**
     * Ensure the current employee holds the requested permission on the
     * AdminSmartBulk tab. Mirrors the old #[AdminSecurity("is_granted('LEVEL',
     * 'AdminSmartBulk')")] guard, but works on PS 8 and PS 9 alike.
     *
     * @param string $level one of: read | create | update | delete
     *
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    protected function assertAccess(string $level): void
    {
        // Map SmartBulk verbs to PrestaShop's tab-permission columns.
        $map  = ['read' => 'view', 'create' => 'add', 'update' => 'edit', 'delete' => 'delete'];
        $perm = $map[$level] ?? 'view';

        $employee = null;
        if (\class_exists(\Context::class)) {
            $ctx = \Context::getContext();
            if ($ctx && isset($ctx->employee) && $ctx->employee) {
                $employee = $ctx->employee;
            }
        }

        // No authenticated employee → deny (the BO firewall normally catches this first).
        if (!$employee || !(int) $employee->id) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('SmartBulk: authentication required.');
        }

        // Super Admin bypasses per-tab permissions.
        $superAdmin = \defined('_PS_ADMIN_PROFILE_') ? (int) \_PS_ADMIN_PROFILE_ : 1;
        if ((int) $employee->id_profile === $superAdmin) {
            return;
        }

        $idTab = (int) \Tab::getIdFromClassName('AdminSmartBulk');
        if ($idTab <= 0) {
            // Tab not resolvable yet (e.g. mid-install): defer to the BO authentication
            // that has already run — don't hard-block a legitimately logged-in admin.
            return;
        }

        // getProfileAccess() returns ['view'=>'0/1','add'=>...,'edit'=>...,'delete'=>...] or false.
        $access = \Profile::getProfileAccess((int) $employee->id_profile, $idTab);
        if (!\is_array($access) || (int) ($access[$perm] ?? 0) !== 1) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                \sprintf('SmartBulk: missing "%s" permission on AdminSmartBulk.', $perm)
            );
        }
    }
}

if (\class_exists(\PrestaShopBundle\Controller\Admin\PrestaShopAdminController::class)) {
    /** @phpstan-ignore-next-line — parent resolved at runtime per PS version */
    abstract class CompatAdminController extends \PrestaShopBundle\Controller\Admin\PrestaShopAdminController
    {
        use SmartBulkAccessTrait;
    }
} else {
    /** @phpstan-ignore-next-line — PS 8 fallback base */
    abstract class CompatAdminController extends \PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController
    {
        use SmartBulkAccessTrait;
    }
}
