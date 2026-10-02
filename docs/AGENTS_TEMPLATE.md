# Per-module AGENTS.md — the format

An `AGENTS.md` an agent ingests when it needs to work *inside* a Magento module:
what the module owns, how to change it safely, and the exact wiring — grounded in
`module.doc.json`, so nothing is invented.

This is **not** a human README with a different title. The reader is different, so
the content is different. Below is *what changes* and why, then the section
template and the generation rules.

## What changes vs. a README written for devs/peers

A README motivates and tells a story to someone reading top-to-bottom. An
AGENTS.md is **reference an agent retrieves and acts on**. Concretely:

| Dimension | Human README | Agent AGENTS.md |
|---|---|---|
| **Primary job** | build a mental model, motivate "why" | supply retrievable facts to *act* on |
| **Voice** | narrative, prose-first | declarative; facts & rules, minimal story |
| **Implicitness** | relies on shared context ("the usual plugin") | everything explicit: FQCNs, event names, config paths, file paths |
| **Structure** | linear; skimmed | uniform sections, each self-contained (survives being retrieved out of context) |
| **Ordering** | hook first, details later | identity + boundary first — an agent must know *which* module and its edges before anything |
| **Emphasis** | the novel/interesting; obvious omitted | the *actionable*: extension points and "how to change safely" |
| **Negative space** | empty topics just omitted | state "none" explicitly (no cron / no GraphQL) so the agent stops looking |
| **Examples** | illustrative, can be loose | exact and runnable; a wrong class name is worse than none |
| **Density** | some redundancy aids reading | token-dense, no filler — the agent pays per token |
| **Variety** | author's style welcome | predictable, same shape every module (so retrieval knows where to look) |
| **Ground truth** | prose may drift from code | every fact traces to extracted wiring; **a hallucinated plugin is actively harmful** |

What does **not** change: it is still **natural language**, not a JSON dump. LLMs
reason over prose well, and the relationships ("stock rides the quote-submit event
chain; a failure reverts") are what make the facts usable. The discipline is
*which* prose: dense, exact, boundary- and action-oriented.

One line captures it: a README answers *"what is this and why?"*; an AGENTS.md
answers *"I need to change this — where do I hook, what must I not break, and what
is actually here?"*

## Section template

```markdown
<!-- agents:module Vendor_Module schema=1 -->
# Vendor_Module — agent guide

`vendor/module-name` · area: <global|frontend|adminhtml> · depends: <exact module names>

<1–2 sentences: what it is, in the reader's terms. Note if it's superseded/legacy.>

## Boundary
- **Owns:** <responsibilities, the tables/contracts it is the source of truth for>
- **Does NOT own:** <adjacent concerns + the exact module that does>

## To change behavior here, use these seams (don't edit core classes)
- **Observe:** <events it dispatches that you can hook>
- **Override preference:** <Interface → Impl you'd rebind in di.xml>
- **Plug:** <public methods already plugged / safe to plug>
- **Config:** <system.xml paths it owns — links to inline_docs>

## Key API (stable contracts)
| Interface | Role |
|---|---|

## Wiring (auto-extracted — ground truth)
**Observes (N):** event → Observer; …
**Plugins (N):** Target ← Plugin; …
**Preferences:** N (Interface → Impl)
**Tables:** …     **Cron:** … / none     **Web API:** … / none     **GraphQL:** … / none

## Gotchas / rules
- <constraints an agent must respect when changing this — phrased as rules, not war stories>
```

## Generation rules

Same spine as [`DOC_TEMPLATE.md`](DOC_TEMPLATE.md): **wiring is extracted, prose is
generated.**

1. Never name a class, interface, event, table, config path or dependency that is
   not in `module.doc.json`. A hallucinated seam is the worst failure mode here.
2. The **Wiring** block is rendered directly from the JSON and is the
   machine-checkable part — regenerable and diffable on its own.
3. **Boundary** and **Gotchas** are the highest-value, lowest-coverage sections:
   spend the effort there. "Does NOT own" prevents an agent editing the wrong
   module; a gotcha prevents a plausible-but-wrong change.
4. State **"none"** for empty facets rather than omitting them.
5. The **Config** section reuses the generated field help (`doc.config[]` +
   `inline_docs.xml`) — same data, pointed at the agent.
6. Keep it dense. If the JSON shows a stub (no classes/wiring), say so in a line.

See [`../generated-agents/`](../generated-agents/) for worked examples
(`Magento_CatalogInventory`, `Magento_Quote`, `Magento_Checkout`, `Magento_Customer`).
