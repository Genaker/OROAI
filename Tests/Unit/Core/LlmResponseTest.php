<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Core;

use Genaker\Bundle\OroAI\Core\Model\LlmResponse;
use PHPUnit\Framework\TestCase;

final class LlmResponseTest extends TestCase
{
    public function testNormalizeUsageMapsProviderSpecificKeys(): void
    {
        $usage = LlmResponse::normalizeUsage(
            ['input_tokens' => 20, 'output_tokens' => 30],
            'input_tokens',
            'output_tokens',
        );

        self::assertSame(
            ['prompt_tokens' => 20, 'completion_tokens' => 30, 'total_tokens' => 50, 'thinking_tokens' => 0],
            $usage,
        );
    }

    public function testNormalizeUsageUsesExplicitTotalKeyWhenPresent(): void
    {
        $usage = LlmResponse::normalizeUsage(
            ['promptTokenCount' => 15, 'candidatesTokenCount' => 25, 'totalTokenCount' => 999],
            'promptTokenCount',
            'candidatesTokenCount',
            'totalTokenCount',
        );

        // The provider's own total (which may include cached/thinking tokens
        // beyond prompt+completion) wins over the computed sum.
        self::assertSame(999, $usage['total_tokens']);
    }

    public function testNormalizeUsageFallsBackToSumWhenTotalKeyMissing(): void
    {
        $usage = LlmResponse::normalizeUsage(
            ['promptTokenCount' => 15, 'candidatesTokenCount' => 25],
            'promptTokenCount',
            'candidatesTokenCount',
            'totalTokenCount',
        );

        self::assertSame(40, $usage['total_tokens']);
    }

    public function testNormalizeUsageDefaultsToZeroForEmptyPayload(): void
    {
        $usage = LlmResponse::normalizeUsage([], 'prompt_tokens', 'completion_tokens', 'total_tokens');

        self::assertSame(
            ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'thinking_tokens' => 0],
            $usage,
        );
    }

    public function testNormalizeUsageCapturesThinkingTokens(): void
    {
        // Gemini 2.5 reports reasoning cost as usageMetadata.thoughtsTokenCount.
        $usage = LlmResponse::normalizeUsage(
            ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 42],
            'promptTokenCount',
            'candidatesTokenCount',
            null,
            'thoughtsTokenCount',
        );

        self::assertSame(42, $usage['thinking_tokens']);
    }

    public function testSumUsageAccumulatesThinkingTokens(): void
    {
        $sum = LlmResponse::sumUsage(['thinking_tokens' => 10], ['thinking_tokens' => 15]);

        self::assertSame(25, $sum['thinking_tokens']);
    }
}
