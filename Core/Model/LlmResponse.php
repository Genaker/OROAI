<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

/** Holds the LLM's response: text content, any tool calls requested, and usage metadata. */
final readonly class LlmResponse
{
    /**
     * @param ToolCall[] $toolCalls
     * @param array{prompt_tokens: int, completion_tokens: int, total_tokens: int} $usage
     */
    public function __construct(
        public string $content,
        public array $toolCalls,
        public ?string $finishReason,
        public array $usage,
    ) {
    }

    /**
     * Maps a provider's raw usage payload to the common
     * {prompt_tokens, completion_tokens, total_tokens} shape every LlmClient
     * must produce, so callers (usage aggregation, cost display) never need
     * to know which provider answered.
     *
     * Each provider uses different key names (OpenAI: prompt_tokens/
     * completion_tokens/total_tokens; Anthropic: input_tokens/output_tokens,
     * no total; Gemini: promptTokenCount/candidatesTokenCount/
     * totalTokenCount) -- passing the raw payload straight through used to
     * silently produce all-zero usage for two of the three providers.
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    public static function normalizeUsage(
        array $raw,
        string $promptKey,
        string $completionKey,
        ?string $totalKey = null,
        ?string $thinkingKey = null
    ): array {
        $prompt = (int) ($raw[$promptKey] ?? 0);
        $completion = (int) ($raw[$completionKey] ?? 0);
        $total = $totalKey !== null && isset($raw[$totalKey]) ? (int) $raw[$totalKey] : $prompt + $completion;
        // Reasoning/thinking tokens (e.g. Gemini usageMetadata.thoughtsTokenCount) —
        // billed like output but not part of the visible reply.
        $thinking = $thinkingKey !== null ? (int) ($raw[$thinkingKey] ?? 0) : 0;

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
            'thinking_tokens' => $thinking,
        ];
    }

    /**
     * Adds two normalized usage arrays together, key by key. A tool-calling
     * turn makes several LLM calls (and the resolution harness's evaluator
     * makes its own on top of that), so callers need to accumulate usage
     * across all of them rather than reporting only the last call's cost.
     *
     * @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int} $a
     * @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int} $b
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    public static function sumUsage(array $a, array $b): array
    {
        return [
            'prompt_tokens' => ($a['prompt_tokens'] ?? 0) + ($b['prompt_tokens'] ?? 0),
            'completion_tokens' => ($a['completion_tokens'] ?? 0) + ($b['completion_tokens'] ?? 0),
            'total_tokens' => ($a['total_tokens'] ?? 0) + ($b['total_tokens'] ?? 0),
            'thinking_tokens' => ($a['thinking_tokens'] ?? 0) + ($b['thinking_tokens'] ?? 0),
        ];
    }
}
