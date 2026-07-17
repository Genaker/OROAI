<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag;

/** Represents a text document to be embedded and stored in the RAG index. */
final readonly class RagDocument
{
    public function __construct(
        public string $id,
        public string $text,
        public string $source,
        public array $metadata = [],
    ) {
    }
}
