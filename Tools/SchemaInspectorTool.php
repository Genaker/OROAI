<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Doctrine\DBAL\Connection;
use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;

/** AI tool to list tables and describe columns and constraints in the database schema. */
final class SchemaInspectorTool implements AiToolInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getName(): string
    {
        return 'schema_inspector';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'schema_inspector',
            'Inspect the database schema. Use "list_tables" to get all table names, or "describe_table" with a table_name to see columns and constraints. '
            . 'Use before writing a sql_query to confirm real table/column names instead of guessing them.',
            [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['list_tables', 'describe_table'],
                        'description' => 'The action to perform.',
                    ],
                    'table_name' => [
                        'type' => 'string',
                        'description' => 'Required when action is "describe_table". The table name to describe.',
                    ],
                ],
                'required' => ['action'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $action = $arguments['action'] ?? '';

        try {
            return match ($action) {
                'list_tables' => $this->listTables(),
                'describe_table' => $this->describeTable($arguments['table_name'] ?? ''),
                default => ToolResult::error(sprintf('Unknown action "%s". Use "list_tables" or "describe_table".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('Schema inspection failed: ' . $e->getMessage());
        }
    }

    private function listTables(): ToolResult
    {
        $rows = $this->connection->executeQuery(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name"
        )->fetchAllAssociative();

        $tables = array_column($rows, 'table_name');

        return ToolResult::success(['table_count' => count($tables), 'tables' => $tables]);
    }

    private function describeTable(string $tableName): ToolResult
    {
        if ($tableName === '') {
            return ToolResult::error('Parameter "table_name" is required for action "describe_table".');
        }

        $columns = $this->connection->executeQuery(
            "SELECT column_name, data_type, is_nullable, column_default
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = :name
             ORDER BY ordinal_position",
            ['name' => $tableName]
        )->fetchAllAssociative();

        if ($columns === []) {
            return ToolResult::error(sprintf('Table "%s" not found or has no columns.', $tableName));
        }

        $constraints = $this->connection->executeQuery(
            "SELECT tc.constraint_name, tc.constraint_type, kcu.column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
             WHERE tc.table_schema = 'public' AND tc.table_name = :name
             ORDER BY tc.constraint_name, kcu.ordinal_position",
            ['name' => $tableName]
        )->fetchAllAssociative();

        return ToolResult::success([
            'table' => $tableName,
            'columns' => $columns,
            'constraints' => $constraints,
        ]);
    }
}
