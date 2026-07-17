<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent;

use Genaker\Bundle\OroAI\Agent\OroAiAgent;
use Genaker\Bundle\OroAI\Agent\ResolutionHarness;
use Genaker\Bundle\OroAI\Core\Contract\LlmClientInterface;
use Genaker\Bundle\OroAI\Core\Model\AgentResult;
use Genaker\Bundle\OroAI\Core\Model\LlmResponse;
use Genaker\Bundle\OroAI\Llm\LlmClientRegistry;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ResolutionHarness — the outer retry loop that wraps OroAiAgent.
 *
 * The harness itself never calls OroAiAgent's LLM client; it delegates agent
 * execution entirely to the mocked OroAiAgent. The only real LLM client
 * interaction in the harness is the cheap evaluator call inside evaluate(),
 * which goes through the mocked LlmClientRegistry.
 */
final class ResolutionHarnessTest extends TestCase
{
    private OroAiAgent&MockObject $agent;
    private LlmClientRegistry&MockObject $registry;
    private LlmClientInterface&MockObject $llmClient;
    private OroAiConfig&MockObject $config;
    private string $memoryDir;

    protected function setUp(): void
    {
        $this->agent     = $this->createMock(OroAiAgent::class);
        $this->llmClient = $this->createMock(LlmClientInterface::class);
        $this->registry  = $this->createMock(LlmClientRegistry::class);
        $this->registry->method('get')->willReturn($this->llmClient);

        $this->config = $this->createMock(OroAiConfig::class);
        $this->config->method('getHarnessMaxTries')->willReturn(3);

        $this->memoryDir = sys_get_temp_dir() . '/oroai_harness_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        // Clean up any memory files written during tests
        if (is_dir($this->memoryDir)) {
            foreach (glob($this->memoryDir . '/*.md') as $file) {
                unlink($file);
            }
            rmdir($this->memoryDir);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function makeHarness(): ResolutionHarness
    {
        return new ResolutionHarness($this->agent, $this->registry, $this->config, $this->memoryDir);
    }

    private function makeAgentResult(string $reply = 'The answer is here.', array $usage = []): AgentResult
    {
        return new AgentResult(
            $reply,
            [['tool' => 'sql_query', 'args' => '{}', 'result' => 'ok']],
            [],
            $usage ?: ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
        );
    }

    private function evaluatorReturns(string $json): void
    {
        $this->llmClient->method('chat')
            ->willReturn(new LlmResponse(content: $json, toolCalls: [], finishReason: 'stop', usage: []));
    }

    // ── Resolved on first attempt ──────────────────────────────────────────

    public function testResolvedOnFirstAttemptReturnsTrueAndSavesMemory(): void
    {
        $this->agent->expects(self::once())
            ->method('run')
            ->willReturn($this->makeAgentResult('Order #42 is in status Shipped.'));

        $this->evaluatorReturns('{"status":"resolved"}');

        $result = $this->makeHarness()->resolve('What is order 42 status?');

        self::assertTrue($result->resolved);
        self::assertSame(1, $result->attempt);
        self::assertTrue($result->memorySaved);
        self::assertFalse($result->needsClarification);
        self::assertSame('Order #42 is in status Shipped.', $result->reply);
    }

    public function testResolvedReplyAndTraceArePassedThrough(): void
    {
        $agentResult = new AgentResult(
            'Found it at /admin/order/42',
            [['tool' => 'find_entity', 'args' => '{"id":42}', 'result' => 'Order 42']],
            ['/admin/order/42'],
        );

        $this->agent->method('run')->willReturn($agentResult);
        $this->evaluatorReturns('{"status":"resolved"}');

        $result = $this->makeHarness()->resolve('Find order 42');

        self::assertSame('Found it at /admin/order/42', $result->reply);
        self::assertCount(1, $result->toolTrace);
        self::assertSame('find_entity', $result->toolTrace[0]['tool']);
        self::assertContains('/admin/order/42', $result->links);
    }

    // ── Memory saving ──────────────────────────────────────────────────────

    public function testResolvedAnswerIsSavedAsMarkdownFile(): void
    {
        $this->agent->method('run')->willReturn($this->makeAgentResult('Answer: 42 rows found.'));
        $this->evaluatorReturns('{"status":"resolved"}');

        $this->makeHarness()->resolve('How many orders?');

        self::assertDirectoryExists($this->memoryDir);
        $files = glob($this->memoryDir . '/*.md');
        self::assertCount(1, $files);

        $content = file_get_contents($files[0]);
        self::assertStringContainsString('How many orders?', $content);
        self::assertStringContainsString('Answer: 42 rows found.', $content);
    }

    public function testMemoryFileNameContainsDateAndSlug(): void
    {
        $this->agent->method('run')->willReturn($this->makeAgentResult('Yes.'));
        $this->evaluatorReturns('{"status":"resolved"}');

        $this->makeHarness()->resolve('Is Redis running?');

        $files = glob($this->memoryDir . '/*.md');
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}/', basename($files[0]));
        self::assertStringContainsString('is-redis-running', basename($files[0]));
    }

    // ── Needs more data — retry loop ───────────────────────────────────────

    public function testNeedsMoreDataOnFirstThenResolvedOnSecond(): void
    {
        $this->agent->expects(self::exactly(2))
            ->method('run')
            ->willReturn($this->makeAgentResult('Found the answer on retry.'));

        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount): LlmResponse {
                $callCount++;
                $json = $callCount === 1
                    ? '{"status":"needs_more_data","missing":"Check the orders table directly"}'
                    : '{"status":"resolved"}';

                return new LlmResponse(content: $json, toolCalls: [], finishReason: 'stop', usage: []);
            });

