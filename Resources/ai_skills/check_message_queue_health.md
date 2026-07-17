---
description: "Use for stuck jobs, consumers, or 'nothing is processing' reports."
---
1. sql_query oro_message_queue: COUNT(*) grouped by queue (backlog size).
2. Oldest message age = now() - MIN(created_at) per queue.
3. Report backlog and whether a consumer appears stalled (old messages piling up).
