<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag;

interface EmbeddingClientInterface
{
    /** @return float[] */
    public function embed(string $text): array;

    /** @return float[][] */
    public function embedBatch(array $texts): array;

    public function getDimension(): int;
}
