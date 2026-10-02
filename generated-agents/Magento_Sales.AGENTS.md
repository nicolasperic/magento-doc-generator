<!-- agents:module Magento_Sales schema=1 -->
# Magento_Sales — agent guide

`magento/module-sales` · area: global (frontend + adminhtml) · depends: Magento_Rule, Magento_Catalog, Magento_Customer, Magento_Payment, Magento_SalesSequence

The placed **order** and its documents: order, invoice, shipment, credit memo —
each with items, comments and a denormalized grid. Owns order status/history, order
payment, order emails and reorder. Large surface (45 REST routes, 14 cron jobs).

## Boundary
- **Owns:** `sales_order*`, `sales_invoice*`, `sales_shipment*`, `sales_creditmemo*`
  and their `*_grid` tables; order state/status transitions; order/invoice/etc.
  emails; reorder.
- **Does NOT own:** the cart/quote before the order (`Magento_Quote`); payment
  method behavior (`Magento_Payment` + methods); the catalog (`Magento_Catalog`);
  the order-number sequence (`Magento_SalesSequence`); cart price rules
  (`Magento_SalesRule`).

## To change behavior here, use these seams (don't edit core classes)
- **Observe:** `sales_order_place_after`; the `sales_order_*_process_relation`
  events (grid sync); `sales_order_save_before`.
- **Plug / override preference:** the invoice/shipment/creditmemo repositories and
  management interfaces are DI-bound (e.g.
  `Api\OrderRepositoryInterface → Model\OrderRepository`).
- **Entities:** build invoices/shipments/credit memos through their
  `*ManagementInterface` / factories, not by writing rows.

## Key API (stable contracts)
| Interface | Role |
|---|---|
| `Api\OrderRepositoryInterface` / `Api\OrderManagementInterface` | load/save orders; hold/cancel/notify |
| `Api\InvoiceRepositoryInterface` / `Api\InvoiceManagementInterface` | invoices |
| `Api\ShipmentRepositoryInterface` | shipments |
| `Api\CreditmemoRepositoryInterface` / `Api\CreditmemoManagementInterface` | credit memos (refunds) |
| `Api\TransactionRepositoryInterface` | payment transactions |

## Wiring (auto-extracted — ground truth)
**Observes (26):** `sales_order_place_after` → AddVatRequestParamsOrderComment ·
`sales_order_process_relation` → SalesOrderIndexGridSyncInsert ·
the invoice/shipment/creditmemo `*_process_relation` and `*_delete_after` → grid
sync insert/remove observers (+ more).

**Plugins (24):** the `Order\{Invoice,Creditmemo,Shipment,History,Print*}` controllers
← Authentication (order-access guard) (+ more).

**Preferences:** 117 — order/invoice/shipment/creditmemo data, comment, item and
search-result interfaces → their models/collections.

**Tables (37):** `sales_order` (+ `_grid`, `_address`, `_item`, `_payment`,
`_status_history`), `sales_invoice*`, `sales_shipment*`, `sales_creditmemo*`.

**Cron (14):** `sales_clean_quotes`, `sales_clean_orders`, and the
`aggregate_sales_report_*` report builders.
**Web API:** 45 routes.  **GraphQL:** none (see `Magento_SalesGraphQl`).

## Gotchas / rules
- **`*_grid` tables are denormalized read models**, kept in sync by the
  `sales_*_process_relation` observers. Never write a grid table directly — change
  the main entity and the grid follows; a direct grid write will be overwritten or
  diverge.
- **Don't flip order state/status by writing columns.** State transitions and the
  invoice→capture / creditmemo→refund flows run through the management/service
  classes (which also move stock and totals). Writing `state`/`status` skips that.
- **Order numbers come from `Magento_SalesSequence`** — never invent an
  `increment_id`; let the sequence allocate it.
- Invoice, shipment and credit memo are **separate entities** off the order, each
  with its own repository and lifecycle — an order isn't "paid" or "shipped" until
  the corresponding document exists.
