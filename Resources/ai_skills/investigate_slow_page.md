---
description: 'Use when a page or request is reported slow.'
---
1. sql_query genaker_http_performance ORDER BY avg_response_ms DESC LIMIT 20 (or WHERE path ILIKE).
2. Compare avg/min/max and request_count; note last_status_code.
3. Check the log around slow timestamps for queries or external API calls.
