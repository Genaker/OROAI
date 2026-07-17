<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Service;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;

/** Reads AI config values from system configuration and env vars. */
class OroAiConfig
{
    private const string KEY_PROVIDER          = 'genaker_oro_ai.provider';
    private const string KEY_API_KEY           = 'genaker_oro_ai.api_key';
    private const string KEY_API_URL           = 'genaker_oro_ai.api_url';
    private const string KEY_MODEL             = 'genaker_oro_ai.model';
    private const string KEY_TEMPERATURE       = 'genaker_oro_ai.temperature';
    private const string KEY_MAX_ITERATIONS    = 'genaker_oro_ai.max_iterations';
    private const string KEY_MAX_RETRIES       = 'genaker_oro_ai.max_retries';
    private const string KEY_EMBEDDING_API_KEY = 'genaker_oro_ai.embedding_api_key';
    private const string KEY_EMBEDDING_URL     = 'genaker_oro_ai.embedding_url';
    private const string KEY_EMBEDDING_MODEL   = 'genaker_oro_ai.embedding_model';
    private const string KEY_SQL_TOOL_ENABLED  = 'genaker_oro_ai.sql_tool_enabled';
    private const string KEY_SQL_ROW_LIMIT     = 'genaker_oro_ai.sql_row_limit';
    private const string KEY_RAG_ENABLED       = 'genaker_oro_ai.rag_enabled';
    private const string KEY_RAG_TOP_K         = 'genaker_oro_ai.rag_top_k';
    private const string KEY_LEARNING_ENABLED  = 'genaker_oro_ai.learning_enabled';
    private const string KEY_HARNESS_ENABLED   = 'genaker_oro_ai.harness_enabled';
    private const string KEY_HARNESS_MAX_TRIES = 'genaker_oro_ai.harness_max_tries';
    private const string KEY_CUSTOM_INSTRUCTIONS = 'genaker_oro_ai.custom_instructions';
    private const string KEY_ADDITIONAL_GUIDELINES = 'genaker_oro_ai.additional_guidelines';
    private const string KEY_DISABLED_SKILLS = 'genaker_oro_ai.disabled_skills';
    private const string KEY_RESEARCH_MAX_ITERATIONS = 'genaker_oro_ai.research_max_iterations';

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
        'research'         => 'tool_research_enabled',
        'skill'            => 'tool_skill_enabled',
        'code_search'      => 'tool_code_search_enabled',
        'code_read'        => 'tool_code_read_enabled',
        'console_command'  => 'tool_console_command_enabled',
    ];

    /**
     * Tools that default to DISABLED when their config value has never been
     * set, unlike every other tool (which defaults to enabled). The research
     * tool spawns a whole extra tool-calling loop (several LLM calls) per
     * invocation, so it's opt-in rather than on-by-default.
     */
    private const array TOOLS_DEFAULT_DISABLED = ['research'];

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

    /**
     * Fallback models to switch to when the configured model hits a persistent
     * rate/quota limit (HTTP 429) — e.g. Google zeroing out the free-tier quota
     * of an older model. Comma-separated in OROAI_FALLBACK_MODELS; empty when
     * unset (clients then apply their own per-provider defaults).
     *
     * @return list<string>
     */
    public function getFallbackModels(): array
    {
        $env = $this->env('OROAI_FALLBACK_MODELS');
        if ($env === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $env))));
    }

    public function getTemperature(): float
    {
        return (float) ($this->configManager->get(self::KEY_TEMPERATURE) ?? 0.3);
    }

    public function getMaxIterations(): int
    {
        return (int) ($this->configManager->get(self::KEY_MAX_ITERATIONS) ?? 5);
    }

    public function getMaxRetries(): int
    {
        return min(5, (int) ($this->configManager->get(self::KEY_MAX_RETRIES) ?? 0));
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

    public function isHarnessEnabled(): bool
    {
        $value = $this->configManager->get(self::KEY_HARNESS_ENABLED);

        return $value === null ? false : (bool) $value;
    }

    public function getHarnessMaxTries(): int
    {
        return max(1, (int) ($this->configManager->get(self::KEY_HARNESS_MAX_TRIES) ?? 10));
    }

    /**
     * Free-form instructions prepended ahead of the built-in system prompt on
     * every LLM call (e.g. house style, company-specific terminology, extra
     * guardrails). Empty by default -- purely additive, never required.
     */
    public function getCustomInstructions(): string
    {
        $env = $this->env('OROAI_CUSTOM_INSTRUCTIONS');
        if ($env !== '') {
            return $env;
        }

        return trim((string) ($this->configManager->get(self::KEY_CUSTOM_INSTRUCTIONS) ?? ''));
    }

    /**
     * Raw admin-entered "Additional Guidelines" text (System Configuration or
     * the OROAI_ADDITIONAL_GUIDELINES env var). Parsing/merge semantics live
     * in GuidelineProvider — a YAML mapping there can override or remove
     * bundle guidelines by key; plain lines are additive.
     */
    /**
     * Skill keys hidden from the agent (unticked in the admin "Skills" list,
     * or the OROAI_DISABLED_SKILLS env var as a comma-separated list). Skills
     * absent from this list are enabled, so newly added skills default ON.
     *
     * @return list<string>
     */
    public function getDisabledSkills(): array
    {
        $env = $this->env('OROAI_DISABLED_SKILLS');
        if ($env !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $env))));
        }

        $value = $this->configManager->get(self::KEY_DISABLED_SKILLS);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public function getAdditionalGuidelinesText(): string
    {
        $env = $this->env('OROAI_ADDITIONAL_GUIDELINES');
        if ($env !== '') {
            return $env;
        }

        return trim((string) ($this->configManager->get(self::KEY_ADDITIONAL_GUIDELINES) ?? ''));
    }

    public function isToolEnabled(string $toolName): bool
    {
        if (!isset(self::TOOL_CONFIG_KEYS[$toolName])) {
            return true;
        }

        $value = $this->configManager->get('genaker_oro_ai.' . self::TOOL_CONFIG_KEYS[$toolName]);
        if ($value !== null) {
            return (bool) $value;
        }

        return !in_array($toolName, self::TOOLS_DEFAULT_DISABLED, true);
    }

    /**
     * Max tool-calling rounds for the research sub-agent's OWN loop (see
     * ResearchSubAgent) -- separate from, and typically higher than,
     * getMaxIterations() since a delegated deep-dive investigation
     * legitimately needs more steps than a single conversational turn.
     */
    public function getResearchMaxIterations(): int
    {
        return max(1, (int) ($this->configManager->get(self::KEY_RESEARCH_MAX_ITERATIONS) ?? 8));
    }

    /**
     * Read an env var from $_SERVER first (Symfony Dotenv sets it there),
     * then $_ENV, then fall back to getenv() for system-level vars.
     *
     * @SuppressWarnings(PHPMD.Superglobals)
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
