---
description: 'Use when a setting behaves differently per website/organization than expected.'
---
1. config_inspector get for the key (global value).
2. sql_query oro_config + oro_config_value joined, WHERE name = key — one row per scope entity.
3. Explain precedence: website > organization > global; show which scope wins.
