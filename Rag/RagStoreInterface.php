<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag;

interface RagStoreInterface
{
    /** @param RagDocument[] $documents */
    public function index(array $documents): void;

    /** @return RagHit[] */
    public function search(string $query, int $topK = 5): array;

    public function clear(): void;
}
