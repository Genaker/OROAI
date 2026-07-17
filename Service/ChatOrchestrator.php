<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Service;

use Genaker\Bundle\OroAI\Agent\ContextWindowManager;
use Genaker\Bundle\OroAI\Agent\HarnessInterface;
use Genaker\Bundle\OroAI\Agent\OroAiAgent;
use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\ChatOutcome;
use Genaker\Bundle\OroAI\Core\Model\Role;

/**
 * Application service for one chat turn: everything between "HTTP gave us a
 * message" and "here is the outcome to serialize". Runs the harness or the
 * plain agent (one code path, not two), and owns the cross-cutting plumbing
 * ChatController used to duplicate per branch: transcript session + final log,
 * cost estimation, session persistence.
 *
 * Conversation history is loaded SERVER-side from ChatSessionStore by session
 * id and trimmed by token budget (ContextWindowManager) — the widget sends
 * only the new message. The client no longer supplies history at all: one
 * source of truth, no oversized payloads, and no way for a client to inject
 * fabricated assistant turns into the prompt.
 *
 * Not final: ChatController's unit tests mock it.
 */
class ChatOrchestrator
{
    public function __construct(
        private readonly OroAiAgent $agent,
        private readonly HarnessInterface $harness,
        private readonly OroAiConfig $config,
        private readonly ContextWindowManager $contextWindow,
        private readonly TokenCostEstimator $costEstimator,
        private readonly ChatTranscriptLogger $transcript,
        private readonly ChatSessionStore $sessionStore,
    ) {
    }

    /**
     * @param (callable(array): void)|null $onProgress see OroAiAgent::run()
     */
    public function handle(string $message, string $sessionId, ?callable $onProgress = null): ChatOutcome
    {
        $this->transcript->setSessionId($sessionId);
        $history = $this->loadHistory();

        if ($this->config->isHarnessEnabled()) {
            $result = $this->harness->resolve($message, $history, $onProgress);
            $outcome = new ChatOutcome(
                reply: $result->reply,
                toolTrace: $result->toolTrace,
                links: $result->links,
                usage: $result->usage,
                promptBreakdown: $result->promptBreakdown,
                cost: $this->costEstimator->estimate($result->usage),
                sessionId: $this->transcript->getSessionId(),
                harnessAttempt: $result->attempt,
                memorySaved: $result->memorySaved,
                needsClarification: $result->needsClarification,
            );
        } else {
            $result = $this->agent->run($message, $history, $onProgress);
            $outcome = new ChatOutcome(
                reply: $result->reply,
                toolTrace: $result->toolTrace,
                links: $result->links,
                usage: $result->usage,
                promptBreakdown: $result->promptBreakdown,
                cost: $this->costEstimator->estimate($result->usage),
                sessionId: $this->transcript->getSessionId(),
            );
        }

        $this->transcript->logFinal($outcome->reply, $outcome->usage, $outcome->cost);
        $this->sessionStore->append((string) $this->transcript->getSessionId(), $message, $outcome->reply);

        return $outcome;
    }

    /**
     * The conversation so far, from the session store (empty for a fresh
     * session id or an unauthenticated caller), trimmed to the token budget.
     *
     * @return ChatMessage[]
     */
    private function loadHistory(): array
    {
        $sessionId = $this->transcript->getSessionId();
        if ($sessionId === null) {
            return [];
        }

        $messages = [];
        foreach ($this->sessionStore->getMessages($sessionId) as $entry) {
            $role = Role::tryFrom($entry['role'] ?? '');
            if ($role === null) {
                continue;
            }
            $messages[] = new ChatMessage(role: $role, content: (string) ($entry['content'] ?? ''));
        }

        return $this->contextWindow->trim($messages);
    }
}
