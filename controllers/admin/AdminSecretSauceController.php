<?php
/**
 * Secret Sauce — shared parent tab controller
 *
 * Provides a default landing when a user clicks the unowned "Secret Sauce" menu entry.
 * Redirects to the first child tab. Multiple modules install this controller; the first
 * one wins — subsequent installs simply reuse the existing class_name registration.
 *
 * @license https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

class AdminSecretSauceController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        $lang = (int) $this->context->language->id;
        $children = Tab::getTabs($lang, $this->id);

        if (!empty($children)) {
            // Redirect to the first visible child module's admin link
            $firstChild = $children[0]['class_name'] ?? null;
            if ($firstChild) {
                Tools::redirectAdmin($this->context->link->getAdminLink($firstChild));
                return;
            }
        }

        parent::initContent();
        $this->content = '<div class="alert alert-info">Secret Sauce — no child modules installed yet.</div>';
        $this->context->smarty->assign(['content' => $this->content]);
    }
}
