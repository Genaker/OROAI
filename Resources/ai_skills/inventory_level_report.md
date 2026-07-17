---
description: 'Use for stock/inventory questions (what is in stock, low stock, by warehouse).'
---
1. schema_inspector oro_inventory_level first.
2. Join oro_product (sku/name) and oro_warehouse (name) on their ids.
3. For low stock: WHERE quantity < threshold ORDER BY quantity ASC LIMIT 25.
