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

final class OpenAiClient implements LlmClientInterface
{
    private const string DEFAULT_URL = 'https://api.openai.com/v1/chat/completions';
    private const string DEFAULT_MODEL = 'gpt-4o-mini';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OroAiConfig $config,
    ) {}

    public function getName(): string
    {
        return 'openai';
    }

    public function chat(LlmRequest $request): LlmResponse
    {
        $url = $this->config->getApiUrl() ?: self::DEFAULT_URL;
        $model = $this->config->getModel() ?: self::DEFAULT_MODEL;

        $body = [
            'model' => $model,
            'temperature' => $request->temperature,
            'messages' => array_map($this->mapMessage(...), $request->messages),
        ];

        if ($request->tools !== []) {
            $body['tools'] = array_map($this->mapTool(...), $request->tools);
        }

        if ($request->maxTokens !== null) {
            $body['max_tokens'] = $request->maxTokens;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config->getApiKey(),
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
            'timeout' => 60,
        ]);

        $data = $response->toArray();
        $choice = $data['choices'][0];
        $message = $choice['message'];

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = new ToolCall(
                id: $tc['id'],
                name: $tc['function']['name'],
                argsJson: $tc['function']['arguments'],
            );
        }

        return new LlmResponse(
            content: $message['content'] ?? '',
            toolCalls: $toolCalls,
            finishReason: $choice['finish_reason'] ?? null,
            usage: $data['usage'] ?? [],
        );
    }

    private function mapMessage(ChatMessage $msg): array
    {
        return match ($msg->role) {
            Role::System => ['role' => 'system', 'content' => $msg->content],
            Role::User => ['role' => 'user', 'content' => $msg->content],
            Role::Assistant => $msg->toolCalls !== []
                ? [
                    'role' => 'assistant',
                    'content' => $msg->content,
                    'tool_calls' => array_map(
                        static fn(ToolCall $tc): array => [
                            'id' => $tc->id,
                            'type' => 'function',
                            'function' => [
                                'name' => $tc->name,
                                'arguments' => $tc->argsJson,
                            ],
                        ],
                        $msg->toolCalls,
                    ),
                ]
                : ['role' => 'assistant', 'content' => $msg->content],
            Role::Tool => [
                'role' => 'tool',
                'tool_call_id' => $msg->toolCallId,
                'content' => $msg->content,
            ],
        };
    }

    private function mapTool(ToolDefinition $tool): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $tool->parameters,
            ],
        ];
    }
}
