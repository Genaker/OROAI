<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Service;

use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class OroAiConfigTest extends TestCase
{
    private ConfigManager&MockObject $configManager;
    private SymmetricCrypterInterface&MockObject $crypter;
    private OroAiConfig $config;

    /** @var array<string,mixed> */
    private array $savedEnv = [];

    private const array ENV_VARS = [
        'OROAI_PROVIDER', 'OROAI_API_KEY', 'OROAI_MODEL',
        'OROAI_API_URL', 'OROAI_EMBEDDING_API_KEY', 'OROAI_REDIS_URL',
        'OROAI_CUSTOM_INSTRUCTIONS',
    ];

    protected function setUp(): void
    {
        foreach (self::ENV_VARS as $var) {
            $this->savedEnv[$var] = $_SERVER[$var] ?? null;
            unset($_SERVER[$var], $_ENV[$var]);
        }

        $this->configManager = $this->createMock(ConfigManager::class);
        $this->crypter = $this->createMock(SymmetricCrypterInterface::class);
        $this->crypter->method('decryptData')->willReturnArgument(0);
        $this->config = new OroAiConfig($this->configManager, $this->crypter);
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_VARS as $var) {
            if ($this->savedEnv[$var] !== null) {
                $_SERVER[$var] = $this->savedEnv[$var];
            } else {
                unset($_SERVER[$var]);
            }
        }
    }

    public function testIsConfiguredReturnsFalseWithoutKey(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.api_key')
            ->willReturn(null);

        self::assertFalse($this->config->isConfigured());
    }

    public function testIsConfiguredReturnsTrueWithKey(): void
    {
        $this->configManager->method('get')
            ->willReturnMap([
                ['genaker_oro_ai.api_key', false, false, null, 'sk-test-key'],
            ]);

        self::assertTrue($this->config->isConfigured());
    }

    public function testGetProviderDefault(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.provider')
            ->willReturn(null);

        self::assertSame('openai', $this->config->getProvider());
    }

    public function testGetProviderFromConfig(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.provider')
            ->willReturn('anthropic');

        self::assertSame('anthropic', $this->config->getProvider());
    }

    public function testGetApiKeyEmpty(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.api_key')
            ->willReturn(null);

        self::assertSame('', $this->config->getApiKey());
    }

    public function testGetApiKeyFromConfig(): void
    {
        $this->configManager->method('get')
            ->willReturnMap([
                ['genaker_oro_ai.api_key', false, false, null, 'sk-my-key'],
            ]);

        self::assertSame('sk-my-key', $this->config->getApiKey());
    }

    public function testGetApiKeyDecryptsEncryptedValue(): void
    {
        $crypter = $this->createMock(SymmetricCrypterInterface::class);
        $crypter->method('decryptData')->with('ENCRYPTED_BLOB')->willReturn('sk-real-key');

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')
            ->willReturnMap([['genaker_oro_ai.api_key', false, false, null, 'ENCRYPTED_BLOB']]);

        $config = new OroAiConfig($configManager, $crypter);

        self::assertSame('sk-real-key', $config->getApiKey());
        self::assertTrue($config->isConfigured());
    }

    public function testGetTemperatureDefault(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.temperature')
            ->willReturn(null);

        self::assertSame(0.3, $this->config->getTemperature());
    }

    public function testGetTemperatureFromConfig(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.temperature')
            ->willReturn(0.7);

        self::assertSame(0.7, $this->config->getTemperature());
    }

    public function testGetMaxIterationsDefault(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.max_iterations')
            ->willReturn(null);

        self::assertSame(5, $this->config->getMaxIterations());
    }

    public function testIsSqlToolEnabledDefault(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.sql_tool_enabled')
            ->willReturn(null);

        self::assertTrue($this->config->isSqlToolEnabled());
    }

    public function testGetSqlRowLimitDefault(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.sql_row_limit')
            ->willReturn(null);

        self::assertSame(200, $this->config->getSqlRowLimit());
    }

    public function testIsRagEnabledDefault(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.rag_enabled')
            ->willReturn(null);

        self::assertTrue($this->config->isRagEnabled());
    }

    public function testGetRagTopKDefault(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.rag_top_k')
            ->willReturn(null);

        self::assertSame(5, $this->config->getRagTopK());
    }

    public function testGetCustomInstructionsDefaultEmpty(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.custom_instructions')
            ->willReturn(null);

        self::assertSame('', $this->config->getCustomInstructions());
    }

    public function testGetCustomInstructionsFromConfig(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.custom_instructions')
            ->willReturn('Always refer to customers as "accounts".');

        self::assertSame('Always refer to customers as "accounts".', $this->config->getCustomInstructions());
    }

    public function testGetCustomInstructionsTrimsWhitespace(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.custom_instructions')
            ->willReturn("  Be concise.  \n");

        self::assertSame('Be concise.', $this->config->getCustomInstructions());
    }

    public function testGetCustomInstructionsEnvVarOverridesConfig(): void
    {
        $_SERVER['OROAI_CUSTOM_INSTRUCTIONS'] = 'From env var.';

        $this->configManager->method('get')
            ->with('genaker_oro_ai.custom_instructions')
            ->willReturn('From DB config.');

        self::assertSame('From env var.', $this->config->getCustomInstructions());
    }

    public function testIsLearningEnabledDefault(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.learning_enabled')
            ->willReturn(null);

        self::assertFalse($this->config->isLearningEnabled());
    }

    public function testGetEmbeddingModelDefault(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.embedding_model')
            ->willReturn(null);

        self::assertSame('text-embedding-3-small', $this->config->getEmbeddingModel());
    }

    public function testIsToolEnabledReturnsTrueByDefault(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.tool_sql_query_enabled')
            ->willReturn(null);

        self::assertTrue($this->config->isToolEnabled('sql_query'));
    }

    public function testIsToolEnabledReturnsFalseWhenDisabled(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.tool_log_reader_enabled')
            ->willReturn(false);

        self::assertFalse($this->config->isToolEnabled('log_reader'));
    }

    public function testIsToolEnabledReturnsTrueForUnknownTool(): void
    {
        self::assertTrue($this->config->isToolEnabled('nonexistent_tool'));
    }

    /**
     * Regression guard: the research tool is the one exception to "every
     * tool defaults to enabled" -- spawning a whole extra sub-agent
     * tool-calling loop per call is opt-in, not on by default.
     */
    public function testIsToolEnabledReturnsFalseByDefaultForResearch(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.tool_research_enabled')
            ->willReturn(null);

        self::assertFalse($this->config->isToolEnabled('research'));
    }

    public function testIsToolEnabledReturnsTrueForResearchWhenExplicitlyEnabled(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.tool_research_enabled')
            ->willReturn(true);

        self::assertTrue($this->config->isToolEnabled('research'));
    }

    public function testIsToolEnabledReturnsFalseForResearchWhenExplicitlyDisabled(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.tool_research_enabled')
            ->willReturn(false);

        self::assertFalse($this->config->isToolEnabled('research'));
    }

    public function testGetResearchMaxIterationsDefault(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.research_max_iterations')
            ->willReturn(null);

        self::assertSame(8, $this->config->getResearchMaxIterations());
    }

    public function testGetResearchMaxIterationsFromConfig(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.research_max_iterations')
            ->willReturn(12);

        self::assertSame(12, $this->config->getResearchMaxIterations());
    }

    public function testGetResearchMaxIterationsEnforcesMinimumOfOne(): void
    {
        $this->configManager->method('get')
            ->with('genaker_oro_ai.research_max_iterations')
            ->willReturn(0);

        self::assertSame(1, $this->config->getResearchMaxIterations());
    }

    public function testIsToolEnabledAllKnownTools(): void
    {
        $toolKeys = [
            'sql_query'          => 'tool_sql_query_enabled',
            'schema_inspector'   => 'tool_schema_inspector_enabled',
            'entity_url'         => 'tool_entity_url_enabled',
            'find_entity'        => 'tool_find_entity_enabled',
            'doc_search'         => 'tool_doc_search_enabled',
            'config_inspector'   => 'tool_config_inspector_enabled',
            'entity_metadata'    => 'tool_entity_metadata_enabled',
            'route_search'       => 'tool_route_search_enabled',
            'log_reader'         => 'tool_log_reader_enabled',
            'system_info'        => 'tool_system_info_enabled',
            'translation_lookup' => 'tool_translation_lookup_enabled',
            'user_info'          => 'tool_user_info_enabled',
        ];

        $this->configManager->method('get')->willReturnCallback(
            static function (string $key) use ($toolKeys): bool {
                return in_array($key, array_map(
                    static fn(string $suffix) => 'genaker_oro_ai.' . $suffix,
                    $toolKeys,
                ), true);
            }
        );

        foreach (array_keys($toolKeys) as $toolName) {
            self::assertTrue(
                $this->config->isToolEnabled($toolName),
                "isToolEnabled('{$toolName}') should return true when config returns true",
            );
        }
    }
}
