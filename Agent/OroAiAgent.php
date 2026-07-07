<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

use Genaker\Bundle\OroAI\Core\Model\AgentResult;
use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\Role;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Genaker\Bundle\OroAI\Llm\LlmClientRegistry;
use Genaker\Bundle\OroAI\Rag\RagStoreInterface;
use Genaker\Bundle\OroAI\Service\OroAiConfig;

class OroAiAgent
{
    public function __construct(
        private readonly LlmClientRegistry $registry,
        private readonly ToolRegistry $toolRegistry,
        private readonly RagStoreInterface $ragStore,
        private readonly OroAiConfig $config,
    ) {
    }

    /**
     * @param ChatMessage[] $history
     */
    public function run(string $userMessage, array $history = []): AgentResult
    {
        $tools = $this->toolRegistry->definitions();
        $messages = [ChatMessage::system($this->buildSystemPrompt())];

        if ($this->config->isRagEnabled()) {
            try {
                $hits = $this->ragStore->search($userMessage, $this->config->getRagTopK());
                foreach ($hits as $hit) {
                    $messages[] = ChatMessage::system("[DOC {$hit->source}]\n{$hit->text}");
                }
            } catch (\Throwable) {
            }
        }

        $messages = array_merge($messages, $history, [ChatMessage::user($userMessage)]);

        $client = $this->registry->get();
        $trace = [];

        for ($i = 0; $i < $this->config->getMaxIterations(); $i++) {
            $resp = $client->chat(new LlmRequest($messages, $tools, $this->config->getTemperature()));

            if (!$resp->toolCalls) {
                return new AgentResult($resp->content, $trace, $this->extractLinks($trace));
            }

            $messages[] = ChatMessage::assistantToolCalls($resp);

            foreach ($resp->toolCalls as $call) {
                try {
                    $args = json_decode($call->argsJson, true) ?? [];
                    $out = $this->toolRegistry->execute($call->name, $args);
                } catch (\Throwable $e) {
                    $out = ToolResult::error($e->getMessage());
                }

                $trace[] = [
                    'tool' => $call->name,
                    'args' => $call->argsJson,
                    'result' => $out->summary(),
                ];

                $messages[] = ChatMessage::toolResult($call->id, $out->toJson(), $call->name);
            }
        }

        return new AgentResult(
            'I could not complete the request within the allowed number of steps. Here is what I found so far: ' . ($trace ? json_encode(end($trace)['result']) : 'No results.'),
            $trace,
            $this->extractLinks($trace),
        );
    }

    private function buildSystemPrompt(): string
    {
        $toolNames = implode(', ', $this->toolRegistry->names());

        return <<<PROMPT
You are an AI assistant for OroCommerce administrators. You help with questions about the platform, finding data, and navigating the admin panel.

Available tools: {$toolNames}

Guidelines:
- When asked about entity locations (e.g. "where can I see customer users"), use the entity_url tool to return the admin URL.
- When asked about specific data (e.g. "do I have a user with email X"), use find_entity or sql_query to look it up.
- Use schema_inspector to understand the database structure before writing SQL queries.
- Use doc_search to find information about OroCommerce features and configuration.
- When a SQL query fails, analyze the error and try again with a corrected query.
- Always provide clickable admin URLs when referencing entities.
- Keep answers concise and actionable.
- If you use sql_query, prefer using schema_inspector first to check table/column names.
PROMPT;
    }

    private function extractLinks(array $trace): array
    {
        $links = [];
        foreach ($trace as $entry) {
            if (is_string($entry['result'])) {
                preg_match_all('#/admin/[^\s"\'<>]+#', $entry['result'], $matches);
                foreach ($matches[0] as $url) {
                    $links[] = $url;
                }
            }
        }

        return array_values(array_unique($links));
    }
}
