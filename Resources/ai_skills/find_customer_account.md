---
description: 'Use when looking up a customer account or customer user (by name, email, id).'
---
1. Try find_entity first; else sql_query oro_customer / oro_customer_user with ILIKE.
2. Show parent account (parent_id), owner, enabled/confirmed flags for users.
3. Links: /admin/customer/view/{id} and /admin/customer/user/view/{id}.
