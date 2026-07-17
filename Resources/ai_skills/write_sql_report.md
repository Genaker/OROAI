---
description: 'Use when the user asks for a data report, statistics, counts, or any answer that requires querying the database.'
---
# Writing a SQL report

1. Call `schema_inspector` first to confirm the exact table and column names —
   never guess them from entity names.
2. Build ONE read-only SELECT. Prefer aggregate functions (COUNT, SUM, AVG)
   over fetching raw rows when the user wants totals or statistics.
3. Always add a LIMIT (the configured row cap applies anyway) and an ORDER BY
   that makes the top rows meaningful.
4. Run it with `sql_query`. If it errors, read the error, fix the query, and
   retry — do not give up after one failed attempt.
5. Present the result as a compact table, mention the time range or filters
   applied, and include clickable /admin/... links for any entity referenced.
