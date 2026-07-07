<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\DependencyInjection;

use Genaker\Bundle\OroAI\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testRootNodeName(): void
    {
        self::assertSame('genaker_oro_ai', Configuration::ROOT_NODE);
    }

    public function testDefaultConfigValues(): void
    {
        $config = $this->processConfig([]);

        self::assertArrayHasKey('settings', $config);
        $s = $config['settings'];

        self::assertSame('openai', $s['provider']['value']);
        self::assertNull($s['api_key']['value']);
        self::assertNull($s['api_url']['value']);
        self::assertNull($s['model']['value']);
        self::assertSame(0.3, $s['temperature']['value']);
        self::assertSame(5, $s['max_iterations']['value']);
        self::assertNull($s['embedding_api_key']['value']);
        self::assertNull($s['embedding_url']['value']);
        self::assertSame('text-embedding-3-small', $s['embedding_model']['value']);
        self::assertTrue($s['sql_tool_enabled']['value']);
        self::assertSame(200, $s['sql_row_limit']['value']);
        self::assertTrue($s['rag_enabled']['value']);
        self::assertSame(5, $s['rag_top_k']['value']);
        self::assertFalse($s['learning_enabled']['value']);
    }

    public function testAllExpectedKeysPresent(): void
    {
        $config = $this->processConfig([]);
        $keys = array_keys($config['settings']);

        $expected = [
            'provider', 'api_key', 'api_url', 'model', 'temperature',
            'max_iterations', 'embedding_api_key', 'embedding_url',
            'embedding_model', 'sql_tool_enabled', 'sql_row_limit',
            'rag_enabled', 'rag_top_k', 'learning_enabled',
        ];

        foreach ($expected as $key) {
            self::assertContains($key, $keys, "Config key \"{$key}\" must be defined");
        }
    }

    private function processConfig(array $input): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$input]);
    }
}
