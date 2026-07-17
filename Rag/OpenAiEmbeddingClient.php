<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag;

use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Generates text embeddings via the OpenAI (or compatible) embeddings API. */
final class OpenAiEmbeddingClient implements EmbeddingClientInterface
{
    private const string DEFAULT_URL = 'https://api.openai.com/v1/embeddings';
    private const int DIMENSION = 1536;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OroAiConfig $config,
    ) {
    }

    public function embed(string $text): array
    {
        $response = $this->request($text);

        return $response['data'][0]['embedding'];
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        if (count($texts) === 1) {
            return [$this->embed($texts[0])];
        }

        $response = $this->request($texts);

        usort($response['data'], static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return array_map(static fn (array $item): array => $item['embedding'], $response['data']);
    }

    public function getDimension(): int
    {
        return self::DIMENSION;
    }

    private function request(string|array $input): array
    {
        $apiKey = $this->config->getEmbeddingApiKey();
        if ($apiKey === '') {
            $apiKey = $this->config->getApiKey();
        }

        $url = $this->config->getEmbeddingUrl();
        if ($url === '') {
            $url = self::DEFAULT_URL;
        }

        $model = $this->config->getEmbeddingModel();
        if ($model === '') {
            $model = 'text-embedding-3-small';
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'input' => $input,
            ],
        ]);

        return $response->toArray();
    }
}
