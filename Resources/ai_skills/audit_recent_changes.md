---
description: 'Use when asked who changed a record or what changed recently.'
---
1. sql_query oro_audit WHERE object_class ILIKE '%Entity%' AND logged_at > now() - interval 'X'.
2. Join oro_audit_field for old/new values; join oro_user for the author.
3. Present chronologically with user names.
