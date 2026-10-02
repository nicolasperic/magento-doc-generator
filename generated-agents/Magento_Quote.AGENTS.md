<!-- agents:module Magento_Quote schema=1 -->
# Magento_Quote — agent guide

`magento/module-quote` · area: global · depends: (core)

The shopping cart. Owns the **quote** data model and its lifecycle up to order
submission: cart items and options, billing/shipping addresses, totals collection,
shipping-method estimation, coupons, and the quote→order submit service. A very
large REST surface (63 routes) — most cart operations go through it.

## Boundary
- **Owns:** the `quote*` tables and the APIs over them; totals collection; the
  guest-cart masked-id mapping (`quote_id_mask`); the submit service that turns a
  quote into an order.
- **Does NOT own:** the placed order (`Magento_Sales`); checkout flow/UI
  orchestration (`Magento_Checkout`); stock decrement (`Magento_CatalogInventory`,
  which hooks the submit events); payment method logic (`Magento_Payment`).

## To change behavior here, use these seams (don't edit core classes)
- **Observe:** `sales_quote_address_collect_totals_before` (CollectTotalsObserver),
  `sales_model_service_quote_submit_success` (SubmitObserver creates the order),
  `checkout_cart_product_add_after`.
- **Override preference:** the cart/address/shipping management interfaces are all
  DI-bound, e.g. `Api\CartRepositoryInterface → Model\QuoteRepository`,
  `Api\ShippingMethodManagementInterface → Model\ShippingMethodManagement`.
- **Plug:** `Api\CartRepositoryInterface ← ValidateQuoteOrigOrder`,
  `Model\Quote ← QuoteAddress`.

## Key API (stable contracts)
| Interface | Role |
|---|---|
| `Api\CartManagementInterface` | create a cart, assign a customer, place the order |
| `Api\CartRepositoryInterface` | load/save/list quotes |
| `Api\CartItemRepositoryInterface` | add/update/remove line items |
| `Api\BillingAddressManagementInterface` / `Api\ShippingMethodManagementInterface` | set address / shipping |
| `Api\CouponManagementInterface` | apply/remove a coupon |
| `Api\CartTotalRepositoryInterface` | read collected totals |
| `Model\MaskedQuoteIdToQuoteIdInterface` | resolve a guest cart's masked id → quote id |

## Wiring (auto-extracted — ground truth)
**Observes (5):** `sales_quote_address_collect_totals_before` → CollectTotalsObserver ·
`sales_model_service_quote_submit_success` → SubmitObserver, SendInvoiceEmailObserver ·
`customer_save_after_data_object` → CustomerQuoteObserver ·
`checkout_cart_product_add_after` → SetBasePriceObserver

**Plugins (16):** `Catalog ResourceModel\Product` ← Remove/UpdateQuoteItems ·
`Api\CartRepositoryInterface` ← ValidateQuoteOrigOrder ·
`Api\TierPriceStorageInterface` ← UpdateQuote · `Model\Quote` ← QuoteAddress (+ more)

**Preferences:** 56 — the Cart/Address/ShippingMethod/Totals management & data
interfaces → their models.

**Tables:** `quote`, `quote_address`, `quote_item`, `quote_address_item`,
`quote_item_option`, `quote_payment`, `quote_shipping_rate`, `quote_id_mask`.

**Web API:** 63 routes.  **Cron:** none.  **GraphQL:** none (cart GraphQL lives in `Magento_QuoteGraphQl`).

## Gotchas / rules
- The quote→order transition happens in the **submit service**, on the
  `sales_model_service_quote_submit_*` event chain — `SubmitObserver` creates the
  order on `…_success`. CatalogInventory's subtract/reindex/revert hangs off the
  same chain. Don't place orders by writing order rows directly; go through
  `CartManagementInterface::placeOrder` so the chain runs.
- **Guest carts are addressed by a masked id** (`quote_id_mask`), never the integer
  `quote_id`, in any customer-facing context. Resolve with
  `MaskedQuoteIdToQuoteId`; exposing the integer id is a leak.
- **Totals are only valid after collection.** Reading a total you haven't triggered
  via the collector chain (`collect_totals_before`) returns stale/zero values.
