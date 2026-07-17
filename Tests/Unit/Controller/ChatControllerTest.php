<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Controller;

use Genaker\Bundle\OroAI\Agent\ChatProgressStore;
use Genaker\Bundle\OroAI\Controller\ChatController;
use Genaker\Bundle\OroAI\Core\Model\ChatOutcome;
use Genaker\Bundle\OroAI\Service\ChatOrchestrator;
use Genaker\Bundle\OroAI\Service\ChatSessionStore;
use Genaker\Bundle\OroAI\Service\LlmErrorPresenter;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Oro\Bundle\DashboardBundle\Model\WidgetConfigs;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * The controller is a thin HTTP layer: parse → orchestrate → serialize.
 * Error humanization is covered by LlmErrorPresenterTest, turn execution by
 * ChatOrchestratorTest.
 */
final class ChatControllerTest extends TestCase
{
    private ChatOrchestrator&MockObject $orchestrator;
    private LlmErrorPresenter&MockObject $errorPresenter;
    private OroAiConfig&MockObject $config;
    private Environment&MockObject $twig;
    private ChatProgressStore&MockObject $progressStore;
    private WidgetConfigs&MockObject $widgetConfigs;
    private ChatSessionStore&MockObject $sessionStore;
    private ChatController $controller;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(ChatOrchestrator::class);
        $this->errorPresenter = $this->createMock(LlmErrorPresenter::class);
        $this->config = $this->createMock(OroAiConfig::class);
        $this->twig = $this->createMock(Environment::class);
        $this->progressStore = $this->createMock(ChatProgressStore::class);
        $this->widgetConfigs = $this->createMock(WidgetConfigs::class);
        $this->sessionStore = $this->createMock(ChatSessionStore::class);
        $this->controller = new ChatController(
            $this->orchestrator,
            $this->errorPresenter,
            $this->config,
            $this->twig,
            $this->progressStore,
            $this->widgetConfigs,
            $this->sessionStore,
        );
    }

    private function outcome(string $reply = 'ok'): ChatOutcome
    {
        return new ChatOutcome(
            reply: $reply,
            toolTrace: [],
            links: [],
            usage: ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            promptBreakdown: [],
            cost: null,
            sessionId: 'sess1',
        );
    }

    public function testMessageActionReturnsNotConfiguredWhenNoApiKey(): void
    {
        $this->config->method('isConfigured')->willReturn(false);

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'hello']));
        $response = $this->controller->messageAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertTrue($data['not_configured']);
        self::assertStringContainsString('not configured', $data['error']);
        self::assertStringContainsString('API key', $data['error']);
    }

    public function testMessageActionReturnsErrorOnEmptyMessage(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $request = new Request([], [], [], [], [], [], json_encode(['message' => '']));
        $response = $this->controller->messageAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('required', $data['error']);
    }

    public function testMessageActionSerializesTheOutcome(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->orchestrator->expects(self::once())
            ->method('handle')
            ->with('Where are customer users?', 'sess1', null)
            ->willReturn(new ChatOutcome(
                reply: 'Customer users are at /admin/customer/user/',
                toolTrace: [['tool' => 'entity_url', 'args' => '{}', 'result' => '/admin/customer/user/']],
                links: ['/admin/customer/user/'],
                usage: ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
                promptBreakdown: ['tools' => 1_400],
                cost: ['total' => 0.001],
                sessionId: 'sess1',
            ));

        $request = new Request([], [], [], [], [], [], json_encode([
            'message' => 'Where are customer users?',
            'session_id' => 'sess1',
        ]));
        $response = $this->controller->messageAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('customer/user', $data['reply']);
        self::assertCount(1, $data['tool_trace']);
        self::assertContains('/admin/customer/user/', $data['links']);
        self::assertSame(120, $data['usage']['total_tokens']);
        self::assertSame(['tools' => 1_400], $data['token_breakdown']);
        self::assertSame('sess1', $data['session_id']);
        self::assertArrayNotHasKey('harness_attempt', $data, 'plain-agent runs omit harness fields');
    }

    public function testMessageActionWithRequestIdForwardsProgressAndClearsItAfterwards(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $capturedCallback = null;
        $this->orchestrator->method('handle')
            ->willReturnCallback(function (string $message, string $sessionId, ?callable $onProgress) use (&$capturedCallback): ChatOutcome {
                $capturedCallback = $onProgress;

                return $this->outcome();
            });
        $this->progressStore->expects(self::once())->method('clear')->with('req-123');

        $request = new Request([], [], [], [], [], [], json_encode([
            'message' => 'test',
            'request_id' => 'req-123',
        ]));
        $this->controller->messageAction($request);

        self::assertIsCallable($capturedCallback);

        $this->progressStore->expects(self::once())
            ->method('addStep')
            ->with('req-123', ['type' => 'tool_call', 'tool' => 'sql_query']);

        $capturedCallback(['type' => 'tool_call', 'tool' => 'sql_query']);
    }

    public function testMessageActionWithoutRequestIdPassesNullProgressCallback(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $capturedCallback = 'not-set';
        $this->orchestrator->method('handle')
            ->willReturnCallback(function (string $message, string $sessionId, ?callable $onProgress) use (&$capturedCallback): ChatOutcome {
                $capturedCallback = $onProgress;

                return $this->outcome();
            });

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test']));
        $this->controller->messageAction($request);

        self::assertNull($capturedCallback);
    }

    public function testMessageActionMapsExceptionsThroughTheErrorPresenter(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $exception = new \RuntimeException('HTTP/1.1 429 Too Many Requests');
        $this->orchestrator->method('handle')->willThrowException($exception);
        $this->errorPresenter->expects(self::once())
            ->method('humanize')->with($exception)
            ->willReturn('API rate limit exceeded.');
        $this->errorPresenter->expects(self::once())
            ->method('detail')->with($exception)
            ->willReturn('x-ratelimit-limit-requests: 60');

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test']));
        $response = $this->controller->messageAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame('API rate limit exceeded.', $data['error']);
        self::assertSame('x-ratelimit-limit-requests: 60', $data['error_detail']);
    }

    public function testMessageActionClearsProgressEvenWhenTheTurnThrows(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->orchestrator->method('handle')->willThrowException(new \RuntimeException('boom'));
        $this->progressStore->expects(self::once())->method('clear')->with('req-9');

        $request = new Request([], [], [], [], [], [], json_encode([
            'message' => 'test',
            'request_id' => 'req-9',
        ]));
        $this->controller->messageAction($request);
    }

    public function testProgressActionReturnsStepsFromStore(): void
    {
        $this->progressStore->method('getSteps')
            ->with('req-123')
            ->willReturn([['type' => 'tool_call', 'tool' => 'sql_query']]);

        $request = new Request(['request_id' => 'req-123']);
        $response = $this->controller->progressAction($request);

        $data = json_decode($response->getContent(), true);
        self::assertSame([['type' => 'tool_call', 'tool' => 'sql_query']], $data['steps']);
    }

    public function testProgressActionReturnsEmptyStepsForMissingRequestId(): void
    {
        $request = new Request();
        $response = $this->controller->progressAction($request);

        $data = json_decode($response->getContent(), true);
        self::assertSame([], $data['steps']);
    }

    public function testStatusActionReturnsConfiguredFalse(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->config->method('getProvider')->willReturn('openai');

        $response = $this->controller->statusAction();

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertFalse($data['configured']);
        self::assertSame('openai', $data['provider']);
    }

    public function testStatusActionReturnsConfiguredTrue(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getProvider')->willReturn('anthropic');

        $response = $this->controller->statusAction();

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertTrue($data['configured']);
        self::assertSame('anthropic', $data['provider']);
    }

    public function testSessionsActionReturnsTheStoredList(): void
    {
        $sessions = [['id' => 's1', 'title' => 'How many orders?', 'updated_at' => 1, 'count' => 2]];
        $this->sessionStore->method('getSessions')->willReturn($sessions);

        $data = json_decode($this->controller->sessionsAction()->getContent(), true);

        self::assertSame($sessions, $data['sessions']);
    }

    public function testSessionActionReturnsMessagesForTheRequestedId(): void
    {
        $messages = [['role' => 'user', 'content' => 'hi']];
        $this->sessionStore->method('getMessages')->with('s1')->willReturn($messages);

        $request = new Request(['id' => 's1']);
        $data = json_decode($this->controller->sessionAction($request)->getContent(), true);

        self::assertSame('s1', $data['id']);
        self::assertSame($messages, $data['messages']);
    }
}
