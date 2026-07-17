---
description: 'Use when asked HOW some feature/logic works internally, or why the code behaves a certain way.'
---
1. code_search the feature keyword (class, route, table, message text) — narrow with a path prefix if known.
2. code_read the smallest relevant slice around the best match; follow ONE level deeper (a called service or parent class) only if needed.
3. For runtime wiring use console_command: debug:container <service>, debug:router <route>, debug:config <bundle>.
4. Answer with the mechanism in 2-4 sentences and cite file:line for every claim. Do not paste large code blocks.
