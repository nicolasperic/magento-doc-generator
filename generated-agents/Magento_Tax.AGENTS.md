<!-- agents:module Magento_Tax schema=1 -->
# Magento_Tax — agent guide

`magento/module-tax` · area: global (frontend + adminhtml) · depends: Magento_Catalog, Magento_Checkout, Magento_Customer, Magento_Directory, Magento_User

Tax **classes, rules, rates and calculation**. Owns the rate resolution and the
applied-tax detail contributed to cart/order totals, plus tax reporting. Tax
*display* settings (incl/excl) also live here but are separate from calculation.

## Boundary
- **Owns:** `tax_class`, `tax_calculation_rule`, `tax_calculation_rate`,
  `tax_calculation` (the resolver join), rate titles, tax report aggregation; the
  tax total collector's applied-tax details.
- **Does NOT own:** the totals it contributes to (`Magento_Quote` / `Magento_Sales`
  own the order/quote totals); prices themselves (`Magento_Catalog`);
  countries/regions (`Magento_Directory`).

## To change behavior here, use these seams (don't edit core classes)
- **Plug:** `Sales Address\ToOrder` ← ToOrderConverter (carry tax onto the order);
  `Quote Cart\TotalsConverter` ← GrandTotalDetailsPlugin;
  `Api\OrderRepositoryInterface` ← AddTaxesExtensionAttribute.
- **Override preference:** `Api\TaxCalculationInterface → Model\Calculation`,
  `Api\TaxRuleRepositoryInterface`, `Api\TaxRateRepositoryInterface`.
- **Observe:** `customer_address_save_after` (AfterAddressSaveObserver),
  `catalog_product_view_config`.
- **Config:** `tax/*` (28 fields — classes, calculation method/base, display; see
  `inline_docs`).

## Key API (stable contracts)
| Interface | Role |
|---|---|
| `Api\TaxCalculationInterface` | calculate tax for a quote/items |
| `Api\TaxRuleRepositoryInterface` | tax rules |
| `Api\TaxRateRepositoryInterface` | tax rates |
| `Api\TaxClassRepositoryInterface` | tax classes |
| `Api\OrderTaxManagementInterface` | applied taxes for a placed order |

## Wiring (auto-extracted — ground truth)
**Observes (4):** `customer_address_save_after` → AfterAddressSaveObserver ·
`customer_data_object_login` → CustomerLoggedInObserver ·
`catalog_product_view_config` → UpdateProductOptionsObserver ·
`catalog_product_option_price_configuration_after` → GetPriceConfigurationObserver.

**Plugins (7):** `Sales Address\ToOrder` ← ToOrderConverter ·
`Quote Cart\TotalsConverter` ← GrandTotalDetailsPlugin ·
`Api\OrderRepositoryInterface` ← AddTaxesExtensionAttribute ·
`View\Layout` ← DepersonalizePlugin (+ more).

**Preferences:** 29 — calculation, rule/rate/class repositories and tax-detail data
interfaces → models.

**Tables (7):** `tax_class`, `tax_calculation_rule`, `tax_calculation_rate`,
`tax_calculation`, `tax_calculation_rate_title`, `tax_order_aggregated_created/updated`.

**Cron (1):** `aggregate_sales_report_tax_data`.
**Web API:** 15 routes.  **GraphQL:** none.

## Gotchas / rules
- **Tax is computed by the totals collector chain**, as a quote total that
  contributes applied-tax details — it isn't a standalone stored amount until the
  order. Don't compute tax ad hoc; go through `TaxCalculationInterface` /
  the collector so rounding and display settings stay consistent.
- **The effective rate is a resolution**, not a single value: (customer tax class ×
  product tax class × rate-by-destination) via `tax_calculation`. Changing one rate
  row can affect many rule combinations.
- **Display ≠ calculation.** The `tax/*_display_*` settings (incl/excl/both) change
  only how prices are *shown*, not what is charged. Don't change display to fix a
  charged-amount problem.
- `DepersonalizePlugin`: tax can be customer-group-specific, which matters for
  full-page-cache correctness.
