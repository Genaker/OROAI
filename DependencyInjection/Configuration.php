<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\DependencyInjection;

use Oro\Bundle\ConfigBundle\DependencyInjection\SettingsBuilder;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public const string ROOT_NODE = 'genaker_oro_ai';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_NODE);
        $rootNode = $treeBuilder->getRootNode();

        if (!$rootNode instanceof ArrayNodeDefinition) {
            throw new \LogicException('Expected the config tree root node to be an ArrayNodeDefinition.');
        }

        SettingsBuilder::append(
            $rootNode,
            [
                'provider' => ['type' => 'scalar', 'value' => 'openai'],
                'api_key' => ['type' => 'scalar', 'value' => null],
                'api_url' => ['type' => 'scalar', 'value' => null],
                'model' => ['type' => 'scalar', 'value' => null],
                'custom_instructions' => ['type' => 'scalar', 'value' => null],
                'additional_guidelines' => ['type' => 'scalar', 'value' => null],
                'temperature' => ['type' => 'scalar', 'value' => 0.3],
                'max_iterations' => ['type' => 'scalar', 'value' => 5],
                'max_retries' => ['type' => 'scalar', 'value' => 0],
                'embedding_api_key' => ['type' => 'scalar', 'value' => null],
                'embedding_url' => ['type' => 'scalar', 'value' => null],
                'embedding_model' => ['type' => 'scalar', 'value' => 'text-embedding-3-small'],
                'sql_tool_enabled' => ['type' => 'boolean', 'value' => true],
                'sql_row_limit' => ['type' => 'scalar', 'value' => 200],
                'rag_enabled' => ['type' => 'boolean', 'value' => true],
                'rag_top_k' => ['type' => 'scalar', 'value' => 5],
                'learning_enabled' => ['type' => 'boolean', 'value' => false],
                'harness_enabled' => ['type' => 'boolean', 'value' => false],
                'harness_max_tries' => ['type' => 'scalar', 'value' => 10],
                'research_max_iterations' => ['type' => 'scalar', 'value' => 8],
                'tool_sql_query_enabled' => ['type' => 'boolean', 'value' => true],
                'tool_schema_inspector_enabled' => ['type' => 'boolean', 'value' => true],
                'tool_entity_url_enabled' => ['type' => 'boolean', 'value' => true],
                'tool_find_entity_enabled' => ['type' => 'boolean', 'value' => true],
                'tool_doc_search_enabled' => ['type' => 'boolean', 'value' => true],
                'tool_config_inspector_enabled' => ['type' => 'boolean', 'value' => true],
                'tool_entity_metadata_enabled' => ['type' => 'boolean', 'value' => true],
                'tool_route_search_enabled' => ['type' => 'boolean', 'value' => true],
                'tool_log_reader_enabled' => ['type' => 'boolean', 'value' => false],
                'tool_system_info_enabled' => ['type' => 'boolean', 'value' => false],
                'tool_translation_lookup_enabled' => ['type' => 'boolean', 'value' => true],
                'tool_user_info_enabled' => ['type' => 'boolean', 'value' => true],
                'tool_research_enabled' => ['type' => 'boolean', 'value' => false],
                'tool_skill_enabled' => ['type' => 'boolean', 'value' => true],
                'disabled_skills' => ['type' => 'array', 'value' => []],
                'tool_code_search_enabled' => ['type' => 'boolean', 'value' => true],
                'tool_code_read_enabled' => ['type' => 'boolean', 'value' => true],
                'tool_console_command_enabled' => ['type' => 'boolean', 'value' => true],
            ]
        );

        return $treeBuilder;
    }
}
