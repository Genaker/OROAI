<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent;

use Genaker\Bundle\OroAI\Agent\ToolRegistry;
use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    private OroAiConfig&MockObject $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(OroAiConfig::class);
        $this->config->method('isToolEnabled')->willReturn(true);
    }

    public function testDefinitionsReturnsAllToolDefinitions(): void
    {
        $tool1 = $this->createToolMock('sql_query', 'Execute SQL');
        $tool2 = $this->createToolMock('entity_url', 'Resolve entity URL');

        $registry = new ToolRegistry([$tool1, $tool2], $this->config);
        $definitions = $registry->definitions();

        self::assertCount(2, $definitions);
        self::assertSame('sql_query', $definitions[0]->name);
        self::assertSame('entity_url', $definitions[1]->name);
    }

    public function testDefinitionsReturnsEmptyForEmptyRegistry(): void
    {
        $registry = new ToolRegistry([], $this->config);
        self::assertSame([], $registry->definitions());
    }

    public function testExecuteCallsCorrectTool(): void
    {
        $expectedResult = ToolResult::success(['data' => 'test']);

        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('sql_query');
        $tool->method('getDefinition')->willReturn(
            new ToolDefinition('sql_query', 'desc', [])
        );
        $tool->expects(self::once())
            ->method('execute')
            ->with(['sql' => 'SELECT 1'])
            ->willReturn($expectedResult);

        $registry = new ToolRegistry([$tool], $this->config);
        $result = $registry->execute('sql_query', ['sql' => 'SELECT 1']);

        self::assertTrue($result->success);
        self::assertSame(['data' => 'test'], $result->data);
    }

    public function testExecuteReturnsErrorForUnknownTool(): void
    {
        $tool = $this->createToolMock('sql_query', 'Execute SQL');
        $registry = new ToolRegistry([$tool], $this->config);

        $result = $registry->execute('nonexistent_tool', []);

        self::assertFalse($result->success);
        self::assertStringContainsString('Unknown tool', $result->errorMessage);
        self::assertStringContainsString('nonexistent_tool', $result->errorMessage);
        self::assertStringContainsString('sql_query', $result->errorMessage);
    }

    public function testExecuteReturnsErrorForEmptyRegistry(): void
    {
        $registry = new ToolRegistry([], $this->config);
        $result = $registry->execute('any_tool', []);

        self::assertFalse($result->success);
        self::assertStringContainsString('Unknown tool', $result->errorMessage);
    }

    public function testHasReturnsTrueForRegisteredTool(): void
    {
        $tool = $this->createToolMock('sql_query', 'Execute SQL');
        $registry = new ToolRegistry([$tool], $this->config);

        self::assertTrue($registry->has('sql_query'));
    }

    public function testHasReturnsFalseForUnregisteredTool(): void
    {
        $tool = $this->createToolMock('sql_query', 'Execute SQL');
        $registry = new ToolRegistry([$tool], $this->config);

        self::assertFalse($registry->has('unknown'));
    }

    public function testHasReturnsFalseForEmptyRegistry(): void
    {
        $registry = new ToolRegistry([], $this->config);
        self::assertFalse($registry->has('anything'));
    }

    public function testNamesReturnsAllToolNames(): void
    {
        $tool1 = $this->createToolMock('sql_query', 'Execute SQL');
        $tool2 = $this->createToolMock('entity_url', 'Resolve URL');
        $tool3 = $this->createToolMock('schema_inspector', 'Inspect schema');

        $registry = new ToolRegistry([$tool1, $tool2, $tool3], $this->config);

        self::assertSame(['sql_query', 'entity_url', 'schema_inspector'], $registry->names());
    }

    public function testNamesReturnsEmptyForEmptyRegistry(): void
    {
        $registry = new ToolRegistry([], $this->config);
        self::assertSame([], $registry->names());
    }

    public function testLastRegisteredToolWinsOnDuplicateName(): void
    {
        $tool1 = $this->createMock(AiToolInterface::class);
        $tool1->method('getName')->willReturn('sql_query');
        $tool1->method('getDefinition')->willReturn(new ToolDefinition('sql_query', 'first', []));
        $tool1->method('execute')->willReturn(ToolResult::success('first'));

        $tool2 = $this->createMock(AiToolInterface::class);
        $tool2->method('getName')->willReturn('sql_query');
        $tool2->method('getDefinition')->willReturn(new ToolDefinition('sql_query', 'second', []));
        $tool2->method('execute')->willReturn(ToolResult::success('second'));

        $registry = new ToolRegistry([$tool1, $tool2], $this->config);

        self::assertCount(1, $registry->names());
        $result = $registry->execute('sql_query', []);
        self::assertSame('second', $result->data);
    }

    public function testDefinitionsPreservesOrder(): void
    {
        $tool1 = $this->createToolMock('alpha', 'Alpha tool');
        $tool2 = $this->createToolMock('beta', 'Beta tool');
        $tool3 = $this->createToolMock('gamma', 'Gamma tool');

        $registry = new ToolRegistry([$tool1, $tool2, $tool3], $this->config);
        $definitions = $registry->definitions();

        self::assertSame('alpha', $definitions[0]->name);
        self::assertSame('beta', $definitions[1]->name);
        self::assertSame('gamma', $definitions[2]->name);
    }

    public function testDisabledToolExcludedFromDefinitions(): void
    {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('isToolEnabled')->willReturnMap([
            ['sql_query', true],
            ['log_reader', false],
        ]);

        $tool1 = $this->createToolMock('sql_query', 'Execute SQL');
        $tool2 = $this->createToolMock('log_reader', 'Read logs');

        $registry = new ToolRegistry([$tool1, $tool2], $config);

        self::assertCount(1, $registry->definitions());
        self::assertSame('sql_query', $registry->definitions()[0]->name);
    }

    public function testDisabledToolNotReturnedByNames(): void
    {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('isToolEnabled')->willReturnMap([
            ['sql_query', true],
            ['system_info', false],
        ]);

        $registry = new ToolRegistry([
            $this->createToolMock('sql_query', 'SQL'),
            $this->createToolMock('system_info', 'Info'),
        ], $config);

        self::assertSame(['sql_query'], $registry->names());
    }

    public function testDisabledToolHasReturnsFalse(): void
    {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('isToolEnabled')->willReturnMap([
            ['log_reader', false],
        ]);

        $registry = new ToolRegistry([$this->createToolMock('log_reader', 'Logs')], $config);

        self::assertFalse($registry->has('log_reader'));
    }

    public function testExecuteDisabledToolReturnsError(): void
    {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('isToolEnabled')->willReturnMap([
            ['system_info', false],
        ]);

        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn('system_info');
        $tool->method('getDefinition')->willReturn(new ToolDefinition('system_info', 'info', []));
        $tool->expects(self::never())->method('execute');

        $registry = new ToolRegistry([$tool], $config);
        $result = $registry->execute('system_info', []);

        self::assertFalse($result->success);
        self::assertStringContainsString('disabled', $result->errorMessage);
    }

    private function createToolMock(string $name, string $description): AiToolInterface
    {
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getDefinition')->willReturn(
            new ToolDefinition($name, $description, ['type' => 'object', 'properties' => []])
        );
        $tool->method('execute')->willReturn(ToolResult::success(null));

        return $tool;
    }
}
