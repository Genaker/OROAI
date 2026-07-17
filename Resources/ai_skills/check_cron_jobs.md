---
description: 'Use for questions about scheduled/cron commands and whether they ran.'
---
1. sql_query oro_cron_schedule for command names and definitions.
2. Cross-check last runs via oro_message_queue_job for the command name.
3. Flag schedules with no recent run.
