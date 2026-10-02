# generated-agents

Per-module **AGENTS.md** — agent-facing guides for working *inside* a Magento /
Mage-OS module, following [`../docs/AGENTS_TEMPLATE.md`](../docs/AGENTS_TEMPLATE.md).
Bundled here in one place (not scattered at each module's root).

Each file is grounded in the module's `module.doc.json`: the **Wiring** section is
extracted (observers, plugins, preferences, tables, web API), the prose
(Boundary, seams, gotchas) is written from those facts — never invented. The
**Config** section reuses the `inline_docs` help.

## Scope

Started with the **high-traffic** modules an agent most often needs to change:

| Module | Shape it demonstrates |
|---|---|
| `Magento_CatalogInventory` | stock + event-chain + index-table traps |
| `Magento_Quote` | the cart data model + submit service + masked guest id |
| `Magento_Checkout` | flow orchestration with no persistence of its own |
| `Magento_Customer` | an EAV entity + large repository/API surface |

More core modules (Catalog, Sales, Payment, Tax, …) to follow.

## Regenerating

```bash
php extract.php <module-dir> > generated-help/<dir>.doc.json   # wiring (ground truth)
#   → write generated-agents/<Vendor_Module>.AGENTS.md from the JSON, per AGENTS_TEMPLATE.md
```
