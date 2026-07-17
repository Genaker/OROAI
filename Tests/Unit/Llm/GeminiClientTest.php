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

        // Default bumped from gemini-2.0-flash: Google zeroed out its free-tier
        // quota (429 "limit: 0"), making it unusable as a default.
        self::assertStringContainsString('gemini-2.5-flash', $capturedUrl);
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
        self::assertSame(15, $response->usage['prompt_tokens']);
        self::assertSame(25, $response->usage['completion_tokens']);
        self::assertSame(40, $response->usage['total_tokens']);
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

    public function testChatCapturesThoughtSignatureFromFunctionCallPart(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'functionCall' => ['name' => 'route_search', 'args' => ['keyword' => 'config']],
                                'thoughtSignature' => 'opaque-signature-abc',
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

        self::assertSame('opaque-signature-abc', $response->toolCalls[0]->thoughtSignature);
    }

    public function testChatMapsAssistantToolCallEchoesThoughtSignatureWhenPresent(): void
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

        // Regression guard for: "Function call is missing a thought_signature
        // in functionCall parts" (HTTP 400) — Gemini's "thinking" models
        // require the signature they originally issued to be echoed back
        // verbatim when the assistant's prior tool-calling turn is replayed
        // as history on the next request.
        $assistantMsg = new ChatMessage(
            role: \Genaker\Bundle\OroAI\Core\Model\Role::Assistant,
            content: '',
            toolCalls: [new ToolCall(
                id: 'tc-1',
                name: 'route_search',
                argsJson: '{"keyword":"config"}',
                thoughtSignature: 'opaque-signature-abc',
            )],
        );

        $client = new GeminiClient($httpClient, $this->createConfigMock());
        $client->chat(new LlmRequest(messages: [$assistantMsg]));

        $part = $capturedBody['contents'][0]['parts'][0];
        self::assertSame('opaque-signature-abc', $part['thoughtSignature']);
    }

    public function testChatMapsAssistantToolCallOmitsThoughtSignatureWhenAbsent(): void
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

        $part = $capturedBody['contents'][0]['parts'][0];
        self::assertArrayNotHasKey('thoughtSignature', $part);
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

    // ─────────────────────────────────────────────────────────────
    // Model fallback on persistent 429 (quota limit 0)
    // ─────────────────────────────────────────────────────────────

    /**
     * Real regression case: Google set gemini-2.0-flash's free-tier quota to 0
     * ("limit: 0" — retrying the same model can never succeed), so a persistent
     * 429 must switch to the first fallback model instead of failing.
     */
    public function testPersistent429SwitchesToFallbackModel(): void
    {
        $requestUrls = [];
        $responses = [
            // Verbatim (abridged) body Gemini returns for a zero-quota model.
            new MockResponse(
                '{"error":{"code":429,"message":"You exceeded your current quota, please check your plan'
                . ' and billing details. Quota exceeded for metric:'
                . ' generativelanguage.googleapis.com/generate_content_free_tier_requests, limit: 0,'
                . ' model: gemini-2.0-flash","status":"RESOURCE_EXHAUSTED"}}',
                ['http_code' => 429],
            ),
            new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'ok from fallback']]], 'finishReason' => 'STOP']],
            ])),
        ];

        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$responses, &$requestUrls): MockResponse {
            $requestUrls[] = $url;

            return array_shift($responses);
        });

        $client = new GeminiClient($httpClient, $this->createConfigMock(model: 'gemini-2.0-flash'));
        $response = $client->chat(new LlmRequest(messages: [ChatMessage::user('hi')]));

        self::assertCount(2, $requestUrls);
        self::assertStringContainsString('gemini-2.0-flash', $requestUrls[0]);
        self::assertStringContainsString('gemini-2.5-flash', $requestUrls[1]);
        self::assertSame('ok from fallback', $response->content);
    }

    public function testSuccessfulPrimaryModelMakesNoFallbackRequests(): void
    {
        $requestUrls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestUrls): MockResponse {
            $requestUrls[] = $url;

            return new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            ]));
        });

        $client = new GeminiClient($httpClient, $this->createConfigMock(model: 'gemini-2.0-flash'));
        $client->chat(new LlmRequest(messages: [ChatMessage::user('hi')]));

        self::assertCount(1, $requestUrls);
        self::assertStringContainsString('gemini-2.0-flash', $requestUrls[0]);
    }

    public function testConfiguredFallbackModelsOverrideDefaults(): void
    {
        $requestUrls = [];
        $responses = [
            new MockResponse('{"error":{"code":429}}', ['http_code' => 429]),
            new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            ])),
        ];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$responses, &$requestUrls): MockResponse {
            $requestUrls[] = $url;

            return array_shift($responses);
        });

        $config = $this->createConfigMock(model: 'gemini-2.0-flash', fallbackModels: ['custom-model-x']);
        $client = new GeminiClient($httpClient, $config);
        $client->chat(new LlmRequest(messages: [ChatMessage::user('hi')]));

        self::assertCount(2, $requestUrls);
        self::assertStringContainsString('custom-model-x', $requestUrls[1]);
    }

    /**
     * A configured model that duplicates a default fallback must not be tried
     * twice; when every candidate is quota-limited the client gives up and the
     * 429 surfaces as an exception (handled by ChatController::humanizeError()).
     */
    public function testAllModels429ThrowsAfterTryingEachCandidateOnce(): void
    {
        $requestUrls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestUrls): MockResponse {
            $requestUrls[] = $url;

            return new MockResponse('{"error":{"code":429}}', ['http_code' => 429]);
        });

        // Primary 'gemini-2.5-flash' duplicates the first default fallback →
        // candidates deduplicate to [gemini-2.5-flash, gemini-flash-latest].
        $client = new GeminiClient($httpClient, $this->createConfigMock(model: 'gemini-2.5-flash'));

        try {
            $client->chat(new LlmRequest(messages: [ChatMessage::user('hi')]));
            self::fail('Expected an exception when every model is 429-limited');
        } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $e) {
            self::assertStringContainsString('429', $e->getMessage());
        }

        self::assertCount(2, $requestUrls);
        self::assertStringContainsString('gemini-2.5-flash', $requestUrls[0]);
        self::assertStringContainsString('gemini-flash-latest', $requestUrls[1]);
    }

    private function createConfigMock(
        string $apiKey = 'test-key',
        string $apiUrl = '',
        string $model = '',
        array $fallbackModels = [],
    ): OroAiConfig {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('getApiKey')->willReturn($apiKey);
        $config->method('getApiUrl')->willReturn($apiUrl);
        $config->method('getModel')->willReturn($model);
        $config->method('getFallbackModels')->willReturn($fallbackModels);

        return $config;
    }
}
