---
description: 'Use when asked to find duplicated data (same email, sku, name...).'
---
1. sql_query: SELECT col, COUNT(*) FROM t GROUP BY col HAVING COUNT(*) > 1 ORDER BY 2 DESC LIMIT 25.
2. Confirm the exact column with schema_inspector first.
3. Report count of duplicate groups and the worst offenders with links.
