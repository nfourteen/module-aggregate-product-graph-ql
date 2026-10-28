# Nfourteen_AggregateProductGraphQl

Storefront GraphQL support for the `aggregate` product type from [`Nfourteen_AggregateProduct`](https://github.com/nfourteen/module-aggregate-product), across catalog, cart, wishlist, and sales documents.

> [!WARNING]
> **Beta - not for production use.** This module is part of the Aggregate Product suite, which is in beta. It has no tagged releases yet, known bugs are still being fixed, and behavior, configuration and stored data may change between commits without an upgrade path. Use it in development or staging environments only.

## Schema

| Type | Purpose |
| --- | --- |
| `AggregateProduct` | `ProductInterface` with an `items` field returning `[AggregateItem]` (child `product` + `qty`) |
| `AggregateCartItem` | `CartItemInterface` exposing `aggregate_options` |
| `AggregateWishlistItem` | `WishlistItemInterface` |
| `AggregateOrderItem`, `AggregateInvoiceItem`, `AggregateShipmentItem`, `AggregateCreditMemoItem` | Sales document items exposing `aggregate_options` (child `product_name` + `quantity`) |

`addAggregateProductsToCart(input: AddAggregateProductsToCartInput)` adds aggregates to a cart, taking the same shape as `addSimpleProductsToCart`.

## Installation

Part of the Aggregate Product suite. Install it through [`nfourteen/aggregate-product-metapackage`](https://github.com/nfourteen/aggregate-product-metapackage), whose README covers the Composer repositories these packages need and the MSI requirement. Requires PHP >= 8.3 and Magento Open Source 2.4.x with MSI enabled.
