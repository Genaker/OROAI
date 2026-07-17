<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;

/**
 * AI tool that reads a SLICE of a project source file (never the whole file)
 * so the agent can inspect the logic code_search located. What is readable is
 * decided by CodeAccessPolicy (whole project incl. vendor/oro; var/, dotfiles
 * and non-Oro vendor excluded; secrets redacted); the hard line cap keeps a
 * single read from flooding the context window.
 */
final class CodeReadTool implements AiToolInterface
{
    private const int DEFAULT_LINES = 80;
    private const int MAX_LINES = 200;

    public function __construct(
        private readonly CodeAccessPolicy $policy,
    ) {
    }

    public function getName(): string
    {
        return 'code_read';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'code_read',
            'Read up to 200 lines of a project source file, starting at a given line. Use after '
            . 'code_search to inspect the implementation around a match. Read the smallest slice '
            . 'that answers the question — every line stays in context. Covers the app code and '
            . 'Oro core (vendor/oro); var/, dotfiles and framework internals are excluded.',
            [
                'type' => 'object',
                'properties' => [
                    'file' => [
                        'type' => 'string',
                        'description' => 'Path relative to the project root, exactly as returned by code_search.',
                    ],
                    'start_line' => [
                        'type' => 'integer',
                        'description' => '1-based first line to read. Default 1.',
                    ],
                    'lines' => [
                        'type' => 'integer',
                        'description' => 'How many lines to read (default 80, max 200).',
                    ],
                ],
                'required' => ['file'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $file = trim((string) ($arguments['file'] ?? ''));
        if ($file === '') {
            return ToolResult::error('Parameter "file" is required.');
        }

        $resolved = $this->policy->resolve($file);
        if ($resolved === null || !is_file($resolved)) {
            return ToolResult::error(
                'File not found or not readable by this tool '
                . '(var/, dotfiles and non-Oro vendor packages are excluded).'
            );
        }

        $start = max(1, (int) ($arguments['start_line'] ?? 1));
        $count = min(self::MAX_LINES, max(1, (int) ($arguments['lines'] ?? self::DEFAULT_LINES)));

        $allLines = file($resolved, FILE_IGNORE_NEW_LINES) ?: [];
        $total = count($allLines);
        $slice = array_slice($allLines, $start - 1, $count, true);

        $numbered = [];
        foreach ($slice as $index => $line) {
            $numbered[] = ($index + 1) . ': ' . $this->policy->redactSecrets($line);
        }

        return ToolResult::success([
            'file' => $this->policy->relativePath($resolved),
            'start_line' => $start,
            'end_line' => $start + count($numbered) - 1,
            'total_lines' => $total,
            'content' => implode("\n", $numbered),
            'note' => $start + count($numbered) - 1 < $total
                ? 'File continues — call code_read again with a higher start_line if needed.'
                : 'End of file reached.',
        ]);
    }
}
