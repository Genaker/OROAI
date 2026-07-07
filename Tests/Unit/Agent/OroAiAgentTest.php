<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent;

use Genaker\Bundle\OroAI\Agent\OroAiAgent;
use Genaker\Bundle\OroAI\Agent\ToolRegistry;
use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Contract\LlmClientInterface;
use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\LlmResponse;
use Genaker\Bundle\OroAI\Core\Model\Role;
use Genaker\Bundle\OroAI\Core\Model\ToolCall;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Genaker\Bundle\OroAI\Llm\LlmClientRegistry;
use Genaker\Bundle\OroAI\Rag\RagHit;
use Genaker\Bundle\OroAI\Rag\RagStoreInterface;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class OroAiAgentTest extends TestCase
{
    private LlmClientRegistry&MockObject $clientRegistry;
    private ToolRegistry $toolRegistry;
    private RagStoreInterface&MockObject $ragStore;
    private OroAiConfig&MockObject $config;
    private LlmClientInterface&MockObject $llmClient;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LlmClientInterface::class);

        $this->clientRegistry = $this->createMock(LlmClientRegistry::class);
        $this->clientRegistry->method('get')->willReturn($this->llmClient);

        $this->ragStore = $this->createMock(RagStoreInterface::class);

        $this->config = $this->createMock(OroAiConfig::class);
        $this->config->method('isRagEnabled')->willReturn(false);
        $this->config->method('getMaxIterations')->willReturn(5);
        $this->config->method('getTemperature')->willReturn(0.3);
        $this->config->method('getRagTopK')->willReturn(5);
        $this->config->method('isToolEnabled')->willReturn(true);

        $this->toolRegistry = new ToolRegistry([], $this->config);
    }

    public function testSimpleAnswerReturnsAgentResultWithReply(): void
    {
        $this->llmClient->expects(self::once())
            ->method('chat')
            ->willReturn(new LlmResponse(
                content: 'OroCommerce is an eCommerce platform.',
                toolCalls: [],
                finishReason: 'stop',
                usage: [],
            ));

        $agent = $this->createAgent();
        $result = $agent->run('What is OroCommerce?');

        self::assertSame('OroCommerce is an eCommerce platform.', $result->reply);
        self::assertSame([], $result->toolTrace);
        self::assertSame([], $result->links);
    }

    public function testToolCallFollowedByFinalAnswer(): void
    {
        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function (LlmRequest $request) use (&$callCount): LlmResponse {
                $callCount++;
                if ($callCount === 1) {
                    return new LlmResponse(
                        content: '',
                        toolCalls: [
                            new ToolCall(
                                id: 'call-1',
                                name: 'test_tool',
                                argsJson: '{"arg":"val"}',
                            ),
                        ],
                        finishReason: 'tool_calls',
                        usage: [],
                    );
                }

                return new LlmResponse(
                    content: 'The answer is 42.',
                    toolCalls: [],
                    finishReason: 'stop',
                    usage: [],
                );
            });

        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('test_tool');
        $tool->method('getDefinition')->willReturn(new ToolDefinition('test_tool', 'A test tool', []));
        $tool->method('execute')
            ->with(['arg' => 'val'])
            ->willReturn(ToolResult::success('42'));

        $this->toolRegistry = new ToolRegistry([$tool], $this->config);

        $agent = $this->createAgent();
        $result = $agent->run('What is the answer?');

        self::assertSame('The answer is 42.', $result->reply);
        self::assertCount(1, $result->toolTrace);
        self::assertSame('test_tool', $result->toolTrace[0]['tool']);
        self::assertSame('{"arg":"val"}', $result->toolTrace[0]['args']);
    }

    /**
     * Regression guard: the tool-result ChatMessage fed back to the LLM on the
     * follow-up turn must carry the tool's name. GeminiClient maps it to
     * functionResponse.name, which the Gemini API rejects outright
     * ("Name cannot be empty") if it's null -- silently breaking every
     * multi-turn conversation that involves a tool call when using Gemini,
     * even though OpenAI/Anthropic (which key off toolCallId instead) never
     * surfaced the missing name.
     */
    public function testToolResultMessageSentBackToLlmIncludesToolName(): void
    {
        $capturedSecondRequest = null;
        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function (LlmRequest $request) use (&$callCount, &$capturedSecondRequest): LlmResponse {
                $callCount++;
                if ($callCount === 1) {
                    return new LlmResponse(
                        content: '',
                        toolCalls: [
                            new ToolCall(id: 'call-1', name: 'test_tool', argsJson: '{"arg":"val"}'),
                        ],
                        finishReason: 'tool_calls',
                        usage: [],
                    );
                }

                $capturedSecondRequest = $request;

                return new LlmResponse(content: 'The answer is 42.', toolCalls: [], finishReason: 'stop', usage: []);
            });

        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('test_tool');
        $tool->method('getDefinition')->willReturn(new ToolDefinition('test_tool', 'A test tool', []));
        $tool->method('execute')->with(['arg' => 'val'])->willReturn(ToolResult::success('42'));

        $this->toolRegistry = new ToolRegistry([$tool], $this->config);

        $agent = $this->createAgent();
        $agent->run('What is the answer?');

        self::assertNotNull($capturedSecondRequest);
        $messages = $capturedSecondRequest->messages;
        $toolResultMessage = end($messages);
        self::assertSame(Role::Tool, $toolResultMessage->role);
        self::assertSame('test_tool', $toolResultMessage->name);
    }

    public function testSelfCorrectionFirstToolCallErrorsSecondSucceeds(): void
    {
        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount): LlmResponse {
                $callCount++;

                return match ($callCount) {
                    1 => new LlmResponse(
                        content: '',
                        toolCalls: [new ToolCall('call-1', 'db_tool', '{"sql":"SELECT bad"}')],
                        finishReason: 'tool_calls',
                        usage: [],
                    ),
                    2 => new LlmResponse(
                        content: '',
                        toolCalls: [new ToolCall('call-2', 'db_tool', '{"sql":"SELECT good"}')],
                        finishReason: 'tool_calls',
                        usage: [],
                    ),
                    default => new LlmResponse(
                        content: 'Found the data.',
                        toolCalls: [],
                        finishReason: 'stop',
                        usage: [],
                    ),
                };
            });

        $executeCount = 0;
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('db_tool');
        $tool->method('getDefinition')->willReturn(new ToolDefinition('db_tool', 'DB tool', []));
        $tool->method('execute')
            ->willReturnCallback(function () use (&$executeCount): ToolResult {
                $executeCount++;

                return match ($executeCount) {
                    1 => ToolResult::error('column "bad" does not exist'),
                    default => ToolResult::success(['row' => 1]),
                };
            });

        $this->toolRegistry = new ToolRegistry([$tool], $this->config);

        $agent = $this->createAgent();
        $result = $agent->run('Find data');

        self::assertSame('Found the data.', $result->reply);
        self::assertCount(2, $result->toolTrace);
        self::assertStringContainsString('Error', $result->toolTrace[0]['result']);
        self::assertStringNotContainsString('Error', $result->toolTrace[1]['result']);
    }

    public function testMaxIterationsCapPreventsInfiniteLoop(): void
    {
        $this->config = $this->createMock(OroAiConfig::class);
        $this->config->method('isRagEnabled')->willReturn(false);
        $this->config->method('getMaxIterations')->willReturn(2);
        $this->config->method('getTemperature')->willReturn(0.3);
        $this->config->method('getRagTopK')->willReturn(5);

        $this->llmClient->method('chat')
            ->willReturn(new LlmResponse(
                content: '',
                toolCalls: [new ToolCall('call-x', 'loop_tool', '{}')],
                finishReason: 'tool_calls',
                usage: [],
            ));

        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('loop_tool');
        $tool->method('getDefinition')->willReturn(new ToolDefinition('loop_tool', 'Loop', []));
        $tool->method('execute')->willReturn(ToolResult::success('still going'));

        $this->toolRegistry = new ToolRegistry([$tool], $this->config);

        $agent = $this->createAgent();
        $result = $agent->run('infinite loop test');

        self::assertStringContainsString('could not complete', $result->reply);
        self::assertCount(2, $result->toolTrace);
    }

    public function testToolExceptionIsCaughtAndReturnedAsError(): void
    {
        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount): LlmResponse {
                $callCount++;

                if ($callCount === 1) {
                    return new LlmResponse(
                        content: '',
                        toolCalls: [new ToolCall('call-1', 'broken_tool', '{}')],
                        finishReason: 'tool_calls',
                        usage: [],
                    );
                }

                return new LlmResponse(
                    content: 'Something went wrong.',
                    toolCalls: [],
                    finishReason: 'stop',
                    usage: [],
                );
            });

        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('broken_tool');
        $tool->method('getDefinition')->willReturn(new ToolDefinition('broken_tool', 'Broken', []));
        $tool->method('execute')->willThrowException(new \RuntimeException('Connection lost'));

        $this->toolRegistry = new ToolRegistry([$tool], $this->config);

        $agent = $this->createAgent();
        $result = $agent->run('test');

        self::assertCount(1, $result->toolTrace);
        self::assertStringContainsString('Error', $result->toolTrace[0]['result']);
        self::assertStringContainsString('Connection lost', $result->toolTrace[0]['result']);
    }

    public function testExtractsLinksFromToolTrace(): void
    {
        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount): LlmResponse {
                $callCount++;

                if ($callCount === 1) {
                    return new LlmResponse(
                        content: '',
                        toolCalls: [new ToolCall('call-1', 'entity_url', '{"entity":"order"}')],
                        finishReason: 'tool_calls',
                        usage: [],
                    );
                }

                return new LlmResponse(
                    content: 'Here is the URL.',
                    toolCalls: [],
                    finishReason: 'stop',
                    usage: [],
                );
            });

        // Use an error result containing a plain-text admin URL
        // (JSON-encoded success results escape slashes, so extractLinks can't match them)
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('entity_url');
        $tool->method('getDefinition')->willReturn(new ToolDefinition('entity_url', 'URL', []));
        $tool->method('execute')
            ->willReturn(ToolResult::error('Redirect to /admin/order/view/42 failed'));

        $this->toolRegistry = new ToolRegistry([$tool], $this->config);

        $agent = $this->createAgent();
        $result = $agent->run('Where are orders?');

        self::assertContains('/admin/order/view/42', $result->links);
    }

    public function testDeduplicatesExtractedLinks(): void
    {
        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount): LlmResponse {
                $callCount++;

                if ($callCount === 1) {
                    return new LlmResponse(
                        content: '',
                        toolCalls: [
                            new ToolCall('call-1', 'url_tool', '{}'),
                            new ToolCall('call-2', 'url_tool', '{}'),
                        ],
                        finishReason: 'tool_calls',
                        usage: [],
                    );
                }

                return new LlmResponse('Done.', [], 'stop', []);
            });

        // Use error results with plain-text admin URLs (not JSON-escaped)
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('url_tool');
        $tool->method('getDefinition')->willReturn(new ToolDefinition('url_tool', 'URL', []));
        $tool->method('execute')
            ->willReturn(ToolResult::error('See /admin/order for details'));

        $this->toolRegistry = new ToolRegistry([$tool], $this->config);

        $agent = $this->createAgent();
        $result = $agent->run('test');

        // Same URL from two tool calls should be deduplicated
        self::assertCount(1, $result->links);
        self::assertSame('/admin/order', $result->links[0]);
    }

    public function testRagContextIsInjectedWhenEnabled(): void
    {
        $this->config = $this->createMock(OroAiConfig::class);
        $this->config->method('isRagEnabled')->willReturn(true);
        $this->config->method('getMaxIterations')->willReturn(5);
        $this->config->method('getTemperature')->willReturn(0.3);
        $this->config->method('getRagTopK')->willReturn(3);

        $this->ragStore->expects(self::once())
            ->method('search')
            ->with('test query', 3)
            ->willReturn([
                new RagHit(
                    text: 'OroCommerce docs content',
                    source: 'guide.md',
                    score: 0.95,
                ),
            ]);

        $capturedRequest = null;
        $this->llmClient->method('chat')
            ->willReturnCallback(function (LlmRequest $req) use (&$capturedRequest): LlmResponse {
                $capturedRequest = $req;

                return new LlmResponse(
                    content: 'Here is the info.',
                    toolCalls: [],
                    finishReason: 'stop',
                    usage: [],
                );
            });

        $agent = $this->createAgent();
        $agent->run('test query');

        self::assertNotNull($capturedRequest);
        // Should have system prompt + RAG context + user message
        self::assertGreaterThanOrEqual(3, count($capturedRequest->messages));
        // Second message should contain RAG doc
        $ragMsg = $capturedRequest->messages[1];
        self::assertSame(Role::System, $ragMsg->role);
        self::assertStringContainsString('OroCommerce docs content', $ragMsg->content);
        self::assertStringContainsString('guide.md', $ragMsg->content);
    }

    public function testRagExceptionIsSilentlyIgnored(): void
    {
        $this->config = $this->createMock(OroAiConfig::class);
        $this->config->method('isRagEnabled')->willReturn(true);
        $this->config->method('getMaxIterations')->willReturn(5);
        $this->config->method('getTemperature')->willReturn(0.3);
        $this->config->method('getRagTopK')->willReturn(5);

        $this->ragStore->method('search')
            ->willThrowException(new \RuntimeException('Redis down'));

        $this->llmClient->method('chat')
            ->willReturn(new LlmResponse(
                content: 'Answered without RAG.',
                toolCalls: [],
                finishReason: 'stop',
                usage: [],
            ));

        $agent = $this->createAgent();
        $result = $agent->run('test');

        self::assertSame('Answered without RAG.', $result->reply);
    }

    public function testMultipleToolCallsInSingleResponse(): void
    {
        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount): LlmResponse {
                $callCount++;

                if ($callCount === 1) {
                    return new LlmResponse(
                        content: '',
                        toolCalls: [
                            new ToolCall('call-1', 'tool_a', '{"a":1}'),
                            new ToolCall('call-2', 'tool_b', '{"b":2}'),
                        ],
                        finishReason: 'tool_calls',
                        usage: [],
                    );
                }

                return new LlmResponse('Done.', [], 'stop', []);
            });

        $toolA = $this->createMock(AiToolInterface::class);
        $toolA->method('getName')->willReturn('tool_a');
        $toolA->method('getDefinition')->willReturn(new ToolDefinition('tool_a', 'A', []));
        $toolA->method('execute')->willReturn(ToolResult::success('a result'));

        $toolB = $this->createMock(AiToolInterface::class);
        $toolB->method('getName')->willReturn('tool_b');
        $toolB->method('getDefinition')->willReturn(new ToolDefinition('tool_b', 'B', []));
        $toolB->method('execute')->willReturn(ToolResult::success('b result'));

        $this->toolRegistry = new ToolRegistry([$toolA, $toolB], $this->config);

        $agent = $this->createAgent();
        $result = $agent->run('test');

        self::assertCount(2, $result->toolTrace);
        self::assertSame('tool_a', $result->toolTrace[0]['tool']);
        self::assertSame('tool_b', $result->toolTrace[1]['tool']);
    }

    public function testHistoryIsIncludedInMessages(): void
    {
        $capturedRequest = null;
        $this->llmClient->method('chat')
            ->willReturnCallback(function (LlmRequest $req) use (&$capturedRequest): LlmResponse {
                $capturedRequest = $req;

                return new LlmResponse(
                    content: 'Follow-up answer.',
                    toolCalls: [],
                    finishReason: 'stop',
                    usage: [],
                );
            });

        $history = [
            ChatMessage::user('Previous question'),
            new ChatMessage(role: Role::Assistant, content: 'Previous answer'),
        ];

        $agent = $this->createAgent();
        $agent->run('Follow-up question', $history);

        self::assertNotNull($capturedRequest);
        // system + history(2) + user = 4
        self::assertCount(4, $capturedRequest->messages);
        self::assertSame('Previous question', $capturedRequest->messages[1]->content);
        self::assertSame('Previous answer', $capturedRequest->messages[2]->content);
        self::assertSame('Follow-up question', $capturedRequest->messages[3]->content);
    }

    public function testMaxIterationsWithNoTraceProducesNoResultsMessage(): void
    {
        $this->config = $this->createMock(OroAiConfig::class);
        $this->config->method('isRagEnabled')->willReturn(false);
        $this->config->method('getMaxIterations')->willReturn(0);
        $this->config->method('getTemperature')->willReturn(0.3);
        $this->config->method('getRagTopK')->willReturn(5);

        $agent = $this->createAgent();
        $result = $agent->run('test with zero iterations');

        self::assertStringContainsString('could not complete', $result->reply);
        self::assertStringContainsString('No results', $result->reply);
    }

    public function testToolCallWithInvalidJsonArgsIsCaughtAsError(): void
    {
        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount): LlmResponse {
                $callCount++;

                if ($callCount === 1) {
                    return new LlmResponse(
                        content: '',
                        toolCalls: [new ToolCall('call-1', 'some_tool', '{invalid json')],
                        finishReason: 'tool_calls',
                        usage: [],
                    );
                }

                return new LlmResponse('Handled error.', [], 'stop', []);
            });

        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('some_tool');
        $tool->method('getDefinition')->willReturn(new ToolDefinition('some_tool', 'Some', []));
        // json_decode with invalid JSON returns null, so args will be []
        $tool->method('execute')->willReturn(ToolResult::success('ok'));

        $this->toolRegistry = new ToolRegistry([$tool], $this->config);

        $agent = $this->createAgent();
        $result = $agent->run('test');

        // Should complete without crashing
        self::assertSame('Handled error.', $result->reply);
    }

    private function createAgent(): OroAiAgent
    {
        return new OroAiAgent(
            $this->clientRegistry,
            $this->toolRegistry,
            $this->ragStore,
            $this->config,
        );
    }
}
