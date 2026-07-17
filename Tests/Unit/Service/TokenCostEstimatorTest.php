<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Service;

use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Genaker\Bundle\OroAI\Service\TokenCostEstimator;
use PHPUnit\Framework\TestCase;

final class TokenCostEstimatorTest extends TestCase
{
    private function estimator(string $configuredModel = 'gemini-2.5-flash'): TokenCostEstimator
    {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('getModel')->willReturn($configuredModel);

        return new TokenCostEstimator($config);
    }

    public function testEstimatesKnownModelAtListPrices(): void
    {
        // gemini-2.5-flash: $0.30/1M input, $2.50/1M output.
        $cost = $this->estimator()->estimate(
            ['prompt_tokens' => 100_000, 'completion_tokens' => 2_000, 'thinking_tokens' => 1_000],
        );

        self::assertSame(0.03, $cost['input']);
        // Thinking is billed as output: (2000 + 1000) / 1M * 2.50.
        self::assertSame(0.0075, $cost['output']);
        self::assertSame(0.0375, $cost['total']);
        self::assertSame('USD', $cost['currency']);
        self::assertTrue($cost['approximate']);
        self::assertSame('gemini-2.5-flash', $cost['model']);
    }

    public function testThinkingTokensArePricedAsOutput(): void
    {
        $withoutThinking = $this->estimator()->estimate(['prompt_tokens' => 0, 'completion_tokens' => 1_000]);
        $withThinking = $this->estimator()->estimate(
            ['prompt_tokens' => 0, 'completion_tokens' => 1_000, 'thinking_tokens' => 1_000],
        );

        self::assertSame($withoutThinking['output'] * 2, $withThinking['output']);
    }

    public function testLongestPrefixWinsOverFamilyFallback(): void
    {
        // gemini-2.5-flash-lite ($0.40/1M out) must NOT match gemini-2.5-flash ($2.50/1M out).
        $cost = $this->estimator()->estimate(
            ['prompt_tokens' => 0, 'completion_tokens' => 1_000_000],
            'gemini-2.5-flash-lite',
        );

        self::assertSame(0.40, $cost['output']);
    }

    public function testUnknownModelFamilyReturnsNull(): void
    {
        self::assertNull(
            $this->estimator()->estimate(['prompt_tokens' => 100], 'llama-3-70b'),
        );
    }

    public function testZeroUsageReturnsNull(): void
    {
        self::assertNull($this->estimator()->estimate([]));
        self::assertNull($this->estimator()->estimate(['prompt_tokens' => 0, 'completion_tokens' => 0]));
    }

    public function testModelDefaultsToConfiguredOne(): void
    {
        $cost = $this->estimator('gpt-4o-mini')->estimate(['prompt_tokens' => 1_000_000]);

        self::assertSame('gpt-4o-mini', $cost['model']);
        self::assertSame(0.15, $cost['input']);
    }
}
