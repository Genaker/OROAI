<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag\Provider;

use Genaker\Bundle\OroAI\Rag\Contract\RagProviderInterface;
use Genaker\Bundle\OroAI\Rag\RagDocument;
use Genaker\Bundle\OroAI\Rag\TextChunker;

/**
 * Indexes Markdown files saved by ResolutionHarness in var/data/oroai-memory/.
 * Each resolved Q&A becomes a RAG document so future similar questions
 * benefit from the cached answer without re-running the full harness.
 */
final class CacheMemoryRagProvider implements RagProviderInterface
{
    public function __construct(private readonly string $memoryDir)
    {
    }

    public function getName(): string
    {
        return 'cache_memory';
    }

    public function getDescription(): string
    {
        return 'Resolved Q&A pairs saved by the harness in var/data/oroai-memory/';
    }

    public function provide(): array
    {
        if (!is_dir($this->memoryDir)) {
            return [];
        }

        $files = glob($this->memoryDir . '/*.md');
        if ($files === false || $files === []) {
            return [];
        }

        $documents = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false || trim($content) === '') {
                continue;
            }

            $source = 'memory/' . basename($file);
            $chunks = TextChunker::chunk($content);

            foreach ($chunks as $index => $chunk) {
                $documents[] = new RagDocument(
                    id: md5($file . ':' . $index),
                    text: $chunk,
                    source: $source,
                    metadata: ['file' => $file, 'chunk_index' => $index],
                );
            }
        }

        return $documents;
    }
}
