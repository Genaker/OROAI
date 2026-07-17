<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag;

/** RAG store backed by Redis with RediSearch vector index and a brute-force cosine fallback. */
final class RediSearchRagStore implements RagStoreInterface
{
    private const string INDEX_NAME = 'oroai_rag';
    private const string PREFIX     = 'oroai:rag:';

    private bool $indexChecked    = false;
    /** null = unknown, true = available, false = unavailable */
    private ?bool $redisearchAvailable = null;

    public function __construct(
        private readonly object $redis,
        private readonly EmbeddingClientInterface $embeddingClient,
    ) {
    }

    public function index(array $documents): void
    {
        if ($documents === []) {
            return;
        }

        if ($this->hasRediSearch()) {
            $this->createIndexIfNeeded();
        }

        $texts      = array_map(static fn (RagDocument $doc): string => $doc->text, $documents);
        $embeddings = $this->embeddingClient->embedBatch($texts);

        foreach ($documents as $i => $document) {
            $key = self::PREFIX . $document->id;
            $this->redis->executeRaw([
                'HSET', $key,
                'text',      $document->text,
                'source',    $document->source,
                'embedding', $this->packEmbedding($embeddings[$i]),
            ]);
        }
    }

    public function search(string $query, int $topK = 5): array
    {
        $embedding = $this->embeddingClient->embed($query);

        if ($this->hasRediSearch()) {
            $this->createIndexIfNeeded();
            return $this->redisearchSearch($embedding, $topK);
        }

        // Fallback: brute-force cosine similarity over all stored documents.
        // Perfectly fine for dev/small indexes (<= a few hundred docs).
        return $this->bruteForceSearch($embedding, $topK);
    }

    public function clear(): void
    {
        if ($this->hasRediSearch()) {
            try {
                $this->redis->executeRaw(['FT.DROPINDEX', self::INDEX_NAME, 'DD']);
            } catch (\Throwable) {
                // intentional
            }
            $this->indexChecked = false;
            $this->createIndexIfNeeded();
            return;
        }

        // Without RediSearch just delete all RAG keys
        $keys = $this->redis->keys(self::PREFIX . '*');
        foreach ($keys as $key) {
            $this->redis->executeRaw(['DEL', $key]);
        }
    }

    // ── RediSearch vector search ──────────────────────────────────────────────

    private function redisearchSearch(array $embedding, int $topK): array
    {
        $blob   = $this->packEmbedding($embedding);
        $result = $this->redis->executeRaw([
            'FT.SEARCH', self::INDEX_NAME,
            '*=>[KNN ' . $topK . ' @embedding $vec AS score]',
            'PARAMS', '2', 'vec', $blob,
            'SORTBY', 'score', 'ASC',
            'LIMIT', '0', (string) $topK,
            'DIALECT', '2',
        ]);

        return $this->parseSearchResult($result);
    }

    private function createIndexIfNeeded(): void
    {
        if ($this->indexChecked) {
            return;
        }

        try {
            $result = $this->redis->executeRaw(['FT.INFO', self::INDEX_NAME]);
            if (is_array($result)) {
                // Index exists and is healthy
                $this->indexChecked = true;
                return;
            }
            // Non-array = error response ("Unknown index name") → fall through to create
        } catch (\Throwable) {
            // Connection or server exception → fall through to create
        }

        $dim = (string) $this->embeddingClient->getDimension();
        $this->redis->executeRaw([
            'FT.CREATE', self::INDEX_NAME,
            'ON', 'HASH',
            'PREFIX', '1', self::PREFIX,
            'SCHEMA',
            'text', 'TEXT',
            'source', 'TAG',
            'embedding', 'VECTOR', 'HNSW', '6',
            'TYPE', 'FLOAT32',
            'DIM', $dim,
            'DISTANCE_METRIC', 'COSINE',
        ]);

        $this->indexChecked = true;
    }

    // ── Brute-force fallback (no RediSearch module) ───────────────────────────

    private function bruteForceSearch(array $queryEmbedding, int $topK): array
    {
        $keys = $this->redis->keys(self::PREFIX . '*');
        if ($keys === []) {
            return [];
        }

        $scored = [];
        foreach ($keys as $key) {
            $doc = $this->redis->hgetall($key);
            if (empty($doc['embedding']) || empty($doc['text'])) {
                continue;
            }
            $docEmbedding = $this->unpackEmbedding($doc['embedding']);
            if ($docEmbedding === []) {
                continue;
            }
            $distance = 1.0 - $this->cosineSimilarity($queryEmbedding, $docEmbedding);
            $scored[]  = new RagHit(
                text:   $doc['text'],
                source: $doc['source'] ?? '',
                score:  $distance,   // lower = more similar (matches RediSearch COSINE distance)
            );
        }

        usort($scored, static fn (RagHit $a, RagHit $b): int => $a->score <=> $b->score);

        return array_slice($scored, 0, $topK);
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot  = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        $len  = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $dot  += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }

        $denom = sqrt($magA) * sqrt($magB);

        return $denom > 0.0 ? $dot / $denom : 0.0;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function hasRediSearch(): bool
    {
        if ($this->redisearchAvailable !== null) {
            return $this->redisearchAvailable;
        }

        try {
            $result   = $this->redis->executeRaw(['FT.INFO', self::INDEX_NAME]);
            $errorMsg = is_array($result) ? '' : (string) $result;
        } catch (\Throwable $exception) {
            $errorMsg = $exception->getMessage();
        }

        // "ERR unknown command 'FT.INFO'" means no RediSearch module.
        // "Unknown Index name" means module IS present but index not yet created.
        $this->redisearchAvailable = !str_contains($errorMsg, 'unknown command');

        return $this->redisearchAvailable;
    }

    private function packEmbedding(array $floats): string
    {
        return pack('f*', ...$floats);
    }

    private function unpackEmbedding(string $blob): array
    {
        if ($blob === '') {
            return [];
        }
        $floats = unpack('f*', $blob);
        return $floats !== false ? array_values($floats) : [];
    }

    private function parseSearchResult(mixed $result): array
    {
        if (!is_array($result) || count($result) < 2) {
            return [];
        }

        $hits  = [];
        $count = (int) $result[0];
        $i     = 1;

        for ($n = 0; $n < $count && isset($result[$i]); $n++) {
            $i++;  // skip document key
            $fields = $result[$i] ?? [];
            $i++;

            $map = [];
            for ($f = 0; isset($fields[$f], $fields[$f + 1]); $f += 2) {
                $map[$fields[$f]] = $fields[$f + 1];
            }

            $hits[] = new RagHit(
                text:   $map['text'] ?? '',
                source: $map['source'] ?? '',
                score:  (float) ($map['score'] ?? 1.0),
            );
        }

        return $hits;
    }
}
