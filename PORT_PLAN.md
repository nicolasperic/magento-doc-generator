# Docs-card port (ac-sandbox → MageOS_InlineDocs)

Porting the InkThread/ac-sandbox structured "docs card" into our module, keeping our
flat element-id addressing and our generation pipeline. Chosen scope: **Tier 1 + Tier 2**.

## Content model (new, flat-addressed)

```xml
<field id="<elementId>" module section path url>
    <summary>one-sentence plain-text lead</summary>          <!-- required; was <comment> -->
    <usage><![CDATA[ inline HTML body ]]></usage>            <!-- optional; was <modal> -->
    <values>                                                 <!-- optional -->
        <value id="1" label="Yes">what Yes does</value>
    </values>
    <technical>                                              <!-- optional; all deterministic -->
        <config_path/> <default_value/> <scope/> <source_model/>
        <backend_model/> <frontend_model/> <validation/> <depends/> <consumed_by/>
    </technical>
</field>
```

Render order in popover: title (field label, from DOM) + config-path pill (`path` attr) +
bold `summary` + `usage` + "Accepted values" + "Technical details" + footer (module + doc link).

## Work items

- [x] Read both implementations end to end
- [x] **Foundation** — XSD, Converter, InlineDocProvider, JS render (card + sanitizer), CSS
- [x] **Deterministic enrichment** — emit joins technical facts + path from *.doc.json;
      summary←comment, usage←modal (MD→HTML). All 7,394 fields are cards (no LLM). Validates, 4.5M.
- [x] **Tier 2 pilot** — Catalog > Inventory (15 fields) regenerated in generated-help/rich/
      catalog-inventory.rich.json: clean summary + rich-HTML usage + per-value descriptions.
      Values grounded by reading the real source classes (Yesno, NotAvailableMessage, Backorders).
- [x] **Live extraction + deterministic backfill** — extract-config-live.php now also resolves
      Field::getOptions(); emit joins it + config.xml defaults. Across all 7,394 fields:
      +6,342 path, +6,259 scope, +5,356 models, +4,321 default, +483 validation, +4,934 values.
      Values capped at 10 options and filtered by a denylist of store-data sources (customer
      group / CMS page / tax class / admin theme correctly skipped). Runtime smoke test passes.
- [x] **Per-value descriptions (reusable)** — generated-help/value-descriptions.json: 65 semantic
      source models described once each, reused across every field (Yes/No + PayPal visual styling
      skipped on purpose). Wired into emit's value backfill; propagates to ~500 fields' value lists.
- [x] **Tier 2 summary/usage — priority sections done** — lifted to full depth, section by section,
      each in generated-help/rich/<section>.rich.json (grounded by reading the real source classes):
      Inventory (pilot), Sales (37), Catalog (103), Customer (65), Checkout (23), Payment core-offline
      (44), Shipping+carriers (192), General (24), Web (38). ~535 fields hand-authored.
      Scope notes: Payment excludes PayPal/Braintree + region-multiplied payment_* (kept brief) and
      the third-party Orangecat group; dynamic store-data selects stay value-less throughout.
- [ ] **Remaining sections** keep their original brief-but-grounded notes + the card. Lift any to
      full depth on demand with the same flow.

## Mage-OS review (Paul H.) — direction correction
Feedback: the card carried too much low-signal, drift-prone content ("battle AI slop"). Specifics:
accepted-values just restated the dropdown and showed internal ids (0/1); value descriptions would
drift from the real source models; technical block (source/backend model, config path shown twice)
got too much visual weight; uppercase headers + uppercased module string aren't in the design system.

**Tier 1 — DONE (structural simplification):**
- [x] Dropped `<values>` and `<technical>` from XSD, Converter, InlineDocProvider, JS, CSS, emitter.
- [x] Config path → a copy-to-clipboard button in the card header (was a pill shown twice).
- [x] Module footer shown verbatim (`Magento_CatalogInventory`), no `text-transform: uppercase`.
- [x] Removed uppercase section headers (gone with the sections). Card = title + summary + usage + footer.
- [x] inline_docs.xml regenerated: 7,397 fields, summary + usage + path only. 8.0M → 4.4M. Validates.
- Deleted dead generator inputs' effect: value-descriptions.json / values/ no longer emitted (kept on
  disk but unused); emitter simplified to path-only live backfill.

**Tier 2 — prose quality pass (pending, gated on Paul's bar):**
- [ ] Tighten each summary/usage to only the non-obvious fact (e.g. show_out_of_stock = direct-URL
      access when off; drop what the label already implies). Draft 3-4 before/after samples for Paul
      to confirm the bar before the full pass.

**Future (v2, separate feature):** rich dropdown — put option descriptions in the actual <select>
instead of a popover (frontend-render territory; only helps dropdowns). Don't let it block the above.

## Rendering contract (block keys JS consumes)
summary, usage(HTML, client-sanitized), values[{id,label,description}], technical[{label,value}],
path (pill), moduleName/title (footer), url (link, gated). Title heading = field's own DOM label.

## Deploy note
Converter output shape changed → `bin/magento cache:flush` + redeploy adminhtml static after pulling.
