<!-- agents:index -->
# Mage-OS / Magento module guides — index

Per-module **AGENTS.md** guides for working inside a module. Find the module you
need below and open its guide; each is self-contained (boundary, extension seams,
wiring, gotchas). Grounded in extracted code facts — see
[AGENTS_TEMPLATE.md](../docs/AGENTS_TEMPLATE.md) for the format.

| Module | What it owns | Guide |
|---|---|---|
| `Magento_Catalog` | Products and categories. | [Magento_Catalog](Magento_Catalog.AGENTS.md) |
| `Magento_CatalogInventory` | Legacy single-source stock: tracks on-hand quantity and in/out-of-stock status for | [Magento_CatalogInventory](Magento_CatalogInventory.AGENTS.md) |
| `Magento_Checkout` | Orchestrates the storefront checkout flow and the cart/mini-cart UI: the | [Magento_Checkout](Magento_Checkout.AGENTS.md) |
| `Magento_Customer` | Customer accounts, addresses, and customer groups. | [Magento_Customer](Magento_Customer.AGENTS.md) |
| `Magento_Payment` | The payment-method abstraction and gateway framework that concrete methods | [Magento_Payment](Magento_Payment.AGENTS.md) |
| `Magento_Quote` | The shopping cart. | [Magento_Quote](Magento_Quote.AGENTS.md) |
| `Magento_Sales` | The placed order and its documents: order, invoice, shipment, credit memo — | [Magento_Sales](Magento_Sales.AGENTS.md) |
| `Magento_Tax` | Tax classes, rules, rates and calculation. | [Magento_Tax](Magento_Tax.AGENTS.md) |

_8 modules documented. More core modules to follow._
