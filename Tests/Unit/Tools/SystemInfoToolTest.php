<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Tools;

use Genaker\Bundle\OroAI\Tools\SystemInfoTool;
use PHPUnit\Framework\TestCase;

final class SystemInfoToolTest extends TestCase
{
    private SystemInfoTool $tool;

    protected function setUp(): void
    {
        $this->tool = new SystemInfoTool('/tmp', 'dev');
    }

    public function testGetName(): void
    {
        self::assertSame('system_info', $this->tool->getName());
    }

    public function testOverviewReturnsPhpVersion(): void
    {
        $result = $this->tool->execute(['section' => 'overview']);

        self::assertTrue($result->success);
        self::assertSame(PHP_VERSION, $result->data['php_version']);
        self::assertSame('dev', $result->data['symfony_env']);
    }

    public function testPhpSectionReturnsDetails(): void
    {
        $result = $this->tool->execute(['section' => 'php']);

        self::assertTrue($result->success);
        self::assertSame(PHP_VERSION, $result->data['version']);
        self::assertArrayHasKey('memory_limit', $result->data);
        self::assertArrayHasKey('sapi', $result->data);
    }

    public function testExtensionsSectionReturnsList(): void
    {
        $result = $this->tool->execute(['section' => 'extensions']);

        self::assertTrue($result->success);
        self::assertIsArray($result->data['extensions']);
        self::assertNotEmpty($result->data['extensions']);
    }

    public function testMemorySectionReturnsUsage(): void
    {
        $result = $this->tool->execute(['section' => 'memory']);

        self::assertTrue($result->success);
        self::assertArrayHasKey('memory_usage_mb', $result->data);
        self::assertArrayHasKey('memory_peak_mb', $result->data);
        self::assertGreaterThan(0, $result->data['memory_usage_mb']);
    }

    public function testUnknownSectionReturnsError(): void
    {
        $result = $this->tool->execute(['section' => 'unknown']);

        self::assertFalse($result->success);
    }
}
