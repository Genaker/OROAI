<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

final readonly class AgentResult
{
    /**
     * @param array<int, array{tool: string, args: array, result: string}> $toolTrace
     * @param string[] $links
     */
    public function __construct(
        public string $reply,
        public array $toolTrace = [],
        public array $links = [],
    ) {}
}
