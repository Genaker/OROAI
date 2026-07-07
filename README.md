# GenakerOroAIBundle

AI assistant for OroCommerce. Adds a chat input to the admin header that connects to a configurable LLM backend, with tool use (SQL queries, entity lookup, schema inspection, config reading) and a RAG knowledge base backed by Redis Stack.

---

## Features

- **Multi-provider LLM** — OpenAI, Anthropic Claude, Google Gemini; switch via env var or admin UI
- **Model selection** — dropdown in System Configuration populated from `Resources/config/ai_models.yml`; no code change needed to add new models
- **Tool use** — the agent can query the database, inspect entity metadata, look up routes, read logs, and more
- **RAG (Retrieval-Augmented Generation)** — semantic search over docs, DB schema, system config, and admin menu; answers are grounded in real OroCommerce data
- **Chat UI** — always-visible input in the admin header; on send the panel slides open below the header and the input relocates inside the panel for a native chat experience

---

## Quick start

### 1. Configure the provider

Add to `.env-app.local`:

```dotenv
###> OroAI / Gemini config ###
OROAI_PROVIDER=gemini
OROAI_API_KEY=<your-gemini-key>
OROAI_MODEL=gemini-2.0-flash
OROAI_EMBEDDING_API_KEY=<your-gemini-key>
OROAI_REDIS_URL=redis://redis_search:6379
###< OroAI / Gemini config ###
```

Supported values for `OROAI_PROVIDER`: `gemini`, `openai`, `anthropic`.

### 2. Start the Redis Stack container

RediSearch (vector search) requires the Redis Stack image — the plain `redis` service does not have it:

```bash
docker-compose up -d redis_search
```

### 3. Build the RAG index

```bash
php bin/console genaker:oroai:rag:reindex --provider=docs --provider=config
```

### 4. Verify RAG is working

```bash
php bin/console genaker:oroai:rag:test "application URL" --top=3
```

### 5. Clear Symfony cache

```bash
php bin/console cache:clear
```

---

## Configuration

### Environment variables

Env vars take priority over admin UI settings. Symfony Dotenv sets `$_SERVER`/`$_ENV` only — `getenv()` returns false for vars loaded from `.env-app.local`.

| Variable | Default | Description |
|----------|---------|-------------|
| `OROAI_PROVIDER` | `openai` | LLM provider: `openai`, `gemini`, `anthropic` |
| `OROAI_API_KEY` | — | API key for the LLM provider |
| `OROAI_MODEL` | provider default | Model name — overrides the admin UI dropdown |
| `OROAI_EMBEDDING_API_KEY` | falls back to `OROAI_API_KEY` | Separate key for embedding calls |
| `OROAI_REDIS_URL` | `redis://redis_search:6379` | Redis Stack URL for the RAG vector index |

### Admin UI

Go to **System → Configuration → General Setup → Oro AI Assistant** to set the provider, API key, model, temperature, and toggle individual tools — no deployment needed.

### Model list

Available models are defined in [`Resources/config/ai_models.yml`](Resources/config/ai_models.yml). To add a model, append an entry under the appropriate provider group:

```yaml
models:
  gemini:
    - { label: 'Gemini 2.0 Flash (15 RPM free)', value: 'gemini-2.0-flash' }
    - { label: 'Gemini 2.5 Flash (10 RPM free)', value: 'gemini-2.5-flash' }
    - { label: 'Gemini 2.5 Pro', value: 'gemini-2.5-pro' }
  openai:
    - { label: 'GPT-4o', value: 'gpt-4o' }
    ...
```

Run `cache:clear` after editing the file.

---

## Chat UI behaviour

1. **Collapsed** — compact input + send button visible in the header search row
2. **First send** — panel slides open below the header; input relocates inside the panel below the message history
3. **Minimize / Clear / Escape / click-outside** — panel closes; input returns to the header
4. **Focus** — input is auto-focused when the panel opens and again after each AI response so you can keep typing without clicking

---

## Directory structure

```
GenakerOroAIBundle/
├── Agent/              # OroAiAgent — orchestrates tools and RAG context
├── Command/            # Console commands (rag:reindex, rag:test)
├── Controller/         # ChatController — handles AJAX chat requests
├── DependencyInjection/
├── Form/Type/          # AiModelChoiceType — builds model dropdown from ai_models.yml
├── Llm/                # LLM clients (OpenAI, Gemini, Anthropic) + registry
├── Rag/                # Embedding clients, RediSearchRagStore, providers
│   ├── Provider/       # DocFiles, Schema, Menu, SystemConfig providers
│   └── Contract/       # RagProviderInterface
├── Resources/
│   ├── config/
│   │   ├── ai_models.yml   # model list for the admin UI dropdown
│   │   └── services.yml
│   ├── public/js/      # oroai-chat.js — chat UI with DOM input relocation
│   ├── rag/            # Markdown knowledge-base files indexed by docs provider
│   └── views/Chat/     # chatBar.html.twig
├── Service/            # OroAiConfig — reads env vars and system config
├── Tools/              # SQL, schema, entity, route, log, config, translation tools
├── RAG.md              # RAG technical reference
└── EXAMPLES.md         # Use-case examples
```

---

## RAG deep-dive

See **[RAG.md](RAG.md)** for:

- Embedding models, dimensions, and storage format
- Cosine similarity algorithm and score interpretation table
- How to tune top-K, similarity thresholds, and chunk size
- HNSW index parameters and brute-force fallback
- Switching between Gemini and OpenAI embeddings
- Adding a custom RAG provider
- Full unit test and integration test examples

---

## CLI reference

| Command | Description |
|---------|-------------|
| `genaker:oroai:rag:reindex` | Rebuild the vector index from all (or selected) providers |
| `genaker:oroai:rag:test <query>` | Search the index and show scores — useful for debugging relevance |

```bash
# Reindex only config and docs
php bin/console genaker:oroai:rag:reindex --provider=config --provider=docs

# List all registered providers
php bin/console genaker:oroai:rag:reindex --list

# Drop index and rebuild from scratch (required after switching embedding model)
php bin/console genaker:oroai:rag:reindex --clear

# Test a query — shows cosine distance, similarity %, and matched text
php bin/console genaker:oroai:rag:test "checkout configuration" --top=5
php bin/console genaker:oroai:rag:test "checkout configuration" -k 1 --full
```

---

## Running tests

```bash
# Unit tests (no containers needed)
bin/phpunit -c phpunit-dev.xml src/Genaker/Bundle/OroAI/Tests/Unit

# Integration tests (requires live redis_search container)
INTEGRATION_TESTS_ENABLED=1 bin/phpunit -c phpunit-dev.xml --filter RagStoreIntegrationTest
```

## Rate limits (Gemini free tier)

| Model | RPM | RPD |
|-------|-----|-----|
| `gemini-2.0-flash` | 15 | 1 500 |
| `gemini-2.5-flash` | 10 | 500 |
| `gemini-2.5-pro` | 5 | 25 |

Upgrade to a paid API key or switch to `gemini-2.0-flash` to reduce 429 errors.
