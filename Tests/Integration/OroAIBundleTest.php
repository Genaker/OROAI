<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Integration;

use Genaker\Bundle\LocalIntegrationTests\Util\IntegrationTestCase;
use Genaker\Bundle\OroAI\Agent\LearningRecorder;
use Genaker\Bundle\OroAI\Agent\OroAiAgent;
use Genaker\Bundle\OroAI\Agent\ResearchAgentInterface;
use Genaker\Bundle\OroAI\Agent\ResearchSubAgent;
use Genaker\Bundle\OroAI\Agent\ToolRegistry;
use Genaker\Bundle\OroAI\Controller\ChatController;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Genaker\Bundle\OroAI\Llm\LlmClientRegistry;
use Genaker\Bundle\OroAI\Rag\RediSearchRagStore;
use Genaker\Bundle\OroAI\Service\OroAiConfig;

/**
 * Integration tests for OroAI bundle service wiring and tool behaviour.
 *
 * Boots the real dev kernel via IntegrationTestCase and resolves services
 * from the live container.
 *
 * Run:
 *   INTEGRATION_TESTS_ENABLED=1 php bin/phpunit -c phpunit-dev.xml \
 *     --testsuite oroai --no-coverage
 */
class OroAIBundleTest extends IntegrationTestCase
{
    // ------------------------------------------------------------------
    // 1. Service wiring
    // ------------------------------------------------------------------

    public function testServicesAreWired(): void
    {
        $container = static::getContainer();

        self::assertInstanceOf(
            LlmClientRegistry::class,
            $container->get(LlmClientRegistry::class),
        );
        self::assertInstanceOf(
            ToolRegistry::class,
            $container->get(ToolRegistry::class),
        );
        self::assertInstanceOf(
            OroAiAgent::class,
            $container->get(OroAiAgent::class),
        );
        self::assertInstanceOf(
            OroAiConfig::class,
            $container->get(OroAiConfig::class),
        );
        self::assertInstanceOf(
            RediSearchRagStore::class,
            $container->get(RediSearchRagStore::class),
        );
    }

    // ------------------------------------------------------------------
    // 2. LLM client registry providers
    // ------------------------------------------------------------------

    public function testLlmClientRegistryHasProviders(): void
    {
        /** @var LlmClientRegistry $registry */
        $registry = static::getContainer()->get(LlmClientRegistry::class);

        $names = $registry->getAvailableNames();

        self::assertContains('openai', $names);
        self::assertContains('anthropic', $names);
        self::assertContains('gemini', $names);
    }

    // ------------------------------------------------------------------
    // 3. Tool registry tool names
    // ------------------------------------------------------------------

    public function testToolRegistryHasTools(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);

        $names = $registry->names();

