# Docs App — API Contract (v1)

The generator and the Confluence-style UI both talk to these endpoints. The design
keeps **generated** content and **human** content in separate fields so they can never
clobber each other, and uses two different concurrency guards:

- **Generated content** is guarded by a **timestamp** (`generatedAt`). A regeneration is
  only applied if it is *newer* than what is stored. An older/stale generation is rejected
  (HTTP 409) unless `force=true`. → *"don't override if the incoming timestamp is older."*
- **Human content** is guarded by an **optimistic version** (`baseVersion`). A UI save that
  was based on a stale version is rejected (HTTP 409) so two editors can't silently
  overwrite each other.

## Page model

```jsonc
{
  "id": "uuid",
  "moduleName": "Vendor_AutoCustomerRegistration", // stable upsert key
  "dirName": "module-auto-customer-registration",
  "title": "Auto Customer Registration",
  "kind": "module",                     // "module" | "runbook" | "concept"
  "generated": {
    "markdown": "<!-- doc:generated ... -->\n# ...",
    "hash": "04ae4002380c",             // sha1(inner block), from the generator
    "generatedAt": "2026-08-31T18:20:00Z"
  },
  "manual": {
    "markdown": "## Team Notes\n- ...",  // human-owned; generator never writes this
    "version": 7,                        // bumped on every UI save
    "humanUpdatedAt": "2026-08-31T19:05:00Z",
    "updatedBy": "user@example.com"
  },
  "doc": { /* full module.doc.json: classes, wiring, stats */ },
  "edges": [ { "kind": "plugin", "from": "...", "to": "..." } ],
  "contentUpdatedAt": "2026-08-31T19:05:00Z"
}
```

---

## 1. Ingest (generator → app)   `POST /api/docs/ingest`

Upsert a module's generated doc + structured data. **Only touches the `generated` and
`doc`/`edges` fields — never `manual`.**

**Request**
```jsonc
{
  "moduleName": "Vendor_AutoCustomerRegistration",
  "dirName": "module-auto-customer-registration",
  "title": "Auto Customer Registration",
  "kind": "module",
  "markdown": "<!-- doc:generated ... -->...",
  "generatedHash": "04ae4002380c",
  "generatedAt": "2026-08-31T18:20:00Z",
  "doc":  { /* module.doc.json */ },
  "edges": [ /* ... */ ]
}
```

**Timestamp guard (server logic)**
```
existing = findByModuleName(moduleName)
if !existing:                         create → 201 {status:"created"}
elif generatedAt <= existing.generated.generatedAt and !force:
                                      → 409 {status:"stale", storedAt, incomingAt}
else:                                 replace generated/doc/edges, keep manual
                                      → 200 {status:"updated", version: manual.version}
```
Query param `?force=true` overrides the staleness check (e.g. manual re-index).

**Responses**: `201 created` · `200 updated` · `409 stale` (with both timestamps) · `422` invalid.

---

## 2. Read   `GET /api/docs/:moduleName`
Returns the full page model above. `?render=html` returns pre-rendered HTML (generated +
manual stitched, generated block first).

## 3. Save manual section (UI → app)   `PUT /api/docs/:moduleName/manual`
**Request**
```jsonc
{ "markdown": "## Team Notes\n- new note", "baseVersion": 7, "updatedBy": "user@example.com" }
```
**Optimistic guard**
```
if baseVersion != page.manual.version:  → 409 {status:"conflict", currentVersion, currentMarkdown}
else: save, version++ , humanUpdatedAt=now → 200 {version}
```
The UI resolves a 409 by showing a diff against `currentMarkdown` (standard Confluence-style
"someone else edited this page" flow).

## 4. Search / list   `GET /api/docs?q=&kind=&depends_on=&plugs=`
Full-text over title + generated + manual, plus structured filters that hit `doc`/`edges`:
- `depends_on=module-catalog-product` — modules whose composer/DI depends on it
- `plugs=Magento\Sales\Api\OrderManagementInterface` — who plugins a class
- `observes=sales_order_place_before` — who observes an event
Returns ranked hits `[{moduleName, title, kind, snippet, score}]`.

## 5. Graph neighbors   `GET /api/graph/:moduleName`
Returns inbound/outbound edges for the wiring graph view:
```jsonc
{
  "plugsInto":   [ { "target": "...OrderManagementInterface", "via": "RemoveCustomerGuestPaymentData" } ],
  "observedEvents": [ "payment_method_assign_data", "sales_order_place_before" ],
  "dependsOn":   [ "module-payment-gateway", "module-customer" ],
  "dependedOnBy":[ /* reverse edges, computed app-side across all ingested modules */ ]
}
```

## 6. Bulk ingest (batch generator run)   `POST /api/docs/ingest/bulk`
Array of ingest payloads; same per-item guard; returns per-item `{moduleName, status}`.
Use after a full-repo scan of every module in the repo.

---

## Why this specific split

| Concern | Field | Guard | Who writes |
|---|---|---|---|
| API reference + wiring | `generated`, `doc`, `edges` | timestamp (`generatedAt`) — newer wins | generator only |
| Runbooks, gotchas, Slack lore | `manual` | version (`baseVersion`) — optimistic lock | UI only |

Because the two live in different fields, a nightly re-scan can refresh every module's
generated reference **without ever risking** a human's hand-written runbook, and two humans
editing the same page get a clean conflict instead of a silent overwrite. This mirrors the
exact behavior already proven on the filesystem side by `apply-readme.php` (marker splice +
hash/mtime edit-guard).
