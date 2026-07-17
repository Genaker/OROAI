<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Service;

use Genaker\Bundle\OroAI\Service\LlmErrorPresenter;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * These scenarios lived in ChatControllerTest until the error knowledge moved
 * out of the controller into this presenter.
 */
final class LlmErrorPresenterTest extends TestCase
{
    private OroAiConfig&MockObject $config;
    private LlmErrorPresenter $presenter;

    protected function setUp(): void
    {
        $this->config = $this->createMock(OroAiConfig::class);
        $this->presenter = new LlmErrorPresenter($this->config);
    }

    private function makeClientException(int $statusCode, string $body, array $headers = []): ClientException
    {
        $client = new MockHttpClient(new MockResponse($body, ['http_code' => $statusCode, 'response_headers' => $headers]));
        $response = $client->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/test:generateContent');

        return new ClientException($response);
    }

    public function testHumanize403ReturnsFirewallMessageWithProviderHost(): void
    {
        $this->config->method('getProvider')->willReturn('openai');

        $message = $this->presenter->humanize(new \RuntimeException(
            'HTTP/1.1 403 Forbidden returned for "https://api.openai.com/v1/chat/completions".'
        ));

        self::assertStringContainsString('firewall', $message);
        self::assertStringContainsString('api.openai.com', $message);
    }

    public function testHumanize401ReturnsKeyMessage(): void
    {
        $message = $this->presenter->humanize(new \RuntimeException(
            'HTTP/1.1 401 Unauthorized returned for "https://api.openai.com/v1/chat/completions".'
        ));

        self::assertStringContainsString('Invalid API key', $message);
    }

    public function testHumanize429ReturnsRateLimitMessage(): void
    {
        $message = $this->presenter->humanize(new \RuntimeException('HTTP/1.1 429 Too Many Requests'));

        self::assertStringContainsString('rate limit', $message);
    }

    public function testHumanize503ReturnsUnavailableMessage(): void
    {
        $message = $this->presenter->humanize(new \RuntimeException('HTTP/1.1 503 Service Unavailable'));

        self::assertStringContainsString('temporarily unavailable', $message);
    }

    public function testHumanizeNetworkErrorReturnsConnectivityMessage(): void
    {
        $message = $this->presenter->humanize(new \RuntimeException('cURL error 6: Could not resolve host'));

        self::assertStringContainsString('outbound internet access', $message);
    }

    public function testHumanizeUnknownErrorFallsBackToTheRawMessage(): void
    {
        $message = $this->presenter->humanize(new \RuntimeException('LLM API timeout'));

        self::assertStringContainsString('LLM API timeout', $message);
    }

    public function testDetail429IncludesLiveRateLimitHeaders(): void
    {
        $this->config->method('getProvider')->willReturn('openai');

        $detail = $this->presenter->detail($this->makeClientException(
            429,
            '{"error":{"message":"Rate limit reached"}}',
            [
                'x-ratelimit-limit-requests' => '60',
                'x-ratelimit-remaining-requests' => '0',
                'x-ratelimit-reset-requests' => '12s',
            ],
        ));

        self::assertStringContainsString('x-ratelimit-limit-requests: 60', $detail);
        self::assertStringContainsString('x-ratelimit-remaining-requests: 0', $detail);
        self::assertStringContainsString('Rate limit reached', $detail);
    }

    public function testDetail429FallsBackToKnownGeminiLimitsWhenNoHeaders(): void
    {
        $this->config->method('getProvider')->willReturn('gemini');
        $this->config->method('getModel')->willReturn('gemini-2.5-flash');

        $detail = $this->presenter->detail($this->makeClientException(
            429,
            '{"error":{"code":429,"message":"Resource has been exhausted.","status":"RESOURCE_EXHAUSTED"}}'
        ));

        self::assertStringContainsString('gemini-2.5-flash', $detail);
        self::assertStringContainsString('10 requests/min', $detail);
        self::assertStringContainsString('500 requests/day', $detail);
        self::assertStringContainsString('RESOURCE_EXHAUSTED', $detail);
    }

    public function testDetail429OmitsRateLimitInfoForUnknownGeminiModel(): void
    {
        $this->config->method('getProvider')->willReturn('gemini');
        $this->config->method('getModel')->willReturn('gemini-flash-latest');

        $detail = $this->presenter->detail($this->makeClientException(
            429,
            '{"error":{"message":"Resource has been exhausted."}}'
        ));

        self::assertStringNotContainsString('requests/min', $detail);
        self::assertStringContainsString('Resource has been exhausted', $detail);
    }

    public function testDetailIncludesProviderResponseBodyPrettyPrinted(): void
    {
        $this->config->method('getProvider')->willReturn('gemini');

        $detail = $this->presenter->detail($this->makeClientException(
            400,
            '{"error":{"code":400,"message":"Invalid JSON payload received. Unknown name \"foo\".","status":"INVALID_ARGUMENT"}}'
        ));

        self::assertStringContainsString('Unknown name', $detail);
        self::assertStringContainsString('INVALID_ARGUMENT', $detail);
    }

    public function testDetailIsNullForNonHttpExceptions(): void
    {
        self::assertNull($this->presenter->detail(new \RuntimeException('Something unrelated broke.')));
    }
}
