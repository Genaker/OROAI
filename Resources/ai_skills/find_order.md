---
description: 'Use when the user asks about a specific order (by number, PO, or customer).'
---
1. sql_query oro_order by identifier/po_number/eg_sap_order_id (ILIKE for partials).
2. Include totals, status (serialized_data->>'internal_status'), created_at.
3. Link it: /admin/order/{id}/view.
