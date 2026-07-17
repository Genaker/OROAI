<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Service;

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Turns raw LLM/HTTP failures into something an administrator can act on:
 * a short human sentence (humanize) plus an expandable technical detail
 * (detail) with the provider's own error JSON and whatever quota information
 * is available. Extracted from ChatController so provider-specific error
 * knowledge lives beside the other provider services, not in the HTTP layer.
 *
 * Not final: ChatController's unit tests mock it.
 */
class LlmErrorPresenter
{
    /**
     * Header names providers actually send their live quota state on —
     * checked case-insensitively via Symfony's already-lowercased header
     * array. OpenAI and Anthropic send these on every response (not just
     * 429s); Gemini currently sends none, hence the static fallback table
     * in geminiKnownLimits().
     */
    private const array RATE_LIMIT_HEADERS = [
        'retry-after',
        'x-ratelimit-limit-requests',
        'x-ratelimit-remaining-requests',
        'x-ratelimit-reset-requests',
        'x-ratelimit-limit-tokens',
        'x-ratelimit-remaining-tokens',
        'x-ratelimit-reset-tokens',
        'anthropic-ratelimit-requests-limit',
        'anthropic-ratelimit-requests-remaining',
        'anthropic-ratelimit-requests-reset',
        'anthropic-ratelimit-tokens-limit',
        'anthropic-ratelimit-tokens-remaining',
        'anthropic-ratelimit-tokens-reset',
    ];

    public function __construct(
        private readonly OroAiConfig $config,
    ) {
    }

    public function humanize(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, '403 Forbidden')) {
            $provider = $this->config->getProvider();
            $host = match ($provider) {
                'anthropic' => 'api.anthropic.com',
                'gemini'    => 'generativelanguage.googleapis.com',
                default     => 'api.openai.com',
            };

            return sprintf(
                'The AI service (%s) is blocked by a network firewall or corporate proxy (Zscaler). '
                . 'Ask your IT administrator to allow outbound HTTPS access to %s.',
                $provider,
                $host,
            );
        }

        if (str_contains($msg, '401 Unauthorized')) {
            return 'Invalid API key. '
                . 'Please check the key in System → Configuration → General Setup → Oro AI Assistant.';
        }

        if (str_contains($msg, '429')) {
            return 'API rate limit exceeded. Please wait a moment and try again.';
        }

        if (str_contains($msg, '500') || str_contains($msg, '502') || str_contains($msg, '503')) {
            return 'The AI provider is temporarily unavailable. Please try again in a few minutes.';
        }

        if (str_contains($msg, 'cURL error')
            || str_contains($msg, 'Connection refused')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'Could not resolve host')
        ) {
            return 'Cannot connect to the AI service. Check that the server has outbound internet access.';
        }

        return 'An error occurred: ' . $msg;
    }

    /**
     * Pulls the raw response body out of an HTTP client exception — the LLM
     * provider's own error JSON (e.g. Gemini's precise "why this request was
     * rejected" message) usually explains a 4xx far better than the generic
     * "HTTP/1.1 400 Bad Request returned for ..." exception message alone.
     * On a 429, this is prefixed with whatever quota information is
     * available (see buildRateLimitInfo()) so the customer can see not just
     * that they were rate-limited, but what the actual limit is.
     * Returned separately from humanize() so the UI can show it as
     * expandable detail rather than dumping it into the main reply.
     */
    public function detail(\Throwable $e): ?string
    {
        if (!$e instanceof HttpExceptionInterface) {
            return null;
        }

        try {
            $body = $e->getResponse()->getContent(false);
        } catch (\Throwable) {
            return null;
        }

        $prettyBody = trim($body);
        if ($prettyBody !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($pretty !== false) {
                    $prettyBody = $pretty;
                }
            }
        }

        $rateLimitInfo = $this->buildRateLimitInfo($e);

        return match (true) {
            $rateLimitInfo !== null && $prettyBody !== '' => $rateLimitInfo . "\n\n" . $prettyBody,
            $rateLimitInfo !== null => $rateLimitInfo,
            $prettyBody !== '' => $prettyBody,
            default => null,
        };
    }

    /**
     * On a 429, reports the actual quota: live rate-limit headers when the
     * provider sends them (OpenAI, Anthropic), or the known free-tier table
     * for the configured Gemini model when it doesn't (Gemini sends none).
     */
    private function buildRateLimitInfo(HttpExceptionInterface $e): ?string
    {
        if ($e->getResponse()->getStatusCode() !== 429) {
            return null;
        }

        $headers = $e->getResponse()->getHeaders(false);
        $found = [];
        foreach (self::RATE_LIMIT_HEADERS as $name) {
            if (isset($headers[$name][0])) {
                $found[] = $name . ': ' . $headers[$name][0];
            }
        }

        if ($found !== []) {
            return "Rate limit (from {$this->config->getProvider()}):\n" . implode("\n", $found);
        }

        $known = $this->geminiKnownLimits();
        if ($known !== null) {
            return "Known free-tier limit for {$known['model']}: "
                . "{$known['rpm']} requests/min, {$known['rpd']} requests/day. "
                . 'Upgrade to a paid key or switch models to raise this — '
                . 'Gemini does not report live quota via response headers.';
        }

        return null;
    }

    /**
     * @return array{model: string, rpm: int, rpd: int}|null
     */
    private function geminiKnownLimits(): ?array
    {
        if ($this->config->getProvider() !== 'gemini') {
            return null;
        }

        $model = $this->config->getModel() ?: 'gemini-2.5-flash';

        // Mirrors the "Rate limits (Gemini free tier)" table in README.md —
        // update both together.
        $limits = [
            'gemini-2.0-flash' => ['rpm' => 15, 'rpd' => 1_500],
            'gemini-2.5-flash' => ['rpm' => 10, 'rpd' => 500],
            'gemini-2.5-pro'   => ['rpm' => 5, 'rpd' => 25],
        ];

        if (!isset($limits[$model])) {
            return null;
        }

        return ['model' => $model, ...$limits[$model]];
    }
}
