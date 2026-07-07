<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Tools;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Genaker\Bundle\OroAI\Tools\SchemaInspectorTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SchemaInspectorToolTest extends TestCase
{
    private Connection&MockObject $connection;
    private SchemaInspectorTool $tool;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->tool = new SchemaInspectorTool($this->connection);
    }

    public function testGetName(): void
    {
        self::assertSame('schema_inspector', $this->tool->getName());
    }

    public function testGetDefinition(): void
    {
        $def = $this->tool->getDefinition();

        self::assertSame('schema_inspector', $def->name);
        self::assertNotEmpty($def->description);
        self::assertArrayHasKey('action', $def->parameters['properties']);
        self::assertContains('action', $def->parameters['required']);
    }

    public function testListTablesAction(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['table_name' => 'oro_user'],
            ['table_name' => 'oro_customer'],
            ['table_name' => 'oro_order'],
        ]);

        $this->connection->method('executeQuery')->willReturn($result);

        $toolResult = $this->tool->execute(['action' => 'list_tables']);

        self::assertTrue($toolResult->success);
        self::assertSame(3, $toolResult->data['table_count']);
        self::assertSame(['oro_user', 'oro_customer', 'oro_order'], $toolResult->data['tables']);
    }

    public function testListTablesReturnsEmptyWhenNoTables(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $this->connection->method('executeQuery')->willReturn($result);

        $toolResult = $this->tool->execute(['action' => 'list_tables']);

        self::assertTrue($toolResult->success);
        self::assertSame(0, $toolResult->data['table_count']);
        self::assertSame([], $toolResult->data['tables']);
    }

    public function testDescribeTableAction(): void
    {
        $columnsResult = $this->createMock(Result::class);
        $columnsResult->method('fetchAllAssociative')->willReturn([
            ['column_name' => 'id', 'data_type' => 'integer', 'is_nullable' => 'NO', 'column_default' => "nextval('oro_user_id_seq')"],
            ['column_name' => 'username', 'data_type' => 'character varying', 'is_nullable' => 'NO', 'column_default' => null],
            ['column_name' => 'email', 'data_type' => 'character varying', 'is_nullable' => 'YES', 'column_default' => null],
        ]);

        $constraintsResult = $this->createMock(Result::class);
        $constraintsResult->method('fetchAllAssociative')->willReturn([
            ['constraint_name' => 'oro_user_pkey', 'constraint_type' => 'PRIMARY KEY', 'column_name' => 'id'],
            ['constraint_name' => 'oro_user_username_uniq', 'constraint_type' => 'UNIQUE', 'column_name' => 'username'],
        ]);

        $callCount = 0;
        $this->connection->method('executeQuery')
            ->willReturnCallback(function () use ($columnsResult, $constraintsResult, &$callCount): Result {
                $callCount++;

                return $callCount === 1 ? $columnsResult : $constraintsResult;
            });

        $toolResult = $this->tool->execute(['action' => 'describe_table', 'table_name' => 'oro_user']);

        self::assertTrue($toolResult->success);
        self::assertSame('oro_user', $toolResult->data['table']);
        self::assertCount(3, $toolResult->data['columns']);
        self::assertSame('id', $toolResult->data['columns'][0]['column_name']);
        self::assertSame('integer', $toolResult->data['columns'][0]['data_type']);
        self::assertCount(2, $toolResult->data['constraints']);
        self::assertSame('PRIMARY KEY', $toolResult->data['constraints'][0]['constraint_type']);
    }

    public function testDescribeTableReturnsErrorForEmptyTableName(): void
    {
        $toolResult = $this->tool->execute(['action' => 'describe_table', 'table_name' => '']);

        self::assertFalse($toolResult->success);
        self::assertStringContainsString('table_name', $toolResult->errorMessage);
        self::assertStringContainsString('required', $toolResult->errorMessage);
    }

    public function testDescribeTableReturnsErrorForMissingTableName(): void
    {
        $toolResult = $this->tool->execute(['action' => 'describe_table']);

        self::assertFalse($toolResult->success);
        self::assertStringContainsString('table_name', $toolResult->errorMessage);
    }

    public function testDescribeTableReturnsErrorForNonexistentTable(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $this->connection->method('executeQuery')->willReturn($result);

        $toolResult = $this->tool->execute(['action' => 'describe_table', 'table_name' => 'nonexistent']);

        self::assertFalse($toolResult->success);
        self::assertStringContainsString('nonexistent', $toolResult->errorMessage);
        self::assertStringContainsString('not found', $toolResult->errorMessage);
    }

    public function testUnknownActionReturnsError(): void
    {
        $toolResult = $this->tool->execute(['action' => 'drop_table']);

        self::assertFalse($toolResult->success);
        self::assertStringContainsString('Unknown action', $toolResult->errorMessage);
        self::assertStringContainsString('drop_table', $toolResult->errorMessage);
    }

    public function testEmptyActionReturnsError(): void
    {
        $toolResult = $this->tool->execute(['action' => '']);

        self::assertFalse($toolResult->success);
    }

    public function testMissingActionReturnsError(): void
    {
        $toolResult = $this->tool->execute([]);

        self::assertFalse($toolResult->success);
    }

    public function testHandlesDatabaseException(): void
    {
        $this->connection->method('executeQuery')
            ->willThrowException(new \RuntimeException('connection refused'));

        $toolResult = $this->tool->execute(['action' => 'list_tables']);

        self::assertFalse($toolResult->success);
        self::assertStringContainsString('Schema inspection failed', $toolResult->errorMessage);
        self::assertStringContainsString('connection refused', $toolResult->errorMessage);
    }
}
