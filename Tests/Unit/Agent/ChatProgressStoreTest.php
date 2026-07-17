<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent;

use Genaker\Bundle\OroAI\Agent\ChatProgressStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ChatProgressStoreTest extends TestCase
{
    private ChatProgressStore $store;

    protected function setUp(): void
    {
        $this->store = new ChatProgressStore(new ArrayAdapter());
    }

    public function testGetStepsReturnsEmptyArrayForUnknownRequestId(): void
    {
        self::assertSame([], $this->store->getSteps('unknown'));
    }

    public function testAddStepThenGetStepsReturnsTheStep(): void
    {
        $this->store->addStep('req-1', ['type' => 'tool_call', 'tool' => 'sql_query']);

        self::assertSame(
            [['type' => 'tool_call', 'tool' => 'sql_query']],
            $this->store->getSteps('req-1'),
        );
    }

    public function testAddStepAppendsInOrder(): void
    {
        $this->store->addStep('req-1', ['type' => 'tool_call', 'tool' => 'a']);
        $this->store->addStep('req-1', ['type' => 'tool_result', 'tool' => 'a', 'success' => true]);
        $this->store->addStep('req-1', ['type' => 'tool_call', 'tool' => 'b']);

        $steps = $this->store->getSteps('req-1');

        self::assertCount(3, $steps);
        self::assertSame('a', $steps[0]['tool']);
        self::assertSame('a', $steps[1]['tool']);
        self::assertSame('b', $steps[2]['tool']);
    }

    public function testDifferentRequestIdsDoNotShareSteps(): void
    {
        $this->store->addStep('req-1', ['type' => 'tool_call', 'tool' => 'a']);
        $this->store->addStep('req-2', ['type' => 'tool_call', 'tool' => 'b']);

        self::assertCount(1, $this->store->getSteps('req-1'));
        self::assertCount(1, $this->store->getSteps('req-2'));
        self::assertSame('a', $this->store->getSteps('req-1')[0]['tool']);
        self::assertSame('b', $this->store->getSteps('req-2')[0]['tool']);
    }

    public function testClearRemovesSteps(): void
    {
        $this->store->addStep('req-1', ['type' => 'tool_call', 'tool' => 'a']);
        $this->store->clear('req-1');

        self::assertSame([], $this->store->getSteps('req-1'));
    }

    public function testEmptyRequestIdIsANoOp(): void
    {
        $this->store->addStep('', ['type' => 'tool_call', 'tool' => 'a']);

        self::assertSame([], $this->store->getSteps(''));
    }

    public function testCacheKeySanitizesUnsafeCharacters(): void
    {
        // PSR-6 keys forbid {}()/\@: -- a request id containing them must not
        // throw, and must still round-trip correctly.
        $requestId = 'req/with:unsafe@chars{}()\\';

        $this->store->addStep($requestId, ['type' => 'tool_call', 'tool' => 'a']);

        self::assertCount(1, $this->store->getSteps($requestId));
    }
}
