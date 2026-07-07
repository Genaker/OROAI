<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Llm;

use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\LlmResponse;
use Genaker\Bundle\OroAI\Core\Model\ToolCall;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Llm\AnthropicClient;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AnthropicClientTest extends TestCase
{
    public function testGetNameReturnsAnthropic(): void
    {
        $client = new AnthropicClient(new MockHttpClient(), $this->createConfigMock());
        self::assertSame('anthropic', $client->getName());
    }

    public function testChatBuildsCorrectRequestFormat(): void
    {
        $capturedRequest = null;

        $mockResponse = new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => 'Hello!']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedRequest): MockResponse {
            $capturedRequest = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return $mockResponse;
        });

        $config = $this->createConfigMock('anthropic-key-123', 'https://custom.anthropic/v1/messages', 'claude-3-opus');

        $request = new LlmRequest(
            messages: [
                ChatMessage::system('Be concise.'),
                ChatMessage::system('You are OroAI.'),
                ChatMessage::user('Hi'),
            ],
            tools: [
                new ToolDefinition('my_tool', 'Does stuff', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]),
            ],
            temperature: 0.7,
            maxTokens: 2048,
        );

        $client = new AnthropicClient($httpClient, $config);
        $client->chat($request);

        self::assertNotNull($capturedRequest);
        self::assertSame('POST', $capturedRequest['method']);
        self::assertSame('https://custom.anthropic/v1/messages', $capturedRequest['url']);

        $headers = $capturedRequest['options']['headers'];
        self::assertContains('x-api-key: anthropic-key-123', $headers);
        self::assertContains('anthropic-version: 2023-06-01', $headers);

        $body = json_decode($capturedRequest['options']['body'], true);
        self::assertSame('claude-3-opus', $body['model']);
        self::assertSame(2048, $body['max_tokens']);
        self::assertSame(0.7, $body['temperature']);

        // System messages should be extracted and concatenated
        self::assertSame("Be concise.\n\nYou are OroAI.", $body['system']);

        // Only user message should remain in messages array
        self::assertCount(1, $body['messages']);
        self::assertSame('user', $body['messages'][0]['role']);
        self::assertSame('Hi', $body['messages'][0]['content']);

        // Tools mapped to Anthropic format
        self::assertCount(1, $body['tools']);
        self::assertSame('my_tool', $body['tools'][0]['name']);
        self::assertSame('Does stuff', $body['tools'][0]['description']);
        self::assertArrayHasKey('input_schema', $body['tools'][0]);
    }

    public function testChatUsesDefaultUrlAndModel(): void
    {
        $capturedUrl = null;
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedUrl, &$capturedBody): MockResponse {
            $capturedUrl = $url;
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $config = $this->createConfigMock('key', '', '');

        $client = new AnthropicClient($httpClient, $config);
        $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertSame('https://api.anthropic.com/v1/messages', $capturedUrl);
        self::assertSame('claude-sonnet-4-20250514', $capturedBody['model']);
    }

    public function testChatUsesDefaultMaxTokens(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $client = new AnthropicClient($httpClient, $this->createConfigMock());
        $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertSame(4096, $capturedBody['max_tokens']);
    }

    public function testChatParsesTextContentResponse(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'content' => [
                ['type' => 'text', 'text' => 'First part. '],
                ['type' => 'text', 'text' => 'Second part.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 30],
        ]));

        $client = new AnthropicClient(new MockHttpClient($mockResponse), $this->createConfigMock());
        $response = $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertSame('First part. Second part.', $response->content);
        self::assertSame([], $response->toolCalls);
        self::assertSame('end_turn', $response->finishReason);
        self::assertSame(20, $response->usage['input_tokens']);
    }

    public function testChatParsesToolUseBlocks(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'content' => [
                ['type' => 'text', 'text' => 'I will query the database.'],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_abc123',
                    'name' => 'sql_query',
                    'input' => ['sql' => 'SELECT * FROM orders LIMIT 10'],
                ],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_def456',
                    'name' => 'entity_url',
                    'input' => ['entity' => 'order', 'action' => 'index'],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 100],
        ]));

        $client = new AnthropicClient(new MockHttpClient($mockResponse), $this->createConfigMock());
        $response = $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertSame('I will query the database.', $response->content);
        self::assertCount(2, $response->toolCalls);

        self::assertSame('toolu_abc123', $response->toolCalls[0]->id);
        self::assertSame('sql_query', $response->toolCalls[0]->name);
        $args0 = json_decode($response->toolCalls[0]->argsJson, true);
        self::assertSame('SELECT * FROM orders LIMIT 10', $args0['sql']);

        self::assertSame('toolu_def456', $response->toolCalls[1]->id);
        self::assertSame('entity_url', $response->toolCalls[1]->name);

        self::assertSame('tool_use', $response->finishReason);
    }

    public function testChatMapsToolResultAsUserRoleWithToolResultContent(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => 'done']],
            'stop_reason' => 'end_turn',
            'usage' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $request = new LlmRequest(messages: [
            ChatMessage::toolResult('toolu_abc', '{"success":true}', 'sql_query'),
        ]);

        $client = new AnthropicClient($httpClient, $this->createConfigMock());
        $client->chat($request);

        $msg = $capturedBody['messages'][0];
        self::assertSame('user', $msg['role']);
        self::assertIsArray($msg['content']);
        self::assertSame('tool_result', $msg['content'][0]['type']);
        self::assertSame('toolu_abc', $msg['content'][0]['tool_use_id']);
        self::assertSame('{"success":true}', $msg['content'][0]['content']);
    }

    public function testChatMapsAssistantWithToolCallsToContentBlocks(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $toolCalls = [new ToolCall(id: 'tc-1', name: 'sql_query', argsJson: '{"sql":"SELECT 1"}')];
        $assistantMsg = new ChatMessage(
            role: \Genaker\Bundle\OroAI\Core\Model\Role::Assistant,
            content: 'Let me check.',
            toolCalls: $toolCalls,
        );

        $request = new LlmRequest(messages: [$assistantMsg]);

        $client = new AnthropicClient($httpClient, $this->createConfigMock());
        $client->chat($request);

        $msg = $capturedBody['messages'][0];
        self::assertSame('assistant', $msg['role']);
        self::assertIsArray($msg['content']);
        // First block should be text
        self::assertSame('text', $msg['content'][0]['type']);
        self::assertSame('Let me check.', $msg['content'][0]['text']);
        // Second block should be tool_use
        self::assertSame('tool_use', $msg['content'][1]['type']);
        self::assertSame('tc-1', $msg['content'][1]['id']);
        self::assertSame('sql_query', $msg['content'][1]['name']);
        self::assertSame(['sql' => 'SELECT 1'], $msg['content'][1]['input']);
    }

    public function testChatOmitsSystemKeyWhenNoSystemMessages(): void
    {
        $capturedBody = null;

        $mockResponse = new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => [],
        ]));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedBody): MockResponse {
            $capturedBody = json_decode($options['body'], true);

            return $mockResponse;
        });

        $client = new AnthropicClient($httpClient, $this->createConfigMock());
        $client->chat(new LlmRequest(messages: [ChatMessage::user('test')]));

        self::assertArrayNotHasKey('system', $capturedBody);
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
