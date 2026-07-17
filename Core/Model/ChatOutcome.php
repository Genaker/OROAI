<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

/**
 * The single result shape a chat turn produces, whether it ran through the
 * plain agent or the resolution harness — so ChatController serializes ONE
 * thing instead of hand-assembling two slightly different JSON payloads.
 * Harness-only fields are null on plain-agent runs and omitted from the JSON.
 */
final class ChatOutcome
{
    public function __construct(
        public readonly string $reply,
        public readonly array $toolTrace,
        public readonly array $links,
        public readonly array $usage,
        public readonly array $promptBreakdown,
        public readonly ?array $cost,
        public readonly ?string $sessionId,
        public readonly ?int $harnessAttempt = null,
        public readonly ?bool $memorySaved = null,
        public readonly ?bool $needsClarification = null,
    ) {
    }

    /** The chat/message JSON payload, keys matching what the widget JS reads. */
    public function toArray(): array
    {
        $payload = [
            'reply' => $this->reply,
            'tool_trace' => $this->toolTrace,
            'links' => $this->links,
            'usage' => $this->usage,
            'token_breakdown' => $this->promptBreakdown,
            'cost' => $this->cost,
            'session_id' => $this->sessionId,
        ];

        if ($this->harnessAttempt !== null) {
            $payload['harness_attempt'] = $this->harnessAttempt;
            $payload['memory_saved'] = $this->memorySaved;
            $payload['needs_clarification'] = $this->needsClarification;
        }

        return $payload;
    }
}
