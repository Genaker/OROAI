<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Llm;

use Genaker\Bundle\OroAI\Core\Contract\LlmClientInterface;
use Genaker\Bundle\OroAI\Llm\LlmClientRegistry;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use PHPUnit\Framework\TestCase;

final class LlmClientRegistryTest extends TestCase
{
    public function testGetByNameReturnsCorrectClient(): void
    {
        $openai = $this->createClientMock('openai');
        $anthropic = $this->createClientMock('anthropic');
        $gemini = $this->createClientMock('gemini');

        $registry = new LlmClientRegistry(
            [$openai, $anthropic, $gemini],
            $this->createOroAiConfigMock('openai'),
        );

        self::assertSame($openai, $registry->get('openai'));
        self::assertSame($anthropic, $registry->get('anthropic'));
        self::assertSame($gemini, $registry->get('gemini'));
    }

    public function testGetWithNullReturnsDefaultFromConfig(): void
    {
        $openai = $this->createClientMock('openai');
        $anthropic = $this->createClientMock('anthropic');

        $registry = new LlmClientRegistry(
            [$openai, $anthropic],
            $this->createOroAiConfigMock('anthropic'),
        );

        self::assertSame($anthropic, $registry->get(null));
        self::assertSame($anthropic, $registry->get());
    }

    /**
     * Regression guard: the provider must be resolved via OroAiConfig::getProvider()
     * (which checks the OROAI_PROVIDER env var before falling back to the DB config),
     * not by reading the DB config value directly. Reading it directly silently
     * ignored OROAI_PROVIDER and always fell back to "openai" for actual chat
     * completions whenever the DB value had never been set -- even when the env var
     * (and every other part of the app) was correctly configured for a different
     * provider.
     */
    public function testGetWithNullDefaultsToOpenaiWhenConfigIsUnset(): void
    {
        $openai = $this->createClientMock('openai');

        $registry = new LlmClientRegistry([$openai], $this->createOroAiConfigMock('openai'));

        self::assertSame($openai, $registry->get());
    }

    public function testGetWithUnknownNameThrowsInvalidArgumentException(): void
    {
        $openai = $this->createClientMock('openai');

        $registry = new LlmClientRegistry(
            [$openai],
            $this->createOroAiConfigMock('openai'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown LLM client "nonexistent"');

        $registry->get('nonexistent');
    }

    public function testGetAvailableNames(): void
    {
        $openai = $this->createClientMock('openai');
        $anthropic = $this->createClientMock('anthropic');
        $gemini = $this->createClientMock('gemini');

        $registry = new LlmClientRegistry(
            [$openai, $anthropic, $gemini],
            $this->createOroAiConfigMock('openai'),
        );

        self::assertSame(['openai', 'anthropic', 'gemini'], $registry->getAvailableNames());
    }

    public function testGetAvailableNamesEmptyRegistry(): void
    {
        $registry = new LlmClientRegistry([], $this->createOroAiConfigMock('openai'));
        self::assertSame([], $registry->getAvailableNames());
    }

    public function testExceptionMessageIncludesAvailableNames(): void
    {
        $openai = $this->createClientMock('openai');
        $anthropic = $this->createClientMock('anthropic');

        $registry = new LlmClientRegistry(
            [$openai, $anthropic],
            $this->createOroAiConfigMock('openai'),
        );

        try {
            $registry->get('gpt');
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('openai', $e->getMessage());
            self::assertStringContainsString('anthropic', $e->getMessage());
        }
    }

    private function createClientMock(string $name): LlmClientInterface
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->method('getName')->willReturn($name);

        return $client;
    }

    private function createOroAiConfigMock(string $provider): OroAiConfig
    {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('getProvider')->willReturn($provider);

        return $config;
    }
}
