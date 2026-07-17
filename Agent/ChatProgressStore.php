<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ephemeral, short-TTL store for "what is the AI assistant doing right now"
 * progress steps, keyed by a client-generated request id.
 *
 * The chat widget starts polling GET /admin/oroai/chat/progress as soon as it
 * sends a message, rendering a live checklist while the main POST request is
 * still running -- without real streaming (no SSE, no nginx buffering
 * concerns to work around). ChatController writes steps here as
 * OroAiAgent/ResolutionHarness report progress via their onProgress callback,
 * and clears the entry once the main request finishes.
 */
class ChatProgressStore
{
    private const int TTL_SECONDS = 120;
    private const string KEY_PREFIX = 'oroai_chat_progress.';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function addStep(string $requestId, array $step): void
    {
        if ($requestId === '') {
            return;
        }

        $item = $this->cache->getItem($this->cacheKey($requestId));
        $steps = $item->isHit() ? $item->get() : [];
        $steps[] = $step;

        $item->set($steps);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    /** @return array<int, array> */
    public function getSteps(string $requestId): array
    {
        if ($requestId === '') {
            return [];
        }

        $item = $this->cache->getItem($this->cacheKey($requestId));

        return $item->isHit() ? $item->get() : [];
    }

    public function clear(string $requestId): void
    {
        if ($requestId === '') {
            return;
        }

        $this->cache->deleteItem($this->cacheKey($requestId));
    }

    /** PSR-6 keys forbid {}()/\@: -- sanitize a client-supplied id defensively. */
    private function cacheKey(string $requestId): string
    {
        return self::KEY_PREFIX . preg_replace('/[^A-Za-z0-9_.]/', '_', $requestId);
    }
}
