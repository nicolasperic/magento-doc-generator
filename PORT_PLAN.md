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
- [ ] **USER REVIEW GATE** — verify the card in-browser; approve before the prose fan-out.
- [ ] **Tier 2 prose fan-out** — generate clean summary + rich usage + per-value DESCRIPTIONS
      for the non-pilot fields (values now have id+label; descriptions are what's left). Batched.

## Rendering contract (block keys JS consumes)
summary, usage(HTML, client-sanitized), values[{id,label,description}], technical[{label,value}],
path (pill), moduleName/title (footer), url (link, gated). Title heading = field's own DOM label.

## Deploy note
Converter output shape changed → `bin/magento cache:flush` + redeploy adminhtml static after pulling.
