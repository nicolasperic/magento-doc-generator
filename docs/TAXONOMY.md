# Structure Labels — Controlled Vocabulary

Structure labels are the **nouns** of a module (what it's *about* / what it's *coupled to*),
complementing **components** (the verbs — what it *does*: observers, cron, graphql…).

Design principle: **cohesion → Domain**, **coupling → Integration**, plus **Status** (lifecycle).
Tech surface (GraphQL, RabbitMQ, CLI, Cron, Web API, DB Schema) is **not** duplicated here —
it lives in the component facets, which are extracted from code and never hand-maintained.

Rules:
- **Domain**: 1–2 per module (its reason to exist). More than 2 = probably too coarse a module or a wrong split.
- **Integration**: as many external systems as it is directly coupled to (0 is fine).
- **Status**: only when it applies (Legacy / Stub).

Domains are the one facet you curate by hand — they encode business meaning that isn't
recoverable from code. Integration and Status are derived automatically.

## Family A — Domain

The domain list is **yours to define**: it should read like the table of contents of your
business, not a generic Magento glossary. Keep it to 15–20 labels; if you need more, the
labels are probably too specific. A typical commerce build lands somewhere near:

| Label | Means |
|---|---|
| Checkout | cart, checkout, quotes, multishipping, checkout messaging |
| Customer | account/identity, registration, sync, anonymization |
| Orders | order lifecycle: create/edit/cancel/import, invoices, credit memos, increment ids |
| Catalog & Products | products, attributes, catalog GraphQL, item options, feeds |
| Promotions & Coupons | coupons, cart price rules, promo codes, sales-rule tweaks |
| Payments | payment methods/gateways, tokenization, billing address handling |
| Refunds | refund workflows, chargebacks |
| Fulfillment | warehouse hand-off, fulfillment decisions, tracking import |
| Shipping | shipping rates/carriers/cost, shipment records + admin |
| Inventory | stock, reservations, out-of-stock substitution |
| Notifications | event + email notifications, newsletter, preference center |
| Tax | tax engines and tax-specific product handling |
| Analytics & Marketing | tag managers, personalization, reviews, consent |
| Platform / Infra | queues, migration tooling, base integrations, theme, security, admin utilities |

Add the labels your own build actually needs (a subscription business needs `Subscription`;
a B2B build needs `Company / B2B`), and drop the ones it doesn't.

Curate the mapping in `taxonomy.php` (start from `taxonomy.example.php`):

```php
'domains' => [
    'module-checkout-express' => ['Checkout'],
    'module-customer-sync'    => ['Customer', 'Platform / Infra'],
],
```

`derive_labels.php` prints every module missing a domain as `UNCOVERED`, so the curation
list stays honest as modules are added.

## Family B — Integration (external coupling)

Emitted automatically from two signals, both configured in `taxonomy.php`:

```php
'integrations' => [
    'Stripe' => ['stripe/stripe-php', '~stripe'],
],
```

- a plain needle matches against the module's composer `require` keys
- a `~needle` matches against the module **directory name**, which catches integrations
  that are wired by config or HTTP rather than a package dependency

## Family C — Status

- **Legacy** — being decommissioned, or one-time migration tooling. Listed in `taxonomy.php`.
- **Stub/Placeholder** — module registration only (no classes yet); consolidation candidates.
  Detected automatically from `stats.classes === 0`.

## Derivation

| Facet | Source | Maintenance |
|---|---|---|
| Integration | composer `require` + dirName signals | fully automatic |
| Status | `legacy` list + `stats.classes == 0` | one small list |
| Domain | curated `dirName → domains` map | human-reviewed |
