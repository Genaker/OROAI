<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag\Contract;

use Genaker\Bundle\OroAI\Rag\RagDocument;

interface RagProviderInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /** @return RagDocument[] */
    public function provide(): array;
}
