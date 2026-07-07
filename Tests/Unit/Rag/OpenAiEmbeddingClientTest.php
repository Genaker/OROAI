<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Rag;

use Genaker\Bundle\OroAI\Rag\OpenAiEmbeddingClient;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiEmbeddingClientTest extends TestCase
{
    public function testEmbedReturnsFloatArray(): void
    {
        $embedding = array_map(static fn (int $i): float => $i * 0.001, range(0, 1535));

        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['embedding' => $embedding, 'index' => 0],
            ],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ]));

        $client = new OpenAiEmbeddingClient(
            new MockHttpClient($mockResponse),
            $this->createConfigMock(),
        );

        $result = $client->embed('Hello, world!');

        self::assertCount(1536, $result);
        // JSON decodes 0 as int, 0.001 as float; both are valid for embedding vectors
        self::assertEquals(0, $result[0]);
        self::assertEquals(0.001, $result[1]);
        self::assertIsNumeric($result[0]);
        self::assertIsFloat($result[1]);
    }

    public function testGetDimensionReturns1536(): void
    {
        $client = new OpenAiEmbeddingClient(
            new MockHttpClient(),
            $this->createConfigMock(),
        );

        self::assertSame(1536, $client->getDimension());
    }

    public function testEmbedBatchReturnsSortedEmbeddings(): void
    {
        $embedding1 = array_fill(0, 1536, 0.1);
        $embedding2 = array_fill(0, 1536, 0.2);

        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['embedding' => $embedding2, 'index' => 1],
                ['embedding' => $embedding1, 'index' => 0],
            ],
        ]));

        $client = new OpenAiEmbeddingClient(
            new MockHttpClient($mockResponse),
            $this->createConfigMock(),
        );

        $result = $client->embedBatch(['text one', 'text two']);

        self::assertCount(2, $result);
        // First result should be embedding1 (index 0), not embedding2
        self::assertSame(0.1, $result[0][0]);
        self::assertSame(0.2, $result[1][0]);
    }

    public function testEmbedBatchWithEmptyArrayReturnsEmpty(): void
    {
        $client = new OpenAiEmbeddingClient(
            new MockHttpClient(),
            $this->createConfigMock(),
        );

        $result = $client->embedBatch([]);

        self::assertSame([], $result);
    }

    public function testEmbedBatchWithSingleItemUsesEmbed(): void
    {
        $embedding = array_fill(0, 1536, 0.5);

        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['embedding' => $embedding, 'index' => 0],
            ],
        ]));

        $client = new OpenAiEmbeddingClient(
            new MockHttpClient($mockResponse),
            $this->createConfigMock(),
        );

        $result = $client->embedBatch(['single text']);

        self::assertCount(1, $result);
        self::assertCount(1536, $result[0]);
        self::assertSame(0.5, $result[0][0]);
    }

    public function testUsesCustomApiKeyAndUrl(): void
    {
        $capturedUrl = null;
        $capturedHeaders = null;

        $mockResponse = new MockResponse(json_encode([
            'data' => [['embedding' => array_fill(0, 1536, 0.0), 'index' => 0]],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedUrl, &$capturedHeaders): MockResponse {
            $capturedUrl = $url;
            $capturedHeaders = $options['headers'] ?? [];

            return $mockResponse;
        });

        $config = $this->createConfigMock(
            embeddingApiKey: 'custom-embed-key',
            embeddingUrl: 'https://custom.embed/v1/embeddings',
            embeddingModel: 'text-embedding-ada-002',
        );

        $client = new OpenAiEmbeddingClient($httpClient, $config);
        $client->embed('test');

        self::assertSame('https://custom.embed/v1/embeddings', $capturedUrl);
        self::assertContains('Authorization: Bearer custom-embed-key', $capturedHeaders);
    }

    public function testFallsBackToMainApiKeyWhenEmbeddingKeyIsEmpty(): void
    {
        $capturedHeaders = null;

        $mockResponse = new MockResponse(json_encode([
            'data' => [['embedding' => array_fill(0, 1536, 0.0), 'index' => 0]],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedHeaders): MockResponse {
            $capturedHeaders = $options['headers'] ?? [];

            return $mockResponse;
        });

        $config = $this->createConfigMock(
            apiKey: 'main-api-key',
            embeddingApiKey: '',
        );

        $client = new OpenAiEmbeddingClient($httpClient, $config);
        $client->embed('test');

        self::assertContains('Authorization: Bearer main-api-key', $capturedHeaders);
    }

    public function testUsesDefaultUrlWhenEmbeddingUrlIsEmpty(): void
    {
        $capturedUrl = null;

        $mockResponse = new MockResponse(json_encode([
            'data' => [['embedding' => array_fill(0, 1536, 0.0), 'index' => 0]],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url) use ($mockResponse, &$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return $mockResponse;
        });

        $config = $this->createConfigMock(embeddingUrl: '');

        $client = new OpenAiEmbeddingClient($httpClient, $config);
        $client->embed('test');

        self::assertSame('https://api.openai.com/v1/embeddings', $capturedUrl);
    }

    public function testUsesDefaultModelWhenEmbeddingModelIsEmpty(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'data' => [['embedding' => array_fill(0, 1536, 0.0), 'index' => 0]],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $config = $this->createConfigMock(embeddingModel: '');

        $client = new OpenAiEmbeddingClient($httpClient, $config);
        $client->embed('test');

        self::assertSame('text-embedding-3-small', $capturedBody['model']);
    }

    public function testSendsCorrectRequestBody(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'data' => [['embedding' => array_fill(0, 1536, 0.0), 'index' => 0]],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $config = $this->createConfigMock(embeddingModel: 'text-embedding-3-large');

        $client = new OpenAiEmbeddingClient($httpClient, $config);
        $client->embed('Test input text');

        self::assertSame('text-embedding-3-large', $capturedBody['model']);
        self::assertSame('Test input text', $capturedBody['input']);
    }

    private function createConfigMock(
        string $apiKey = 'test-key',
        string $embeddingApiKey = 'embed-key',
        string $embeddingUrl = '',
        string $embeddingModel = '',
    ): OroAiConfig {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('getApiKey')->willReturn($apiKey);
        $config->method('getEmbeddingApiKey')->willReturn($embeddingApiKey);
        $config->method('getEmbeddingUrl')->willReturn($embeddingUrl);
        $config->method('getEmbeddingModel')->willReturn($embeddingModel);

        return $config;
    }
}
