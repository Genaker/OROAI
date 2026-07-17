---
description: "Use for a general 'is everything OK' system check."
---
1. system_info for versions, disk, PHP.
2. Queue backlog: COUNT oro_message_queue (old messages = stalled consumer).
3. Recent CRITICAL entries via log_reader.
4. Failed jobs count last 24h. Summarize red flags first.
