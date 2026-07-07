<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Llm;

use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\ToolCall;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Llm\GeminiClient;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeminiClientTest extends TestCase
{
    public function testGetNameReturnsGemini(): void
    {
        $client = new GeminiClient(new MockHttpClient(), $this->createConfigMock());
        self::assertSame('gemini', $client->getName());
    }

    public function testChatBuildsCorrectRequestFormat(): void
    {
        $capturedRequest = null;

        $mockResponse = new MockResponse(json_encode([
            'candidates' => [
                [
                    'content' => ['parts' => [['text' => 'Hello!']]],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedRequest): MockResponse {
            $capturedRequest = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return $mockResponse;
        });

        $config = $this->createConfigMock('gemini-key-abc', '', 'gemini-1.5-pro');

        $request = new LlmRequest(
            messages: [
                ChatMessage::system('Be concise.'),
                ChatMessage::user('What is OroCommerce?'),
            ],
            tools: [
                new ToolDefinition('search', 'Search docs', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]),
            ],
            temperature: 0.4,
            maxTokens: 512,
        );

        $client = new GeminiClient($httpClient, $config);
        $client->chat($request);

        self::assertNotNull($capturedRequest);
        self::assertSame('POST', $capturedRequest['method']);

        // URL should contain model name and API key
        self::assertStringContainsString('gemini-1.5-pro', $capturedRequest['url']);
        self::assertStringContainsString('key=gemini-key-abc', $capturedRequest['url']);
        self::assertStringContainsString(':generateContent', $capturedRequest['url']);

        $body = json_decode($capturedRequest['options']['body'], true);

        // System messages extracted to systemInstruction
        self::assertArrayHasKey('systemInstruction', $body);
        self::assertSame([['text' => 'Be concise.']], $body['systemInstruction']['parts']);

        // Only non-system messages in contents
        self::assertCount(1, $body['contents']);
        self::assertSame('user', $body['contents'][0]['role']);
        self::assertSame([['text' => 'What is OroCommerce?']], $body['contents'][0]['parts']);

        // Generation config
        self::assertSame(0.4, $body['generationConfig']['temperature']);
        self::assertSame(512, $body['generationConfig']['maxOutputTokens']);

        // Tools in Gemini format
        self::assertCount(1, $body['tools']);
        self::assertArrayHasKey('functionDeclarations', $body['tools'][0]);
        self::assertSame('search', $body['tools'][0]['functionDeclarations'][0]['name']);
        self::assertSame('Search docs', $body['tools'][0]['functionDeclarations'][0]['description']);
    }

    public function testChatUsesDefaultModel(): void
    {
        $capturedUrl = null;

        $mockResponse = new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            'usageMetadata' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url) use ($mockResponse, &$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return $mockResponse;
        });

        $config = $this->createConfigMock('key', '', '');

        $client = new GeminiClient($httpClient, $config);
        $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertStringContainsString('gemini-2.0-flash', $capturedUrl);
    }

    public function testChatParsesTextPartsResponse(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Part one. '],
                            ['text' => 'Part two.'],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 15, 'candidatesTokenCount' => 25],
        ]));

        $client = new GeminiClient(new MockHttpClient($mockResponse), $this->createConfigMock());
        $response = $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertSame('Part one. Part two.', $response->content);
        self::assertSame([], $response->toolCalls);
        self::assertSame('STOP', $response->finishReason);
        self::assertSame(15, $response->usage['promptTokenCount']);
    }

    public function testChatParsesFunctionCallParts(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'sql_query',
                                    'args' => ['sql' => 'SELECT COUNT(*) FROM orders'],
                                ],
                            ],
                            [
                                'functionCall' => [
                                    'name' => 'entity_url',
                                    'args' => ['entity' => 'product'],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [],
        ]));

        $client = new GeminiClient(new MockHttpClient($mockResponse), $this->createConfigMock());
        $response = $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertSame('', $response->content);
        self::assertCount(2, $response->toolCalls);

        self::assertSame('sql_query', $response->toolCalls[0]->name);
        $args0 = json_decode($response->toolCalls[0]->argsJson, true);
        self::assertSame('SELECT COUNT(*) FROM orders', $args0['sql']);

        self::assertSame('entity_url', $response->toolCalls[1]->name);
        $args1 = json_decode($response->toolCalls[1]->argsJson, true);
        self::assertSame('product', $args1['entity']);

        // IDs should be UUID-like strings
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $response->toolCalls[0]->id,
        );
    }

    public function testChatOmitsSystemInstructionWhenNoSystemMessages(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            'usageMetadata' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $client = new GeminiClient($httpClient, $this->createConfigMock());
        $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertArrayNotHasKey('systemInstruction', $capturedBody);
    }

    public function testChatOmitsToolsWhenEmpty(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            'usageMetadata' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $client = new GeminiClient($httpClient, $this->createConfigMock());
        $client->chat(new LlmRequest(messages: [ChatMessage::user('test')], tools: []));

        self::assertArrayNotHasKey('tools', $capturedBody);
    }

    public function testChatOmitsMaxOutputTokensWhenNull(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            'usageMetadata' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $client = new GeminiClient($httpClient, $this->createConfigMock());
        $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertArrayNotHasKey('maxOutputTokens', $capturedBody['generationConfig']);
    }

    public function testChatMapsAssistantWithToolCallsToFunctionCallParts(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            'usageMetadata' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $assistantMsg = new ChatMessage(
            role: \Genaker\Bundle\OroAI\Core\Model\Role::Assistant,
            content: '',
            toolCalls: [new ToolCall(id: 'tc-1', name: 'sql_query', argsJson: '{"sql":"SELECT 1"}')],
        );

        $client = new GeminiClient($httpClient, $this->createConfigMock());
        $client->chat(new LlmRequest(messages: [$assistantMsg]));

        $msg = $capturedBody['contents'][0];
        self::assertSame('model', $msg['role']);
        self::assertArrayHasKey('functionCall', $msg['parts'][0]);
        self::assertSame('sql_query', $msg['parts'][0]['functionCall']['name']);
        self::assertSame(['sql' => 'SELECT 1'], $msg['parts'][0]['functionCall']['args']);
    }

    public function testChatMapsToolMessageToFunctionResponse(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            'usageMetadata' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $toolMsg = new ChatMessage(
            role: \Genaker\Bundle\OroAI\Core\Model\Role::Tool,
            content: '{"success":true,"data":[1,2,3]}',
            name: 'sql_query',
        );

        $client = new GeminiClient($httpClient, $this->createConfigMock());
        $client->chat(new LlmRequest(messages: [$toolMsg]));

        $msg = $capturedBody['contents'][0];
        self::assertSame('user', $msg['role']);
        self::assertArrayHasKey('functionResponse', $msg['parts'][0]);
        self::assertSame('sql_query', $msg['parts'][0]['functionResponse']['name']);
    }

    private function createConfigMock(
        string $apiKey = 'test-key',
        string $apiUrl = '',
        string $model = '',
    ): OroAiConfig {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('getApiKey')->willReturn($apiKey);
        $config->method('getApiUrl')->willReturn($apiUrl);
        $config->method('getModel')->willReturn($model);

        return $config;
    }
}
