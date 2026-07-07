<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag;

use Doctrine\DBAL\Connection;
use Genaker\Bundle\OroAI\Rag\Contract\RagProviderInterface;

final class RagIndexer
{
    public function __construct(
        private readonly RagStoreInterface $store,
        private readonly EmbeddingClientInterface $embedder,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Index all documents from a single provider and return the count indexed.
     */
    public function indexFromProvider(RagProviderInterface $provider): int
    {
        $documents = $provider->provide();

        if ($documents === []) {
            return 0;
        }

        $this->store->index($documents);

        return count($documents);
    }

    /**
     * Kept for backwards compatibility — prefer indexFromProvider() with DocFilesRagProvider.
     */
    public function indexDocFiles(string $directory): int
    {
        $files = glob(rtrim($directory, '/') . '/*.md');
        if ($files === false || $files === []) {
            return 0;
        }

        $documents = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false || trim($content) === '') {
                continue;
            }

            $basename = basename($file);
            $chunks = TextChunker::chunk($content);

            foreach ($chunks as $index => $chunk) {
                $documents[] = new RagDocument(
                    id: md5($basename . ':' . $index),
                    text: $chunk,
                    source: $basename,
                    metadata: ['file' => $file, 'chunk_index' => $index],
                );
            }
        }

        if ($documents === []) {
            return 0;
        }

        $this->store->index($documents);

        return count($documents);
    }

    /**
     * Kept for backwards compatibility — prefer indexFromProvider() with SchemaRagProvider.
     */
    public function indexSchema(Connection $connection): int
    {
        $rows = $connection->executeQuery(
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

        if ($documents === []) {
            return 0;
        }

        $this->store->index($documents);

        return count($documents);
    }
}
