<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

final readonly class ChatMessage
{
    /**
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        public Role $role,
        public string $content,
        public ?string $toolCallId = null,
        public ?string $name = null,
        public array $toolCalls = [],
    ) {}

    public static function system(string $content): self
    {
        return new self(role: Role::System, content: $content);
    }

    public static function user(string $content): self
    {
        return new self(role: Role::User, content: $content);
    }

    public static function assistantToolCalls(LlmResponse $response): self
    {
        return new self(
            role: Role::Assistant,
            content: $response->content,
            toolCalls: $response->toolCalls,
        );
    }

    public static function toolResult(string $toolCallId, string $content, string $name): self
    {
        return new self(
            role: Role::Tool,
            content: $content,
            toolCallId: $toolCallId,
            name: $name,
        );
    }
}
