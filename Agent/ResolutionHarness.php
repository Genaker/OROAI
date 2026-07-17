<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\LlmResponse;
use Genaker\Bundle\OroAI\Llm\LlmClientRegistry;
use Genaker\Bundle\OroAI\Service\OroAiConfig;

/**
 * Outer harness loop that retries OroAiAgent up to N times when the first
 * reply is incomplete.  On each failed attempt an evaluator LLM call judges
 * whether the answer is resolved, needs more data (harness retries with a
 * richer context hint), or needs customer clarification (returns a question
 * directly to the chat).  Resolved answers are persisted as Markdown files
 * in the configured memory directory so the CacheMemoryRagProvider can index
 * them for future conversations.
 */
final class ResolutionHarness implements HarnessInterface
{
    public function __construct(
        private readonly OroAiAgent $agent,
        private readonly LlmClientRegistry $registry,
        private readonly OroAiConfig $config,
        private readonly string $memoryDir,
    ) {
    }

    private const array ZERO_USAGE = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

    /**
     * @param ChatMessage[] $history
     * @param (callable(array): void)|null $onProgress See OroAiAgent::run() —
     *        forwarded to each attempt's agent run, plus this harness's own
     *        "attempt N of M" / "evaluating" steps.
     */
    public function resolve(string $userMessage, array $history = [], ?callable $onProgress = null): HarnessResult
    {
        $maxTries = $this->config->getHarnessMaxTries();
        $extraContext = '';
        $bestResult = null;
        $usage = self::ZERO_USAGE;

        for ($attempt = 1; $attempt <= $maxTries; $attempt++) {
            if ($onProgress !== null) {
                $onProgress(['type' => 'harness_attempt', 'attempt' => $attempt, 'max' => $maxTries]);
            }

            $enrichedMessage = $extraContext !== ''
                ? $userMessage . "\n\n[Harness context from prior attempt — use this to look deeper]: " . $extraContext
                : $userMessage;

            $agentResult = $this->agent->run($enrichedMessage, $history, $onProgress);
            $bestResult = $agentResult;
            $usage = LlmResponse::sumUsage($usage, $agentResult->usage);

            if ($onProgress !== null) {
                $onProgress(['type' => 'evaluating']);
            }

            $eval = $this->evaluate($userMessage, $agentResult->reply, $agentResult->toolTrace);
            $usage = LlmResponse::sumUsage($usage, $eval['usage']);

            if ($eval['status'] === 'resolved') {
                $saved = $this->saveToMemory($userMessage, $agentResult->reply);

                return new HarnessResult(
                    reply: $agentResult->reply,
                    toolTrace: $agentResult->toolTrace,
                    links: $agentResult->links,
                    resolved: true,
                    memorySaved: $saved,
                    attempt: $attempt,
                    usage: $usage,
                    promptBreakdown: $agentResult->promptBreakdown,
                );
            }

            if ($eval['status'] === 'needs_customer_input') {
                return new HarnessResult(
                    reply: $eval['question'] ?? $agentResult->reply,
                    toolTrace: $agentResult->toolTrace,
                    links: $agentResult->links,
                    resolved: false,
                    needsClarification: true,
                    attempt: $attempt,
                    usage: $usage,
                    promptBreakdown: $agentResult->promptBreakdown,
                );
            }

            // needs_more_data — build a rich context hint for the next attempt:
            // what the evaluator says is missing + which tools were already called
            // so the agent doesn't repeat the same queries.
            $extraContext = $eval['missing'] ?? 'Try alternative tools or broader queries to find the answer.';
            if ($agentResult->toolTrace !== []) {
                $tried = implode(', ', array_column($agentResult->toolTrace, 'tool'));
                $extraContext .= ' (Tools already tried this attempt: ' . $tried . ' — use different tools or parameters.)';
            }
        }

        return new HarnessResult(
            reply: $bestResult->reply,
            toolTrace: $bestResult->toolTrace,
            links: $bestResult->links,
            resolved: false,
            attempt: $maxTries,
            usage: $usage,
            promptBreakdown: $bestResult->promptBreakdown,
        );
    }

