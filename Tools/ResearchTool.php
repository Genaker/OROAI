<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Genaker\Bundle\OroAI\Agent\ResearchAgentInterface;
use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;

/**
 * Delegates an open-ended, multi-step investigation to a dedicated research
 * sub-agent with its own tool-calling loop and iteration budget, returning a
 * single synthesized answer instead of a raw tool trace.
 *
 * Disabled by default (see OroAiConfig::TOOLS_DEFAULT_DISABLED) -- unlike
 * every other tool here, one call spawns a whole extra multi-step LLM loop,
 * so it's opt-in via System Configuration → AI Assistant → Enabled Tools.
 */
final class ResearchTool implements AiToolInterface
{
    public function __construct(private readonly ResearchAgentInterface $subAgent)
    {
    }

    public function getName(): string
    {
        return 'research';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'research',
            'Delegate a deep, multi-step investigation to a research sub-agent with its own tool-calling '
            . 'loop and a larger step budget. Use this for open-ended questions that need cross-checking '
            . 'several tools or tables rather than a single direct lookup -- e.g. "explain how shipping '
            . 'rates are calculated end to end" or "investigate why order #123 has no invoice". Returns '
            . 'one synthesized answer, not a raw trace. Prefer a direct tool when a single lookup suffices.',
            [
                'type' => 'object',
                'properties' => [
                    'task' => [
                        'type' => 'string',
                        'description' => 'The investigation task, described as clearly and completely as possible.',
                    ],
                ],
                'required' => ['task'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $task = trim($arguments['task'] ?? '');
        if ($task === '') {
            return ToolResult::error('Parameter "task" is required.');
        }

        try {
            $summary = $this->subAgent->investigate($task);

            return ToolResult::success(['task' => $task, 'summary' => $summary]);
        } catch (\Throwable $e) {
            return ToolResult::error('Research sub-agent failed: ' . $e->getMessage());
        }
    }
}
