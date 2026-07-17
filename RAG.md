# OroAI RAG — Technical Reference

Retrieval-Augmented Generation (RAG) extends the AI assistant with knowledge specific to your OroCommerce installation. When a user asks a question, the system retrieves the most semantically relevant documents from the knowledge base and injects them into the LLM prompt as grounding context — answers are based on real data, not model weights alone.

---

## Table of Contents

- [Architecture](#architecture)
- [Embeddings](#embeddings)
- [Cosine Similarity](#cosine-similarity)
- [Components](#components)
  - [RediSearchRagStore](#redisearchragstore)
  - [Embedding Clients](#embedding-clients)
  - [RAG Providers](#rag-providers)
  - [TextChunker](#textchunker)
- [Tuning the Search](#tuning-the-search)
- [CLI Commands](#cli-commands)
- [Docker Setup](#docker-setup)
- [Unit Tests](#unit-tests)
- [Integration Tests](#integration-tests)

---

## Architecture

### Indexing pipeline

```
RagProvider  →  EmbeddingClient  →  Redis HSET  →  HNSW index
(documents)     (float32[3072])     (binary)        (FT.CREATE)
```

### Query pipeline

```
User query  →  embed(query)  →  FT.SEARCH KNN  →  RagHit[]
```

Every document and every query is embedded with the **same model**. The index stores raw float32 binary blobs; RediSearch's HNSW index enables approximate nearest-neighbour search in milliseconds.

---

## Embeddings

An embedding is a dense vector of floating-point numbers encoding the *meaning* of text. Texts with similar meaning produce vectors that point in nearly the same direction, regardless of word choice.

### Supported models

| Provider | Model | Dimensions | Auth | Batch limit |
|----------|-------|-----------|------|-------------|
| Gemini | `gemini-embedding-001` | 3 072 | `?key=` query param | 100 per call |
| OpenAI | `text-embedding-3-small` | 1 536 | `Authorization: Bearer` | 2 048 per call |

> **Critical:** every document must be embedded with the same model used at query time. Switching models requires a full `--clear` reindex. Mixing Gemini 3 072-dim and OpenAI 1 536-dim vectors in the same index produces nonsense scores.

### Storage format

Each document is a Redis Hash stored under `oroai:rag:<id>`:

```
HSET oroai:rag:a3f2b1c8
  text      "System config section: oro_ui …"
  source    "system_config:oro_ui"
  embedding <binary: 12 288 bytes>
```

The `embedding` field is a raw binary blob — each dimension packed as a 4-byte little-endian IEEE 754 float32 via PHP's `pack('f*', ...$floats)`. At 3 072 dimensions that is exactly 12 288 bytes per document.

---

## Cosine Similarity

The similarity between a query vector **q** and a document vector **d** is the cosine of the angle between them:

```
                  q · d           Σ qᵢdᵢ
similarity(q,d) = ─────── = ───────────────────
                  ‖q‖·‖d‖   √(Σqᵢ²) · √(Σdᵢ²)
```

Range: −1 (opposite) → 0 (orthogonal) → +1 (identical). Embedding models normalise output to the unit sphere, so the effective range is 0 → 1.

### Score convention

RediSearch stores and returns **cosine distance** (not similarity): `distance = 1 − similarity`. Results are sorted ascending by distance — the best match has the **lowest** score. `RagHit::$score` follows this convention in both the RediSearch backend and the PHP brute-force fallback.

| Distance (score) | Similarity | Meaning |
|-----------------|-----------|---------|
| 0.00 – 0.15 | 85 – 100 % | Excellent — near-identical meaning |
| 0.15 – 0.30 | 70 – 85 % | Good — closely related topic |
| 0.30 – 0.45 | 55 – 70 % | Fair — same domain, different focus |
| 0.45 + | < 55 % | Weak — probably not useful context |

Converting in the CLI test command: `similarity = 1.0 - $hit->score`.

### PHP brute-force implementation

Used as a fallback when RediSearch is not available:

```php
private function cosineSimilarity(array $a, array $b): float
{
    $dot = $magA = $magB = 0.0;
    $len = min(count($a), count($b));

    for ($i = 0; $i < $len; $i++) {
        $dot  += $a[$i] * $b[$i];
        $magA += $a[$i] * $a[$i];
        $magB += $b[$i] * $b[$i];
    }

    $denom = sqrt($magA) * sqrt($magB);
    return $denom > 0.0 ? $dot / $denom : 0.0;
}
```

---

## Components

### RediSearchRagStore

`Genaker\Bundle\OroAI\Rag\RediSearchRagStore`

The primary persistence and search layer. Implements `RagStoreInterface` with two backends selected automatically at runtime:

| `FT.INFO` response | Backend used |
|--------------------|-------------|
| Returns an array | RediSearch HNSW vector search via `FT.SEARCH KNN` |
| Returns `"Unknown index name"` | RediSearch present — index auto-created, then HNSW search |
| Returns `"ERR unknown command"` | No RediSearch module — PHP brute-force cosine fallback |

> **DB 0 only:** RediSearch can only index hashes in database 0. The `redis_search` container is a dedicated Redis Stack instance, so using DB 0 causes no collision with OroCommerce's cache/session data (which live on the separate `redis` container).

```php
interface RagStoreInterface
{
    /** @param RagDocument[] $documents */
    public function index(array $documents): void;

    /** @return RagHit[] sorted by score ASC (best first) */
    public function search(string $query, int $topK = 5): array;

    public function clear(): void;
}
```

### Embedding Clients

**`ProviderAwareEmbeddingClient`** — the service container binds `EmbeddingClientInterface` to this router. It reads `OROAI_PROVIDER` at call time and delegates to `GeminiEmbeddingClient` or `OpenAiEmbeddingClient`.

**`GeminiEmbeddingClient`**

| Constant | Value |
|----------|-------|
| `DEFAULT_MODEL` | `gemini-embedding-001` |
| `DIMENSION` | 3 072 |
| `BATCH_LIMIT` | 100 — Gemini hard cap per `batchEmbedContents` call. Larger batches are chunked with a 1 s inter-chunk pause. |
| `MAX_CHARS` | 12 000 — ~2 048 token safety limit. Longer texts are silently truncated before embedding. |

Rate-limit handling: `embedChunk()` retries on HTTP 429 with exponential backoff — 5 s → 10 s → 20 s — up to 3 attempts.

**`OpenAiEmbeddingClient`** — sends a single `POST /v1/embeddings` with the full batch as a JSON array. Returns 1 536-dim vectors. Uses `Authorization: Bearer` header (different from Gemini's `?key=` query param).

### RAG Providers

Providers are tagged services (`genaker_oroai.rag_provider`) that produce `RagDocument[]`. Add a new provider to extend the knowledge base without touching any existing code.

| Name | Source | Documents produced |
|------|--------|-------------------|
| `docs` | `Resources/rag/*.md` | One chunk per ~500-char paragraph |
| `config` | `oro_config_value` DB table | One doc per config section (grouped by prefix) |
| `schema` | `information_schema.columns` | One doc per DB table (column list) |
| `menu` | Symfony Router | One doc per admin route (name + path) |

#### Adding a custom provider

```php
final class ProductRagProvider implements RagProviderInterface
{
    public function getName(): string { return 'products'; }

    public function getDescription(): string
    {
        return 'Product names and SKUs from the catalog';
    }

    public function provide(): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT sku, name FROM oro_product LIMIT 500'
        )->fetchAllAssociative();

        return array_map(
            static fn($r) => new RagDocument(
                id:     md5('product:' . $r['sku']),
                text:   "SKU: {$r['sku']}\nName: {$r['name']}",
                source: 'product:' . $r['sku'],
            ),
            $rows,
        );
    }
}
```

Register in `services.yml`:

```yaml
Acme\Rag\Provider\ProductRagProvider:
  arguments:
    $connection: '@doctrine.dbal.default_connection'
  tags: ['genaker_oroai.rag_provider']
```

### TextChunker

`Genaker\Bundle\OroAI\Rag\TextChunker`

Splits a Markdown document into paragraph-bounded chunks. It merges consecutive short paragraphs until the next paragraph would exceed `$maxLength` characters (default 500).

Boundaries are always at paragraph breaks (`\n\n`). A single paragraph exceeding `$maxLength` is never split mid-sentence — it becomes its own oversized chunk. Adjust `GeminiEmbeddingClient::MAX_CHARS` if very long single paragraphs need truncation before embedding.

```php
// Default: 500-char chunks
$chunks = TextChunker::chunk($content);

// Custom max length
$chunks = TextChunker::chunk($content, maxLength: 800);
```

---

## Tuning the Search

### top-K

The `$topK` parameter controls how many candidate documents are returned and injected into the LLM prompt.

| Use case | Recommended top-K |
|----------|-------------------|
| Single-fact lookup (e.g. "what is the application URL?") | 3 |
| General Q&A over config / schema | 5 (default) |
| Complex multi-step reasoning | 8–10 |

### Similarity threshold filtering

Neither the HNSW search nor the brute-force fallback applies a threshold — they always return exactly `$topK` hits. To filter out weak matches, post-filter on the returned hits:

```php
$hits = $this->ragStore->search($query, topK: 10);

// Drop anything below 60 % similarity (distance > 0.40)
$hits = array_filter($hits, fn($h) => $h->score <= 0.40);

// Hard-cap at 5 for the prompt
$hits = array_slice($hits, 0, 5);
```

### HNSW index parameters

Set when the index is first created in `createIndexIfNeeded()`. To change them, drop and recreate with `--clear`.

| Parameter | Current value | Notes |
|-----------|--------------|-------|
| `TYPE FLOAT32` | 4 bytes/dim | Use `FLOAT64` for max precision (doubles storage) |
| `DIM 3072` | Gemini dimension | Must match embedding model. OpenAI: 1536 |
| `DISTANCE_METRIC COSINE` | Normalises vectors | Alternatives: `L2` (Euclidean), `IP` (inner product) |

### Switching embedding providers

Set `OROAI_PROVIDER` in `.env-app.local` then reindex with `--clear`. The `ProviderAwareEmbeddingClient` reads the same env at both index and query time, so they always match.

```dotenv
# Switch to OpenAI embeddings
OROAI_PROVIDER=openai
OROAI_API_KEY=sk-…
OROAI_EMBEDDING_API_KEY=sk-…   # optional: separate key for embeddings
```

---

## CLI Commands

### `genaker:oroai:rag:reindex`

| Option | Effect |
|--------|--------|
| `--provider=NAME` | Run only named provider(s). Repeatable. Omit to run all. |
| `--clear` | Drop and recreate the HNSW index before indexing. Required when switching embedding models. |
| `--list` | Print all registered providers and exit. |

```bash
# Full reindex
php bin/console genaker:oroai:rag:reindex

# Only docs and config (skip schema/menu — avoids 429s on free API tier)
php bin/console genaker:oroai:rag:reindex --provider=docs --provider=config

# Wipe and rebuild after switching from OpenAI to Gemini
php bin/console genaker:oroai:rag:reindex --clear

# List available providers
php bin/console genaker:oroai:rag:reindex --list
```

### `genaker:oroai:rag:test`

| Option | Effect |
|--------|--------|
| `query` (argument) | Required. The text to search for. |
| `--top / -k N` | Number of results to display. Default 5. |
| `--full` | Show complete document text instead of the 300-char preview. |

```bash
# Quick relevance check
php bin/console genaker:oroai:rag:test "application URL" --top=3

# Inspect full document text of top result
php bin/console genaker:oroai:rag:test "payment method configuration" -k 1 --full
```

Example output:

```
RAG Debug — Query: "application URL"
====================================

   Provider : gemini
   Top-K    : 3

 Embedding query…
 Search completed in 568 ms — 3 hit(s) found.

#1  source: system_config:oro_ui
--------------------------------

  Score (cosine distance): 0.299635  |  Similarity: 70.0%  [██████████████░░░░░░]

  System configuration section: oro_ui
  Config key: oro_ui.application_url Value: http://localhost:8000
```

---

## Docker Setup

The RAG stack requires **Redis Stack** (`redis/redis-stack-server`), which ships the RediSearch module. The existing `redis` service is plain Redis used for OroCommerce cache/session/doctrine data. The two containers never share data.

| Service | Role |
|---------|------|
| `redis` | Plain Redis 7.4. DBs 0–3 for OroCommerce. Port 6379. |
| `redis_search` | Redis Stack 7.4 with RediSearch. DB 0 only. Port 6380 on host. `OROAI_REDIS_URL` points here. |

```yaml
# docker-compose.yml (redis_search service)
redis_search:
  image: redis/redis-stack-server:7.4.0-v3
  ports: ["6380:6379"]
  volumes:
    - redis_search:/data
  restart: on-failure

oro_app:
  environment:
    OROAI_REDIS_URL: "redis://redis_search:6379"
```

After starting the container, run `genaker:oroai:rag:reindex` once — it auto-creates the HNSW index on first call.

---

## Unit Tests

Unit tests mock the Redis client and embedding client. No live network calls or containers required.

### TextChunker

```php
final class TextChunkerTest extends TestCase
{
    public function testShortDocumentIsNotSplit(): void
    {
        $chunks = TextChunker::chunk('Hello world');
        self::assertCount(1, $chunks);
        self::assertSame('Hello world', $chunks[0]);
    }

    public function testLongDocumentIsSplitAtParagraphBoundary(): void
    {
        $para1 = str_repeat('a ', 150);  // 300 chars
        $para2 = str_repeat('b ', 150);  // 300 chars
        $text  = $para1 . "\n\n" . $para2;

        $chunks = TextChunker::chunk($text, maxLength: 500);

        self::assertCount(2, $chunks);
        self::assertStringStartsWith('a', $chunks[0]);
        self::assertStringStartsWith('b', $chunks[1]);
    }

    public function testEmptyTextReturnsEmptyArray(): void
    {
        self::assertSame([], TextChunker::chunk(''));
    }
}
```

### Cosine similarity via brute-force fallback

```php
final class RediSearchRagStoreTest extends TestCase
{
    private function makeMockRedis(array $storedDocs): object
    {
        $redis = $this->createMock(ClientInterface::class);

        // FT.INFO returns "ERR unknown command" → brute-force path
        $redis->method('executeRaw')
            ->willReturnCallback(function (array $cmd) {
                if ($cmd[0] === 'FT.INFO') { return 'ERR unknown command'; }
                if ($cmd[0] === 'HSET')    { return 1; }
                return null;
            });

        $redis->method('keys')
            ->willReturn(array_keys($storedDocs));

        $redis->method('hgetall')
            ->willReturnCallback(static fn($key) => $storedDocs[$key] ?? []);

        return $redis;
    }

    public function testBruteForceReturnsBestMatchFirst(): void
    {
        $packF = static fn(array $v) => pack('f*', ...$v);

        // Two docs: one aligned with the query vector, one orthogonal
        $storedDocs = [
            'oroai:rag:match' => [
                'text'      => 'application URL',
                'source'    => 'config',
                'embedding' => $packF([1.0, 0.0, 0.0]),
            ],
            'oroai:rag:noise' => [
                'text'      => 'unrelated content',
                'source'    => 'other',
                'embedding' => $packF([0.0, 1.0, 0.0]),
            ],
        ];

        $embedder = $this->createMock(EmbeddingClientInterface::class);
        $embedder->method('embed')->willReturn([1.0, 0.0, 0.0]);
        $embedder->method('getDimension')->willReturn(3);

        $store = new RediSearchRagStore(
            $this->makeMockRedis($storedDocs),
            $embedder,
        );

        $hits = $store->search('query', 2);

        self::assertCount(2, $hits);
        // perfect match: cosine distance = 0
        self::assertEqualsWithDelta(0.0, $hits[0]->score, 0.001);
        self::assertSame('application URL', $hits[0]->text);
        // orthogonal vector: cosine distance = 1
        self::assertEqualsWithDelta(1.0, $hits[1]->score, 0.001);
    }
}
```

### ProviderAwareEmbeddingClient routing

```php
final class ProviderAwareEmbeddingClientTest extends TestCase
{
    public function testRoutesToGeminiWhenProviderIsGemini(): void
    {
        $gemini = $this->createMock(GeminiEmbeddingClient::class);
        $openai = $this->createMock(OpenAiEmbeddingClient::class);
        $config = $this->createMock(OroAiConfig::class);

        $config->method('getProvider')->willReturn('gemini');
        $gemini->expects(self::once())->method('embed')->willReturn([0.1, 0.2]);
        $openai->expects(self::never())->method('embed');

        $client = new ProviderAwareEmbeddingClient($openai, $gemini, $config);
        $client->embed('hello');
    }

    public function testRoutesToOpenAiForAnyOtherProvider(): void
    {
        $gemini = $this->createMock(GeminiEmbeddingClient::class);
        $openai = $this->createMock(OpenAiEmbeddingClient::class);
        $config = $this->createMock(OroAiConfig::class);

        $config->method('getProvider')->willReturn('openai');
        $openai->expects(self::once())->method('embed')->willReturn([0.1, 0.2]);
        $gemini->expects(self::never())->method('embed');

        $client = new ProviderAwareEmbeddingClient($openai, $gemini, $config);
        $client->embed('hello');
    }
}
```

---

## Integration Tests

Integration tests hit a live `redis_search` container. They are gated by `INTEGRATION_TESTS_ENABLED=1` so they skip automatically in CI.

### Full round-trip with stub embedder

Uses a deterministic fake embedder — no API key required — to verify index → search against a real Redis Stack instance.

```php
final class RagStoreIntegrationTest extends TestCase
{
    private static RediSearchRagStore $store;

    public static function setUpBeforeClass(): void
    {
        if (!getenv('INTEGRATION_TESTS_ENABLED')) {
            self::markTestSkipped('Set INTEGRATION_TESTS_ENABLED=1 to run');
        }

        $redis = new Predis\Client('redis://redis_search:6379');

        // Stub: each document gets a unit vector along its own axis
        $embedder = new class implements EmbeddingClientInterface {
            public function embed(string $text): array
            {
                // query always on axis 0
                return [1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
            }

            public function embedBatch(array $texts): array
            {
                return array_map(function (int $i) {
                    $v = array_fill(0, 8, 0.0);
                    $v[$i % 8] = 1.0;
                    return $v;
                }, array_keys($texts));
            }

            public function getDimension(): int { return 8; }
        };

        self::$store = new RediSearchRagStore($redis, $embedder);
        self::$store->clear();
    }

    public function testIndexAndRetrieveRoundTrip(): void
    {
        $docs = [
            new RagDocument('doc-0', 'axis 0 content', 'test'),
            new RagDocument('doc-1', 'axis 1 content', 'test'),
        ];

        self::$store->index($docs);

        $hits = self::$store->search('query', 1);

        self::assertCount(1, $hits);
        self::assertSame('axis 0 content', $hits[0]->text);
        self::assertEqualsWithDelta(0.0, $hits[0]->score, 0.01);
    }

    public function testSearchReturnsBestMatchFirst(): void
    {
        $hits = self::$store->search('query', 2);

        self::assertGreaterThanOrEqual(2, count($hits));
        // First hit must have lower or equal score than second
        self::assertLessThanOrEqual($hits[1]->score, $hits[0]->score);
    }

    public function testClearEmptiesTheIndex(): void
    {
        self::$store->clear();
        $hits = self::$store->search('anything', 5);
        self::assertSame([], $hits);
    }
}
```

### Running integration tests

```bash
# All RAG integration tests
INTEGRATION_TESTS_ENABLED=1 bin/phpunit \
  -c phpunit-dev.xml \
  --filter RagStoreIntegrationTest

# Verbose — shows each assertion
INTEGRATION_TESTS_ENABLED=1 bin/phpunit \
  -c phpunit-dev.xml \
  --filter RagStoreIntegrationTest --testdox
```

> **Note:** Integration tests boot the Symfony kernel. Running them in the same PHPUnit process as unit tests can corrupt static caches. The `INTEGRATION_TESTS_ENABLED` gate ensures they only run when explicitly requested and never in CI automatically.