    /**
     * Cheap single-turn LLM call that judges whether the reply fully resolved the question.
     * Receives the tool trace so the evaluator can detect "0 rows found" or "tool errored"
     * cases that may not be obvious from the reply text alone.
     *
     * @param array<int, array{tool: string, args: string, result: string}> $toolTrace
     * @return array{status: string, question?: string, missing?: string, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}}
     */
    private function evaluate(string $question, string $reply, array $toolTrace = []): array
    {
        $traceSection = '';
        if ($toolTrace !== []) {
            $lines = [];
            foreach ($toolTrace as $entry) {
                $lines[] = '  - ' . $entry['tool'] . ': ' . mb_substr((string) $entry['result'], 0, 300);
            }
            $traceSection = "\n\nTools called and their output:\n" . implode("\n", $lines);
        }

        $prompt = <<<PROMPT
You are a strict evaluator. A user asked a question and an AI assistant replied. Judge the reply.

User question: {$question}

AI reply: {$reply}{$traceSection}

Output ONLY valid JSON — no markdown, no prose — in exactly one of these three forms:
{"status":"resolved"}
{"status":"needs_customer_input","question":"<one concise clarifying question to ask the customer>"}
{"status":"needs_more_data","missing":"<brief note on what data or tool output is still needed>"}

Rules:
- "resolved" if the reply directly and completely answers the question with concrete data or a clear action.
- "needs_customer_input" only if the question is ambiguous and cannot be answered without more
  details from the customer (e.g. missing order number, email address, date range).
- "needs_more_data" if the AI gave a generic or partial answer, tool results show errors or 0 rows,
  or the agent could do better by querying additional tools or using different parameters.
PROMPT;

        try {
            $client = $this->registry->get();
            $resp = $client->chat(new LlmRequest(
                messages: [ChatMessage::user($prompt)],
                tools: [],
                temperature: 0.0,
            ));

            $json = self::extractJson($resp->content);
            if (is_array($json) && isset($json['status'])) {
                $json['usage'] = $resp->usage;

                return $json;
            }

            // Evaluator replied but not in the requested JSON shape — treat as
            // inconclusive rather than silently trusting the agent's answer,
            // or the harness would never retry a genuinely bad first attempt.
            return [
                'status' => 'needs_more_data',
                'missing' => 'The evaluator response could not be parsed; try a different approach.',
                'usage' => $resp->usage,
            ];
        } catch (\Throwable) {
            // The evaluator call itself failed (network/API error) — likewise
            // inconclusive, not an automatic pass.
            return [
                'status' => 'needs_more_data',
                'missing' => 'The evaluator call failed; try a different approach.',
                'usage' => self::ZERO_USAGE,
            ];
        }
    }

    /**
     * Parses the evaluator's JSON reply, tolerating the two shapes models
     * commonly produce despite being told "JSON only, no markdown": a
     * ```json fenced block, or a bare object with leading/trailing prose.
     */
    private static function extractJson(string $content): ?array
    {
        $content = trim($content);

        $direct = json_decode($content, true);
        if (is_array($direct)) {
            return $direct;
        }

        $unfenced = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
        $fenced = json_decode(trim($unfenced), true);
        if (is_array($fenced)) {
            return $fenced;
        }

        if (preg_match('/\{.*\}/s', $content, $matches) === 1) {
            $extracted = json_decode($matches[0], true);
            if (is_array($extracted)) {
                return $extracted;
            }
        }

        return null;
    }

    private function saveToMemory(string $question, string $answer): bool
    {
        try {
            if (!is_dir($this->memoryDir)) {
                mkdir($this->memoryDir, 0755, true);
            }

            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(substr($question, 0, 50)));
            $slug = trim($slug, '-');
            $filename = $this->memoryDir . '/' . date('Y-m-d_H-i-s') . '_' . $slug . '.md';

            $content = "# Q: {$question}\n\n{$answer}\n";
            file_put_contents($filename, $content);

            return true;
        } catch (\Throwable) {
            // intentional — memory save is best-effort
            return false;
        }
    }
}
