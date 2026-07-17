---
description: 'Use when a product is missing from the storefront or search.'
---
1. sql_query oro_product: status, is_featured, organization, inventory_status via serialized_data.
2. Check oro_inventory_level quantity and product visibility tables (schema_inspector 'visibility').
3. Check website search index presence; suggest reindex if the row is stale.
4. Link /admin/product/view/{id}.
