<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Llm;

use Genaker\Bundle\OroAI\Core\Contract\LlmClientInterface;
use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\LlmResponse;
use Genaker\Bundle\OroAI\Core\Model\Role;
use Genaker\Bundle\OroAI\Core\Model\ToolCall;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AnthropicClient implements LlmClientInterface
{
    private const string DEFAULT_URL = 'https://api.anthropic.com/v1/messages';
    private const string DEFAULT_MODEL = 'claude-sonnet-4-20250514';
    private const int DEFAULT_MAX_TOKENS = 4096;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OroAiConfig $config,
    ) {}

    public function getName(): string
    {
        return 'anthropic';
    }

    public function chat(LlmRequest $request): LlmResponse
    {
        $url = $this->config->getApiUrl() ?: self::DEFAULT_URL;
        $model = $this->config->getModel() ?: self::DEFAULT_MODEL;

        $systemParts = [];
        $messages = [];

        foreach ($request->messages as $msg) {
            if ($msg->role === Role::System) {
                $systemParts[] = $msg->content;
                continue;
            }
            $messages[] = $this->mapMessage($msg);
        }

        $body = [
            'model' => $model,
            'max_tokens' => $request->maxTokens ?? self::DEFAULT_MAX_TOKENS,
            'temperature' => $request->temperature,
            'messages' => $messages,
        ];

        if ($systemParts !== []) {
            $body['system'] = implode("\n\n", $systemParts);
        }

        if ($request->tools !== []) {
            $body['tools'] = array_map($this->mapTool(...), $request->tools);
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'x-api-key' => $this->config->getApiKey(),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => $body,
            'timeout' => 60,
        ]);

        $data = $response->toArray();

        $content = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: $block['id'],
                    name: $block['name'],
                    argsJson: json_encode($block['input'], JSON_THROW_ON_ERROR),
                );
            }
        }

        return new LlmResponse(
            content: $content,
            toolCalls: $toolCalls,
            finishReason: $data['stop_reason'] ?? null,
            usage: $data['usage'] ?? [],
        );
    }

    private function mapMessage(ChatMessage $msg): array
    {
        return match ($msg->role) {
            Role::User => ['role' => 'user', 'content' => $msg->content],
            Role::Assistant => $msg->toolCalls !== []
                ? [
                    'role' => 'assistant',
                    'content' => $this->buildAssistantContentBlocks($msg),
                ]
                : ['role' => 'assistant', 'content' => $msg->content],
            Role::Tool => [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => $msg->toolCallId,
                        'content' => $msg->content,
                    ],
                ],
            ],
            Role::System => throw new \LogicException('System messages must be extracted before mapping.'),
        };
    }

    private function buildAssistantContentBlocks(ChatMessage $msg): array
    {
        $blocks = [];

        if ($msg->content !== '') {
            $blocks[] = ['type' => 'text', 'text' => $msg->content];
        }

        foreach ($msg->toolCalls as $tc) {
            $blocks[] = [
                'type' => 'tool_use',
                'id' => $tc->id,
                'name' => $tc->name,
                'input' => json_decode($tc->argsJson, true, 512, JSON_THROW_ON_ERROR),
            ];
        }

        return $blocks;
    }

    private function mapTool(ToolDefinition $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'input_schema' => $tool->parameters,
        ];
    }
}
