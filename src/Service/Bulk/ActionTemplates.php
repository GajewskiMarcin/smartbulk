<?php
/**
 * SmartBulk — Action Templates
 *
 * Pre-defined action sets that users can load into the Bulk Editor with one click.
 * Each template is a named bundle of actions that serves a common workflow
 * (SEO auto-generate, stock reset, etc.).
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Bulk;

final class ActionTemplates
{
    /**
     * Merge built-in + user-defined templates. User templates carry an
     * `is_user` flag so the UI can show a delete affordance.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function allWithUserDefined(\SmartBulk\Repository\ActionTemplateRepository $repo, int $idShop): array
    {
        $userRows = $repo->listForShop($idShop);
        $userTemplates = array_map(static function (array $r): array {
            $actions = $r['actions'] ? json_decode((string) $r['actions'], true) : [];
            return [
                'id'          => 'user-' . (int) $r['id_template'],
                'id_user'     => (int) $r['id_template'],
                'name'        => (string) $r['name'],
                'description' => (string) ($r['description'] ?? ''),
                'emoji'       => '⭐',
                'group'       => 'user',
                'actions'     => is_array($actions) ? $actions : [],
                'is_user'     => true,
                'created_at'  => (string) $r['created_at'],
            ];
        }, $userRows);

        return array_merge($userTemplates, self::all());
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        // Run name/description through the module's $this->l() so they're picked up
        // by PrestaShop's translation scanner and resolved against translations/<iso>.php.
        $module = \Module::getInstanceByName('smartbulk');
        $l = static fn (string $s): string => $module ? $module->l($s, 'smartbulk') : $s;

        return [
            [
                'id'          => 'seo_auto',
                'name'        => $l('Auto-generate SEO'),
                'description' => $l('Meta title from product name, meta description from short description.'),
                'emoji'       => '🎯',
                'group'       => 'seo',
                'actions'     => [
                    ['field' => 'meta_title',       'operator' => 'generate_from_name',      'id_lang' => null],
                    ['field' => 'meta_description', 'operator' => 'generate_from_short_desc','id_lang' => null],
                ],
            ],
            [
                'id'          => 'seo_clear',
                'name'        => $l('Clear SEO overrides'),
                'description' => $l('Empty meta title and meta description so PrestaShop falls back to name-based defaults.'),
                'emoji'       => '🧹',
                'group'       => 'seo',
                'actions'     => [
                    ['field' => 'meta_title',       'operator' => 'clear', 'id_lang' => null],
                    ['field' => 'meta_description', 'operator' => 'clear', 'id_lang' => null],
                ],
            ],
            [
                'id'          => 'slug_rebuild',
                'name'        => $l('Regenerate friendly URLs'),
                'description' => $l('Rebuild link_rewrite from product name (same button as PrestaShop product form).'),
                'emoji'       => '🔗',
                'group'       => 'seo',
                'actions'     => [
                    ['field' => 'link_rewrite', 'operator' => 'generate_from_name', 'id_lang' => null],
                ],
            ],
            [
                'id'          => 'stock_reset',
                'name'        => $l('Reset stock to zero'),
                'description' => $l('Set quantity to 0 — useful before a re-stocktake.'),
                'emoji'       => '📦',
                'group'       => 'stock',
                'actions'     => [
                    ['field' => 'quantity', 'operator' => 'set', 'value' => 0],
                ],
            ],
            [
                'id'          => 'deactivate',
                'name'        => $l('Deactivate products'),
                'description' => $l('Set Active = No. Matching products will be hidden from the shop.'),
                'emoji'       => '🚫',
                'group'       => 'basic',
                'actions'     => [
                    ['field' => 'active', 'operator' => 'set', 'value' => false],
                ],
            ],
            [
                'id'          => 'mark_on_sale',
                'name'        => $l('Mark as "On sale"'),
                'description' => $l('Flip the "On sale!" flag on.'),
                'emoji'       => '🏷️',
                'group'       => 'basic',
                'actions'     => [
                    ['field' => 'on_sale', 'operator' => 'set', 'value' => true],
                ],
            ],
            [
                'id'          => 'condition_new',
                'name'        => $l('Mark condition = New'),
                'description' => $l('Set product condition to "New".'),
                'emoji'       => '✨',
                'group'       => 'basic',
                'actions'     => [
                    ['field' => 'condition', 'operator' => 'set', 'value' => 'new'],
                ],
            ],
            [
                'id'          => 'visibility_everywhere',
                'name'        => $l('Visible everywhere'),
                'description' => $l('Set visibility to both catalog and search.'),
                'emoji'       => '👁️',
                'group'       => 'basic',
                'actions'     => [
                    ['field' => 'visibility', 'operator' => 'set', 'value' => 'both'],
                ],
            ],
            [
                'id'          => 'markdown_cleanup_name',
                'name'        => $l('Strip markdown asterisks from name'),
                'description' => $l('Remove ** and * characters often left over from AI-generated names.'),
                'emoji'       => '🪄',
                'group'       => 'content',
                'actions'     => [
                    ['field' => 'name', 'operator' => 'replace', 'find' => '**', 'replace' => '', 'id_lang' => null],
                ],
            ],
            [
                'id'          => 'ai_seo_pair',
                'name'        => $l('AI-write SEO meta (needs prompt)'),
                'description' => $l('Generate meta title + meta description via AI. Pick a prompt after loading.'),
                'emoji'       => '🤖',
                'group'       => 'ai',
                'actions'     => [
                    ['field' => 'meta_title',       'operator' => 'ai_generate', 'prompt_id' => 0, 'id_lang' => null],
                    ['field' => 'meta_description', 'operator' => 'ai_generate', 'prompt_id' => 0, 'id_lang' => null],
                ],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $t) {
            if ($t['id'] === $id) return $t;
        }
        return null;
    }
}
