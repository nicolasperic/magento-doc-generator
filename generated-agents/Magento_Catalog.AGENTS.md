<!-- agents:module Magento_Catalog schema=1 -->
# Magento_Catalog — agent guide

`magento/module-catalog` · area: global (frontend + adminhtml) · depends: Magento_Eav, Magento_Cms, Magento_Indexer, Magento_Customer

Products and categories. Owns the **product and category EAV entities**, their
repositories and attributes, product types, the media gallery, and the source data
behind the catalog indexers. One of the largest modules (1100+ classes, 81 REST
routes) — most catalog reads/writes pass through it.

## Boundary
- **Owns:** `catalog_product_entity*` and `catalog_category_entity*` (EAV), product
  types, product/category attributes, media gallery, base price, the flat/category
  indexer source data.
- **Does NOT own:** stock (`Magento_CatalogInventory` / MSI); search
  (`Magento_CatalogSearch`); URL rewrites (`Magento_CatalogUrlRewrite`); rule/tier
  pricing (`Magento_CatalogRule`, pricing modules); the EAV engine
  (`Magento_Eav`).

## To change behavior here, use these seams (don't edit core classes)
- **Observe the API data lifecycle:** `magento_catalog_api_data_productinterface_save_before/after`,
  `…_delete_before/after`, `…_load_after` (and the `categoryinterface` equivalents).
  These fire for repository saves — prefer them over the legacy
  `catalog_product_save_*` events.
- **Plug / override preference:** `Api\ProductRepositoryInterface → Model\ProductRepository`,
  `Api\CategoryRepositoryInterface → Model\CategoryRepository` are DI-bound.
- **Config:** `catalog/*` (see `inline_docs`).

## Key API (stable contracts)
| Interface | Role |
|---|---|
| `Api\ProductRepositoryInterface` | load/save/list products |
| `Api\CategoryRepositoryInterface` / `Api\CategoryLinkManagementInterface` | categories & product↔category links |
| `Api\ProductAttributeRepositoryInterface` | product attributes |
| `Api\BasePriceStorageInterface` / `Api\SpecialPriceStorageInterface` | bulk price I/O |
| `Api\AttributeSetRepositoryInterface` | attribute sets |

## Wiring (auto-extracted — ground truth)
**Observes (28):** the `magento_catalog_api_data_{product,category}interface_{save,delete,load}_{before,after}` entity-lifecycle events → EAV save/delete/load handlers (+ more).

**Plugins (46):** `Theme Html\Topmenu` ← Topmenu · `Mview View\StateInterface` ← MviewState · catalog-on-visitor/website plugins (+ more).

**Preferences:** 102 — product/category repositories, attribute management and data interfaces → models.

**Tables (63):** `catalog_product_entity` (+ `_datetime/_decimal/_int/_text/_varchar`, `_gallery`), `catalog_category_entity` (+ value tables), link/index tables.

**Cron (5):** `catalog_index_refresh_price`, `catalog_product_flat_indexer_store_cleanup`, `catalog_product_outdated_price_values_cleanup`, `catalog_product_frontend_actions_flush`, `catalog_product_attribute_value_synchronize`.
**Web API:** 81 routes.  **GraphQL:** none (see `Magento_CatalogGraphQl`).

## Gotchas / rules
- **Product and category are EAV entities.** Attribute values live in the
  `catalog_product_entity_<type>` value tables, not columns on the base table. Add
  attributes via `eav_setup`, read/write through the repository & attribute APIs —
  never raw SQL against value tables.
- **Catalog is heavily indexed** (price, EAV, category-product, flat). A write that
  bypasses the repository won't reindex, so the storefront goes stale. Go through
  the repositories/APIs so the Mview chain schedules reindex; never write
  `*_index`/flat tables directly.
- **Product type matters.** Simple/configurable/bundle/grouped/virtual/downloadable
  are a type hierarchy with different save, price and stock behavior — branch on
  type rather than assuming simple.
- **Flat catalog is legacy** and off by default on modern stores; don't rely on
  flat tables existing.
