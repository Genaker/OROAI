<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Tools;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Genaker\Bundle\OroAI\Tools\SqlQueryTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SqlQueryToolTest extends TestCase
{
    private Connection&MockObject $connection;
    private OroAiConfig&MockObject $config;
    private SqlQueryTool $tool;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->config = $this->createMock(OroAiConfig::class);
        $this->config->method('isSqlToolEnabled')->willReturn(true);
        $this->config->method('getSqlRowLimit')->willReturn(200);

        $this->tool = new SqlQueryTool($this->connection, $this->config);
    }

    public function testGetName(): void
    {
        self::assertSame('sql_query', $this->tool->getName());
    }

    public function testGetDefinitionReturnsToolDefinition(): void
    {
        $def = $this->tool->getDefinition();

        self::assertSame('sql_query', $def->name);
        self::assertNotEmpty($def->description);
        self::assertSame('object', $def->parameters['type']);
        self::assertArrayHasKey('sql', $def->parameters['properties']);
        self::assertContains('sql', $def->parameters['required']);
    }

    public function testAcceptsSelectQuery(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([['id' => 1, 'name' => 'Test']]);

        $this->connection->method('executeStatement');
        $this->connection->method('executeQuery')
            ->willReturn($result);

        $toolResult = $this->tool->execute(['sql' => 'SELECT id, name FROM users']);

        self::assertTrue($toolResult->success);
        self::assertSame(1, $toolResult->data['row_count']);
        self::assertSame([['id' => 1, 'name' => 'Test']], $toolResult->data['rows']);
    }

    public function testAcceptsWithCteQuery(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $this->connection->method('executeStatement');
        $this->connection->method('executeQuery')->willReturn($result);

        $toolResult = $this->tool->execute([
            'sql' => 'WITH cte AS (SELECT id FROM users) SELECT * FROM cte',
        ]);

        self::assertTrue($toolResult->success);
    }

    public function testRejectsInsertQuery(): void
    {
        $toolResult = $this->tool->execute(['sql' => "INSERT INTO users (name) VALUES ('test')"]);

        self::assertFalse($toolResult->success);
        self::assertStringContainsString('Only SELECT or WITH', $toolResult->errorMessage);
    }

    public function testRejectsUpdateQuery(): void
    {
        $toolResult = $this->tool->execute(['sql' => "UPDATE users SET name = 'test' WHERE id = 1"]);

        self::assertFalse($toolResult->success);
        self::assertStringContainsString('Only SELECT or WITH', $toolResult->errorMessage);
    }

    public function testRejectsDeleteQuery(): void
    {
        $toolResult = $this->tool->execute(['sql' => 'DELETE FROM users WHERE id = 1']);

        self::assertFalse($toolResult->success);
        self::assertStringContainsString('Only SELECT or WITH', $toolResult->errorMessage);
    }

    public function testRejectsDropQuery(): void
    {
        $toolResult = $this->tool->execute(['sql' => 'DROP TABLE users']);

        self::assertFalse($toolResult->success);
        self::assertStringContainsString('Only SELECT or WITH', $toolResult->errorMessage);
    }

    public function testRejectsAlterQuery(): void
    {
        $toolResult = $this->tool->execute(['sql' => 'ALTER TABLE users ADD COLUMN age INT']);

        self::assertFalse($toolResult->success);
        self::assertStringContainsString('Only SELECT or WITH', $toolResult->errorMessage);
    }

    public function testRejectsTruncateQuery(): void
    {
        $toolResult = $this->tool->execute(['sql' => 'TRUNCATE TABLE users']);

        self::assertFalse($toolResult->success);
        self::assertStringContainsString('Only SELECT or WITH', $toolResult->errorMessage);
    }

    public function testRejectsForbiddenKeywordInSelect(): void
    {
        $toolResult = $this->tool->execute([
            'sql' => 'SELECT * FROM users; DELETE FROM users',
        ]);

        self::assertFalse($toolResult->success);
    }

    public function testRejectsMultiStatementWithTrailingSemicolon(): void
    {
        $toolResult = $this->tool->execute([
            'sql' => 'SELECT 1; DROP TABLE users;',
        ]);

        self::assertFalse($toolResult->success);
    }

    public function testRejectsSelectFollowedByDml(): void
    {
        $toolResult = $this->tool->execute([
            'sql' => 'SELECT 1; DELETE FROM users',
        ]);

        self::assertFalse($toolResult->success);
    }

    public function testAutoAppendsLimitWhenMissing(): void
    {
        $capturedSql = null;

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $this->connection->method('executeStatement');
        $this->connection->method('executeQuery')
            ->willReturnCallback(function (string $sql) use ($result, &$capturedSql): Result {
                $capturedSql = $sql;

                return $result;
            });

        $this->tool->execute(['sql' => 'SELECT * FROM users']);

        self::assertNotNull($capturedSql);
        self::assertStringContainsString('LIMIT 200', $capturedSql);
    }

    public function testDoesNotDoubleAddLimit(): void
    {
        $capturedSql = null;

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $this->connection->method('executeStatement');
        $this->connection->method('executeQuery')
            ->willReturnCallback(function (string $sql) use ($result, &$capturedSql): Result {
                $capturedSql = $sql;

                return $result;
            });

        $this->tool->execute(['sql' => 'SELECT * FROM users LIMIT 10']);

        self::assertNotNull($capturedSql);
        self::assertSame(1, substr_count(strtoupper($capturedSql), 'LIMIT'));
        self::assertStringContainsString('LIMIT 10', $capturedSql);
    }

    public function testReturnsErrorWhenDisabled(): void
    {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('isSqlToolEnabled')->willReturn(false);

        $tool = new SqlQueryTool($this->connection, $config);
        $toolResult = $tool->execute(['sql' => 'SELECT 1']);

        self::assertFalse($toolResult->success);
        self::assertSame('SQL tool is disabled in system configuration.', $toolResult->errorMessage);
    }

    public function testReturnsErrorOnEmptySql(): void
    {
        $toolResult = $this->tool->execute(['sql' => '']);

        self::assertFalse($toolResult->success);
        self::assertSame('SQL query is empty.', $toolResult->errorMessage);
    }

    public function testReturnsErrorOnMissingSql(): void
    {
        $toolResult = $this->tool->execute([]);

        self::assertFalse($toolResult->success);
        self::assertSame('SQL query is empty.', $toolResult->errorMessage);
    }

    public function testReturnsErrorOnWhitespaceSql(): void
    {
        $toolResult = $this->tool->execute(['sql' => '   ']);

        self::assertFalse($toolResult->success);
        self::assertSame('SQL query is empty.', $toolResult->errorMessage);
    }

    public function testReturnsErrorOnDatabaseException(): void
    {
        $this->connection->method('executeStatement');
        $this->connection->method('executeQuery')
            ->willThrowException(new \RuntimeException('relation "nonexistent" does not exist'));

        $toolResult = $this->tool->execute(['sql' => 'SELECT * FROM nonexistent']);

        self::assertFalse($toolResult->success);
        self::assertStringContainsString('SQL error:', $toolResult->errorMessage);
        self::assertStringContainsString('nonexistent', $toolResult->errorMessage);
    }

    public function testAssertReadOnlyAcceptsSelectWithWhitespace(): void
    {
        $this->tool->assertReadOnly('  SELECT * FROM users  ');
        $this->expectNotToPerformAssertions();
    }

    public function testAssertReadOnlyAcceptsCaseInsensitiveSelect(): void
    {
        $this->tool->assertReadOnly('select id from users');
        $this->expectNotToPerformAssertions();
    }

    public function testAssertReadOnlyRejectsCreateKeyword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CREATE');

        $this->tool->assertReadOnly('SELECT 1; CREATE TABLE test (id INT)');
    }

    public function testRejectsCommentSmuggledDml(): void
    {
        $toolResult = $this->tool->execute(['sql' => '/* SELECT */ DELETE FROM users']);

        self::assertFalse($toolResult->success);
    }

    public function testTransactionRollsBack(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $callOrder = [];
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$callOrder): int {
                $callOrder[] = $sql;

                return 0;
            });
        $this->connection->method('executeQuery')->willReturn($result);

        $this->tool->execute(['sql' => 'SELECT 1']);

        self::assertSame('START TRANSACTION READ ONLY', $callOrder[0]);
        self::assertSame('ROLLBACK', $callOrder[1]);
    }

    public function testTransactionRollsBackEvenOnError(): void
    {
        $callOrder = [];
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$callOrder): int {
                $callOrder[] = $sql;

                return 0;
            });
        $this->connection->method('executeQuery')
            ->willThrowException(new \RuntimeException('query failed'));

        $this->tool->execute(['sql' => 'SELECT 1']);

        self::assertCount(2, $callOrder);
        self::assertSame('ROLLBACK', $callOrder[1]);
    }

    public function testAssertReadOnlyRejectsInsertDirectly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->tool->assertReadOnly("INSERT INTO users VALUES (1)");
    }
}
