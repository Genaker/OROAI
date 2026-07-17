<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

use Genaker\Bundle\OroAI\Core\Model\ChatMessage;

/**
 * Token-aware trimming of conversation history before it enters the prompt.
 *
 * The old widget sent history.slice(-20) — a MESSAGE count, blind to size: 20
 * turns of pasted SQL output could blow the budget while 20 one-liners wasted
 * headroom. This trims by estimated tokens instead: newest messages are kept,
 * oldest fall off once the budget is spent. The last exchange is always kept
 * whole, even over budget — answering without the question makes no sense.
 *
 * Estimates use the same ~4 chars/token heuristic as the widget's token bar;
 * fine for a trimming decision, never used for billing. A later iteration can
 * summarize evicted turns into one system line (Claude-Code-style compaction)
 * instead of dropping them.
 */
class ContextWindowManager
{
    /**
     * Default history budget in estimated tokens. Deliberately well under any
     * model's context limit — history competes with tool definitions, RAG hits
     * and tool results for attention (and input-token cost) on every iteration
     * of the agent loop, so more history is not automatically better.
     */
    public const int DEFAULT_BUDGET_TOKENS = 6_000;

    /** Newest messages always kept regardless of budget (one full exchange). */
    private const int MIN_KEEP_MESSAGES = 2;

    public function __construct(
        private readonly int $budgetTokens = self::DEFAULT_BUDGET_TOKENS,
    ) {
    }

    /**
     * @param ChatMessage[] $messages oldest first, as stored
     * @return ChatMessage[] the newest suffix that fits the budget, oldest first
     */
    public function trim(array $messages): array
    {
        $kept = [];
        $spent = 0;
        foreach (array_reverse($messages) as $message) {
            $size = $this->estimateTokens($message->content);
            if ($spent + $size > $this->budgetTokens && count($kept) >= self::MIN_KEEP_MESSAGES) {
                break;
            }
            $kept[] = $message;
            $spent += $size;
        }

        return array_reverse($kept);
    }

    /** Rough token estimate: ~4 characters per token. */
    public function estimateTokens(string $text): int
    {
        return intdiv(mb_strlen($text) + 3, 4);
    }
}
