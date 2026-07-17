<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Service;

use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\LlmResponse;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Genaker\Bundle\OroAI\Service\ChatTranscriptLogger;
use PHPUnit\Framework\TestCase;

final class ChatTranscriptLoggerTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/oroai_transcript_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->cacheDir));
    }

    private function logger(?string $sessionId = 'sess1'): ChatTranscriptLogger
    {
        $logger = new ChatTranscriptLogger($this->cacheDir);
        $logger->setSessionId($sessionId);

        return $logger;
    }

    private function transcript(string $sessionId = 'sess1'): string
    {
        return (string) @file_get_contents($this->cacheDir . '/chats/' . $sessionId . '.txt');
    }

    public function testFullFlowIsAppendedWithSeparators(): void
    {
        $logger = $this->logger();
        $logger->logUserMessage('How many orders?');
        $logger->logLlmRequest(1, new LlmRequest(messages: [ChatMessage::user('How many orders?')]));
        $logger->logLlmResponse(1, new LlmResponse('', [], 'tool_calls', ['total_tokens' => 10]));
        $logger->logToolCall('sql_query', ['sql' => 'SELECT COUNT(*) FROM oro_order']);
        $logger->logToolResult('sql_query', ToolResult::success(['count' => 7444]));
        $logger->logFinal('There are 7444 orders.', ['total_tokens' => 99], ['total' => 0.0012]);

        $text = $this->transcript();
        // Flow markers, in order:
        foreach (['USER MESSAGE', 'LLM REQUEST #1', 'LLM RESPONSE #1', 'TOOL CALL sql_query',
             'TOOL RESULT sql_query (success)', 'FINAL REPLY'] as $marker
        ) {
            self::assertStringContainsString($marker, $text);
        }
        self::assertGreaterThan(
            strpos($text, 'LLM REQUEST #1'),
            strpos($text, 'FINAL REPLY'),
            'entries must appear in flow order',
        );
        // Separators actually separate:
        self::assertStringContainsString(str_repeat('=', 80), $text);
        self::assertStringContainsString(str_repeat('-', 80), $text);
        // Payloads are complete:
        self::assertStringContainsString('SELECT COUNT(*) FROM oro_order', $text);
        self::assertStringContainsString('7444', $text);
        self::assertStringContainsString('cost≈$0.0012', $text);
    }

    public function testSessionIdIsSanitizedAgainstPathTricks(): void
    {
        $logger = $this->logger('../../evil/../ID-9 x');
        $logger->logUserMessage('hi');

        self::assertSame('evilid-9x', $logger->getSessionId());
        self::assertFileExists($this->cacheDir . '/chats/evilid-9x.txt');
        self::assertFileDoesNotExist(dirname($this->cacheDir) . '/evil');
    }

    public function testNoSessionIdMeansNoFile(): void
    {
        $logger = $this->logger(null);
        $logger->logUserMessage('hi');
        $logger->logFinal('reply', []);

        self::assertNull($logger->getSessionId());
        self::assertDirectoryDoesNotExist($this->cacheDir . '/chats');
    }
}
