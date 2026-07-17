---
description: 'Use when asked what a user/role can access or why access is denied.'
---
1. user_info for the user; note roles.
2. sql_query oro_access_role / oro_user_access_role to list role assignments.
3. ACL details live in acl_* tables — check acl_entries for the class of interest.
4. Link /admin/user/view/{id} and /admin/user/role/view/{roleId}.
