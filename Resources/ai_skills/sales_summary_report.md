---
description: 'Use for revenue/sales totals over a period (per month, per customer, per product).'
---
1. sql_query oro_order: SUM(total_value), COUNT(*) grouped by date_trunc/customer.
2. Filter by created_at range; exclude cancelled via serialized_data status if asked.
3. Present as a table with the period stated.
