<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

final readonly class LlmRequest
{
    /**
     * @param ChatMessage[] $messages
     * @param ToolDefinition[] $tools
     */
    public function __construct(
        public array $messages,
        public array $tools = [],
        public float $temperature = 0.3,
        public ?int $maxTokens = null,
    ) {}
}
