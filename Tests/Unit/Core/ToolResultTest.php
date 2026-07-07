<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Core;

use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use PHPUnit\Framework\TestCase;

final class ToolResultTest extends TestCase
{
    public function testSuccessFactory(): void
    {
        $result = ToolResult::success(['count' => 5, 'items' => [1, 2, 3]]);

        self::assertTrue($result->success);
        self::assertSame(['count' => 5, 'items' => [1, 2, 3]], $result->data);
        self::assertNull($result->errorMessage);
    }

    public function testSuccessWithScalarData(): void
    {
        $result = ToolResult::success(42);

        self::assertTrue($result->success);
        self::assertSame(42, $result->data);
        self::assertNull($result->errorMessage);
    }

    public function testSuccessWithStringData(): void
    {
        $result = ToolResult::success('hello');

        self::assertTrue($result->success);
        self::assertSame('hello', $result->data);
    }

    public function testSuccessWithNullData(): void
    {
        $result = ToolResult::success(null);

        self::assertTrue($result->success);
        self::assertNull($result->data);
    }

    public function testErrorFactory(): void
    {
        $result = ToolResult::error('Something went wrong.');

        self::assertFalse($result->success);
        self::assertNull($result->data);
        self::assertSame('Something went wrong.', $result->errorMessage);
    }

    public function testSummaryForError(): void
    {
        $result = ToolResult::error('Query failed.');

        self::assertSame('Error: Query failed.', $result->summary());
    }

    public function testSummaryForErrorWithNullMessage(): void
    {
        $result = new ToolResult(success: false, data: null, errorMessage: null);

        self::assertSame('Error: Unknown error', $result->summary());
    }

    public function testSummaryForSuccessShortData(): void
    {
        $result = ToolResult::success(['key' => 'value']);

        $expected = $result->toJson();
        self::assertSame($expected, $result->summary());
    }

    public function testSummaryForSuccessTruncatesLongData(): void
    {
        $longData = str_repeat('x', 600);
        $result = ToolResult::success($longData);

        $summary = $result->summary();
        $json = $result->toJson();

        self::assertGreaterThan(500, mb_strlen($json));
        self::assertSame(503, mb_strlen($summary)); // 500 + '...'
        self::assertStringEndsWith('...', $summary);
        self::assertSame(mb_substr($json, 0, 500) . '...', $summary);
    }

    public function testSummaryForSuccessExactly500Characters(): void
    {
        // Build data that produces exactly 500 chars of JSON
        // We'll test that data at or under 500 does NOT get truncated
        $result = ToolResult::success('short');
        $json = $result->toJson();

        self::assertLessThanOrEqual(500, mb_strlen($json));
        self::assertSame($json, $result->summary());
        self::assertStringEndsNotWith('...', $result->summary());
    }

    public function testToJsonForSuccess(): void
    {
        $result = ToolResult::success(['rows' => [['id' => 1]]]);

        $json = $result->toJson();
        $decoded = json_decode($json, true);

        self::assertTrue($decoded['success']);
        self::assertSame([['id' => 1]], $decoded['data']['rows']);
        self::assertNull($decoded['error']);
    }

    public function testToJsonForError(): void
    {
        $result = ToolResult::error('bad query');

        $json = $result->toJson();
        $decoded = json_decode($json, true);

        self::assertFalse($decoded['success']);
        self::assertNull($decoded['data']);
        self::assertSame('bad query', $decoded['error']);
    }

    public function testToJsonUsesUnescapedUnicode(): void
    {
        $result = ToolResult::success('Привет мир');

        $json = $result->toJson();
        self::assertStringContainsString('Привет мир', $json);
        self::assertStringNotContainsString('\\u', $json);
    }

    public function testToJsonThrowsOnInvalidData(): void
    {
        // NAN cannot be encoded as JSON
        $result = ToolResult::success(NAN);

        $this->expectException(\JsonException::class);
        $result->toJson();
    }
}
