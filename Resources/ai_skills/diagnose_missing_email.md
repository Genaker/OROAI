---
description: 'Use when notification/order emails were not received.'
---
1. config_inspector oro_email (smtp settings, sender).
2. sql_query oro_email / spool-related tables for the recipient (schema_inspector 'email' first).
3. log_reader for 'mailer' or the recipient address.
4. Distinguish: never generated vs generated-but-not-sent vs SMTP rejected.
