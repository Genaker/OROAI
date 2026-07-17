<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Tools;

use Genaker\Bundle\OroAI\Tools\CodeAccessPolicy;
use Genaker\Bundle\OroAI\Tools\CodeReadTool;
use Genaker\Bundle\OroAI\Tools\CodeSearchTool;
use PHPUnit\Framework\TestCase;

/**
 * Tests the code tools against a throwaway project dir built in setUp — a
 * real filesystem and the real grep binary (the tools are thin wrappers
 * around both; mocking them would test nothing), including every path that
 * MUST stay unreachable.
 */
final class CodeToolsTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/oroai_code_tools_' . bin2hex(random_bytes(4));
        foreach (['src/Acme', 'config', 'var/cache', 'vendor/oro/platform', 'vendor/symfony/console', 'custom'] as $dir) {
            mkdir($this->projectDir . '/' . $dir, 0777, true);
        }

        file_put_contents(
            $this->projectDir . '/src/Acme/Demo.php',
            "<?php\nclass Demo\n{\n    public function resolveBillOfLading(): void\n    {\n    }\n}\n",
        );
        file_put_contents($this->projectDir . '/config/params.yml', "api_key: super-secret-value\nplain: harmless\n");
        file_put_contents($this->projectDir . '/custom/notes.txt', "resolveBillOfLading mentioned in custom root\n");
        // Oro business logic in vendor — MUST be visible:
        file_put_contents(
            $this->projectDir . '/vendor/oro/platform/OrderManager.php',
            "<?php // resolveBillOfLading in oro core\n",
        );
        // Framework internals + runtime state + secrets — MUST stay invisible:
        file_put_contents(
            $this->projectDir . '/vendor/symfony/console/Application.php',
            "<?php // resolveBillOfLading in symfony\n",
        );
        file_put_contents($this->projectDir . '/var/cache/dump.php', "<?php // resolveBillOfLading in var\n");
        file_put_contents($this->projectDir . '/.env-app', "OROAI_API_KEY=leaked\n");
        file_put_contents($this->projectDir . '/auth.json', '{"github-oauth": {"github.com": "leaked"}}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectDir));
    }

    private function policy(?string $excludes = null, ?string $vendorAllowed = null): CodeAccessPolicy
    {
        return new CodeAccessPolicy($this->projectDir, $excludes, $vendorAllowed);
    }

    // ── code_search ──────────────────────────────────────────────

    public function testSearchCoversAppCodeCustomRootsAndOroVendorOnly(): void
    {
        $result = (new CodeSearchTool($this->policy()))->execute(['pattern' => 'resolveBillOfLading']);
        $files = array_column($result->data['matches'], 'file');

        self::assertContains('src/Acme/Demo.php', $files);
        self::assertContains('custom/notes.txt', $files, 'whole project is searchable by default');
        self::assertContains('vendor/oro/platform/OrderManager.php', $files, 'Oro core is business logic');
        self::assertNotContains('vendor/symfony/console/Application.php', $files, 'framework internals excluded');
        self::assertNotContains('var/cache/dump.php', $files, 'var/ excluded by default');
    }

    public function testSearchNeverLeaksEnvFiles(): void
    {
        $result = (new CodeSearchTool($this->policy()))->execute(['pattern' => 'leaked']);

        self::assertSame(0, $result->data['count'], '.env* and auth.json must be unreachable');
    }

    public function testSearchReportsFileLineAndRedactsSecrets(): void
    {
        $result = (new CodeSearchTool($this->policy()))->execute(['pattern' => 'api_key']);

        self::assertSame('config/params.yml', $result->data['matches'][0]['file']);
        self::assertSame(1, $result->data['matches'][0]['line']);
        self::assertStringContainsString('***REDACTED***', $result->data['matches'][0]['text']);
        self::assertStringNotContainsString('super-secret-value', $result->data['matches'][0]['text']);
    }

    public function testSearchPathFilterLimitsToSubtreeAndDeniesExcludedOnes(): void
    {
        $tool = new CodeSearchTool($this->policy());

        $narrowed = $tool->execute(['pattern' => 'resolveBillOfLading', 'path' => 'src']);
        self::assertSame(['src/Acme/Demo.php'], array_column($narrowed->data['matches'], 'file'));

        self::assertFalse($tool->execute(['pattern' => 'x', 'path' => 'var'])->success);
        self::assertFalse($tool->execute(['pattern' => 'x', 'path' => 'vendor/symfony'])->success);
        self::assertTrue($tool->execute(['pattern' => 'x', 'path' => 'vendor/oro'])->success);
    }

    public function testConfiguredExcludesAndVendorNamespacesAreHonored(): void
    {
        // "custom" excluded by config; symfony vendor granted by config.
        $policy = $this->policy('var,custom', 'oro,symfony');
        $result = (new CodeSearchTool($policy))->execute(['pattern' => 'resolveBillOfLading']);
        $files = array_column($result->data['matches'], 'file');

        self::assertNotContains('custom/notes.txt', $files);
        self::assertContains('vendor/symfony/console/Application.php', $files);
        self::assertNotContains('var/cache/dump.php', $files);
    }

    // ── code_read ────────────────────────────────────────────────

    public function testReadReturnsNumberedSlice(): void
    {
        $result = (new CodeReadTool($this->policy()))
            ->execute(['file' => 'src/Acme/Demo.php', 'start_line' => 2, 'lines' => 2]);

        self::assertTrue($result->success);
        self::assertSame(2, $result->data['start_line']);
        self::assertSame(3, $result->data['end_line']);
        self::assertSame(7, $result->data['total_lines']);
        self::assertSame("2: class Demo\n3: {", $result->data['content']);
    }

    public function testReadAllowsOroVendorButDeniesEverythingElseSensitive(): void
    {
        $tool = new CodeReadTool($this->policy());

        self::assertTrue($tool->execute(['file' => 'vendor/oro/platform/OrderManager.php'])->success);

        self::assertFalse($tool->execute(['file' => 'vendor/symfony/console/Application.php'])->success);
        self::assertFalse($tool->execute(['file' => 'var/cache/dump.php'])->success);
        self::assertFalse($tool->execute(['file' => '.env-app'])->success);
        self::assertFalse($tool->execute(['file' => 'auth.json'])->success);
        self::assertFalse($tool->execute(['file' => '../outside.txt'])->success);
        self::assertFalse($tool->execute(['file' => 'src/../.env-app'])->success);
    }

    public function testReadRedactsSecretLines(): void
    {
        $result = (new CodeReadTool($this->policy()))->execute(['file' => 'config/params.yml']);

        self::assertStringContainsString('***REDACTED***', $result->data['content']);
        self::assertStringNotContainsString('super-secret-value', $result->data['content']);
    }
}
