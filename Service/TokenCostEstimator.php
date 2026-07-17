<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Service;

/**
 * APPROXIMATE cost estimate for a turn's token usage. LLM APIs report tokens
 * but never money, so this maps usage onto a static list-price table
 * (USD per 1M tokens). Prices drift and negotiated/batch/cached rates differ —
 * every consumer must present the number as an estimate, not billing truth.
 *
 * Thinking/reasoning tokens are priced as OUTPUT — that is how Gemini and
 * the other providers bill them.
 */
final class TokenCostEstimator
{
    /**
     * model prefix => [input $/1M, output $/1M]. Longest prefix wins, so a
     * specific model can override its family default. Update alongside
     * Resources/config/ai_models.yml when providers change list prices.
     */
    private const array PRICES_PER_MILLION = [
        // Gemini
        'gemini-2.5-pro'        => [1.25, 10.00],
        'gemini-2.5-flash-lite' => [0.10, 0.40],
        'gemini-2.5-flash'      => [0.30, 2.50],
        'gemini-2.0-flash'      => [0.10, 0.40],
        'gemini-flash-latest'   => [0.30, 2.50],
        'gemini'                => [0.30, 2.50],   // family fallback
        // OpenAI
        'gpt-4o-mini'           => [0.15, 0.60],
        'gpt-4o'                => [2.50, 10.00],
        'gpt-4.1-mini'          => [0.40, 1.60],
        'gpt-4.1'               => [2.00, 8.00],
        'gpt'                   => [2.50, 10.00],  // family fallback
        // Anthropic
        'claude-haiku'          => [0.80, 4.00],
        'claude-opus'           => [5.00, 25.00],
        'claude-sonnet'         => [3.00, 15.00],
        'claude'                => [3.00, 15.00],  // family fallback
    ];

    public function __construct(
        private readonly OroAiConfig $config,
    ) {
    }

    /**
     * @param array{prompt_tokens?: int, completion_tokens?: int, thinking_tokens?: int} $usage
     * @return array{input: float, output: float, total: float, currency: string,
     *               approximate: true, model: string}|null null when usage is
     *               empty or no price is known for the model
     */
    public function estimate(array $usage, ?string $model = null): ?array
    {
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        // Thinking tokens are billed at the output rate.
        $outputTokens = (int) ($usage['completion_tokens'] ?? 0) + (int) ($usage['thinking_tokens'] ?? 0);
        if ($promptTokens + $outputTokens === 0) {
            return null;
        }

        $model ??= $this->config->getModel();
        $prices = $this->pricesFor($model);
        if ($prices === null) {
            return null;
        }

        $input = $promptTokens / 1_000_000 * $prices[0];
        $output = $outputTokens / 1_000_000 * $prices[1];

        return [
            'input' => round($input, 6),
            'output' => round($output, 6),
            'total' => round($input + $output, 6),
            'currency' => 'USD',
            'approximate' => true,
            'model' => $model,
        ];
    }

    /** @return array{0: float, 1: float}|null [input $/1M, output $/1M] */
    private function pricesFor(string $model): ?array
    {
        $bestPrefix = null;
        foreach (array_keys(self::PRICES_PER_MILLION) as $prefix) {
            if (str_starts_with($model, $prefix)
                && ($bestPrefix === null || strlen($prefix) > strlen($bestPrefix))
            ) {
                $bestPrefix = $prefix;
            }
        }

        return $bestPrefix !== null ? self::PRICES_PER_MILLION[$bestPrefix] : null;
    }
}