        $result = $this->makeHarness()->resolve('How many open orders?');

        self::assertTrue($result->resolved);
        self::assertSame(2, $result->attempt);
        self::assertTrue($result->memorySaved);
    }

    public function testContextHintIsAppendedToMessageOnRetry(): void
    {
        $capturedMessages = [];
        $this->agent->expects(self::exactly(2))
            ->method('run')
            ->willReturnCallback(function (string $msg) use (&$capturedMessages): AgentResult {
                $capturedMessages[] = $msg;

                return $this->makeAgentResult();
            });

        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount): LlmResponse {
                $callCount++;
                $json = $callCount === 1
                    ? '{"status":"needs_more_data","missing":"check the shipment table"}'
                    : '{"status":"resolved"}';

                return new LlmResponse(content: $json, toolCalls: [], finishReason: 'stop', usage: []);
            });

        $this->makeHarness()->resolve('Where are shipments?');

        // First call: raw question
        self::assertSame('Where are shipments?', $capturedMessages[0]);
        // Second call: question + harness context hint
        self::assertStringContainsString('Where are shipments?', $capturedMessages[1]);
        self::assertStringContainsString('check the shipment table', $capturedMessages[1]);
    }

    // ── Needs customer input ───────────────────────────────────────────────

    public function testNeedsCustomerInputReturnsClarifyingQuestion(): void
    {
        $this->agent->expects(self::once())
            ->method('run')
            ->willReturn($this->makeAgentResult('I need more details.'));

        $this->evaluatorReturns(
            '{"status":"needs_customer_input","question":"Could you provide the order number?"}'
        );

        $result = $this->makeHarness()->resolve('What happened to my order?');

        self::assertTrue($result->needsClarification);
        self::assertFalse($result->resolved);
        self::assertFalse($result->memorySaved);
        self::assertSame(1, $result->attempt);
        self::assertSame('Could you provide the order number?', $result->reply);
    }

    public function testNeedsCustomerInputDoesNotRetryAgent(): void
    {
        $this->agent->expects(self::once())->method('run')
            ->willReturn($this->makeAgentResult());

        $this->evaluatorReturns('{"status":"needs_customer_input","question":"Which store?"}');

        $this->makeHarness()->resolve('What is the discount?');
        // Agent was called exactly once — verified by expects(once()) above
    }

    // ── Exhausted retries ──────────────────────────────────────────────────

    public function testExhaustingAllTriesReturnsBestAttemptUnresolved(): void
    {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('getHarnessMaxTries')->willReturn(2);

        $this->agent->expects(self::exactly(2))
            ->method('run')
            ->willReturn($this->makeAgentResult('Partial answer only.'));

        $this->evaluatorReturns('{"status":"needs_more_data","missing":"still need data"}');

        $harness = new ResolutionHarness($this->agent, $this->registry, $config, $this->memoryDir);
        $result = $harness->resolve('Complex query');

        self::assertFalse($result->resolved);
        self::assertFalse($result->needsClarification);
        self::assertSame(2, $result->attempt);
        self::assertSame('Partial answer only.', $result->reply);
        self::assertFalse($result->memorySaved);
    }

    public function testMaxTriesOneResolvesOrReturnsInSingleAttempt(): void
    {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('getHarnessMaxTries')->willReturn(1);

        $this->agent->expects(self::once())
            ->method('run')
            ->willReturn($this->makeAgentResult('Quick answer.'));

        $this->evaluatorReturns('{"status":"needs_more_data","missing":"more"}');

        $harness = new ResolutionHarness($this->agent, $this->registry, $config, $this->memoryDir);
        $result = $harness->resolve('test');

        // Even with needs_more_data, max 1 try means we stop
        self::assertFalse($result->resolved);
        self::assertSame(1, $result->attempt);
    }

    // ── Evaluator fault tolerance ──────────────────────────────────────────

    public function testEvaluatorLlmExceptionTriggersRetryInsteadOfSilentResolve(): void
    {
        // A persistently-failing evaluator must NOT be treated as an automatic
        // pass — that would defeat the harness's entire purpose. It should be
        // treated as inconclusive and retried up to the configured max.
        $this->agent->expects(self::exactly(3))
            ->method('run')
            ->willReturn($this->makeAgentResult('Some answer.'));

        $this->llmClient->method('chat')
            ->willThrowException(new \RuntimeException('Evaluator LLM timeout'));

        // Should NOT throw — should exhaust retries and report unresolved.
        $result = $this->makeHarness()->resolve('test');

        self::assertFalse($result->resolved);
        self::assertSame(3, $result->attempt);
    }

    public function testEvaluatorReturnsInvalidJsonTriggersRetryInsteadOfSilentResolve(): void
    {
        $this->agent->expects(self::exactly(3))->method('run')->willReturn($this->makeAgentResult());
        $this->evaluatorReturns('not valid json at all');

        $result = $this->makeHarness()->resolve('test');

        self::assertFalse($result->resolved);
        self::assertSame(3, $result->attempt);
    }

    public function testEvaluatorRecoversFromTransientInvalidJsonOnRetry(): void
    {
        // First evaluator call is garbled, second is a clean "resolved" — the
        // harness should retry past the glitch and still land on resolved=true.
        $this->agent->expects(self::exactly(2))->method('run')->willReturn($this->makeAgentResult());

        $this->llmClient->method('chat')->willReturnOnConsecutiveCalls(
            new LlmResponse(content: 'not valid json at all', toolCalls: [], finishReason: 'stop', usage: []),
            new LlmResponse(content: '{"status":"resolved"}', toolCalls: [], finishReason: 'stop', usage: []),
        );

        $result = $this->makeHarness()->resolve('test');

        self::assertTrue($result->resolved);
        self::assertSame(2, $result->attempt);
    }

    public function testEvaluatorHandlesMarkdownFencedJson(): void
    {
        // Models are told "JSON only, no markdown" but commonly wrap the
        // reply in a ```json fence anyway — the evaluator must still parse it.
        $this->agent->expects(self::once())->method('run')->willReturn($this->makeAgentResult());
        $this->evaluatorReturns("```json\n{\"status\":\"resolved\"}\n```");

        $result = $this->makeHarness()->resolve('test');

        self::assertTrue($result->resolved);
        self::assertSame(1, $result->attempt);
    }

    public function testEvaluatorReturnsUnknownStatusFallsBackToResolved(): void
    {
        $this->agent->method('run')->willReturn($this->makeAgentResult());
        $this->evaluatorReturns('{"status":"unknown_status"}');

        $result = $this->makeHarness()->resolve('test');

        // Unknown status has no branch — falls through to resolved by default
        self::assertFalse($result->needsClarification);
    }

    // ── HarnessResult DTO ──────────────────────────────────────────────────

    public function testHarnessResultDefaultsAreCorrect(): void
    {
        $result = new \Genaker\Bundle\OroAI\Agent\HarnessResult(reply: 'hi');

        self::assertSame('hi', $result->reply);
        self::assertSame([], $result->toolTrace);
        self::assertSame([], $result->links);
        self::assertFalse($result->resolved);
        self::assertFalse($result->needsClarification);
        self::assertFalse($result->memorySaved);
        self::assertSame(1, $result->attempt);
        self::assertSame(
            ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            $result->usage,
        );
    }

    // ── Usage aggregation ──────────────────────────────────────────────────

    public function testUsageIsAggregatedAcrossAgentRunAndEvaluatorCall(): void
    {
        $this->agent->method('run')
            ->willReturn($this->makeAgentResult(
                'Answer.',
                ['prompt_tokens' => 200, 'completion_tokens' => 50, 'total_tokens' => 250],
            ));

        $this->llmClient->method('chat')
            ->willReturn(new LlmResponse(
                content: '{"status":"resolved"}',
                toolCalls: [],
                finishReason: 'stop',
                usage: ['prompt_tokens' => 30, 'completion_tokens' => 10, 'total_tokens' => 40],
            ));

        $result = $this->makeHarness()->resolve('test');

        // Agent's usage (200/50/250) + evaluator's own call (30/10/40).
        self::assertSame(230, $result->usage['prompt_tokens']);
        self::assertSame(60, $result->usage['completion_tokens']);
        self::assertSame(290, $result->usage['total_tokens']);
    }

    public function testUsageIsAggregatedAcrossMultipleAttempts(): void
    {
        $agentCallCount = 0;
        $this->agent->method('run')
            ->willReturnCallback(function () use (&$agentCallCount): AgentResult {
                $agentCallCount++;

                return $this->makeAgentResult(
                    'Answer.',
                    ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
                );
            });

        $evalCallCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$evalCallCount): LlmResponse {
                $evalCallCount++;
                $json = $evalCallCount === 1
                    ? '{"status":"needs_more_data","missing":"more"}'
                    : '{"status":"resolved"}';

                return new LlmResponse(
                    content: $json,
                    toolCalls: [],
                    finishReason: 'stop',
                    usage: ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                );
            });

        $result = $this->makeHarness()->resolve('test');

        // Two full attempts: agent (100+20) x2 + evaluator (10+5) x2.
        self::assertSame(2, $result->attempt);
        self::assertSame(220, $result->usage['prompt_tokens']);
        self::assertSame(50, $result->usage['completion_tokens']);
        self::assertSame(270, $result->usage['total_tokens']);
    }

    // ── Progress callback ──────────────────────────────────────────────────

    public function testProgressCallbackReceivesHarnessAndForwardedAgentEvents(): void
    {
        $this->agent->method('run')
            ->willReturnCallback(function (string $msg, array $history, ?callable $onProgress) {
                if ($onProgress !== null) {
                    $onProgress(['type' => 'tool_call', 'tool' => 'sql_query', 'args' => []]);
                    $onProgress(['type' => 'tool_result', 'tool' => 'sql_query', 'success' => true]);
                }

                return $this->makeAgentResult();
            });

        $this->evaluatorReturns('{"status":"resolved"}');

        $events = [];
        $this->makeHarness()->resolve('test', [], function (array $event) use (&$events): void {
            $events[] = $event;
        });

        self::assertSame('harness_attempt', $events[0]['type']);
        self::assertSame(1, $events[0]['attempt']);
        self::assertSame(3, $events[0]['max']);
        self::assertSame('tool_call', $events[1]['type']);
        self::assertSame('tool_result', $events[2]['type']);
        self::assertSame('evaluating', $events[3]['type']);
    }

    public function testProgressCallbackReportsEachRetryAttempt(): void
    {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('getHarnessMaxTries')->willReturn(2);

        $this->agent->method('run')->willReturn($this->makeAgentResult());

        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount): LlmResponse {
                $callCount++;
                $json = $callCount === 1
                    ? '{"status":"needs_more_data","missing":"more"}'
                    : '{"status":"resolved"}';

                return new LlmResponse(content: $json, toolCalls: [], finishReason: 'stop', usage: []);
            });

        $events = [];
        $harness = new ResolutionHarness($this->agent, $this->registry, $config, $this->memoryDir);
        $harness->resolve('test', [], function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $attemptEvents = array_values(array_filter($events, static fn (array $e) => $e['type'] === 'harness_attempt'));
        self::assertCount(2, $attemptEvents);
        self::assertSame(1, $attemptEvents[0]['attempt']);
        self::assertSame(2, $attemptEvents[1]['attempt']);
    }

    // ── Config: max tries = 10 default ────────────────────────────────────

    public function testGetHarnessMaxTriesDefaultIsTen(): void
    {
        $configManager = $this->createMock(\Oro\Bundle\ConfigBundle\Config\ConfigManager::class);
        $configManager->method('get')
            ->with('genaker_oro_ai.harness_max_tries')
            ->willReturn(null);

        $crypter = $this->createMock(\Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface::class);

        $config = new \Genaker\Bundle\OroAI\Service\OroAiConfig($configManager, $crypter);

        self::assertSame(10, $config->getHarnessMaxTries());
    }

    public function testGetHarnessMaxTriesEnforcesMinimumOfOne(): void
    {
        $configManager = $this->createMock(\Oro\Bundle\ConfigBundle\Config\ConfigManager::class);
        $configManager->method('get')
            ->with('genaker_oro_ai.harness_max_tries')
            ->willReturn(0);

        $crypter = $this->createMock(\Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface::class);

        $config = new \Genaker\Bundle\OroAI\Service\OroAiConfig($configManager, $crypter);

        self::assertSame(1, $config->getHarnessMaxTries());
    }
}
