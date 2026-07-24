<?php
/**
 * SmartBulk — DefaultPrompts seeder
 *
 * Seeds the prompt library with curated starter prompts on first install.
 * Idempotent: skips slugs that already exist (so re-install doesn't duplicate).
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Prompt;

use SmartBulk\Repository\PromptRepository;

final class DefaultPrompts
{
    public function __construct(
        private readonly PromptService $service,
        private readonly PromptRepository $repo,
    ) {
    }

    /**
     * @return int Number of prompts created.
     */
    public function seed(): int
    {
        $created = 0;
        foreach ($this->definitions() as $def) {
            if ($this->repo->findPromptBySlug($def['slug']) !== null) {
                continue;
            }
            $this->service->createPrompt($def);
            $created++;
        }
        return $created;
    }

    /**
     * @return array<int,array{slug:string,name:string,task_type:string,system_prompt:string,user_prompt:string,model:string,provider:string,params:array<string,mixed>,notes:string}>
     */
    private function definitions(): array
    {
        return [
            [
                'slug'      => 'meta-title-default',
                'name'      => 'Meta title — default',
                'task_type' => 'meta_title',
                'provider'  => 'claude',
                'model'     => 'claude-haiku-4-5',
                'params'    => ['temperature' => 0.4, 'max_tokens' => 80],
                'notes'     => 'Initial version',
                'system_prompt' => 'You are an SEO copywriter. Write a concise, compelling meta title for an e-commerce product.'
                    . " Constraints:\n"
                    . "- Maximum 60 characters (hard limit, never exceed)\n"
                    . "- Include the focus keyphrase naturally\n"
                    . "- Match brand tone: {brand_tone}\n"
                    . "- Output language: {target_lang}\n"
                    . '- Output ONLY the title text. No quotation marks, no commentary.',
                'user_prompt' => "Product:\n"
                    . "  Name: {name}\n"
                    . "  Brand: {brand}\n"
                    . "  Category: {category}\n"
                    . "  Focus keyphrase: {focus_keyphrase}\n\n"
                    . 'Generate the meta title.',
            ],

            [
                'slug'      => 'meta-description-default',
                'name'      => 'Meta description — default',
                'task_type' => 'meta_description',
                'provider'  => 'claude',
                'model'     => 'claude-haiku-4-5',
                'params'    => ['temperature' => 0.4, 'max_tokens' => 200],
                'notes'     => 'Initial version',
                'system_prompt' => 'You are an SEO copywriter. Write a meta description for an e-commerce product.'
                    . " Constraints:\n"
                    . "- 150–160 characters (aim for 155)\n"
                    . "- Include the focus keyphrase naturally\n"
                    . "- End with a subtle call-to-action\n"
                    . "- Match brand tone: {brand_tone}\n"
                    . "- Output language: {target_lang}\n"
                    . '- Output ONLY the description text. No quotation marks, no emojis.',
                'user_prompt' => "Product:\n"
                    . "  Name: {name}\n"
                    . "  Category: {category}\n"
                    . "  Brand: {brand}\n"
                    . "  Focus keyphrase: {focus_keyphrase}\n"
                    . "  Short description: {short_description}\n\n"
                    . 'Generate the meta description.',
            ],

            [
                'slug'      => 'short-desc-default',
                'name'      => 'Short description — default',
                'task_type' => 'short_desc',
                'provider'  => 'claude',
                'model'     => 'claude-haiku-4-5',
                'params'    => ['temperature' => 0.5, 'max_tokens' => 220],
                'notes'     => 'Initial version',
                'system_prompt' => 'You are a product copywriter for e-commerce. Write a punchy short description that grabs attention.'
                    . " Constraints:\n"
                    . "- 1 to 3 sentences\n"
                    . "- Highlight the main benefit, not just features\n"
                    . "- Match brand tone: {brand_tone}\n"
                    . "- Output language: {target_lang}\n"
                    . '- Plain text only. No HTML tags, no quotation marks.',
                'user_prompt' => "Product:\n"
                    . "  Name: {name}\n"
                    . "  Category: {category}\n"
                    . "  Brand: {brand}\n"
                    . "  Features: {features}\n\n"
                    . 'Write the short description.',
            ],

            [
                'slug'      => 'long-desc-default',
                'name'      => 'Long description — default',
                'task_type' => 'long_desc',
                'provider'  => 'claude',
                'model'     => 'claude-sonnet-4-6',
                'params'    => ['temperature' => 0.6, 'max_tokens' => 1500],
                'notes'     => 'Initial version. Uses Sonnet for higher quality on long-form output.',
                'system_prompt' => 'You are an expert e-commerce copywriter. Write a structured long description for a product.'
                    . " Structure:\n"
                    . "1. Opening paragraph with the main value proposition (2–3 sentences)\n"
                    . "2. \"Key features\" section with bullet points\n"
                    . "3. \"Who is this for\" section (1 paragraph)\n"
                    . "4. Closing call-to-action paragraph\n\n"
                    . " Constraints:\n"
                    . "- Output valid HTML using only: <p>, <h3>, <ul>, <li>, <strong>, <em>\n"
                    . "- Match brand tone: {brand_tone}\n"
                    . "- Output language: {target_lang}\n"
                    . '- No quotation marks around the whole output.',
                'user_prompt' => "Product:\n"
                    . "  Name: {name}\n"
                    . "  Category: {category}\n"
                    . "  Brand: {brand}\n"
                    . "  Features: {features}\n"
                    . "  Short description: {short_description}\n\n"
                    . 'Write the long description.',
            ],

            [
                'slug'      => 'translate-default',
                'name'      => 'Translate — default',
                'task_type' => 'translate',
                'provider'  => 'claude',
                'model'     => 'claude-haiku-4-5',
                'params'    => ['temperature' => 0.2, 'max_tokens' => 1500, 'target_field' => 'description'],
                'notes'     => 'Initial version. Low temperature for consistent translations. Default target_field is description — override per run if translating name/meta_title/meta_description/description_short.',
                'system_prompt' => 'You are a professional translator specializing in e-commerce content.'
                    . " Constraints:\n"
                    . "- Translate from {source_lang} to {target_lang}\n"
                    . "- Preserve HTML markup exactly if present\n"
                    . "- Keep brand names, product codes, and technical terms (e.g. EAN, SKU, DAF, Scania) untranslated\n"
                    . "- Match brand tone: {brand_tone}\n"
                    . '- Output ONLY the translation. No commentary, no quotation marks.',
                'user_prompt' => "Translate the following text:\n\n{existing_text}",
            ],

            [
                'slug'      => 'seo-rewrite-meta-title',
                'name'      => 'SEO rewrite — meta title',
                'task_type' => 'seo_rewrite',
                'provider'  => 'claude',
                'model'     => 'claude-haiku-4-5',
                'params'    => ['temperature' => 0.4, 'max_tokens' => 80, 'target_field' => 'meta_title'],
                'notes'     => 'Rewrites an existing meta_title to be more SEO-friendly while keeping the product identifiable. Reads {existing_text} (current meta_title).',
                'system_prompt' => 'You are an SEO copywriter specialized in e-commerce. Rewrite meta titles so they are:' . "\n"
                    . "- 45–60 characters\n"
                    . "- include the brand when relevant ({brand})\n"
                    . "- put the most important keyword first\n"
                    . "- use a natural, non-clickbait tone\n"
                    . "- output language: {target_lang}\n"
                    . "- output only the new meta title, no commentary or quotes.",
                'user_prompt' => "Product:\n"
                    . "  Name: {name}\n"
                    . "  Category: {category}\n"
                    . "  Brand: {brand}\n"
                    . "  Current meta title: {existing_text}\n\n"
                    . "Rewrite the meta title.",
            ],

            [
                'slug'      => 'tagging-default',
                'name'      => 'Auto-tagging — default',
                'task_type' => 'tagging',
                'provider'  => 'claude',
                'model'     => 'claude-haiku-4-5',
                'params'    => ['temperature' => 0.3, 'max_tokens' => 120],
                'notes'     => 'Generates 5–10 product tags for internal grouping and front-office search discovery. Output: comma-separated list. Apply replaces all existing tags for the target language.',
                'system_prompt' => 'You assign short tags to e-commerce products. These tags are used:' . "\n"
                    . "- internally, to group products (e.g. for segment filters),\n"
                    . "- on the front-office search, so customers who type those words find the product.\n\n"
                    . "Rules:\n"
                    . "- Output language: {target_lang}\n"
                    . "- 5–10 tags\n"
                    . "- Each tag 1–3 words, max 32 characters\n"
                    . "- Lowercase unless a proper noun\n"
                    . "- No duplicates, no hashtags, no punctuation inside tags\n"
                    . "- Cover: product type, key use case / audience, standout attribute\n"
                    . "- Do NOT include brand or category (they're already structured fields)\n"
                    . "- Output: comma-separated only, no numbering, no commentary",
                'user_prompt' => "Product:\n"
                    . "  Name: {name}\n"
                    . "  Category: {category}\n"
                    . "  Brand: {brand}\n"
                    . "  Features: {features}\n"
                    . "  Short description: {short_description}\n\n"
                    . "Generate tags.",
            ],

            [
                'slug'      => 'alt-text-default',
                'name'      => 'Image alt text — default',
                'task_type' => 'alt_text',
                'provider'  => 'claude',
                'model'     => 'claude-haiku-4-5',
                'params'    => ['temperature' => 0.3, 'max_tokens' => 80],
                'notes'     => 'Initial version. Vision-capable model required at runtime.',
                'system_prompt' => 'You are writing accessibility alt text for product images on an e-commerce store.'
                    . " Constraints:\n"
                    . "- Describe what the image SHOWS, not the product name\n"
                    . "- Maximum 125 characters\n"
                    . "- Output language: {target_lang}\n"
                    . "- No \"image of\", \"picture of\" prefixes\n"
                    . '- Output ONLY the alt text.',
                'user_prompt' => "Product context:\n"
                    . "  Name: {name}\n"
                    . "  Category: {category}\n\n"
                    . 'Write alt text for the image.',
            ],
        ];
    }
}
