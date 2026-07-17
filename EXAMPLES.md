# Your OroCommerce AI Should Know Your Business. Here's How to Make It.

> RAG turns a generic chatbot into a system that knows your product catalog, your customers, your prices, and your config. A practical guide to seven use cases — with the code to build each one.

---

Ask a vanilla LLM "what products do we sell under $200?" and it will either confess ignorance or, worse, make something up. The model was trained on the internet — not your catalog. It doesn't know your SKUs, your pricing tiers, your customer contracts, or the configuration choices you made three years ago that still matter today.

This is the problem RAG solves. **Retrieval-Augmented Generation** doesn't try to bake your business knowledge into a model. Instead, it retrieves the most relevant pieces of your data at query time — semantically, not by keyword — and hands them to the LLM as context. The model then answers based on facts, not guesses.

The result is an AI assistant that feels like it's been working at your company for years. It knows your application URL. It knows which tables store pricing rules. It knows that Customer #4821 has a preferred payment method and a $50,000 credit limit. Not because a model was trained on this data — because we looked it up, right now, before answering.

> The model doesn't need to know your business. It needs to be able to look it up.

---

## How RAG works

Every piece of knowledge you want the AI to use gets converted into an **embedding** — a vector of 3,072 numbers that encodes its meaning. Similar content produces similar vectors. We store these in Redis Stack with a HNSW index that supports approximate nearest-neighbour search in milliseconds.

At query time: the user's question gets embedded the same way, and we search for the top-K most similar document vectors. Those documents get injected into the LLM prompt. The model reads the real data and gives a grounded answer.

```
INDEXING  (run once, refresh on data change)

  Your data  →  Provider  →  Embeddings  →  Redis HSET
  (any source)   (PHP class)   (Gemini API)    (binary blob)
                                    ↓
                              HNSW vector index (FT.CREATE)

QUERYING  (every chat message)

  User query  →  embed(query)  →  FT.SEARCH KNN  →  top-K docs
                  (same model)     (cosine dist.)       ↓
                                             LLM prompt + context
                                                     ↓
                                              Grounded answer
```

Any data you can express as text can become a RAG provider. Each of the seven use cases below takes the same shape: a PHP class that produces `RagDocument[]` objects, registered as a tagged service. The indexing pipeline and the search are shared infrastructure.

---

## Use case 1: Product Discovery

Your sales team asks: *"Show me industrial safety products under $150 that are compatible with our standard PPE kits."* A keyword search returns nothing useful. Semantic search finds exactly what they mean.

**The conversation:**

```
You:  What safety gloves do we carry for chemical handling,
      and what's our best price for a pallet quantity?

AI:   We carry three chemical-resistant glove lines:
      the Ansell AlphaTec 58-270 (nitrile, $4.20/pair, pallet of 2,400 = $9,600),
      the Kimberly-Clark Purple Nitrile ($3.85/pair, pallet pricing unlocked at 1,000+ units),
      and the Showa 7552 PVC glove rated for strong acids ($6.10/pair).
      The Ansell line has the best volume discount — 8% off at pallet quantity.
      Want me to generate a quote?
```

The provider indexes product name, SKU, description, category, and price tiers — enough for the LLM to reason about options, not just list them.

```php
final class ProductCatalogRagProvider implements RagProviderInterface
{
    public function getName(): string { return 'products'; }

    public function provide(): array
    {
        $rows = $this->connection->executeQuery("
            SELECT
                p.sku,
                pn.string                               AS name,
                pd.wysiwyg                              AS description,
                pt.value                                AS unit_price,
                cat.title                               AS category
            FROM oro_product p
            JOIN oro_product_name pn   ON pn.product_id = p.id AND pn.localization_id IS NULL
            LEFT JOIN oro_product_descr pd ON pd.product_id = p.id AND pd.localization_id IS NULL
            LEFT JOIN oro_price_list_product_prices pt ON pt.product_id = p.id
            LEFT JOIN oro_catalog_category_product cp  ON cp.product_id = p.id
            LEFT JOIN oro_catalog_category cat         ON cat.id = cp.category_id
            ORDER BY p.sku
            LIMIT 2000
        ")->fetchAllAssociative();

        return array_map(static fn($row) => new RagDocument(
            id:     md5('product:' . $row['sku']),
            text:   implode("\n", array_filter([
                "SKU: {$row['sku']}",
                "Name: {$row['name']}",
                "Category: {$row['category']}",
                $row['unit_price'] ? "Unit price: \${$row['unit_price']}" : null,
                $row['description'] ? strip_tags($row['description']) : null,
            ])),
            source: 'product:' . $row['sku'],
        ), $rows);
    }
}
```

