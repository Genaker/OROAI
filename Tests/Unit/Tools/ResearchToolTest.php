<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Tools;

use Genaker\Bundle\OroAI\Agent\ResearchAgentInterface;
use Genaker\Bundle\OroAI\Tools\ResearchTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ResearchToolTest extends TestCase
{
    private ResearchAgentInterface&MockObject $subAgent;
    private ResearchTool $tool;

    protected function setUp(): void
    {
        $this->subAgent = $this->createMock(ResearchAgentInterface::class);
        $this->tool = new ResearchTool($this->subAgent);
    }

    public function testGetName(): void
    {
        self::assertSame('research', $this->tool->getName());
    }

    public function testGetDefinitionRequiresTaskParameter(): void
    {
        $definition = $this->tool->getDefinition();

        self::assertSame('research', $definition->name);
        self::assertContains('task', $definition->parameters['required']);
        self::assertArrayHasKey('task', $definition->parameters['properties']);
    }

    public function testExecuteDelegatesToSubAgentAndReturnsSummary(): void
    {
        $this->subAgent->expects(self::once())
            ->method('investigate')
            ->with('Explain shipping rates end to end')
            ->willReturn('Shipping rates come from the carrier rate table.');

        $result = $this->tool->execute(['task' => 'Explain shipping rates end to end']);

        self::assertTrue($result->success);
        self::assertSame('Explain shipping rates end to end', $result->data['task']);
        self::assertSame('Shipping rates come from the carrier rate table.', $result->data['summary']);
    }

    public function testExecuteReturnsErrorWhenTaskIsMissing(): void
    {
        $this->subAgent->expects(self::never())->method('investigate');

        $result = $this->tool->execute([]);

        self::assertFalse($result->success);
        self::assertStringContainsString('task', $result->errorMessage);
        self::assertStringContainsString('required', $result->errorMessage);
    }

    public function testExecuteReturnsErrorWhenTaskIsBlank(): void
    {
        $this->subAgent->expects(self::never())->method('investigate');

        $result = $this->tool->execute(['task' => '   ']);

        self::assertFalse($result->success);
    }

    public function testExecuteCatchesSubAgentException(): void
    {
        $this->subAgent->method('investigate')->willThrowException(new \RuntimeException('LLM timeout'));

        $result = $this->tool->execute(['task' => 'test']);

        self::assertFalse($result->success);
        self::assertStringContainsString('Research sub-agent failed', $result->errorMessage);
        self::assertStringContainsString('LLM timeout', $result->errorMessage);
    }
}
