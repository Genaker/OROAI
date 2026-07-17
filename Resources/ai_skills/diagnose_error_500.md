---
description: 'Use when the user reports a 500 error or a stack trace on some page.'
---
1. log_reader latest prod/dev log filtered by 'CRITICAL' or the route/URL.
2. Extract exception class, message, file:line of the FIRST frame in project code.
3. If DB-related, verify the table/column with schema_inspector.
4. Summarize root cause and the exact log line.
