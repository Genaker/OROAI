<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

use Genaker\Bundle\OroAI\Rag\RagDocument;
use Genaker\Bundle\OroAI\Rag\RagStoreInterface;
use Genaker\Bundle\OroAI\Service\OroAiConfig;

/** Records successful SQL queries as RAG documents to improve future responses. */
final class LearningRecorder
{
    public function __construct(
        private readonly RagStoreInterface $ragStore,
        private readonly OroAiConfig $config,
    ) {
    }

    public function recordSuccessfulQuery(string $question, string $workingSql, string $failedSql): void
    {
        if (!$this->config->isLearningEnabled()) {
            return;
        }

        $doc = new RagDocument(
            id: 'learned_' . md5($question . $workingSql),
            text: sprintf(
                "Question: %s\nWorking SQL: %s\nFailed SQL: %s",
                $question,
                $workingSql,
                $failedSql,
            ),
            source: 'learned_query',
            metadata: [
                'question' => $question,
                'working_sql' => $workingSql,
                'failed_sql' => $failedSql,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        );

        try {
            $this->ragStore->index([$doc]);
        } catch (\Throwable) {
            // intentional
        }
    }
}
