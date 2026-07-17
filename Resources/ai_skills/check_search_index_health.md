---
description: 'Use when storefront/backend search returns stale or missing results.'
---
1. schema_inspector tables matching 'search'.
2. Compare COUNT of oro_website_search_* index rows vs source entity count.
3. If stale, recommend oro:website-search:reindex for the affected entity.
