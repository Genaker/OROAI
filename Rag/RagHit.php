<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag;

/** Represents a single scored result returned from a RAG similarity search. */
final readonly class RagHit
{
    public function __construct(
        public string $text,
        public string $source,
        public float $score,
        public array $metadata = [],
    ) {
    }
}
