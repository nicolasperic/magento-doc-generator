<!-- agents:module Magento_Checkout schema=1 -->
# Magento_Checkout — agent guide

`magento/module-checkout` · area: global (frontend + adminhtml) · depends: Magento_Sales, Magento_Quote, Magento_CatalogInventory

Orchestrates the storefront **checkout flow** and the cart/mini-cart UI: the
shipping- and payment-information management APIs that carry a quote to a placed
order, plus guest-checkout gating. It holds **no persistence of its own** — the
state lives in the quote.

## Boundary
- **Owns:** the checkout step APIs (shipping info, payment info, totals info), the
  guest-checkout gate, onepage/cart/mini-cart presentation and config.
- **Does NOT own:** the quote/cart data model (`Magento_Quote`); the placed order
  (`Magento_Sales`); stock checks (`Magento_CatalogInventory`); payment-method
  behavior (`Magento_Payment` + method modules). Checkout *coordinates* these.

## To change behavior here, use these seams (don't edit core classes)
- **Observe:** `customer_login` (LoadCustomerQuoteObserver merges the cart),
  `customer_logout` (UnsetAllObserver), `sales_quote_save_after`.
- **Plug the guest-checkout boundary:** the `Api\Guest*ManagementInterface` methods
  are each plugged with a `VerifyIsGuestCheckoutEnabled…` plugin — this is where the
  "Allow Guest Checkout" config is *enforced*.
- **Override preference:** `Api\ShippingInformationManagementInterface`,
  `Api\PaymentInformationManagementInterface` (and their Guest variants) are
  DI-bound to their models.
- **Config:** `checkout/options/*`, `checkout/cart/*`, `checkout/sidebar/*`,
  `checkout/payment_failed/*` (see `inline_docs`).

## Key API (stable contracts)
| Interface | Role |
|---|---|
| `Api\PaymentInformationManagementInterface` | save payment info + place the order |
| `Api\ShippingInformationManagementInterface` | set shipping address/method, return payment options |
| `Api\TotalsInformationManagementInterface` | recalculate totals for an address/method |
| `Api\Guest*ManagementInterface` | the guest-session equivalents (masked cart id) |
| `Api\AgreementsValidatorInterface` | validate accepted terms before placing |

## Wiring (auto-extracted — ground truth)
**Observes (5):** `customer_login` → LoadCustomerQuoteObserver ·
`customer_logout` → UnsetAllObserver · `sales_quote_save_after` → SalesQuoteSaveAfterObserver ·
`controller_action_predispatch_checkout_index_index` → CspPolicyObserver

**Plugins (8):** `View\Layout` ← DepersonalizePlugin ·
`Catalog ResourceModel\Customer` ← RecollectQuoteOnCustomerGroupChange ·
`Quote Model\Quote` ← ResetQuoteAddresses · the five
`Api\Guest*ManagementInterface` ← VerifyIsGuestCheckoutEnabled… plugins

**Preferences:** 15 — the shipping/payment information management & data interfaces → their models.

**Tables:** none — checkout state is the quote.
**Web API:** 12 routes.  **Cron:** none.  **GraphQL:** none (see `Magento_CheckoutAgreementsGraphQl` etc.).

## Gotchas / rules
- **No checkout tables.** Don't look for checkout persistence — everything is on
  the quote (`Magento_Quote`) until the order is placed (`Magento_Sales`).
- **Guest checkout is enforced by plugins at the API boundary**, not only in the UI.
  Calling a non-guest management interface, or skipping the `Guest*` path, bypasses
  the "Allow Guest Checkout" gate — route guest traffic through the `Guest*`
  interfaces with the masked cart id.
- `DepersonalizePlugin` on the layout strips customer-specific data for
  full-page-cache correctness; logic that assumes a customer in cached checkout
  blocks will break. Respect the depersonalization boundary.
