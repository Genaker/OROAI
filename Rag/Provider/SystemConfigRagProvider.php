<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag\Provider;

use Doctrine\DBAL\Connection;
use Genaker\Bundle\OroAI\Rag\Contract\RagProviderInterface;
use Genaker\Bundle\OroAI\Rag\RagDocument;
use Genaker\Bundle\OroAI\Rag\TextChunker;

/** Provides RAG documents from the OroCommerce global system configuration table. */
final class SystemConfigRagProvider implements RagProviderInterface
{
    private const array SENSITIVE_PATTERNS = ['password', 'secret', 'api_key', 'token', 'sk-', 'private'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'config';
    }

    public function getDescription(): string
    {
        return 'OroCommerce global system configuration values from the database';
    }

    public function provide(): array
    {
        // oro_config_value stores values in typed columns:
        // text_value (scalar), array_value (array), object_value (object)
        $rows = $this->connection->createQueryBuilder()
            ->select('cv.name', 'cv.section', 'cv.type', 'cv.text_value', 'cv.array_value')
            ->from('oro_config_value', 'cv')
            ->join('cv', 'oro_config', 'c', 'c.id = cv.config_id')
            ->where("c.entity = 'app'")
            ->orderBy('cv.section')
            ->addOrderBy('cv.name')
            ->execute()
            ->fetchAllAssociative();

        $grouped = [];

        foreach ($rows as $row) {
            $section = $row['section'] ?? 'general';
            $name = $row['name'];
            $fullKey = $section . '.' . $name;
            $raw = $row['type'] === 'array' ? $row['array_value'] : $row['text_value'];
            $value = $this->decodeValue($raw, $fullKey);

            if ($value === null) {
                continue;
            }

            $grouped[$section][] = sprintf(
                "Config key: %s\nValue: %s",
                $fullKey,
                $value,
            );
        }

        ksort($grouped);

        $documents = [];

        foreach ($grouped as $section => $lines) {
            $block = "System configuration section: $section\n\n" . implode("\n\n", $lines);

            foreach (TextChunker::chunk($block, 800) as $chunkIndex => $chunk) {
                $documents[] = new RagDocument(
                    id: md5('config:' . $section . ':' . $chunkIndex),
                    text: $chunk,
                    source: 'system_config:' . $section,
                );
            }
        }

        return $documents;
    }

    private function decodeValue(mixed $raw, string $key): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $keyLower = strtolower($key);
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (str_contains($keyLower, $pattern)) {
                return '***REDACTED***';
            }
        }

        // array_value may be a JSON-encoded array
        if (is_string($raw) && str_starts_with($raw, '[') || str_starts_with((string) $raw, '{')) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return (string) $raw;
    }
}
