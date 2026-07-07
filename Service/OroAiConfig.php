<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Service;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;

class OroAiConfig
{
    private const string KEY_PROVIDER          = 'genaker_oro_ai.provider';
    private const string KEY_API_KEY           = 'genaker_oro_ai.api_key';
    private const string KEY_API_URL           = 'genaker_oro_ai.api_url';
    private const string KEY_MODEL             = 'genaker_oro_ai.model';
    private const string KEY_TEMPERATURE       = 'genaker_oro_ai.temperature';
    private const string KEY_MAX_ITERATIONS    = 'genaker_oro_ai.max_iterations';
    private const string KEY_EMBEDDING_API_KEY = 'genaker_oro_ai.embedding_api_key';
    private const string KEY_EMBEDDING_URL     = 'genaker_oro_ai.embedding_url';
    private const string KEY_EMBEDDING_MODEL   = 'genaker_oro_ai.embedding_model';
    private const string KEY_SQL_TOOL_ENABLED  = 'genaker_oro_ai.sql_tool_enabled';
    private const string KEY_SQL_ROW_LIMIT     = 'genaker_oro_ai.sql_row_limit';
    private const string KEY_RAG_ENABLED       = 'genaker_oro_ai.rag_enabled';
    private const string KEY_RAG_TOP_K         = 'genaker_oro_ai.rag_top_k';
    private const string KEY_LEARNING_ENABLED  = 'genaker_oro_ai.learning_enabled';

    /** Maps tool getName() → config key suffix */
    private const array TOOL_CONFIG_KEYS = [
        'sql_query'        => 'tool_sql_query_enabled',
        'schema_inspector' => 'tool_schema_inspector_enabled',
        'entity_url'       => 'tool_entity_url_enabled',
        'find_entity'      => 'tool_find_entity_enabled',
        'doc_search'       => 'tool_doc_search_enabled',
        'config_inspector' => 'tool_config_inspector_enabled',
        'entity_metadata'  => 'tool_entity_metadata_enabled',
        'route_search'     => 'tool_route_search_enabled',
        'log_reader'       => 'tool_log_reader_enabled',
        'system_info'      => 'tool_system_info_enabled',
        'translation_lookup' => 'tool_translation_lookup_enabled',
        'user_info'        => 'tool_user_info_enabled',
    ];

    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly SymmetricCrypterInterface $crypter,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->getApiKey() !== '';
    }

    public function getProvider(): string
    {
        $env = $this->env('OROAI_PROVIDER');
        if ($env !== '') {
            return $env;
        }

        return (string) ($this->configManager->get(self::KEY_PROVIDER) ?? 'openai');
    }

    public function getApiKey(): string
    {
        $env = $this->env('OROAI_API_KEY');
        if ($env !== '') {
            return $env;
        }

        return $this->decryptConfigValue($this->configManager->get(self::KEY_API_KEY));
    }

    public function getApiUrl(): string
    {
        $env = $this->env('OROAI_API_URL');
        if ($env !== '') {
            return $env;
        }

        return (string) ($this->configManager->get(self::KEY_API_URL) ?? '');
    }

    public function getModel(): string
    {
        $env = $this->env('OROAI_MODEL');
        if ($env !== '') {
            return $env;
        }

        return (string) ($this->configManager->get(self::KEY_MODEL) ?? '');
    }

    public function getTemperature(): float
    {
        return (float) ($this->configManager->get(self::KEY_TEMPERATURE) ?? 0.3);
    }

    public function getMaxIterations(): int
    {
        return (int) ($this->configManager->get(self::KEY_MAX_ITERATIONS) ?? 5);
    }

    public function getEmbeddingApiKey(): string
    {
        $env = $this->env('OROAI_EMBEDDING_API_KEY');
        if ($env !== '') {
            return $env;
        }

        return $this->decryptConfigValue($this->configManager->get(self::KEY_EMBEDDING_API_KEY));
    }

    public function getEmbeddingUrl(): string
    {
        return (string) ($this->configManager->get(self::KEY_EMBEDDING_URL) ?? '');
    }

    public function getEmbeddingModel(): string
    {
        return (string) ($this->configManager->get(self::KEY_EMBEDDING_MODEL) ?? 'text-embedding-3-small');
    }

    public function isSqlToolEnabled(): bool
    {
        $value = $this->configManager->get(self::KEY_SQL_TOOL_ENABLED);

        return $value === null ? true : (bool) $value;
    }

    public function getSqlRowLimit(): int
    {
        return (int) ($this->configManager->get(self::KEY_SQL_ROW_LIMIT) ?? 200);
    }

    public function isRagEnabled(): bool
    {
        $value = $this->configManager->get(self::KEY_RAG_ENABLED);

        return $value === null ? true : (bool) $value;
    }

    public function getRagTopK(): int
    {
        return (int) ($this->configManager->get(self::KEY_RAG_TOP_K) ?? 5);
    }

    public function isLearningEnabled(): bool
    {
        $value = $this->configManager->get(self::KEY_LEARNING_ENABLED);

        return $value === null ? false : (bool) $value;
    }

    public function isToolEnabled(string $toolName): bool
    {
        if (!isset(self::TOOL_CONFIG_KEYS[$toolName])) {
            return true;
        }

        $value = $this->configManager->get('genaker_oro_ai.' . self::TOOL_CONFIG_KEYS[$toolName]);

        return $value === null ? true : (bool) $value;
    }

    /**
     * Read an env var from $_SERVER first (Symfony Dotenv sets it there),
     * then $_ENV, then fall back to getenv() for system-level vars.
     */
    private function env(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return ($value !== false && $value !== null) ? (string) $value : '';
    }

    private function decryptConfigValue(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        try {
            $decrypted = $this->crypter->decryptData((string) $raw);
        } catch (\Throwable) {
            $decrypted = (string) $raw;
        }

        return $decrypted !== '' ? $decrypted : (string) $raw;
    }
}
