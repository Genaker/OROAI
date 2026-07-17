<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Symfony\Component\Process\Process;

/**
 * AI tool that greps the project source — Claude-Code style — so the agent
 * can find where logic is implemented, then inspect it with code_read.
 *
 * Runs the system grep binary via Process with plain argv (no shell, every
 * argument escaped by construction) for speed over the full project +
 * vendor/oro. What is searchable is decided entirely by CodeAccessPolicy:
 * whole project by default, var/ and friends excluded, vendor limited to
 * Oro business logic, secrets redacted. Results are capped (50 hits,
 * 200-char lines) so one search cannot flood the context window.
 */
final class CodeSearchTool implements AiToolInterface
{
    private const int MAX_MATCHES = 50;
    private const int MAX_LINE_LENGTH = 200;
    private const int TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly CodeAccessPolicy $policy,
    ) {
    }

    public function getName(): string
    {
        return 'code_search';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'code_search',
            'Search (grep) the project source code for a string or regex. Returns file:line matches. '
            . 'Use to find where a class, method, table, route or message is implemented, then '
            . 'code_read to inspect the logic. Covers the app code and Oro core (vendor/oro); '
            . 'framework internals, var/ and dotfiles are excluded.',
            [
                'type' => 'object',
                'properties' => [
                    'pattern' => [
                        'type' => 'string',
                        'description' => 'Text to search for. Treated as a literal string unless regex=true.',
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'Optional directory to search, relative to the project root '
                            . '(e.g. "src/Egerdau" or "vendor/oro/platform").',
                    ],
                    'regex' => [
                        'type' => 'boolean',
                        'description' => 'Treat pattern as an extended (ERE) regular expression. Default false.',
                    ],
                ],
                'required' => ['pattern'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $pattern = (string) ($arguments['pattern'] ?? '');
        if (trim($pattern) === '') {
            return ToolResult::error('Parameter "pattern" is required.');
        }

        $roots = $this->resolveRoots(trim((string) ($arguments['path'] ?? '')));
        if ($roots === null) {
            return ToolResult::error(
                'Path not found or not readable by this tool (var/, dotfiles and non-Oro vendor packages are excluded).'
            );
        }

        $process = new Process(
            $this->buildGrepArgv($pattern, (bool) ($arguments['regex'] ?? false), $roots),
            $this->policy->getProjectDir(),
            null,
            null,
            self::TIMEOUT_SECONDS,
        );

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ToolResult::error('Search failed to run: ' . $e->getMessage());
        }

        // grep exit codes: 0 = matches, 1 = no matches, >1 = real error.
        if ($process->getExitCode() > 1) {
            return ToolResult::error('Search failed: ' . trim($process->getErrorOutput()));
        }

        [$matches, $truncated] = $this->parseGrepOutput($process->getOutput());

        return ToolResult::success([
            'matches' => $matches,
            'count' => count($matches),
            'truncated' => $truncated,
            'note' => $matches === []
                ? 'No matches. Try a shorter pattern, a different spelling, or drop the path filter.'
                : 'Use code_read with a file and line to inspect the surrounding logic.',
        ]);
    }

    /**
     * The absolute directories to grep: the policy's default roots (project
     * minus vendor, plus each allowed vendor namespace), or the single
     * requested subdirectory when a path filter is given.
     *
     * @return list<string>|null null when the requested path is denied
     */
    private function resolveRoots(string $pathFilter): ?array
    {
        if ($pathFilter === '') {
            return $this->policy->getSearchRoots();
        }

        $resolved = $this->policy->resolve($pathFilter);

        return ($resolved !== null && is_dir($resolved)) ? [$resolved] : null;
    }

    /** @param list<string> $roots
     *  @return list<string> */
    private function buildGrepArgv(string $pattern, bool $isRegex, array $roots): array
    {
        $argv = ['grep', '-r', '-n', '-I', $isRegex ? '-E' : '-F', '-i', '--', $pattern, ...$roots];

        // Insert exclusions before the `--` terminator.
        $exclusions = [];
        foreach ($this->policy->getSearchExcludedDirNames() as $dirName) {
            $exclusions[] = '--exclude-dir=' . $dirName;
        }

        array_splice($argv, 6, 0, $exclusions);

        return $argv;
    }

    /** @return array{0: list<array{file: string, line: int, text: string}>, 1: bool} */
    private function parseGrepOutput(string $output): array
    {
        $matches = [];
        $truncated = false;

        foreach (explode("\n", $output) as $row) {
            if ($row === '') {
                continue;
            }
            if (count($matches) >= self::MAX_MATCHES) {
                $truncated = true;
                break;
            }
            // absolute/file/path:line:text — text may itself contain colons.
            if (!preg_match('/^(.*?):(\d+):(.*)$/', $row, $parts)) {
                continue;
            }

            $relative = $this->policy->relativePath($parts[1]);
            // Defense in depth: grep excludes should already enforce this.
            if (!$this->policy->isRelativePathReadable($relative, true)) {
                continue;
            }

            $matches[] = [
                'file' => $relative,
                'line' => (int) $parts[2],
                'text' => $this->policy->redactSecrets(mb_substr(trim($parts[3]), 0, self::MAX_LINE_LENGTH)),
            ];
        }

        return [$matches, $truncated];
    }
}
