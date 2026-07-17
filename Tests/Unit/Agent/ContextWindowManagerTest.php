<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent;

use Genaker\Bundle\OroAI\Agent\ContextWindowManager;
use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\Role;
use PHPUnit\Framework\TestCase;

final class ContextWindowManagerTest extends TestCase
{
    /** @return ChatMessage[] */
    private function exchange(string $question, string $answer): array
    {
        return [ChatMessage::user($question), new ChatMessage(Role::Assistant, $answer)];
    }

    public function testEmptyHistoryStaysEmpty(): void
    {
        self::assertSame([], (new ContextWindowManager())->trim([]));
    }

    public function testHistoryUnderBudgetIsKeptCompletelyAndInOrder(): void
    {
        $messages = [
            ...$this->exchange('first question', 'first answer'),
            ...$this->exchange('second question', 'second answer'),
        ];

        $trimmed = (new ContextWindowManager())->trim($messages);

        self::assertSame($messages, $trimmed, 'nothing to trim, order untouched');
    }

    public function testOldestMessagesFallOffWhenOverBudget(): void
    {
        // ~25 estimated tokens per message (100 chars), budget fits ~4 of 6.
        $messages = [];
        for ($i = 1; $i <= 6; $i++) {
            $messages[] = ChatMessage::user('message ' . $i . ' ' . str_repeat('x', 90));
        }

        $trimmed = (new ContextWindowManager(100))->trim($messages);

        self::assertCount(4, $trimmed);
        self::assertStringContainsString('message 3', $trimmed[0]->content, 'newest survive, oldest dropped');
        self::assertStringContainsString('message 6', $trimmed[3]->content);
    }

    public function testLastExchangeIsKeptEvenWhenItAloneExceedsTheBudget(): void
    {
        $messages = [
            ChatMessage::user('old small question'),
            ChatMessage::user(str_repeat('a', 4_000)),
            new ChatMessage(Role::Assistant, str_repeat('b', 4_000)),
        ];

        $trimmed = (new ContextWindowManager(100))->trim($messages);

        self::assertCount(2, $trimmed, 'the newest exchange always survives');
        self::assertSame(str_repeat('a', 4_000), $trimmed[0]->content);
    }

    public function testEstimateTokensUsesFourCharsPerToken(): void
    {
        $manager = new ContextWindowManager();

        self::assertSame(0, $manager->estimateTokens(''));
        self::assertSame(1, $manager->estimateTokens('abc'));
        self::assertSame(25, $manager->estimateTokens(str_repeat('x', 100)));
    }
}