The `text` field is the key design decision. Pack in everything a salesperson would naturally say about the product — name, category, price, a cleaned description. The embedding captures the semantic meaning of this full paragraph, not just the SKU string.

---

## Use case 2: Customer Intelligence

Account managers spend 20 minutes before every call pulling data from three different screens. With a customer RAG provider, that same context surfaces in a single question.

**The conversation:**

```
You:  Tell me about Acme Manufacturing's account —
      what do they usually buy and are there any issues?

AI:   Acme Manufacturing (acme-mfg.com) has been a customer since 2019.
      They primarily buy industrial fasteners (SKUs FBT-*) and cutting tools,
      averaging $84,000/quarter. Their last order was 3 weeks ago —
      2,400 hex bolts and a case of carbide inserts.
      They're on Net-30 terms with a $150,000 credit limit, currently using $42,000.
      One open support ticket from last month: a delivery delay on ORD-48821 that was resolved.
      Their preferred contact is James Renner, purchasing manager.
```

```php
public function provide(): array
{
    $accounts = $this->connection->executeQuery("
        SELECT
            a.name,
            a.website,
            a.created_at,
            u.first_name || ' ' || u.last_name  AS primary_contact,
            pl.name                              AS payment_term,
            a.internal_rating
        FROM oro_account a
        LEFT JOIN oro_account_user u ON u.account_id = a.id AND u.is_default = true
        LEFT JOIN oro_payment_term pl ON pl.id = a.payment_term_id
        ORDER BY a.updated_at DESC
        LIMIT 1000
    ")->fetchAllAssociative();

    return array_map(static fn($a) => new RagDocument(
        id:     md5('account:' . $a['name']),
        text:   implode("\n", array_filter([
            "Customer: {$a['name']}",
            "Website: {$a['website']}",
            "Primary contact: {$a['primary_contact']}",
            "Payment terms: {$a['payment_term']}",
            "Internal rating: {$a['internal_rating']}",
        ])),
        source: 'account:' . $a['name'],
    ), $accounts);
}
```

Pair this with an order history provider (Use Case 5) and the AI has a complete picture of every account — profile and transaction history retrieved together in one semantic search.

---

## Use case 3: Storefront Semantic Search

OroCommerce's built-in search is Elasticsearch-based and works well for exact and fuzzy text matches. What it can't do is answer queries like *"something waterproof I can use in a food processing environment"* — where the buyer knows what they need but not what it's called.

**The conversation:**

```
Buyer:    I need something to protect workers from splashing hot oil —
          hands and forearms, FDA-compliant

Results:  Based on your requirements:
          Ansell 76-736 Neoprene Gauntlet (food-safe, heat-resistant to 120°C, 18" sleeve)
          Tucker Safety 92-DBG (FDA-compliant silicone glove, oil resistant)
          Showa 481 Therma-Fit (deep fat fryer rated)
          All three cover forearms and meet FDA 21 CFR standards.
```

Embed the user's query, retrieve top-K products from the RAG index, feed them to the LLM to generate a ranked result list with explanations. The semantic search happens in milliseconds — embedding the query takes ~300ms via the Gemini API; the Redis HNSW search adds under 10ms even for 100,000 indexed products.

---

## Use case 4: Documentation Assistant

Your OroCommerce installation has hundreds of configuration options, workflow rules, and integration points. Nobody has the whole thing memorised. The documentation assistant answers "how do I configure X" questions by retrieving relevant Markdown docs from your `Resources/rag/` directory.

**The conversation:**

