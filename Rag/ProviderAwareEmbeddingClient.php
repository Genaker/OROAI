<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag;

use Genaker\Bundle\OroAI\Service\OroAiConfig;

/**
 * Routes embedding calls to the correct client based on the configured LLM provider.
 * gemini → GeminiEmbeddingClient; everything else → OpenAiEmbeddingClient.
 */
final class ProviderAwareEmbeddingClient implements EmbeddingClientInterface
{
    public function __construct(
        private readonly OpenAiEmbeddingClient $openai,
        private readonly GeminiEmbeddingClient $gemini,
        private readonly OroAiConfig $config,
    ) {}

    public function embed(string $text): array
    {
        return $this->client()->embed($text);
    }

    public function embedBatch(array $texts): array
    {
        return $this->client()->embedBatch($texts);
    }

    public function getDimension(): int
    {
        return $this->client()->getDimension();
    }

    private function client(): EmbeddingClientInterface
    {
        return $this->config->getProvider() === 'gemini' ? $this->gemini : $this->openai;
    }
}
