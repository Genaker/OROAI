<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Llm;

use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Llm\OpenAiClient;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiClientTest extends TestCase
{
    public function testGetNameReturnsOpenai(): void
    {
        $client = new OpenAiClient(new MockHttpClient(), $this->createConfigMock());
        self::assertSame('openai', $client->getName());
    }

    public function testChatBuildsCorrectRequestFormat(): void
    {
        $capturedRequest = null;

        $mockResponse = new MockResponse(json_encode([
            'choices' => [
                [
                    'message' => ['content' => 'Hello!', 'role' => 'assistant'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedRequest): MockResponse {
            $capturedRequest = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return $mockResponse;
        });

        $config = $this->createConfigMock('test-key-123', 'https://custom.api/v1/chat', 'gpt-4o');

        $request = new LlmRequest(
            messages: [
                ChatMessage::system('Be helpful.'),
                ChatMessage::user('Hi'),
            ],
            tools: [
                new ToolDefinition('my_tool', 'A test tool', ['type' => 'object', 'properties' => []]),
            ],
            temperature: 0.5,
            maxTokens: 1000,
        );

        $client = new OpenAiClient($httpClient, $config);
        $client->chat($request);

        self::assertNotNull($capturedRequest);
        self::assertSame('POST', $capturedRequest['method']);
        self::assertSame('https://custom.api/v1/chat', $capturedRequest['url']);

        // Check authorization header
        self::assertArrayHasKey('headers', $capturedRequest['options']);
        $headers = $capturedRequest['options']['headers'];
        self::assertContains('Authorization: Bearer test-key-123', $headers);

        // Check JSON body
        $body = json_decode($capturedRequest['options']['body'], true);
        self::assertSame('gpt-4o', $body['model']);
        self::assertSame(0.5, $body['temperature']);
        self::assertSame(1000, $body['max_tokens']);

        // Check messages
        self::assertCount(2, $body['messages']);
        self::assertSame('system', $body['messages'][0]['role']);
        self::assertSame('Be helpful.', $body['messages'][0]['content']);
        self::assertSame('user', $body['messages'][1]['role']);
        self::assertSame('Hi', $body['messages'][1]['content']);

        // Check tools
        self::assertCount(1, $body['tools']);
        self::assertSame('function', $body['tools'][0]['type']);
        self::assertSame('my_tool', $body['tools'][0]['function']['name']);
        self::assertSame('A test tool', $body['tools'][0]['function']['description']);
    }

    public function testChatUsesDefaultUrlAndModel(): void
    {
        $capturedUrl = null;

        $mockResponse = new MockResponse(json_encode([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url) use ($mockResponse, &$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return $mockResponse;
        });

        $config = $this->createConfigMock('key', '', '');

        $request = new LlmRequest(messages: [ChatMessage::user('test')]);

        $client = new OpenAiClient($httpClient, $config);
        $client->chat($request);

        self::assertSame('https://api.openai.com/v1/chat/completions', $capturedUrl);
    }

    public function testChatParsesContentOnlyResponse(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => 'The answer is 42.',
                        'role' => 'assistant',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 10, 'total_tokens' => 15],
        ]));

        $client = new OpenAiClient(new MockHttpClient($mockResponse), $this->createConfigMock());
        $response = $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertSame('The answer is 42.', $response->content);
        self::assertSame([], $response->toolCalls);
        self::assertSame('stop', $response->finishReason);
        self::assertSame(5, $response->usage['prompt_tokens']);
        self::assertSame(10, $response->usage['completion_tokens']);
        self::assertSame(15, $response->usage['total_tokens']);
    }

    public function testChatParsesResponseWithToolCalls(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => null,
                        'role' => 'assistant',
                        'tool_calls' => [
                            [
                                'id' => 'call_abc123',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'sql_query',
                                    'arguments' => '{"sql":"SELECT * FROM users LIMIT 5"}',
                                ],
                            ],
                            [
                                'id' => 'call_def456',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'entity_url',
                                    'arguments' => '{"entity":"order","action":"index"}',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
        ]));

        $client = new OpenAiClient(new MockHttpClient($mockResponse), $this->createConfigMock());
        $response = $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertSame('', $response->content);
        self::assertCount(2, $response->toolCalls);

        self::assertSame('call_abc123', $response->toolCalls[0]->id);
        self::assertSame('sql_query', $response->toolCalls[0]->name);
        self::assertSame('{"sql":"SELECT * FROM users LIMIT 5"}', $response->toolCalls[0]->argsJson);

        self::assertSame('call_def456', $response->toolCalls[1]->id);
        self::assertSame('entity_url', $response->toolCalls[1]->name);

        self::assertSame('tool_calls', $response->finishReason);
    }

    public function testChatWithoutToolsOmitsToolsKey(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $client = new OpenAiClient($httpClient, $this->createConfigMock());
        $client->chat(new LlmRequest(messages: [ChatMessage::user('test')], tools: []));

        self::assertArrayNotHasKey('tools', $capturedBody);
    }

    public function testChatWithoutMaxTokensOmitsKey(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $client = new OpenAiClient($httpClient, $this->createConfigMock());
        $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertArrayNotHasKey('max_tokens', $capturedBody);
    }

    public function testChatMapsToolResultMessage(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $request = new LlmRequest(messages: [
            ChatMessage::toolResult('call-abc', '{"success":true,"data":"test"}', 'sql_query'),
        ]);

        $client = new OpenAiClient($httpClient, $this->createConfigMock());
        $client->chat($request);

        $toolMsg = $capturedBody['messages'][0];
        self::assertSame('tool', $toolMsg['role']);
        self::assertSame('call-abc', $toolMsg['tool_call_id']);
        self::assertSame('{"success":true,"data":"test"}', $toolMsg['content']);
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
