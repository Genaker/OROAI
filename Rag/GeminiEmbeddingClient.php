<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag;

use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Embedding client backed by the Google Gemini embedContent / batchEmbedContents API.
 *
 * Auth: ?key=API_KEY query parameter (not Bearer).
 * Default model: gemini-embedding-001 (3072 dimensions).
 */
final class GeminiEmbeddingClient implements EmbeddingClientInterface
{
    private const string BASE_URL       = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const string DEFAULT_MODEL  = 'gemini-embedding-001';
    private const int    DIMENSION      = 3072;
    // Gemini batchEmbedContents hard cap
    private const int    BATCH_LIMIT    = 100;
    // ~2048 token safety limit (≈ 6 chars/token)
    private const int    MAX_CHARS      = 12000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OroAiConfig $config,
    ) {
    }

    public function embed(string $text): array
    {
        [$model, $apiKey] = $this->modelAndKey();
        $url     = self::BASE_URL . $model . ':embedContent?key=' . $apiKey;
        $options = [
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => ['content' => ['parts' => [['text' => $text]]]],
            'timeout' => 30,
        ];

        $delay = 5;
        for ($attempt = 0; $attempt <= 3; $attempt++) {
            $response = $this->httpClient->request('POST', $url, $options);
            if ($response->getStatusCode() !== 429 || $attempt === 3) {
                return $response->toArray()['embedding']['values'];
            }
            sleep($delay);
            $delay *= 2;
        }

        throw new \RuntimeException('Gemini embed: exhausted retries');
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }
        if (count($texts) === 1) {
            return [$this->embed($texts[0])];
        }

        // Gemini batchEmbedContents is capped at 100 requests per call.
        // Pace requests to avoid 429 rate limits (~1 s between chunks).
        $chunks = array_chunk($texts, self::BATCH_LIMIT);
        $results = [];
        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                sleep(1);
            }
            foreach ($this->embedChunk($chunk) as $vector) {
                $results[] = $vector;
            }
        }

        return $results;
    }

    /**
     * @return float[][]
     * Retries up to 3 times with exponential backoff on HTTP 429.
     */
    private function embedChunk(array $texts): array
    {
        [$model, $apiKey] = $this->modelAndKey();
        $url = self::BASE_URL . $model . ':batchEmbedContents?key=' . $apiKey;

        $payload = [
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => [
                'requests' => array_map(
                    fn (string $text): array => [
                        'model'   => 'models/' . $model,
                        'content' => ['parts' => [['text' => $this->truncate($text)]]],
                    ],
                    $texts,
                ),
            ],
            'timeout' => 60,
        ];

        $delay = 5;
        for ($attempt = 0; $attempt <= 3; $attempt++) {
            $response = $this->httpClient->request('POST', $url, $payload);
            $status   = $response->getStatusCode();

            if ($status === 429 && $attempt < 3) {
                sleep($delay);
                $delay *= 2;
                continue;
            }

            return array_map(
                static fn (array $item): array => $item['values'],
                $response->toArray()['embeddings'],
            );
        }

        // unreachable, but satisfies static analysis
        throw new \RuntimeException('Gemini embedChunk: exhausted retries');
    }

    public function getDimension(): int
    {
        return self::DIMENSION;
    }

    private function truncate(string $text): string
    {
        return mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS) : $text;
    }

    /** @return array{0: string, 1: string} [model, apiKey] */
    private function modelAndKey(): array
    {
        $model = $this->config->getEmbeddingModel();
        if ($model === '' || $model === 'text-embedding-3-small') {
            $model = self::DEFAULT_MODEL;
        }

        $key = $this->config->getEmbeddingApiKey();
        if ($key === '') {
            $key = $this->config->getApiKey();
        }

        return [$model, $key];
    }
}
