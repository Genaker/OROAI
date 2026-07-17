---
description: 'Use when a user cannot log in (backend or storefront).'
---
1. sql_query the user row (oro_user or oro_customer_user): enabled, confirmed, failed_login_count, auth_status.
2. log_reader for 'authentication' / the username around the reported time.
3. Check password-reset and locked states; suggest the admin reset path.
