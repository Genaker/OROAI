# ResolutionHarness

The `ResolutionHarness` is an optional outer retry loop that wraps `OroAiAgent`. When enabled, it runs the agent up to N times, evaluates each reply with a cheap second LLM call, and takes one of three paths:

- **resolved** — saves the answer to `var/data/oroai-memory/` and returns
- **needs_more_data** — enriches the context with what's missing and which tools already ran, then retries
- **needs_customer_input** — returns a clarifying question directly to the chat

Disabled by default. Enable in System → Configuration → General Setup → Oro AI Assistant.

---

## How it works

```
resolve(userMessage, history)
│
├─ attempt 1..N ──────────────────────────────────────────────────────┐
│                                                                       │
│   enrichedMessage = userMessage                                       │
│     [+ "Harness context: <missing> (tools tried: X, Y)"]  ← retry   │
│                                                                       │
│   AgentResult = OroAiAgent.run(enrichedMessage, history)             │
│     └─ inner tool loop (sql_query, find_entity, …) up to max_iters  │
│                                                                       │
│   eval = Evaluator LLM(question, reply, toolTrace)  ← T=0, no tools │
│                                                                       │
│   ┌─────────────────┬────────────────────┬────────────────────────┐  │
│   │    resolved     │  needs_more_data   │ needs_customer_input   │  │
│   │                 │                    │                        │  │
│   │ saveMemory()    │ extraContext =      │ return question        │  │
│   │ return reply ✓  │   missing +        │ to chat (no retry) ✓  │  │
│   │                 │   tried-tools hint  │                        │  │
│   └─────────────────┘ ─────────────────►─┘ back to top of loop   │  │
│                                                                       │
└───────────────────────────────────────────────────────────────────────┘
  after N attempts → return best attempt (resolved=false)
```

### The evaluator

A single `ChatMessage::user(prompt)` call with `temperature: 0.0` and no tools — fast and cheap. It sees:

- The original user question
- The agent's reply
- The tool trace summary (up to 300 chars per tool result)

Having the tool trace is the key: it lets the evaluator detect that a query returned 0 rows, a tool errored, or the agent used `schema_inspector` but never tried `sql_query` — things invisible from the reply text alone.

The evaluator returns one of three JSON shapes:

```json
{"status": "resolved"}
{"status": "needs_customer_input", "question": "Could you provide the order number?"}
{"status": "needs_more_data", "missing": "check the oro_order table for status column"}
```

The evaluator tolerates the two malformed shapes models commonly produce despite being told
"JSON only, no markdown": a fenced <code>```json</code> block, or a bare object with
leading/trailing prose — both are extracted and parsed correctly.

If the evaluator call still can't be parsed, or the LLM call itself throws (network/API
error), the harness treats that attempt as `needs_more_data` and retries — it does **not**
silently treat a failed evaluation as `resolved`. A persistently-failing evaluator exhausts
`harness_max_tries` and returns the best attempt with `resolved: false`, rather than passing
through a first answer nobody actually checked.

### Context enrichment on retry

When the evaluator says `needs_more_data`, the harness builds a context hint for the next attempt:

```
[Harness context from prior attempt — use this to look deeper]:
<missing note from evaluator>
(Tools already tried this attempt: sql_query, schema_inspector — use different tools or parameters.)
```

This prevents the agent from calling the same tools in the same way on every retry.

---

## Memory system

Resolved answers are saved to `var/data/oroai-memory/` as Markdown files:

```
var/data/oroai-memory/
  2026-07-07_14-32-01_where-are-customer-orders.md
  2026-07-07_15-01-44_how-to-create-a-price-list.md
```

File format:

```markdown
# Q: Where are customer orders?

Customer orders are at /admin/order/. Use the Orders grid to filter by status,
customer, or date range. [entity_url tool result: /admin/order/]
```

### Indexing into RAG

The `CacheMemoryRagProvider` (provider name: `cache_memory`) reads these files and indexes them so future similar questions hit the RAG context before the agent even runs:

```bash
# Index saved memories into the vector store
php bin/console genaker:oroai:rag:reindex --provider=cache_memory

# Verify retrieval
php bin/console genaker:oroai:rag:test "customer orders" --top=3
```

> **Note:** memories live in `var/data/oroai-memory/` — deliberately OUTSIDE `var/cache/`, so `cache:clear` and deploys never wipe learned knowledge. Override the `$memoryDir` argument in `services.yml` to relocate.

---

## Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| `genaker_oro_ai.harness_enabled` | `false` | Enable the harness. When false, `OroAiAgent::run()` is called directly with no retry. |
| `genaker_oro_ai.harness_max_tries` | `10` | Maximum outer loop iterations. Each try runs the full inner agent loop (up to `max_iterations` tool calls). Min 1. |

Both are editable in the admin UI without a deployment.

The `harness_max_tries` default of 10 is intentionally high — most questions resolve in 1–2 attempts. The ceiling exists to prevent runaway spend on pathological questions.

---

## Cost model

Each harness attempt consumes:

1. **Agent call** — 1..`max_iterations` LLM calls with tools (expensive)
2. **Evaluator call** — 1 cheap LLM call, no tools, small prompt

At 10 max tries and 5 inner iterations each, the theoretical ceiling is 50 agent LLM calls + 10 evaluator calls per request. In practice the evaluator exits early: typical questions resolve in attempt 1 or 2 with 1–3 tool calls.

Recommendation: start with `harness_max_tries = 3` in production, raise it for high-stakes support contexts.

---

## API

```php
interface HarnessInterface
{
    /** @param ChatMessage[] $history */
    public function resolve(string $userMessage, array $history = []): HarnessResult;
}
```

```php
readonly class HarnessResult
{
    public string $reply;
    public array  $toolTrace;       // same shape as AgentResult::$toolTrace
    public array  $links;           // extracted /admin/ URLs
    public bool   $resolved;        // evaluator confirmed a complete answer
    public bool   $needsClarification; // evaluator asked a question back
    public bool   $memorySaved;     // answer written to var/data/oroai-memory/
    public int    $attempt;         // which attempt resolved / exhausted
}
```

The JSON response from `/admin/oroai/chat/message` gains three extra fields when the harness is active:

```json
{
  "reply": "…",
  "tool_trace": […],
  "links": […],
  "harness_attempt": 2,
  "memory_saved": true,
  "needs_clarification": false
}
```

---

## When to enable

| Scenario | Recommendation |
|----------|----------------|
| Simple admin navigation questions | Harness off — single-pass is fast enough |
| Data lookups (orders, users, prices) | Harness on — agent often misses column names first try |
| Customer support with unknown context | Harness on — `needs_customer_input` path is valuable |
| High-traffic, rate-limited API key | Harness off or `max_tries = 2` — evaluator doubles call count |
| Development / debugging | Harness on with `max_tries = 3` — watch `harness_attempt` in responses |

---

## Architecture notes

- `ResolutionHarness` is `final` and implements `HarnessInterface` — inject the interface for testability.
- The evaluator reuses the same `LlmClientRegistry` as the agent. If the provider is rate-limited, evaluator calls also hit the limit. A separate lightweight model for evaluation would reduce pressure (not currently implemented).
- `saveToMemory()` is best-effort: filesystem errors are silently caught and `memorySaved` returns `false`.
- The harness is stateless between requests — `$extraContext` lives only for the duration of a single `resolve()` call.
