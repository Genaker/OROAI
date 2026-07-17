---
description: 'Use when the user asks what a customer ordered or their order history.'
---
1. Resolve the customer via find_entity or sql_query on oro_customer (ILIKE name).
2. sql_query oro_order WHERE customer_id = X ORDER BY created_at DESC LIMIT 20.
3. Show identifier, date, total, status; link each /admin/order/{id}/view.
