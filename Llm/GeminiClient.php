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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** LLM client that calls the Google Gemini generateContent API with tool-use support. */
final class GeminiClient implements LlmClientInterface
{
    private const string DEFAULT_MODEL = 'gemini-2.5-flash';
    private const string BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * Models to switch to when the active model still returns 429 after all
     * retries — a persistent 429 usually means the model's quota limit is 0
     * (e.g. Google withdrew gemini-2.0-flash's free tier), so retrying the
     * same model can never succeed. Overridable via OROAI_FALLBACK_MODELS.
     */
    private const array DEFAULT_FALLBACK_MODELS = ['gemini-2.5-flash', 'gemini-flash-latest'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OroAiConfig $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function chat(LlmRequest $request): LlmResponse
    {
        $apiKey = $this->config->getApiKey();

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

        // Try each candidate model in turn: a model that still returns 429 after
        // all retries is switched out for the next one (persistent 429 = quota
        // limit 0, not a transient burst — waiting longer can never help).
        $models = $this->buildModelCandidates();
        $response = null;
        foreach ($models as $index => $model) {
            $url = self::BASE_URL . $model . ':generateContent?key=' . $apiKey;
            $response = $this->requestWithRetry($url, $body);
            if ($response->getStatusCode() !== 429) {
                break;
            }
            if (isset($models[$index + 1])) {
                $this->logger->warning('Gemini model rate/quota limited (429) — switching model', [
                    'model' => $model,
                    'next' => $models[$index + 1],
                ]);
            }
        }

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
                    thoughtSignature: $part['thoughtSignature'] ?? null,
                );
            }
        }

        return new LlmResponse(
            content: $content,
            toolCalls: $toolCalls,
            finishReason: $candidate['finishReason'] ?? null,
            usage: LlmResponse::normalizeUsage(
                $data['usageMetadata'] ?? [],
                'promptTokenCount',
                'candidatesTokenCount',
                'totalTokenCount',
                'thoughtsTokenCount',
            ),
        );
    }

    private function mapMessage(ChatMessage $msg): array
    {
        return match ($msg->role) {
            Role::User => ['role' => 'user', 'parts' => [['text' => $msg->content]]],
            Role::Assistant => $msg->toolCalls !== []
                ? [
                    'role' => 'model',
                    'parts' => array_map($this->mapToolCallToPart(...), $msg->toolCalls),
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

    /**
     * Echoes back thoughtSignature (if the model set one when it originally
     * returned this call) so Gemini's "thinking" models accept the replayed
     * history — see ToolCall::$thoughtSignature.
     */
    private function mapToolCallToPart(ToolCall $tc): array
    {
        $part = [
            'functionCall' => [
                'name' => $tc->name,
                'args' => json_decode($tc->argsJson, true, 512, JSON_THROW_ON_ERROR),
            ],
        ];

        if ($tc->thoughtSignature !== null) {
            $part['thoughtSignature'] = $tc->thoughtSignature;
        }

        return $part;
    }

    private function mapTool(ToolDefinition $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $tool->parameters,
        ];
    }

    /**
     * The configured model followed by the fallback chain
     * (OROAI_FALLBACK_MODELS, or the class defaults), deduplicated.
     *
     * @return list<string>
     */
    private function buildModelCandidates(): array
    {
        $primary = $this->config->getModel() ?: self::DEFAULT_MODEL;
        $fallbacks = $this->config->getFallbackModels() ?: self::DEFAULT_FALLBACK_MODELS;

        return array_values(array_unique([$primary, ...$fallbacks]));
    }

    /** POST with the exponential-backoff retry loop for transient 429s. */
    private function requestWithRetry(string $url, array $body): ResponseInterface
    {
        $retryDelays = $this->buildRetryDelays($this->config->getMaxRetries());
        $response = null;
        foreach ([null, ...$retryDelays] as $delay) {
            if ($delay !== null) {
                usleep($delay);
            }
            $response = $this->httpClient->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $body,
                'timeout' => 60,
            ]);
            if ($response->getStatusCode() !== 429) {
                break;
            }
        }

        return $response;
    }

    /** @return int[] microsecond delays: 500ms, 1s, 2s, 4s, 8s … up to $count entries */
    private function buildRetryDelays(int $count): array
    {
        $delays = [];
        for ($i = 0; $i < $count; $i++) {
            $delays[] = 500_000 * (2 ** $i);
        }

        return $delays;
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
