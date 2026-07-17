<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Command;

use Genaker\Bundle\OroAI\Command\LiveStatusLine;
use PHPUnit\Framework\TestCase;

/**
 * describe() is the pure step→text mapping LiveStatusLine redraws on every
 * onProgress() call — tested directly, no Output/terminal involved.
 */
final class LiveStatusLineTest extends TestCase
{
    public function testToolCallShowsTheToolName(): void
    {
        self::assertSame(
            'running sql_query',
            LiveStatusLine::describe(['type' => 'tool_call', 'tool' => 'sql_query', 'args' => []]),
        );
    }

    public function testToolCallForSkillShowsTheSkillName(): void
    {
        self::assertSame(
            'running skill: write_sql_report',
            LiveStatusLine::describe([
                'type' => 'tool_call',
                'tool' => 'skill',
                'args' => ['name' => 'write_sql_report'],
            ]),
        );
    }

    public function testToolCallForSkillWithoutANameFallsBackToThePlainToolName(): void
    {
        self::assertSame(
            'running skill',
            LiveStatusLine::describe(['type' => 'tool_call', 'tool' => 'skill', 'args' => []]),
        );
    }

    public function testSuccessfulToolResultShowsDone(): void
    {
        self::assertSame(
            'done sql_query',
            LiveStatusLine::describe(['type' => 'tool_result', 'tool' => 'sql_query', 'success' => true]),
        );
    }

    public function testFailedToolResultShowsFailed(): void
    {
        self::assertSame(
            'failed sql_query',
            LiveStatusLine::describe(['type' => 'tool_result', 'tool' => 'sql_query', 'success' => false]),
        );
    }

    public function testHarnessAttemptShowsProgress(): void
    {
        self::assertSame(
            'attempt 2/10',
            LiveStatusLine::describe(['type' => 'harness_attempt', 'attempt' => 2, 'max' => 10]),
        );
    }

    public function testEvaluatingShowsCheckingMessage(): void
    {
        self::assertSame('checking the answer…', LiveStatusLine::describe(['type' => 'evaluating']));
    }

    public function testUnknownOrMissingTypeFallsBackToThinking(): void
    {
        self::assertSame('thinking…', LiveStatusLine::describe([]));
        self::assertSame('thinking…', LiveStatusLine::describe(['type' => 'something_new']));
    }
}
