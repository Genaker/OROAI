<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Genaker\Bundle\OroAI\Rag\RagStoreInterface;
use Genaker\Bundle\OroAI\Service\OroAiConfig;

final class DocSearchTool implements AiToolInterface
{
    public function __construct(
        private readonly RagStoreInterface $ragStore,
        private readonly OroAiConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'doc_search';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'doc_search',
            'Search documentation and knowledge base for answers about OroCommerce features, configuration, and usage.',
            [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'The search query describing what you want to find.',
                    ],
                    'top_k' => [
                        'type' => 'integer',
                        'description' => 'Number of top results to return. Defaults to 5.',
                    ],
                ],
                'required' => ['query'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        if (!$this->config->isRagEnabled()) {
            return ToolResult::error('RAG is disabled in system configuration.');
        }

        $query = trim($arguments['query'] ?? '');
        if ($query === '') {
            return ToolResult::error('Parameter "query" is required.');
        }

        $topK = (int) ($arguments['top_k'] ?? 5);

        try {
            $hits = $this->ragStore->search($query, $topK);

            $results = [];
            foreach ($hits as $hit) {
                $results[] = [
                    'text' => $hit->text,
                    'source' => $hit->source,
                    'score' => $hit->score,
                ];
            }

            return ToolResult::success(['query' => $query, 'count' => count($results), 'results' => $results]);
        } catch (\Throwable $e) {
            return ToolResult::error('Documentation search failed: ' . $e->getMessage());
        }
    }
}
