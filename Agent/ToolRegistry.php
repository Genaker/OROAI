<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Genaker\Bundle\OroAI\Service\OroAiConfig;

/** Holds all registered AI tools and dispatches execution to the correct tool by name. */
final class ToolRegistry
{
    /** @var array<string, AiToolInterface> */
    private array $tools = [];

    public function __construct(iterable $tools, private readonly OroAiConfig $config)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }
    }

    /** @return ToolDefinition[] — only enabled tools */
    public function definitions(): array
    {
        return array_map(
            static fn(AiToolInterface $t) => $t->getDefinition(),
            array_values($this->enabledTools()),
        );
    }

    public function execute(string $name, array $arguments): ToolResult
    {
        if (!isset($this->tools[$name])) {
            return ToolResult::error(sprintf(
                'Unknown tool "%s". Available: %s',
                $name,
                implode(', ', array_keys($this->enabledTools())),
            ));
        }

        if (!$this->config->isToolEnabled($name)) {
            return ToolResult::error(sprintf('Tool "%s" is disabled in system configuration.', $name));
        }

        return $this->tools[$name]->execute($arguments);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]) && $this->config->isToolEnabled($name);
    }

    /** @return string[] — only enabled tools */
    public function names(): array
    {
        return array_keys($this->enabledTools());
    }

    /** @return array<string, AiToolInterface> */
    private function enabledTools(): array
    {
        return array_filter(
            $this->tools,
            fn(AiToolInterface $t) => $this->config->isToolEnabled($t->getName()),
        );
    }
}
