---
description: 'Use when asked why a product has a certain price or which price list applies.'
---
1. schema_inspector tables matching 'price_list'.
2. sql_query oro_price_product for the product across price lists; note currency/qty tiers.
3. Check combined price list activation rows for the website/customer.
4. Report the winning price list chain.
