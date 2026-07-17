<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;

/** AI tool that reads and searches OroCommerce log files for diagnostic information. */
final class LogReaderTool implements AiToolInterface
{
    public function __construct(
        private readonly string $logDir,
    ) {
    }

    public function getName(): string
    {
        return 'log_reader';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'log_reader',
            'Read recent log entries from OroCommerce log files. Use this to diagnose errors, check recent activity, or investigate issues reported by the user.',
            [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['list_files', 'tail', 'search'],
                        'description' => '"list_files" = show available log files, "tail" = last N lines of a log, "search" = grep for a pattern.',
                    ],
                    'file' => [
                        'type' => 'string',
                        'description' => 'Log file name (e.g. "dev.log", "prod.log", "oroai.log"). Required for "tail" and "search".',
                    ],
                    'lines' => [
                        'type' => 'integer',
                        'description' => 'Number of lines to return (default 50, max 200).',
                    ],
                    'pattern' => [
                        'type' => 'string',
                        'description' => 'Search pattern for "search" action (case-insensitive substring match).',
                    ],
                ],
                'required' => ['action'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $action = $arguments['action'] ?? '';

        return match ($action) {
            'list_files' => $this->listFiles(),
            'tail' => $this->tail($arguments),
            'search' => $this->search($arguments),
            default => ToolResult::error('Unknown action. Use "list_files", "tail", or "search".'),
        };
    }

    private function listFiles(): ToolResult
    {
        if (!is_dir($this->logDir)) {
            return ToolResult::error('Log directory not found.');
        }

        $files = [];
        foreach (glob($this->logDir . '/*.log') as $path) {
            $files[] = [
                'name' => basename($path),
                'size_kb' => round(filesize($path) / 1024, 1),
                'modified' => date('Y-m-d H:i:s', filemtime($path)),
            ];
        }

        usort($files, static fn($a, $b) => $b['modified'] <=> $a['modified']);

        return ToolResult::success(['files' => $files]);
    }

    private function tail(array $args): ToolResult
    {
        $file = $args['file'] ?? '';
        if ($file === '') {
            return ToolResult::error('Parameter "file" is required for tail action.');
        }

        $path = $this->resolvePath($file);
        if ($path === null) {
            return ToolResult::error("Log file \"{$file}\" not found.");
        }

        $lines = min((int) ($args['lines'] ?? 50), 200);

        $content = $this->readLastLines($path, $lines);

        return ToolResult::success([
            'file' => $file,
            'lines' => $content,
            'count' => count($content),
        ]);
    }

    private function search(array $args): ToolResult
    {
        $file = $args['file'] ?? '';
        $pattern = $args['pattern'] ?? '';

        if ($file === '' || $pattern === '') {
            return ToolResult::error('Parameters "file" and "pattern" are required for search action.');
        }

        $path = $this->resolvePath($file);
        if ($path === null) {
            return ToolResult::error("Log file \"{$file}\" not found.");
        }

        $limit = min((int) ($args['lines'] ?? 50), 200);
        $patternLower = strtolower($pattern);
        $matches = [];

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ToolResult::error('Cannot open log file.');
        }

        fseek($handle, max(0, filesize($path) - 1024 * 1024));

        $matchCount = 0;
        while (($line = fgets($handle)) !== false && $matchCount < $limit) {
            if (str_contains(strtolower($line), $patternLower)) {
                $matches[] = rtrim($line);
                $matchCount++;
            }
        }
        fclose($handle);

        return ToolResult::success([
            'file' => $file,
            'pattern' => $pattern,
            'matches' => $matches,
            'count' => count($matches),
        ]);
    }

    private function resolvePath(string $file): ?string
    {
        $file = basename($file);
        $path = $this->logDir . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /** @return string[] */
    private function readLastLines(string $path, int $count): array
    {
        $lines = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $fileSize = filesize($path);
        $chunk = min($fileSize, $count * 512);

        fseek($handle, max(0, $fileSize - $chunk));
        $buffer = fread($handle, $chunk);
        fclose($handle);

        if ($buffer === false) {
            return [];
        }

        $lines = explode("\n", $buffer);
        $lines = array_filter($lines, static fn(string $l) => trim($l) !== '');
        $lines = array_values($lines);

        return array_slice($lines, -$count);
    }
}
