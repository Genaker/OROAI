---
description: 'Use when a data import/export job failed or imported nothing.'
---
1. sql_query oro_import_export result tables (schema_inspector 'import' first) for the newest jobs.
2. Read counts: added/updated/errors; fetch error log attachment path.
3. log_reader around the job time for validation messages.
