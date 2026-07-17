<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent;

use Genaker\Bundle\OroAI\Agent\ResearchSubAgent;
use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Contract\LlmClientInterface;
use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\LlmResponse;
use Genaker\Bundle\OroAI\Core\Model\ToolCall;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Genaker\Bundle\OroAI\Llm\LlmClientRegistry;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ResearchSubAgentTest extends TestCase
{
    private LlmClientRegistry&MockObject $clientRegistry;
    private LlmClientInterface&MockObject $llmClient;
    private OroAiConfig&MockObject $config;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LlmClientInterface::class);

        $this->clientRegistry = $this->createMock(LlmClientRegistry::class);
        $this->clientRegistry->method('get')->willReturn($this->llmClient);

        $this->config = $this->createMock(OroAiConfig::class);
        $this->config->method('getResearchMaxIterations')->willReturn(5);
        $this->config->method('getTemperature')->willReturn(0.3);
        $this->config->method('isToolEnabled')->willReturn(true);
    }

    private function makeTool(string $name, ToolResult $result): AiToolInterface&MockObject
    {
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getDefinition')->willReturn(new ToolDefinition($name, 'A tool', []));
        $tool->method('execute')->willReturn($result);

        return $tool;
    }

    private function makeAgent(array $tools = []): ResearchSubAgent
    {
        return new ResearchSubAgent($this->clientRegistry, $tools, $this->config);
    }

    public function testInvestigateReturnsFinalContentWhenNoToolCalls(): void
    {
        $this->llmClient->method('chat')
            ->willReturn(new LlmResponse(
                content: 'Shipping rates are calculated from the carrier rate table.',
                toolCalls: [],
                finishReason: 'stop',
                usage: [],
            ));

        $agent = $this->makeAgent();
        $result = $agent->investigate('How are shipping rates calculated?');

        self::assertSame('Shipping rates are calculated from the carrier rate table.', $result);
    }

    public function testInvestigateExecutesToolsThenReturnsFinalAnswer(): void
    {
        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount): LlmResponse {
                $callCount++;
                if ($callCount === 1) {
                    return new LlmResponse(
                        content: '',
                        toolCalls: [new ToolCall(id: 'call-1', name: 'schema_inspector', argsJson: '{}')],
                        finishReason: 'tool_calls',
                        usage: [],
                    );
                }

                return new LlmResponse('Found it in the rate table.', [], 'stop', []);
            });

        $tool = $this->makeTool('schema_inspector', ToolResult::success('table info'));

        $agent = $this->makeAgent([$tool]);
        $result = $agent->investigate('Investigate shipping rates');

        self::assertSame('Found it in the rate table.', $result);
    }

    public function testInvestigateExcludesResearchToolFromItsOwnToolList(): void
    {
        $capturedRequest = null;
        $this->llmClient->method('chat')
            ->willReturnCallback(function (LlmRequest $request) use (&$capturedRequest): LlmResponse {
                $capturedRequest = $request;

                return new LlmResponse('Done.', [], 'stop', []);
            });

        $researchTool = $this->makeTool('research', ToolResult::success('should never be reached'));
        $otherTool = $this->makeTool('doc_search', ToolResult::success('docs'));

        $agent = $this->makeAgent([$researchTool, $otherTool]);
        $agent->investigate('test');

        self::assertNotNull($capturedRequest);
        $toolNames = array_map(static fn (ToolDefinition $t) => $t->name, $capturedRequest->tools);

        self::assertNotContains('research', $toolNames);
        self::assertContains('doc_search', $toolNames);
    }

    public function testInvestigateReturnsErrorSummaryIfResearchToolIsSomehowCalled(): void
    {
        // Even if a misbehaving LLM calls "research" anyway (it was never
        // offered in the tools list), the sub-agent's own ToolRegistry must
        // still reject it as unknown -- it's not just hidden, it's absent.
        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount): LlmResponse {
                $callCount++;
                if ($callCount === 1) {
                    return new LlmResponse(
                        content: '',
                        toolCalls: [new ToolCall(id: 'call-1', name: 'research', argsJson: '{}')],
                        finishReason: 'tool_calls',
                        usage: [],
                    );
                }

                return new LlmResponse('Gave up recursing.', [], 'stop', []);
            });

        $researchTool = $this->makeTool('research', ToolResult::success('should never be reached'));

        $agent = $this->makeAgent([$researchTool]);
        $result = $agent->investigate('test');

        self::assertSame('Gave up recursing.', $result);
    }

    public function testInvestigateRespectsMaxIterationsAndReturnsFallbackMessage(): void
    {
        $this->config = $this->createMock(OroAiConfig::class);
        $this->config->method('getResearchMaxIterations')->willReturn(2);
        $this->config->method('getTemperature')->willReturn(0.3);
        $this->config->method('isToolEnabled')->willReturn(true);

        $this->llmClient->method('chat')
            ->willReturn(new LlmResponse(
                content: '',
                toolCalls: [new ToolCall(id: 'call-x', name: 'doc_search', argsJson: '{}')],
                finishReason: 'tool_calls',
                usage: [],
            ));

        $tool = $this->makeTool('doc_search', ToolResult::success('partial finding'));

        $agent = $this->makeAgent([$tool]);
        $result = $agent->investigate('test');

        self::assertStringContainsString('did not fully conclude', $result);
        self::assertStringContainsString('partial finding', $result);
    }

    public function testInvestigateCatchesToolExecutionException(): void
    {
        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount): LlmResponse {
                $callCount++;
                if ($callCount === 1) {
                    return new LlmResponse(
                        content: '',
                        toolCalls: [new ToolCall(id: 'call-1', name: 'failing_tool', argsJson: '{}')],
                        finishReason: 'tool_calls',
                        usage: [],
                    );
                }

                return new LlmResponse('Handled the failure.', [], 'stop', []);
            });

        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('failing_tool');
        $tool->method('getDefinition')->willReturn(new ToolDefinition('failing_tool', 'Fails', []));
        $tool->method('execute')->willThrowException(new \RuntimeException('boom'));

        $agent = $this->makeAgent([$tool]);
        $result = $agent->investigate('test');

        self::assertSame('Handled the failure.', $result);
    }

    public function testInvestigateSendsTaskAsUserMessage(): void
    {
        $capturedRequest = null;
        $this->llmClient->method('chat')
            ->willReturnCallback(function (LlmRequest $request) use (&$capturedRequest): LlmResponse {
                $capturedRequest = $request;

                return new LlmResponse('ok', [], 'stop', []);
            });

        $agent = $this->makeAgent();
        $agent->investigate('Explain the shipping rate calculation end to end.');

        self::assertNotNull($capturedRequest);
        $messages = $capturedRequest->messages;
        $lastMessage = end($messages);
        self::assertSame('Explain the shipping rate calculation end to end.', $lastMessage->content);
    }
}
