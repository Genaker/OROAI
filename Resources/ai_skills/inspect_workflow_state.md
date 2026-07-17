---
description: 'Use when asked what workflow step a record is in or why a transition is unavailable.'
---
1. sql_query oro_workflow_item WHERE entity_class ILIKE '%X%' AND entity_id = N.
2. Join oro_workflow_step for the current step name.
3. Check oro_workflow_definition active flag for that workflow.
