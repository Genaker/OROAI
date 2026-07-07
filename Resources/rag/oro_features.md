# OroCommerce Features Guide

## Customer User Management
Customer users represent the people who log into the storefront. Each customer user belongs to a Customer (company).
To manage customer users: navigate to Customers > Customer Users (/admin/customer/user/).
Key fields: email, first name, last name, roles, customer, enabled status.
Entity: Oro\Bundle\CustomerBundle\Entity\CustomerUser

## Order Management
Orders represent completed purchases. Each order has line items, shipping info, payment status.
To view orders: navigate to Sales > Orders (/admin/order/).
Key entity: Oro\Bundle\OrderBundle\Entity\Order
Order statuses are stored via internal_status enum.

## Product Management
Products are the items available for sale. They can have variants, images, descriptions, and prices.
Navigate to Products > Products (/admin/product/).
Entity: Oro\Bundle\ProductBundle\Entity\Product

## Shopping Lists
Shopping lists are the B2B equivalent of shopping carts. Users can have multiple shopping lists.
Navigate to Sales > Shopping Lists (/admin/shopping-list/).

## Price Lists
Price lists control product pricing. Multiple price lists can be assigned per customer/website.
Navigate to Sales > Price Lists (/admin/pricing/price-list/).

## Shipping Configuration
Shipping rules define which shipping methods are available and their conditions.
Configure at System > Shipping Rules (/admin/shipping-rule/).

## Tax Configuration
Tax rules connect customer tax codes, product tax codes, and tax jurisdictions.
Configure at System > Tax Rules (/admin/tax/rule/).

## Web Catalogs
Web catalogs organize content and products for the storefront navigation.
Navigate to Marketing > Web Catalogs (/admin/web-catalog/).

## Workflows
OroCommerce uses workflows to manage entity lifecycle (order processing, checkout, etc.).
Workflows are configured at System > Workflows (/admin/workflow/definition/).
