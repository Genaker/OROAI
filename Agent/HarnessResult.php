<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

/** Result from a ResolutionHarness run, extending AgentResult with harness metadata. */
final readonly class HarnessResult
{
    /**
     * @param array<int, array{tool: string, args: string, result: string}> $toolTrace
     * @param string[] $links
     * @param array{prompt_tokens: int, completion_tokens: int, total_tokens: int} $usage
     */
    public function __construct(
        public string $reply,
        public array $toolTrace = [],
        public array $links = [],
        public bool $resolved = false,
        public bool $needsClarification = false,
        public bool $memorySaved = false,
        public int $attempt = 1,
        public array $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
        /** Estimated token cost of each prompt ingredient (from the wrapped agent run). */
        public array $promptBreakdown = [],
    ) {
    }
}
