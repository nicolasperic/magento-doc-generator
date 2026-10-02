# generated-agents

Per-module **AGENTS.md** — agent-facing guides for working *inside* a Magento /
Mage-OS module, following [`../docs/AGENTS_TEMPLATE.md`](../docs/AGENTS_TEMPLATE.md).
Bundled here in one place (not scattered at each module's root).

Each file is grounded in the module's `module.doc.json`: the **Wiring** section is
extracted (observers, plugins, preferences, tables, web API), the prose
(Boundary, seams, gotchas) is written from those facts — never invented. The
**Config** section reuses the `inline_docs` help.

## Distribution

The guides are authored here in one place (easy to generate and maintain), but the
**unit is one `AGENTS.md` per module** — placed at each module's own root, where an
agent working in that module finds it. [`INDEX.md`](INDEX.md) is the small pointer:
an agent reads the index, finds the module it needs, and opens that module's guide.
A concatenated mega-doc is deliberately avoided — too large to load, too hard to
keep true. (A deploy step places each file at `<module>/AGENTS.md` and the index at
the project root; until then, this bundle is the source of truth.)

## Scope — high-traffic first

The modules an agent most often needs to change. 8 done:

| Module | What it covers |
|---|---|
| `Magento_Catalog` | product/category EAV, indexing, API data lifecycle |
| `Magento_Sales` | orders + invoice/shipment/creditmemo, grid read-models |
| `Magento_Customer` | customer EAV entity + repository/API surface |
| `Magento_Quote` | the cart model + submit service + masked guest id |
| `Magento_Checkout` | flow orchestration with no persistence of its own |
| `Magento_CatalogInventory` | stock + event-chain + index-table traps |
| `Magento_Payment` | payment-method abstraction + gateway framework |
| `Magento_Tax` | tax classes/rules/rates + calculation vs display |

More core modules (SalesRule, ConfigurableProduct, Eav, Directory, Cms, …) to
follow.

## Regenerating

```bash
php extract.php <module-dir> > generated-help/<dir>.doc.json   # wiring (ground truth)
#   → write generated-agents/<Vendor_Module>.AGENTS.md from the JSON, per AGENTS_TEMPLATE.md
```
