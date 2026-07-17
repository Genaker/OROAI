<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

/** Holds the final reply, tool-call trace, and extracted admin links from an agent run. */
final readonly class AgentResult
{
    /**
     * @param array<int, array{tool: string, tool_description: string, args: array, result: string}> $toolTrace
     * @param string[] $links
     * @param array{prompt_tokens: int, completion_tokens: int, total_tokens: int} $usage
     */
    public function __construct(
        public string $reply,
        public array $toolTrace = [],
        public array $links = [],
        public array $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
        /** Estimated (~chars/4) token cost of each prompt ingredient, for the widget's token bar. */
        public array $promptBreakdown = [],
    ) {
    }
}
