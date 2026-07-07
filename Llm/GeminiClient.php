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

final class GeminiClient implements LlmClientInterface
{
    private const string DEFAULT_MODEL = 'gemini-2.0-flash';
    private const string BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OroAiConfig $config,
    ) {}

    public function getName(): string
    {
        return 'gemini';
    }

    public function chat(LlmRequest $request): LlmResponse
    {
        $model = $this->config->getModel() ?: self::DEFAULT_MODEL;
        $apiKey = $this->config->getApiKey();
        $url = self::BASE_URL . $model . ':generateContent?key=' . $apiKey;

        $systemParts = [];
        $contents = [];

        foreach ($request->messages as $msg) {
            if ($msg->role === Role::System) {
                $systemParts[] = ['text' => $msg->content];
                continue;
            }
            $contents[] = $this->mapMessage($msg);
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $request->temperature,
            ],
        ];

        if ($request->maxTokens !== null) {
            $body['generationConfig']['maxOutputTokens'] = $request->maxTokens;
        }

        if ($systemParts !== []) {
            $body['systemInstruction'] = ['parts' => $systemParts];
        }

        if ($request->tools !== []) {
            $body['tools'] = [
                [
                    'functionDeclarations' => array_map($this->mapTool(...), $request->tools),
                ],
            ];
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => $body,
            'timeout' => 60,
        ]);

        $data = $response->toArray();
        $candidate = $data['candidates'][0];
        $parts = $candidate['content']['parts'] ?? [];

        $content = '';
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $content .= $part['text'];
            } elseif (isset($part['functionCall'])) {
                $fc = $part['functionCall'];
                $toolCalls[] = new ToolCall(
                    id: $this->generateUuid(),
                    name: $fc['name'],
                    argsJson: json_encode($fc['args'] ?? new \stdClass(), JSON_THROW_ON_ERROR),
                );
            }
        }

        return new LlmResponse(
            content: $content,
            toolCalls: $toolCalls,
            finishReason: $candidate['finishReason'] ?? null,
            usage: $data['usageMetadata'] ?? [],
        );
    }

    private function mapMessage(ChatMessage $msg): array
    {
        return match ($msg->role) {
            Role::User => ['role' => 'user', 'parts' => [['text' => $msg->content]]],
            Role::Assistant => $msg->toolCalls !== []
                ? [
                    'role' => 'model',
                    'parts' => array_map(
                        static fn(ToolCall $tc): array => [
                            'functionCall' => [
                                'name' => $tc->name,
                                'args' => json_decode($tc->argsJson, true, 512, JSON_THROW_ON_ERROR),
                            ],
                        ],
                        $msg->toolCalls,
                    ),
                ]
                : ['role' => 'model', 'parts' => [['text' => $msg->content]]],
            Role::Tool => [
                'role' => 'user',
                'parts' => [
                    [
                        'functionResponse' => [
                            'name' => $msg->name,
                            'response' => [
                                'content' => json_decode($msg->content, true) ?? $msg->content,
                            ],
                        ],
                    ],
                ],
            ],
            Role::System => throw new \LogicException('System messages must be extracted before mapping.'),
        };
    }

    private function mapTool(ToolDefinition $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $tool->parameters,
        ];
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        );
    }
}
