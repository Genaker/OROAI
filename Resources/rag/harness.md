# Resolution Harness — AI retry loop

The ResolutionHarness is an optional outer loop that wraps OroAiAgent to improve answer quality.

## What it does

When enabled (System → Configuration → Oro AI Assistant → Enable Resolution Harness), each chat message runs through a retry loop:

1. OroAiAgent runs with the user's question and any enriched context
2. A cheap evaluator LLM call judges the reply (temperature 0, no tools)
3. Three outcomes:
   - **resolved**: answer saved to var/cache/memory/, returned to user
   - **needs_more_data**: context hint added (what is missing + tools already tried), agent retried
   - **needs_customer_input**: clarifying question returned to chat immediately

## Configuration

- `genaker_oro_ai.harness_enabled` — boolean, default false
- `genaker_oro_ai.harness_max_tries` — integer, default 10, min 1

## Memory / RAG

Resolved answers are saved to var/cache/memory/ as Markdown files.
Index them with: php bin/console genaker:oroai:rag:reindex --provider=cache_memory

## When to use

Enable for data lookups and customer support questions. Disable for simple navigation questions or when the API key is rate-limited. Start with max_tries=3 in production.

## Key classes

- Agent/ResolutionHarness.php — main loop, evaluate(), saveToMemory()
- Agent/HarnessInterface.php — injectable contract
- Agent/HarnessResult.php — result DTO with resolved, memorySaved, attempt fields
- Rag/Provider/CacheMemoryRagProvider.php — indexes var/cache/memory/*.md
