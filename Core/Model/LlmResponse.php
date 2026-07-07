<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

final readonly class LlmResponse
{
    /**
     * @param ToolCall[] $toolCalls
     * @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int} $usage
     */
    public function __construct(
        public string $content,
        public array $toolCalls,
        public ?string $finishReason,
        public array $usage,
    ) {}
}
