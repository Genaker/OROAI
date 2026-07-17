<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Genaker\Bundle\OroAI\Llm\LlmClientRegistry;
use Genaker\Bundle\OroAI\Service\OroAiConfig;

/**
 * Narrow, independent tool-calling loop for open-ended, multi-step
 * investigations (e.g. "explore the shipping schema and explain how rates
 * are calculated end to end"), invoked by the main agent via ResearchTool.
 *
 * Mirrors Claude Code's own Agent tool: delegating an exploratory task to a
 * sub-agent with its own iteration budget keeps the main conversation's
 * tool-calling loop from being consumed by back-and-forth investigation, and
 * returns one synthesized answer instead of a raw multi-step trace.
 *
 * Two things are deliberate here, not oversights:
 *  - Its own ToolRegistry excludes the "research" tool itself, to prevent a
 *    sub-agent from recursively delegating to another sub-agent forever.
 *  - That ToolRegistry is built lazily (on first investigate() call), not in
 *    the constructor. $tools is the same `genaker_oroai.tool` tagged
 *    iterator ResearchTool itself is tagged into; eagerly consuming it here
 *    would instantiate ResearchTool while ResearchTool is still in the
 *    middle of being constructed (it depends on this class) -- a genuine
 *    circular self-reference that only building lazily avoids.
 */
final class ResearchSubAgent implements ResearchAgentInterface
{
    private ?ToolRegistry $toolRegistry = null;

    /**
     * @param iterable<AiToolInterface> $tools
     */
    public function __construct(
        private readonly LlmClientRegistry $registry,
        private readonly iterable $tools,
        private readonly OroAiConfig $config,
    ) {
    }

    public function investigate(string $task): string
    {
        $toolRegistry = $this->getToolRegistry();
        $tools = $toolRegistry->definitions();

        $messages = [
            ChatMessage::system($this->buildSystemPrompt()),
            ChatMessage::user($task),
        ];

        $client = $this->registry->get();
        $trace = [];

        for ($i = 0; $i < $this->config->getResearchMaxIterations(); $i++) {
            $resp = $client->chat(new LlmRequest($messages, $tools, $this->config->getTemperature()));

            if (!$resp->toolCalls) {
                return $resp->content;
            }

            $messages[] = ChatMessage::assistantToolCalls($resp);

            foreach ($resp->toolCalls as $call) {
                $args = json_decode($call->argsJson, true) ?? [];

                try {
                    $out = $toolRegistry->execute($call->name, $args);
                } catch (\Throwable $e) {
                    $out = ToolResult::error($e->getMessage());
                }

                $trace[] = $out->summary();
                $messages[] = ChatMessage::toolResult($call->id, $out->toJson(), $call->name);
            }
        }

        $findings = $trace !== [] ? implode('; ', $trace) : 'no findings';

        return 'The investigation did not fully conclude within the allotted steps. '
            . 'Findings so far: ' . $findings;
    }

    private function getToolRegistry(): ToolRegistry
    {
        if ($this->toolRegistry === null) {
            $filtered = [];
            foreach ($this->tools as $tool) {
                if ($tool->getName() !== 'research') {
                    $filtered[] = $tool;
                }
            }
            $this->toolRegistry = new ToolRegistry($filtered, $this->config);
        }

        return $this->toolRegistry;
    }

    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
You are a research sub-agent for an OroCommerce admin assistant. You have
been delegated one specific investigation task -- use the available tools
to dig deeply, cross-check findings across tools where useful, and then
respond with a single clear, complete summary of what you found.

Do not ask the user clarifying questions; make reasonable assumptions and
note them if something is genuinely ambiguous. Be thorough but concise.
PROMPT;
    }
}