```
Admin:  How do I set up the MuleSoft integration for order sync?

AI:     The MuleSoft integration uses OAuth 2.0 client credentials.
        Set MULESOFT_CLIENT_ID and MULESOFT_CLIENT_SECRET in your environment,
        then run:
          php bin/console egerdau:mulesoft:sync:orders --since=yesterday
        to test. The base URL for QA is https://qa-exp-gln-enx-api.us-e1.cloudhub.io.
        Full setup is documented in the Shipment Import bundle —
        see ShipmentImportService.php for the payload format the API expects.
```

Drop any Markdown file into `src/Genaker/Bundle/OroAI/Resources/rag/` and it gets chunked and indexed automatically on the next reindex run. No code changes needed. This makes it practical to index runbooks, deployment guides, data dictionaries, or onboarding documents.

```bash
# Reindex docs only
php bin/console genaker:oroai:rag:reindex --provider=docs

# Test that it worked
php bin/console genaker:oroai:rag:test "MuleSoft order sync setup" --top=3
```

> Every new Markdown file dropped into Resources/rag/ makes the AI smarter — without a deployment, without a model fine-tune, without a code change.

---

## Use case 5: Order History & Reorder Assistance

B2B buyers re-order the same products on a predictable cycle. An order history provider lets the AI suggest reorders, flag items that haven't been ordered recently, and draft new orders from natural language requests.

**The conversation:**

```
Buyer:  Set up our monthly maintenance order —
        same as last time, but double the cutting inserts.

AI:     Your last monthly maintenance order (ORD-49102, placed June 3rd) included:
        24× FBT-M8x30 hex bolts, 12× carbide inserts CI-35, and 4× lubricant canisters LB-500.
        I'll draft a new order with the same quantities except CI-35 at 24 units.
        Total estimate: $847. Shall I submit it for approval?
```

```php
public function provide(): array
{
    $rows = $this->connection->executeQuery("
        SELECT
            o.identifier,
            o.created_at::date       AS order_date,
            a.name                   AS customer,
            SUM(li.value)            AS total,
            STRING_AGG(
                p.sku || ' ×' || li.quantity::int,
                ', ' ORDER BY li.product_sku
            )                        AS line_items
        FROM oro_order o
        JOIN oro_account a          ON a.id = o.account_id
        JOIN oro_order_line_item li ON li.order_id = o.id
        JOIN oro_product p          ON p.id = li.product_id
        WHERE o.created_at > NOW() - INTERVAL '12 months'
        GROUP BY o.id, o.identifier, o.created_at, a.name
        ORDER BY o.created_at DESC
        LIMIT 5000
    ")->fetchAllAssociative();

    return array_map(static fn($r) => new RagDocument(
        id:     md5('order:' . $r['identifier']),
        text:   implode("\n", [
            "Order: {$r['identifier']} placed {$r['order_date']}",
            "Customer: {$r['customer']}",
            "Total: \${$r['total']}",
            "Items: {$r['line_items']}",
        ]),
        source: 'order:' . $r['identifier'],
    ), $rows);
}
```

---

## Use case 6: Schema & SQL Assistant

New developers spend their first weeks navigating the OroCommerce schema. The `schema` provider indexes every table and its columns — so the AI can answer structural questions without anyone maintaining a separate data dictionary.

**The conversation:**

```
Developer:  Which tables should I join to get a customer's current
            price list with product prices?

AI:         Start from oro_account, join oro_price_list_account to get the assigned
            price list, then join oro_price_list and oro_price_list_product_prices
            on price_list_id. That last table has product_id, quantity, value,
            currency, and unit_code. For tiered pricing join on quantity as well.
            If the account has no direct assignment, fall back through
            oro_price_list_account_group → oro_price_list_website → the default list.
```

The `schema` provider is built-in. At 694 tables it generates 694 documents — each describing one table's columns, types, and nullable flags. Enable it with:

```bash
php bin/console genaker:oroai:rag:reindex --provider=schema
```

> A schema RAG index is a living data dictionary. It updates itself every time you reindex.

---

## Use case 7: Admin Navigation

