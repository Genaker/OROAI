<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Tools;

use Genaker\Bundle\OroAI\Tools\ConfigInspectorTool;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConfigInspectorToolTest extends TestCase
{
    private ConfigManager&MockObject $configManager;
    private ConfigInspectorTool $tool;

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->tool = new ConfigInspectorTool($this->configManager);
    }

    public function testGetName(): void
    {
        self::assertSame('config_inspector', $this->tool->getName());
    }

    public function testGetActionReturnsValue(): void
    {
        $this->configManager->method('get')
            ->with('oro_locale.language')
            ->willReturn('en');

        $result = $this->tool->execute(['action' => 'get', 'key' => 'oro_locale.language']);

        self::assertTrue($result->success);
        self::assertSame('en', $result->data['value']);
        self::assertSame('oro_locale.language', $result->data['key']);
    }

    public function testGetActionHandlesNullValue(): void
    {
        $this->configManager->method('get')
            ->with('some.missing.key')
            ->willReturn(null);

        $result = $this->tool->execute(['action' => 'get', 'key' => 'some.missing.key']);

        self::assertTrue($result->success);
        self::assertNull($result->data['value']);
    }

    public function testSearchActionFindsMatchingPrefixes(): void
    {
        $this->configManager->method('get')->willReturn('test-value');

        $result = $this->tool->execute(['action' => 'search', 'key' => 'email']);

        self::assertTrue($result->success);
        self::assertContains('oro_email', $result->data['matching_prefixes']);
        self::assertGreaterThan(0, $result->data['count']);
    }

    public function testSearchActionNoMatchingPrefixes(): void
    {
        $result = $this->tool->execute(['action' => 'search', 'key' => 'zzz_nonexistent_zzz']);

        self::assertTrue($result->success);
        self::assertArrayHasKey('message', $result->data);
        self::assertArrayHasKey('available_prefixes', $result->data);
    }

    public function testEmptyKeyReturnsError(): void
    {
        $result = $this->tool->execute(['action' => 'get', 'key' => '']);

        self::assertFalse($result->success);
        self::assertStringContainsString('required', $result->errorMessage);
    }

    public function testUnknownActionReturnsError(): void
    {
        $result = $this->tool->execute(['action' => 'delete', 'key' => 'test']);

        self::assertFalse($result->success);
    }

    public function testSensitiveValuesRedacted(): void
    {
        $this->configManager->method('get')
            ->with('some.password')
            ->willReturn('sk-secret123');

        $result = $this->tool->execute(['action' => 'get', 'key' => 'some.password']);

        self::assertTrue($result->success);
        self::assertSame('***REDACTED***', $result->data['value']);
    }
}
