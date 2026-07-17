<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Service;

use Genaker\Bundle\OroAI\Agent\ContextWindowManager;
use Genaker\Bundle\OroAI\Agent\HarnessInterface;
use Genaker\Bundle\OroAI\Agent\HarnessResult;
use Genaker\Bundle\OroAI\Agent\OroAiAgent;
use Genaker\Bundle\OroAI\Core\Model\AgentResult;
use Genaker\Bundle\OroAI\Service\ChatOrchestrator;
use Genaker\Bundle\OroAI\Service\ChatSessionStore;
use Genaker\Bundle\OroAI\Service\ChatTranscriptLogger;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Genaker\Bundle\OroAI\Service\TokenCostEstimator;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ChatOrchestratorTest extends TestCase
{
    private OroAiAgent&MockObject $agent;
    private HarnessInterface&MockObject $harness;
    private OroAiConfig&MockObject $config;
    private ChatSessionStore $sessionStore;
    private string $transcriptDir;

    protected function setUp(): void
    {
        $this->agent = $this->createMock(OroAiAgent::class);
        $this->harness = $this->createMock(HarnessInterface::class);
        $this->config = $this->createMock(OroAiConfig::class);
        $this->transcriptDir = sys_get_temp_dir() . '/oroai_orch_' . bin2hex(random_bytes(4));

        $tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $tokenAccessor->method('getUserId')->willReturn(7);
        $this->sessionStore = new ChatSessionStore(new ArrayAdapter(), $tokenAccessor);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->transcriptDir));
    }

    private function orchestrator(?ContextWindowManager $contextWindow = null): ChatOrchestrator
    {
        return new ChatOrchestrator(
            $this->agent,
            $this->harness,
            $this->config,
            $contextWindow ?? new ContextWindowManager(),
            new TokenCostEstimator($this->config),
            new ChatTranscriptLogger($this->transcriptDir),
            $this->sessionStore,
        );
    }

    public function testAgentPathProducesOutcomeWithoutHarnessFields(): void
    {
        $this->config->method('isHarnessEnabled')->willReturn(false);
        $this->config->method('getModel')->willReturn('gemini-2.5-flash');
        $this->agent->method('run')->willReturn(new AgentResult(
            'Customer users are at /admin/customer/user/',
            [['tool' => 'entity_url', 'args' => '{}', 'result' => '/admin/customer/user/']],
            ['/admin/customer/user/'],
            ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ));

        $payload = $this->orchestrator()->handle('Where are customer users?', 'sess1')->toArray();

        self::assertStringContainsString('customer/user', $payload['reply']);
        self::assertCount(1, $payload['tool_trace']);
        self::assertContains('/admin/customer/user/', $payload['links']);
        self::assertSame(120, $payload['usage']['total_tokens']);
        self::assertSame('sess1', $payload['session_id']);
        self::assertNotNull($payload['cost'], 'cost estimated from usage');
        self::assertArrayNotHasKey('harness_attempt', $payload);
    }

    public function testHarnessPathIncludesHarnessFields(): void
    {
        $this->config->method('isHarnessEnabled')->willReturn(true);
        $this->harness->method('resolve')->willReturn(new HarnessResult(
            reply: 'resolved answer',
            resolved: true,
            memorySaved: true,
            attempt: 2,
        ));

        $payload = $this->orchestrator()->handle('hard question', 'sess1')->toArray();

        self::assertSame('resolved answer', $payload['reply']);
        self::assertSame(2, $payload['harness_attempt']);
        self::assertTrue($payload['memory_saved']);
        self::assertFalse($payload['needs_clarification']);
    }

    public function testHistoryIsLoadedServerSideFromTheSessionStore(): void
    {
        $this->config->method('isHarnessEnabled')->willReturn(false);
        $this->sessionStore->append('sess1', 'previous question', 'previous answer');

        $this->agent->expects(self::once())
            ->method('run')
            ->willReturnCallback(function (string $message, array $history): AgentResult {
                self::assertSame('follow up', $message);
                self::assertCount(2, $history);
                self::assertSame('user', $history[0]->role->value);
                self::assertSame('previous question', $history[0]->content);
                self::assertSame('assistant', $history[1]->role->value);
                self::assertSame('previous answer', $history[1]->content);

                return new AgentResult('follow-up answer');
            });

        $this->orchestrator()->handle('follow up', 'sess1');
    }

    public function testHistoryIsTrimmedByTokenBudgetOldestFirst(): void
    {
        $this->config->method('isHarnessEnabled')->willReturn(false);
        // Two exchanges ≈ (400+400+10)/4 + small — a 120-token budget only fits
        // the last exchange (~102 tokens), so the first one must fall off.
        $this->sessionStore->append('sess1', str_repeat('a', 400), str_repeat('b', 400));
        $this->sessionStore->append('sess1', 'latest question', str_repeat('c', 400));

        $this->agent->expects(self::once())
            ->method('run')
            ->willReturnCallback(function (string $message, array $history): AgentResult {
                self::assertCount(2, $history, 'over-budget oldest exchange dropped');
                self::assertSame('latest question', $history[0]->content);

                return new AgentResult('ok');
            });

        $this->orchestrator(new ContextWindowManager(120))->handle('next', 'sess1');
    }

    public function testCompletedExchangeIsAppendedToTheSessionStore(): void
    {
        $this->config->method('isHarnessEnabled')->willReturn(false);
        $this->agent->method('run')->willReturn(new AgentResult('the answer'));

        $this->orchestrator()->handle('the question', 'sess1');

        self::assertSame(
            [
                ['role' => 'user', 'content' => 'the question'],
                ['role' => 'assistant', 'content' => 'the answer'],
            ],
            $this->sessionStore->getMessages('sess1'),
        );
    }

    public function testSessionIdIsSanitizedForTheOutcome(): void
    {
        $this->config->method('isHarnessEnabled')->willReturn(false);
        $this->agent->method('run')->willReturn(new AgentResult('ok'));

        $outcome = $this->orchestrator()->handle('question', 'ABC 123/../x');

        self::assertSame('abc123x', $outcome->sessionId);
    }

    public function testOnProgressCallbackIsForwardedToTheAgent(): void
    {
        $this->config->method('isHarnessEnabled')->willReturn(false);
        $callback = static function (): void {
        };

        $this->agent->expects(self::once())
            ->method('run')
            ->with('question', [], $callback)
            ->willReturn(new AgentResult('ok'));

        $this->orchestrator()->handle('question', 'sess1', $callback);
    }

    public function testTranscriptLogsTheFinalReply(): void
    {
        $this->config->method('isHarnessEnabled')->willReturn(false);
        $this->agent->method('run')->willReturn(new AgentResult('the final reply'));

        $this->orchestrator()->handle('question', 'sess1');

        $transcript = (string) @file_get_contents($this->transcriptDir . '/chats/sess1.txt');
        self::assertStringContainsString('FINAL REPLY', $transcript);
        self::assertStringContainsString('the final reply', $transcript);
    }
}
