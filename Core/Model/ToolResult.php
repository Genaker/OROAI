<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

/** Wraps the outcome of a tool execution: success data or an error message. */
final readonly class ToolResult
{
    private const int SUMMARY_MAX_LENGTH = 500;

    public function __construct(
        public bool $success,
        public mixed $data,
        public ?string $errorMessage = null,
    ) {
    }

    public static function success(mixed $data): self
    {
        return new self(success: true, data: $data);
    }

    public static function error(string $message): self
    {
        return new self(success: false, data: null, errorMessage: $message);
    }

    public function summary(): string
    {
        if (!$this->success) {
            return 'Error: ' . ($this->errorMessage ?? 'Unknown error');
        }

        $text = $this->toJson();

        if (mb_strlen($text) <= self::SUMMARY_MAX_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::SUMMARY_MAX_LENGTH) . '...';
    }

    public function toJson(): string
    {
        return json_encode(
            ['success' => $this->success, 'data' => $this->data, 'error' => $this->errorMessage],
            // UNESCAPED_SLASHES: admin URLs in tool results ("/admin/...") must stay
            // literal — OroAiAgent::extractLinks() regexes them out of this JSON, and
            // escaped "\/admin\/..." never matches (links were always empty before).
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
