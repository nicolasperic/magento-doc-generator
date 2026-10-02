<!-- agents:module Magento_CatalogInventory schema=1 -->
# Magento_CatalogInventory — agent guide

`magento/module-catalog-inventory` · area: global · depends: Magento_Catalog

Legacy single-source stock: tracks on-hand quantity and in/out-of-stock status for
catalog products, validates requested quantities, and decrements/reverts stock
across the order lifecycle. On installations with Multi-Source Inventory (MSI,
`Magento_Inventory*`) enabled, MSI becomes the source of truth; this module remains
the stable stock **API surface** and the legacy data model.

## Boundary
- **Owns:** per-product stock quantity & status (the `cataloginventory_stock*`
  tables), requested-quantity validation at add-to-cart and checkout, and stock
  decrement / revert during order placement.
- **Does NOT own:** multi-source / warehouse inventory (`Magento_InventoryApi` and
  friends); the product entity itself (`Magento_Catalog`); the quote/order
  lifecycle (`Magento_Quote` / `Magento_Sales`) — this module only *reacts* to
  their events.

## To change behavior here, use these seams (don't edit core classes)
- **Observe** an event in the order flow it already hooks:
  `sales_model_service_quote_submit_before` (subtract stock),
  `sales_model_service_quote_submit_failure` (revert), `sales_order_item_cancel`
  (return to stock), `catalog_product_save_after` (persist stock item).
- **Override a preference** — the stock API is bound by DI, e.g.
  `Api\StockConfigurationInterface → Model\Configuration`,
  `Api\StockStateInterface → Model\StockState`. Rebind in your `di.xml`.
- **Plug a public method** — e.g. the existing
  `Model\ResourceModel\Product\Collection ← AddStockStatusToCollection`,
  `Model\Product ← AfterProductLoad`.
- **Config:** `cataloginventory/options/*` and `cataloginventory/item_options/*`
  (documented in `inline_docs.xml`). Saving this section dispatches
  `admin_system_config_changed_section_cataloginventory`.

## Key API (stable contracts)
| Interface | Role |
|---|---|
| `Api\StockRegistryInterface` | read/write a product's stock item & status by SKU/id |
| `Api\StockStateInterface` | check whether a requested qty is available |
| `Api\RegisterProductSaleInterface` / `Api\RevertProductSaleInterface` | decrement / restore stock for a sale |
| `Api\StockConfigurationInterface` | resolve effective stock configuration |

## Wiring (auto-extracted — ground truth)
**Observes (13):** `catalog_product_load_after` → AddInventoryDataObserver ·
`sales_quote_item_qty_set_after` → QuantityValidatorObserver ·
`sales_model_service_quote_submit_before` → SubtractQuoteInventoryObserver ·
`sales_model_service_quote_submit_success` → ReindexQuoteInventoryObserver ·
`sales_model_service_quote_submit_failure` → RevertQuoteInventoryObserver ·
`sales_order_item_cancel` → CancelOrderItemObserver ·
`catalog_product_save_before/after` → Process/SaveInventoryDataObserver ·
`admin_system_config_changed_section_cataloginventory` → UpdateItemsStockUponConfigChange, InvalidatePriceIndexUponConfigChange (+4 more)

**Plugins (9):** `ResourceModel\Product\Collection` ← AddStockStatusToCollection ·
`Model\Product` ← AfterProductLoad · `Catalog\...\Product\View` ← ProductView ·
`Catalog\...\Product\Action` ← ReindexUpdatedProducts (+5 more)

**Preferences:** 23 — the Stock/StockItem/StockStatus data, criteria and
collection interfaces → their models.

**Tables:** `cataloginventory_stock`, `cataloginventory_stock_item`,
`cataloginventory_stock_status` (+ `_status_idx`, `_status_tmp`, `_status_replica`
index tables).

**Cron:** none.  **GraphQL:** none.

## Gotchas / rules
- Stock moves on the **quote-submit event chain**: `…submit_before` subtracts,
  `…submit_success` reindexes, `…submit_failure` reverts. Don't decrement stock
  directly — go through `RegisterProductSale` / `RevertProductSale` so the failure
  path stays consistent.
- `*_status_idx`, `*_status_tmp`, `*_status_replica` are **indexer artifacts**.
  Never write them directly; change stock via the API and let the stock-status
  indexer rebuild them.
- With MSI modules enabled the effective source of truth shifts to
  `Magento_Inventory*`. Confirm which stack is active before changing decrement or
  availability logic — editing here may have no effect, or a divergent one.
