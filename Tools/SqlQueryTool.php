<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Doctrine\DBAL\Connection;
use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Genaker\Bundle\OroAI\Service\OroAiConfig;

final class SqlQueryTool implements AiToolInterface
{
    private const FORBIDDEN_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
        'CREATE', 'GRANT', 'REVOKE', 'COPY', 'CALL', 'DO', 'MERGE',
        'REPLACE', 'LOCK', 'VACUUM', 'REINDEX', 'CLUSTER', 'REFRESH',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly OroAiConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'sql_query';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'sql_query',
            'Execute a read-only SQL SELECT query against the OroCommerce database. Returns rows as JSON. Only SELECT statements are allowed.',
            [
                'type' => 'object',
                'properties' => [
                    'sql' => [
                        'type' => 'string',
                        'description' => 'A SQL SELECT query to execute. Only SELECT/WITH statements are allowed. A LIMIT will be auto-appended if missing.',
                    ],
                ],
                'required' => ['sql'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        if (!$this->config->isSqlToolEnabled()) {
            return ToolResult::error('SQL tool is disabled in system configuration.');
        }

        $sql = trim($arguments['sql'] ?? '');
        if ($sql === '') {
            return ToolResult::error('SQL query is empty.');
        }

        try {
            $this->assertReadOnly($sql);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }

        $sql = $this->ensureLimit($sql);

        try {
            $this->connection->executeStatement('START TRANSACTION READ ONLY');
            try {
                $rows = $this->connection->executeQuery($sql)->fetchAllAssociative();
            } finally {
                $this->connection->executeStatement('ROLLBACK');
            }

            return ToolResult::success([
                'row_count' => count($rows),
                'rows' => $rows,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('SQL error: ' . $e->getMessage());
        }
    }

    public function assertReadOnly(string $sql): void
    {
        $normalized = preg_replace('/\s+/', ' ', $sql);

        if (str_contains($normalized, ';') && trim($normalized, '; ') !== rtrim(trim($normalized), ';')) {
            $parts = array_filter(array_map('trim', explode(';', $normalized)));
            if (count($parts) > 1) {
                throw new \InvalidArgumentException('Multi-statement queries are not allowed.');
            }
        }

        $stripped = preg_replace('/--[^\n]*/', '', $normalized);
        $stripped = preg_replace('/\/\*.*?\*\//', '', $stripped);
        $stripped = trim($stripped, "; \t\n\r");

        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $stripped)) {
            throw new \InvalidArgumentException('Only SELECT or WITH (CTE) queries are allowed.');
        }

        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $stripped)) {
                throw new \InvalidArgumentException(sprintf('Forbidden keyword "%s" detected in query.', $keyword));
            }
        }
    }

    private function ensureLimit(string $sql): string
    {
        $limit = $this->config->getSqlRowLimit();
        if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
            $sql = rtrim($sql, '; ') . ' LIMIT ' . $limit;
        }

        return $sql;
    }
}