        self::assertContains('sql_query', $names);
        self::assertContains('schema_inspector', $names);
        self::assertContains('entity_url', $names);
        self::assertContains('find_entity', $names);
        self::assertContains('doc_search', $names);
    }

    // ------------------------------------------------------------------
    // 4. Tool registry definitions
    // ------------------------------------------------------------------

    public function testToolRegistryDefinitions(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);

        $definitions = $registry->definitions();

        self::assertNotEmpty($definitions);

        foreach ($definitions as $definition) {
            self::assertInstanceOf(ToolDefinition::class, $definition);
            self::assertNotEmpty($definition->name);
            self::assertNotEmpty($definition->description);
            self::assertIsArray($definition->parameters);
        }
    }

    // ------------------------------------------------------------------
    // 5. Chat controller registered
    // ------------------------------------------------------------------

    public function testChatControllerIsRegistered(): void
    {
        $controller = static::getContainer()->get(ChatController::class);

        self::assertInstanceOf(ChatController::class, $controller);
    }

    // ------------------------------------------------------------------
    // 6. EntityUrlTool returns a URL
    // ------------------------------------------------------------------

    public function testEntityUrlToolReturnsUrl(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);

        self::assertTrue($registry->has('entity_url'), 'entity_url tool must be registered');

        $result = $registry->execute('entity_url', [
            'entity' => 'user',
            'action' => 'index',
        ]);

        self::assertInstanceOf(ToolResult::class, $result);
        self::assertTrue($result->success, 'entity_url should succeed: ' . ($result->errorMessage ?? ''));
        self::assertIsArray($result->data);
        self::assertArrayHasKey('url', $result->data);
        self::assertStringContainsString('/admin/', $result->data['url']);
    }

    // ------------------------------------------------------------------
    // 7. SqlQueryTool rejects INSERT
    // ------------------------------------------------------------------

    public function testSqlQueryToolRejectsInsert(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);

        self::assertTrue($registry->has('sql_query'), 'sql_query tool must be registered');

        $result = $registry->execute('sql_query', [
            'sql' => "INSERT INTO oro_user (username) VALUES ('hacker')",
        ]);

        self::assertInstanceOf(ToolResult::class, $result);
        self::assertFalse($result->success, 'INSERT query must be rejected');
        self::assertNotNull($result->errorMessage);
        self::assertStringContainsString('SELECT', $result->errorMessage);
    }

    // ------------------------------------------------------------------
    // 8. SchemaInspectorTool lists tables
    // ------------------------------------------------------------------

    public function testSchemaInspectorListsTables(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);

        self::assertTrue($registry->has('schema_inspector'), 'schema_inspector tool must be registered');

        try {
            $result = $registry->execute('schema_inspector', [
                'action' => 'list_tables',
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unreachable for schema inspector: ' . $e->getMessage());
        }

        self::assertInstanceOf(ToolResult::class, $result);

        if (!$result->success) {
            $this->markTestSkipped('Schema inspector failed (DB may be unavailable): ' . $result->errorMessage);
        }

        self::assertIsArray($result->data);
        self::assertArrayHasKey('tables', $result->data);
        self::assertNotEmpty($result->data['tables'], 'Database should have at least one table');
        self::assertArrayHasKey('table_count', $result->data);
        self::assertGreaterThan(0, $result->data['table_count']);
    }

    // ------------------------------------------------------------------
    // 9. New tools are registered
    // ------------------------------------------------------------------

    public function testNewToolsAreRegistered(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);
        $names = $registry->names();

        $expected = [
            'config_inspector', 'entity_metadata', 'route_search',
            'log_reader', 'system_info', 'translation_lookup', 'user_info',
        ];

        foreach ($expected as $tool) {
            self::assertContains($tool, $names, "Tool \"{$tool}\" must be registered");
        }
    }

    // ------------------------------------------------------------------
    // 10. OroAiConfig service works
    // ------------------------------------------------------------------

    public function testOroAiConfigReturnsDefaults(): void
    {
        /** @var OroAiConfig $config */
        $config = static::getContainer()->get(OroAiConfig::class);

        self::assertSame('openai', $config->getProvider());
        self::assertSame(0.3, $config->getTemperature());
        self::assertSame(5, $config->getMaxIterations());
        self::assertTrue($config->isSqlToolEnabled());
        self::assertSame(200, $config->getSqlRowLimit());
        self::assertTrue($config->isRagEnabled());
        self::assertSame(5, $config->getRagTopK());
        self::assertFalse($config->isLearningEnabled());
    }

    // ------------------------------------------------------------------
    // 11. isConfigured reflects API key state
    // ------------------------------------------------------------------

    public function testIsConfiguredWithoutKey(): void
    {
        /** @var OroAiConfig $config */
        $config = static::getContainer()->get(OroAiConfig::class);

        $hasKey = $config->getApiKey() !== '';
        self::assertSame($hasKey, $config->isConfigured());
    }

    // ------------------------------------------------------------------
    // 12. System configuration page renders
    // ------------------------------------------------------------------

    public function testSystemConfigurationFieldsAreRegistered(): void
    {
        $container = static::getContainer();

        if (!$container->has('oro_config.provider.system_configuration.form_provider')) {
            $this->markTestSkipped('System configuration form provider not available.');
        }

        try {
            $formProvider = $container->get('oro_config.provider.system_configuration.form_provider');
            $tree = $formProvider->getTree();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Cannot access config tree: ' . $e->getMessage());
        }

        self::assertNotNull($tree, 'System configuration tree must not be null');
    }

    // ------------------------------------------------------------------
    // 13. ConfigInspectorTool works with real ConfigManager
    // ------------------------------------------------------------------

    public function testConfigInspectorToolGetProvider(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);

        $result = $registry->execute('config_inspector', [
            'action' => 'get',
            'key' => 'genaker_oro_ai.provider',
        ]);

        self::assertTrue($result->success, 'config_inspector get should succeed: ' . ($result->errorMessage ?? ''));
        self::assertSame('openai', $result->data['value']);
    }

    // ------------------------------------------------------------------
    // 14. SystemInfoTool works in real environment
    // ------------------------------------------------------------------

    public function testSystemInfoToolOverview(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);

        $result = $registry->execute('system_info', ['section' => 'overview']);

        self::assertTrue($result->success);
        self::assertSame(PHP_VERSION, $result->data['php_version']);
        self::assertSame('dev', $result->data['symfony_env']);
    }

    // ------------------------------------------------------------------
    // 15. SqlQueryTool works with real DB (SELECT)
    // ------------------------------------------------------------------

    public function testSqlQueryToolSelectWorks(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);

        try {
            $result = $registry->execute('sql_query', [
                'sql' => 'SELECT 1 AS test_val',
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unreachable: ' . $e->getMessage());
        }

        if (!$result->success) {
            $this->markTestSkipped('SQL tool failed (DB may be unavailable): ' . $result->errorMessage);
        }

        self::assertSame(1, $result->data['row_count']);
        self::assertSame(1, $result->data['rows'][0]['test_val']);
    }

    // ------------------------------------------------------------------
    // 16. RouteSearchTool finds admin routes
    // ------------------------------------------------------------------

    public function testRouteSearchToolFindsOrderRoutes(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);

        $result = $registry->execute('route_search', ['keyword' => 'order']);

        self::assertTrue($result->success);
        self::assertGreaterThan(0, $result->data['count']);
        self::assertNotEmpty($result->data['routes']);
    }

    // ------------------------------------------------------------------
    // 17. LearningRecorder service is wired
    // ------------------------------------------------------------------

    public function testLearningRecorderIsWired(): void
    {
        $recorder = static::getContainer()->get(LearningRecorder::class);
        self::assertInstanceOf(LearningRecorder::class, $recorder);
    }

    // ------------------------------------------------------------------
    // 18. ToolRegistry respects per-tool enable/disable config
    // ------------------------------------------------------------------

    public function testToolRegistryRespectsDefaultDisabledTools(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);
        $names = $registry->names();

        self::assertNotContains('log_reader', $names, 'log_reader is disabled by default');
        self::assertNotContains('system_info', $names, 'system_info is disabled by default');
        self::assertNotContains('research', $names, 'research is disabled by default');
    }

    public function testResearchSubAgentIsRegisteredAndWired(): void
    {
        // ResearchAgentInterface's alias is private (like HarnessInterface/
        // RagStoreInterface elsewhere in this bundle) -- it only needs to be
        // resolvable via constructor autowiring, not fetched directly from
        // the container, so we fetch the concrete public service instead.
        $subAgent = static::getContainer()->get(ResearchSubAgent::class);

        self::assertInstanceOf(ResearchAgentInterface::class, $subAgent);
    }

    public function testToolRegistryEnabledToolsArePresent(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);
        $names = $registry->names();

        $expectedEnabled = [
            'sql_query', 'schema_inspector', 'entity_url', 'find_entity',
            'doc_search', 'config_inspector', 'entity_metadata', 'route_search',
            'translation_lookup', 'user_info',
        ];

        foreach ($expectedEnabled as $tool) {
            self::assertContains($tool, $names, "Tool \"{$tool}\" should be enabled by default");
        }
    }

    public function testDisabledToolExecuteReturnsError(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);

        $result = $registry->execute('log_reader', ['action' => 'tail', 'lines' => 5]);

        self::assertFalse($result->success, 'Disabled tool must return error result');
        self::assertStringContainsString('disabled', $result->errorMessage ?? '');
    }

    // ------------------------------------------------------------------
    // 19. OroAiConfig::isToolEnabled reads defaults correctly
    // ------------------------------------------------------------------

    public function testIsToolEnabledReflectsConfig(): void
    {
        /** @var OroAiConfig $config */
        $config = static::getContainer()->get(OroAiConfig::class);

        self::assertTrue($config->isToolEnabled('sql_query'));
        self::assertTrue($config->isToolEnabled('schema_inspector'));
        self::assertFalse($config->isToolEnabled('log_reader'), 'log_reader disabled by default');
        self::assertFalse($config->isToolEnabled('system_info'), 'system_info disabled by default');
        self::assertTrue($config->isToolEnabled('unknown_future_tool'), 'Unknown tool name must default to enabled');
    }

    // ------------------------------------------------------------------
    // 20. Chat controller returns JSON (not HTML) when configured
    //     Skipped when no API key is set in the database.
    // ------------------------------------------------------------------

    public function testChatControllerReturnsJsonNotHtml(): void
    {
        /** @var OroAiConfig $config */
        $config = static::getContainer()->get(OroAiConfig::class);

        if (!$config->isConfigured()) {
            $this->markTestSkipped('No API key configured — skipping live chat test.');
        }

        /** @var ChatController $controller */
        $controller = static::getContainer()->get(ChatController::class);

        $request = new \Symfony\Component\HttpFoundation\Request(
            [],
            [],
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['message' => 'Hello, what is 1+1?', 'history' => []], JSON_THROW_ON_ERROR),
        );

        try {
            $response = $controller->messageAction($request);
        } catch (\Throwable $e) {
            self::fail('Chat controller threw instead of returning JSON: ' . $e->getMessage());
        }

        $content = $response->getContent();

        self::assertNotFalse($content);
        self::assertStringNotContainsString(
            '<',
            $content,
            'Response must not be HTML — got: ' . substr((string) $content, 0, 300),
        );
        self::assertJson($content, 'Response must be valid JSON');

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey('reply', $data, 'Successful response must have "reply". Got: ' . json_encode($data));
        self::assertNotEmpty($data['reply']);
    }

    // ------------------------------------------------------------------
    // 21. Chat controller returns JSON 400 (not HTML) when not configured
    // ------------------------------------------------------------------

    public function testChatControllerReturns400JsonWhenNotConfigured(): void
    {
        /** @var OroAiConfig $config */
        $config = static::getContainer()->get(OroAiConfig::class);

        if ($config->isConfigured()) {
            $this->markTestSkipped('API key is set — this test only applies when no key is configured.');
        }

        /** @var ChatController $controller */
        $controller = static::getContainer()->get(ChatController::class);

        $request = new \Symfony\Component\HttpFoundation\Request(
            [],
            [],
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['message' => 'hello'], JSON_THROW_ON_ERROR),
        );

        $response = $controller->messageAction($request);
        $content = $response->getContent();

        self::assertSame(400, $response->getStatusCode());
        self::assertJson($content, 'Must return JSON even when not configured — got: ' . substr((string) $content, 0, 200));

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['not_configured'] ?? false);
    }
}
