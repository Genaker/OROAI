<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Service;

use Genaker\Bundle\OroAI\Service\ChatSessionStore;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ChatSessionStoreTest extends TestCase
{
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    private function store(?int $userId = 7): ChatSessionStore
    {
        $tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $tokenAccessor->method('getUserId')->willReturn($userId);

        return new ChatSessionStore($this->cache, $tokenAccessor);
    }

    public function testAppendAndRestoreRoundTrip(): void
    {
        $store = $this->store();
        $store->append('sess1', 'How many orders?', 'There are 7444 orders.');
        $store->append('sess1', 'And customers?', '812 customers.');

        self::assertSame(
            [
                ['role' => 'user', 'content' => 'How many orders?'],
                ['role' => 'assistant', 'content' => 'There are 7444 orders.'],
                ['role' => 'user', 'content' => 'And customers?'],
                ['role' => 'assistant', 'content' => '812 customers.'],
            ],
            $store->getMessages('sess1'),
        );

        $sessions = $store->getSessions();
        self::assertCount(1, $sessions);
        self::assertSame('sess1', $sessions[0]['id']);
        self::assertSame('How many orders?', $sessions[0]['title'], 'first message names the session');
        self::assertSame(4, $sessions[0]['count']);
    }

    public function testMostRecentFirstAndCapAtFive(): void
    {
        $store = $this->store();
        foreach (['s1', 's2', 's3', 's4', 's5', 's6'] as $id) {
            $store->append($id, 'question in ' . $id, 'answer');
        }

        $ids = array_column($store->getSessions(), 'id');
        self::assertSame(['s6', 's5', 's4', 's3', 's2'], $ids);
        self::assertSame([], $store->getMessages('s1'), 'evicted session messages are deleted too');
    }

    public function testAppendingToExistingSessionBumpsItToFront(): void
    {
        $store = $this->store();
        $store->append('older', 'first question', 'a');
        $store->append('newer', 'second question', 'b');
        $store->append('older', 'follow-up', 'c');

        $sessions = $store->getSessions();
        self::assertSame(['older', 'newer'], array_column($sessions, 'id'));
        self::assertSame('first question', $sessions[0]['title'], 'title never changes after creation');
    }

    public function testLongTitleIsTruncatedAndWhitespaceCollapsed(): void
    {
        $store = $this->store();
        $store->append('sess1', "  list   every\n\norder " . str_repeat('x', 80), 'reply');

        $title = $store->getSessions()[0]['title'];
        self::assertStringStartsWith('list every order', $title);
        self::assertStringEndsWith('…', $title);
        self::assertLessThanOrEqual(61, mb_strlen($title));
    }

    public function testMessagesAreCappedKeepingTheTail(): void
    {
        $store = $this->store();
        for ($i = 1; $i <= 25; $i++) {
            $store->append('sess1', 'question ' . $i, 'answer ' . $i);
        }

        $messages = $store->getMessages('sess1');
        self::assertCount(40, $messages);
        self::assertSame('question 6', $messages[0]['content'], 'oldest exchanges fall off first');
        self::assertSame('answer 25', $messages[39]['content']);
    }

    public function testUsersAreIsolatedFromEachOther(): void
    {
        $this->store(7)->append('sess1', 'user 7 secret', 'reply');

        $otherUser = $this->store(8);
        self::assertSame([], $otherUser->getSessions());
        self::assertSame([], $otherUser->getMessages('sess1'));
    }

    public function testNoAuthenticatedUserIsANoOp(): void
    {
        $anonymous = $this->store(null);
        $anonymous->append('sess1', 'question', 'reply');

        self::assertSame([], $anonymous->getSessions());
        self::assertSame([], $anonymous->getMessages('sess1'));
    }

    public function testSessionIdIsSanitizedAgainstCacheKeyTricks(): void
    {
        $store = $this->store();
        $store->append('../{evil}@ID 9', 'question', 'reply');

        $sessions = $store->getSessions();
        self::assertSame('evilid9', $sessions[0]['id']);
        self::assertNotSame([], $store->getMessages('evilid9'));
    }

    public function testEmptySessionIdIsANoOp(): void
    {
        $store = $this->store();
        $store->append('', 'question', 'reply');

        self::assertSame([], $store->getSessions());
    }
}
