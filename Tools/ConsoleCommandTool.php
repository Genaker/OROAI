<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Symfony\Component\Process\Process;

/**
 * AI tool that runs ALLOWLISTED Symfony/Oro console commands (bin/console).
 *
 * This is deliberately NOT a shell: the command string is split into plain
 * argv tokens (any shell metacharacter rejects the call), executed as
 * `php bin/console <args> --no-interaction --no-ansi` via Process with no
 * shell involved, and only commands whose name starts with an allowlisted
 * READ-ONLY prefix may run — debug:*, lint:*, doctrine introspection and
 * similar. Anything that can write, migrate, drop or load is refused by
 * default; extra prefixes can be granted consciously via
 * OROAI_CONSOLE_ALLOWED (comma-separated).
 */
final class ConsoleCommandTool implements AiToolInterface
{
    private const int TIMEOUT_SECONDS = 30;
    private const int MAX_OUTPUT_CHARS = 4000;

    /** Read-only command-name prefixes allowed out of the box. */
    private const array DEFAULT_ALLOWED_PREFIXES = [
        'about',
        'debug:',
        'lint:',
        'router:match',
        'doctrine:mapping:info',
        'doctrine:schema:validate',
        'oro:platform:version',
        'oro:cron:definitions:list',
        'oro:message-queue:consume --help', // help only; consuming itself is not read-only
        'list',
        'help',
    ];

    public function __construct(
        private readonly string $projectDir,
        private readonly ?string $extraAllowedPrefixes = '',
    ) {
    }

    public function getName(): string
    {
        return 'console_command';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'console_command',
            'Run a READ-ONLY Symfony/Oro console command (bin/console) and return its output. '
            . 'Allowed: ' . implode(', ', $this->allowedPrefixes()) . '. '
            . 'Use for runtime introspection the other tools cannot reach: debug:container <service>, '
            . 'debug:router <route>, debug:config <bundle>, doctrine:mapping:info, lint checks. '
            . 'Write/migrate/load commands are refused.',
            [
                'type' => 'object',
                'properties' => [
                    'command' => [
                        'type' => 'string',
                        'description' => 'The console command with its arguments, e.g. "debug:router oro_order_view" '
                            . 'or "doctrine:schema:validate". Without "bin/console" and without shell syntax.',
                    ],
                ],
                'required' => ['command'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $command = trim((string) ($arguments['command'] ?? ''));
        if ($command === '') {
            return ToolResult::error('Parameter "command" is required.');
        }

        // No shell ever: reject anything that only makes sense to a shell.
        if (preg_match('/[;&|`$<>(){}!\\\\\n\r"\']/', $command)) {
            return ToolResult::error('Shell syntax is not allowed — pass a plain console command with arguments.');
        }

        $argv = preg_split('/\s+/', $command) ?: [];
        $name = ltrim((string) ($argv[0] ?? ''), '-');

        if (!$this->isAllowed($command, $name)) {
            return ToolResult::error(sprintf(
                'Command "%s" is not in the read-only allowlist (%s). '
                . 'Extra prefixes can be granted via OROAI_CONSOLE_ALLOWED.',
                $name,
                implode(', ', $this->allowedPrefixes()),
            ));
        }

        $process = new Process(
            ['php', 'bin/console', ...$argv, '--no-interaction', '--no-ansi'],
            $this->projectDir,
            null,
            null,
            self::TIMEOUT_SECONDS,
        );

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ToolResult::error('Command failed to run: ' . $e->getMessage());
        }

        $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());
        if (mb_strlen($output) > self::MAX_OUTPUT_CHARS) {
            $output = mb_substr($output, 0, self::MAX_OUTPUT_CHARS) . "\n… (output truncated)";
        }

        return ToolResult::success([
            'command' => $command,
            'exit_code' => $process->getExitCode(),
            'output' => $output,
        ]);
    }

    private function isAllowed(string $fullCommand, string $name): bool
    {
        foreach ($this->allowedPrefixes() as $prefix) {
            // A prefix may constrain the full command line (e.g. "... --help"),
            // otherwise it matches against the command name.
            $subject = str_contains($prefix, ' ') ? $fullCommand : $name;
            if (str_starts_with($subject, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function allowedPrefixes(): array
    {
        $extra = array_filter(array_map('trim', explode(',', (string) $this->extraAllowedPrefixes)));

        return array_values(array_unique([...self::DEFAULT_ALLOWED_PREFIXES, ...$extra]));
    }
}
