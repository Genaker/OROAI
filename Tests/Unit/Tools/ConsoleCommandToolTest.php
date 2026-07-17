<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Tools;

use Genaker\Bundle\OroAI\Tools\ConsoleCommandTool;
use PHPUnit\Framework\TestCase;

/**
 * Validation paths are tested for real; execution is tested against a STUB
 * bin/console (a tiny PHP script echoing its argv) in a temp project dir —
 * hermetic and fast, no kernel boot, while still exercising the real
 * Process invocation with the no-shell argv contract.
 */
final class ConsoleCommandToolTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/oroai_console_' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/bin', 0777, true);
        file_put_contents(
            $this->projectDir . '/bin/console',
            "<?php echo 'STUB ' . implode(' ', array_slice(\$argv, 1));\n",
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectDir));
    }

    private function tool(string $extra = ''): ConsoleCommandTool
    {
        return new ConsoleCommandTool($this->projectDir, $extra);
    }

    public function testShellMetacharactersAreRejected(): void
    {
        foreach (['debug:router; rm -rf /', 'about | tee x', 'about `id`', 'about $(id)', "about > /tmp/x"] as $cmd) {
            $result = $this->tool()->execute(['command' => $cmd]);
            self::assertFalse($result->success, "Must reject: $cmd");
            self::assertStringContainsString('Shell syntax', $result->errorMessage);
        }
    }

    public function testWriteCapableCommandsAreRefused(): void
    {
        foreach (['doctrine:schema:drop', 'doctrine:migrations:migrate', 'oro:user:create', 'cache:clear'] as $cmd) {
            $result = $this->tool()->execute(['command' => $cmd]);
            self::assertFalse($result->success, "Must refuse: $cmd");
            self::assertStringContainsString('not in the read-only allowlist', $result->errorMessage);
        }
    }

    public function testAllowedCommandRunsWithoutShellAndAppendsSafetyFlags(): void
    {
        $result = $this->tool()->execute(['command' => 'debug:router oro_order_view']);

        self::assertTrue($result->success);
        self::assertSame(0, $result->data['exit_code']);
        // Stub echoes argv: proves argv passing (no shell) and the forced flags.
        self::assertSame(
            'STUB debug:router oro_order_view --no-interaction --no-ansi',
            $result->data['output'],
        );
    }

    public function testExtraPrefixesFromEnvExtendTheAllowlist(): void
    {
        $denied = $this->tool()->execute(['command' => 'oro:api:doc:cache:dump']);
        self::assertFalse($denied->success);

        $allowed = $this->tool('oro:api:doc:')->execute(['command' => 'oro:api:doc:cache:dump']);
        self::assertTrue($allowed->success);
    }

    public function testEmptyCommandReturnsError(): void
    {
        self::assertFalse($this->tool()->execute(['command' => '  '])->success);
    }
}
