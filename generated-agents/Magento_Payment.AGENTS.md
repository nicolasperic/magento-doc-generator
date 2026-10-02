<!-- agents:module Magento_Payment schema=1 -->
# Magento_Payment — agent guide

`magento/module-payment` · area: global · depends: Magento_Store, Magento_Catalog

The payment-method **abstraction and gateway framework** that concrete methods
build on: the method list and config, the Gateway command/handler/validator pools,
and payment additional info. It has **no persistence of its own**.

## Boundary
- **Owns:** the `PaymentMethodInterface` contract, the method list/config, the
  Gateway framework (command pool, value-handler pool, validator pool), payment
  additional info.
- **Does NOT own:** any concrete method — `Magento_OfflinePayments`,
  `Magento_Paypal`, Braintree, etc. are built *on* this; the order/quote payment
  rows (they live on `Magento_Sales` / `Magento_Quote`); the checkout flow
  (`Magento_Checkout`).

## To change behavior here, use these seams (don't edit core classes)
- **Add a method:** implement the **Gateway framework** (configure a `CommandPool`,
  `ValueHandlerPool`, `ValidatorPool` via `di.xml`) — the modern path — rather than
  extending the legacy `AbstractMethod`.
- **Register:** methods are discovered from config (`payment/<code>/model`), not a
  code registry.
- **Observe:** `sales_order_save_before` (SalesOrderBeforeSaveObserver).
- **Override preference:** `Api\PaymentMethodListInterface → Model\MethodList`.

## Key API (stable contracts)
| Interface | Role |
|---|---|
| `Api\PaymentMethodListInterface` | list active/available payment methods |
| `Api\PaymentVerificationInterface` | AVS/CVV verification mapping |
| `Data\PaymentMethodInterface` | a method's data representation |
| `Gateway\CommandInterface` (framework) | execute an authorize/capture/refund command |

## Wiring (auto-extracted — ground truth)
**Observes (2):** `sales_order_save_before` → SalesOrderBeforeSaveObserver ·
`sales_order_status_unassign` → UpdateOrderStatusForPaymentMethodsObserver.

**Plugins (1):** `Checkout LayoutProcessor` ← PaymentConfigurationProcess (injects
method config into the checkout layout).

**Preferences:** 8 — method, method-list, config-factory and gateway result
interfaces → models.

**Tables:** none.  **Cron:** none.  **Web API:** none.  **GraphQL:** none.

## Gotchas / rules
- **No payment tables here.** A payment's data lives on `quote_payment` /
  `sales_order_payment` (owned by `Magento_Quote` / `Magento_Sales`). Don't look for
  payment persistence in this module.
- **Prefer the Gateway framework over `AbstractMethod`.** New methods compose
  commands/handlers/validators via DI; the legacy base class still exists but is the
  old pattern.
- Method availability is resolved at runtime from config + `isAvailable()` checks —
  a method appearing in `payment/*` config is not the same as being offered at
  checkout.