The OroCommerce admin has hundreds of pages. New staff waste real time clicking through menus looking for where things live. The `menu` provider indexes every admin route with its name and path — the AI acts as a guide.

**The conversation:**

```
Admin:  Where do I configure shipping rules and set up free shipping thresholds?

AI:     Shipping rules are at System → Shipping → Shipping Rules
        (route: oro_shipping_rule_index, path: /admin/shipping-rule/).
        Create a rule, add a "Free Shipping" method condition, and set the
        minimum order amount. For flat-rate and other method configuration,
        go to System → Shipping → Shipping Methods first to enable the integrations.
```

```bash
php bin/console genaker:oroai:rag:reindex --provider=menu
```

---

## All seven providers at a glance

| Provider | Source | What gets indexed |
|----------|--------|------------------|
| `products` | `oro_product` + joins | SKU, name, category, price, description |
| `customers` | `oro_account` | Name, contact, payment terms, rating |
| `orders` | `oro_order` + line items | 12-month history — identifier, customer, items, total |
| `docs` | `Resources/rag/*.md` | Runbooks, guides, API docs — any Markdown |
| `config` | `oro_config_value` | OroCommerce system configuration, grouped by prefix |
| `schema` | `information_schema` | Every table with columns, types, nullable flags |
| `menu` | Symfony Router | Every admin route with name and path |
| *(custom)* | Anything | Implement `RagProviderInterface`, tag it, done |

---

## Getting started in five minutes

**1. Add the Redis Stack container** to `docker-compose.yml` (Redis Stack includes RediSearch; plain Redis does not):

```yaml
redis_search:
  image: redis/redis-stack-server:7.4.0-v3
  ports: ["6380:6379"]
  volumes:
    - redis_search:/data

oro_app:
  environment:
    OROAI_REDIS_URL: "redis://redis_search:6379"
```

**2. Configure your API key** in `.env-app.local`:

```dotenv
OROAI_PROVIDER=gemini
OROAI_API_KEY=your-gemini-key
OROAI_MODEL=gemini-2.5-flash
OROAI_REDIS_URL=redis://redis_search:6379
```

**3. Build the index:**

```bash
docker-compose up -d redis_search
php bin/console cache:clear
php bin/console genaker:oroai:rag:reindex --provider=docs --provider=config
```

**4. Verify with a test query:**

```bash
php bin/console genaker:oroai:rag:test "application URL" --top=3
```

The HNSW vector index auto-creates on first run. After that, adding a new provider is three steps: write the PHP class, register it in `services.yml` with the `genaker_oroai.rag_provider` tag, run reindex with `--provider=yourname`.

---

## Why RAG beats writing tools for everything

The alternative to RAG is **tool use** — writing specific PHP methods for every question type the AI might face. That scales badly. Every new question type needs a new tool, new testing, new maintenance.

RAG trades that engineering overhead for an indexing step: describe your data as text, embed it, done. The cosine similarity search handles synonyms naturally (a query about "safety footwear" retrieves documents about "protective boots"), tolerates paraphrasing, and degrades gracefully — a weak match is still the closest thing in the index, which the LLM can use or decline.

The PHP brute-force cosine fallback — activated when RediSearch isn't available — means the system works in development without the Redis Stack container. The scores are numerically identical; only search speed differs. At under 200 documents, brute-force completes in under 50ms anyway.

The real compounding value is the documentation use case. Your team's institutional knowledge — how to configure the MuleSoft integration, what the seeding API expects, what the non-obvious behaviour of the pricing engine is — all of it can live in Markdown files, indexed and searchable. The AI becomes a searchable memory for your team, not just a chatbot for your customers.

> Drop a Markdown file. Run reindex. The AI knows it. That's the whole loop.

---

## Further reading

- [RAG.md](RAG.md) — technical reference: cosine similarity, HNSW parameters, tuning, unit tests, integration tests
- [README.md](README.md) — bundle overview and quick-start guide
- `php bin/console genaker:oroai:rag:reindex --list` — see all registered providers
- `php bin/console genaker:oroai:rag:test "<your query>" --full` — debug RAG relevance interactively
