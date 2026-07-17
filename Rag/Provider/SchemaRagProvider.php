<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag\Provider;

use Doctrine\DBAL\Connection;
use Genaker\Bundle\OroAI\Rag\Contract\RagProviderInterface;
use Genaker\Bundle\OroAI\Rag\RagDocument;

/** Provides RAG documents by reading the database schema from information_schema. */
final class SchemaRagProvider implements RagProviderInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'schema';
    }

    public function getDescription(): string
    {
        return 'Database schema (tables, columns, types) from information_schema';
    }

    public function provide(): array
    {
        $rows = $this->connection->executeQuery(
            "SELECT table_name, column_name, data_type, is_nullable
             FROM information_schema.columns
             WHERE table_schema = 'public'
             ORDER BY table_name, ordinal_position"
        )->fetchAllAssociative();

        $tables = [];
        foreach ($rows as $row) {
            $tables[$row['table_name']][] = $row;
        }

        $documents = [];

        foreach ($tables as $tableName => $columns) {
            $lines = ["Table: $tableName", 'Columns:'];
            foreach ($columns as $col) {
                $nullable = $col['is_nullable'] === 'YES' ? ', nullable' : '';
                $lines[] = sprintf('  - %s (%s%s)', $col['column_name'], $col['data_type'], $nullable);
            }

            $documents[] = new RagDocument(
                id: md5('schema:' . $tableName),
                text: implode("\n", $lines),
                source: 'db_schema:' . $tableName,
            );
        }

        return $documents;
    }
}
