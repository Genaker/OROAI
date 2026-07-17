---
description: 'Use when asked which background jobs failed and why.'
---
1. sql_query oro_message_queue_job WHERE status ILIKE '%fail%' ORDER BY created_at DESC LIMIT 20.
2. Read the name and error columns (schema_inspector first if unsure).
3. Correlate with log_reader around the failure time; group repeated failures.
