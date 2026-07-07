<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Controller;

use Genaker\Bundle\OroAI\Agent\OroAiAgent;
use Genaker\Bundle\OroAI\Controller\ChatController;
use Genaker\Bundle\OroAI\Core\Model\AgentResult;
use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

final class ChatControllerTest extends TestCase
{
    private OroAiAgent&MockObject $agent;
    private OroAiConfig&MockObject $config;
    private Environment&MockObject $twig;
    private ChatController $controller;

    protected function setUp(): void
    {
        // Role enum is co-defined in ChatMessage.php; ensure it's loaded before any test
        // that may invoke parseHistory (which calls Role::tryFrom).
        class_exists(ChatMessage::class, true);

        $this->agent  = $this->createMock(OroAiAgent::class);
        $this->config = $this->createMock(OroAiConfig::class);
        $this->twig   = $this->createMock(Environment::class);
        $this->controller = new ChatController($this->agent, $this->config, $this->twig);
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

    public function testMessageActionReturnsAgentReply(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->agent->method('run')
            ->with('Where are customer users?', [])
            ->willReturn(new AgentResult(
                'Customer users are at /admin/customer/user/',
                [['tool' => 'entity_url', 'args' => '{}', 'result' => '/admin/customer/user/']],
                ['/admin/customer/user/'],
            ));

        $request = new Request([], [], [], [], [], [], json_encode([
            'message' => 'Where are customer users?',
            'history' => [],
        ]));
        $response = $this->controller->messageAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('customer/user', $data['reply']);
        self::assertCount(1, $data['tool_trace']);
        self::assertContains('/admin/customer/user/', $data['links']);
    }

    public function testMessageActionHandlesAgentException(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->agent->method('run')
            ->willThrowException(new \RuntimeException('LLM API timeout'));

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test']));
        $response = $this->controller->messageAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('LLM API timeout', $data['error']);
    }

    public function testMessageAction403ReturnsFirewallMessage(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getProvider')->willReturn('openai');

        $this->agent->method('run')
            ->willThrowException(new \RuntimeException(
                'HTTP/1.1 403 Forbidden returned for "https://api.openai.com/v1/chat/completions".'
            ));

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test']));
        $response = $this->controller->messageAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('firewall', $data['error']);
        self::assertStringContainsString('api.openai.com', $data['error']);
    }

    public function testMessageAction401ReturnsKeyMessage(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getProvider')->willReturn('openai');

        $this->agent->method('run')
            ->willThrowException(new \RuntimeException(
                'HTTP/1.1 401 Unauthorized returned for "https://api.openai.com/v1/chat/completions".'
            ));

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test']));
        $response = $this->controller->messageAction($request);

        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('Invalid API key', $data['error']);
    }

    public function testMessageAction429ReturnsRateLimitMessage(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getProvider')->willReturn('openai');

        $this->agent->method('run')
            ->willThrowException(new \RuntimeException('HTTP/1.1 429 Too Many Requests'));

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test']));
        $response = $this->controller->messageAction($request);

        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('rate limit', $data['error']);
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

    public function testMessageActionParsesHistory(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->agent->expects(self::once())
            ->method('run')
            ->willReturnCallback(function (string $msg, array $history) {
                self::assertCount(2, $history);
                self::assertSame('user', $history[0]->role->value);
                self::assertSame('previous question', $history[0]->content);
                self::assertSame('assistant', $history[1]->role->value);
                self::assertSame('previous answer', $history[1]->content);

                return new AgentResult('follow-up answer', [], []);
            });

        $request = new Request([], [], [], [], [], [], json_encode([
            'message' => 'follow up',
            'history' => [
                ['role' => 'user', 'content' => 'previous question'],
                ['role' => 'assistant', 'content' => 'previous answer'],
            ],
        ]));

        $response = $this->controller->messageAction($request);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testMessageActionIgnoresInvalidHistoryRoles(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->agent->expects(self::once())
            ->method('run')
            ->willReturnCallback(function (string $msg, array $history) {
                self::assertCount(1, $history);

                return new AgentResult('ok', [], []);
            });

        $request = new Request([], [], [], [], [], [], json_encode([
            'message' => 'test',
            'history' => [
                ['role' => 'invalid_role', 'content' => 'should be skipped'],
                ['role' => 'user', 'content' => 'valid'],
            ],
        ]));

        $this->controller->messageAction($request);
    }
}
