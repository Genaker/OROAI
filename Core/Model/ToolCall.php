<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

/** Represents a single tool-call request returned by the LLM. */
final readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public string $argsJson,
        /**
         * Gemini-specific: the opaque "thought signature" Gemini's "thinking"
         * models (e.g. gemini-flash-latest) attach to each functionCall part.
         * Must be echoed back verbatim on that same call in the next turn's
         * conversation history, or Gemini rejects the whole request with a
         * 400 ("Function call is missing a thought_signature..."). Always
         * null for other providers, which don't have this concept.
         */
        public ?string $thoughtSignature = null,
    ) {
    }
}
