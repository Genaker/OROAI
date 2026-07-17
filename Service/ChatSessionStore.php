<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Service;

use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Recent-conversation store, so a closed chat can be resumed later (like
 * `claude --resume`): the last MAX_SESSIONS conversations per admin user are
 * kept in cache.app (Redis when configured) and listed at the bottom of the
 * chat widget; clicking one restores its full message history into the widget
 * and continues under the same session id (same debug transcript file too).
 *
 * Storage layout, all TTL-bound:
 *   oroai_chat_sessions.<userId>        -> MRU index [{id, title, updated_at, count}]
 *   oroai_chat_session.<userId>.<sid>   -> [{role, content}, ...] (capped)
 *
 * Scoped by user id on purpose — admins must never see each other's chats.
 * No authenticated user (or no session id) = silent no-op, mirroring
 * ChatTranscriptLogger's "debug plumbing must never break the chat" rule.
 */
class ChatSessionStore
{
    private const int MAX_SESSIONS = 5;
    private const int MAX_MESSAGES_PER_SESSION = 40;
    private const int TTL_SECONDS = 7 * 86_400;
    private const int TITLE_MAX_CHARS = 60;
    private const string INDEX_PREFIX = 'oroai_chat_sessions.';
    private const string SESSION_PREFIX = 'oroai_chat_session.';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        #[Autowire(service: 'oro_security.token_accessor')]
        private readonly TokenAccessorInterface $tokenAccessor,
    ) {
    }

    /** Records one completed exchange; first exchange of a session names it. */
    public function append(string $sessionId, string $userMessage, string $reply): void
    {
        $userId = $this->tokenAccessor->getUserId();
        $sessionId = $this->sanitize($sessionId);
        if ($userId === null || $sessionId === '') {
            return;
        }

        $sessionItem = $this->cache->getItem($this->sessionKey($userId, $sessionId));
        $messages = $sessionItem->isHit() ? $sessionItem->get() : [];
        $messages[] = ['role' => 'user', 'content' => $userMessage];
        $messages[] = ['role' => 'assistant', 'content' => $reply];
        // Oldest messages fall off first — the tail is what resuming needs.
        $messages = array_slice($messages, -self::MAX_MESSAGES_PER_SESSION);

        $sessionItem->set($messages);
        $sessionItem->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($sessionItem);

        $this->touchIndex($userId, $sessionId, $userMessage, count($messages));
    }

    /**
     * Most recent first.
     *
     * @return array<int, array{id: string, title: string, updated_at: int, count: int}>
     */
    public function getSessions(): array
    {
        $userId = $this->tokenAccessor->getUserId();
        if ($userId === null) {
            return [];
        }

        $indexItem = $this->cache->getItem($this->indexKey($userId));

        return $indexItem->isHit() ? $indexItem->get() : [];
    }

    /** @return array<int, array{role: string, content: string}> */
    public function getMessages(string $sessionId): array
    {
        $userId = $this->tokenAccessor->getUserId();
        $sessionId = $this->sanitize($sessionId);
        if ($userId === null || $sessionId === '') {
            return [];
        }

        $sessionItem = $this->cache->getItem($this->sessionKey($userId, $sessionId));

        return $sessionItem->isHit() ? $sessionItem->get() : [];
    }

    /** Moves/creates the session at the front of the MRU index, evicting past MAX_SESSIONS. */
    private function touchIndex(mixed $userId, string $sessionId, string $firstMessage, int $count): void
    {
        $indexItem = $this->cache->getItem($this->indexKey($userId));
        $sessions = $indexItem->isHit() ? $indexItem->get() : [];

        $existing = null;
        foreach ($sessions as $i => $meta) {
            if ($meta['id'] === $sessionId) {
                $existing = $meta;
                unset($sessions[$i]);
                break;
            }
        }

        array_unshift($sessions, [
            'id' => $sessionId,
            'title' => $existing['title'] ?? $this->makeTitle($firstMessage),
            'updated_at' => time(),
            'count' => $count,
        ]);

        foreach (array_slice($sessions, self::MAX_SESSIONS) as $evicted) {
            $this->cache->deleteItem($this->sessionKey($userId, $evicted['id']));
        }
        $sessions = array_slice(array_values($sessions), 0, self::MAX_SESSIONS);

        $indexItem->set($sessions);
        $indexItem->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($indexItem);
    }

    private function makeTitle(string $message): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if (mb_strlen($title) > self::TITLE_MAX_CHARS) {
            $title = rtrim(mb_substr($title, 0, self::TITLE_MAX_CHARS)) . '…';
        }

        return $title !== '' ? $title : 'Untitled chat';
    }

    /** Same alphabet the transcript logger enforces — ids are shared between both. */
    private function sanitize(string $sessionId): string
    {
        return substr(preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($sessionId))) ?? '', 0, 64);
    }

    private function indexKey(mixed $userId): string
    {
        return self::INDEX_PREFIX . $userId;
    }

    private function sessionKey(mixed $userId, string $sessionId): string
    {
        return self::SESSION_PREFIX . $userId . '.' . $sessionId;
    }
}
