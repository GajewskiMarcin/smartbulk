<?php
/**
 * SmartBulk — AIProviderInterface
 *
 * Abstract contract over Claude / OpenAI / future providers. All providers return
 * an AIResponse with identical shape so higher-level code doesn't care which LLM ran.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Ai;

interface AIProviderInterface
{
    public function getName(): string;

    /**
     * @param array{model?:string,temperature?:float,max_tokens?:int,top_p?:float} $params
     */
    public function generate(string $systemPrompt, string $userPrompt, array $params): AIResponse;
}
