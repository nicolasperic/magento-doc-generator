# Admin config field help — content format

Help text for admin **Stores → Configuration** fields, generated from the
`doc.config[]` records that `extract.php` pulls out of `system.xml`. Two tiers per
field:

- **`comment`** — one short sentence (≤ 160 chars). The popover card, and a
  candidate for the field's `<comment>` in `system.xml`. Says what the setting
  does and what turning it on / each option means.
- **`modal`** — a few sentences or bullets of Markdown. What it controls, when
  you'd change it, what the options mean, the scope, and any dependency. Shown
  when the reader opens "more".

Same discipline as the rest of this toolkit: **facts are extracted, prose is
generated.** The narrative layer writes *from* the field record and never invents
a fact about it.

## The record a writer is given

Everything in a `doc.config[]` entry: `path`, `elementId`, `sectionLabel`,
`groupLabel`, `label`, `type`, `sourceModel`, `backendModel`, `scope`,
`depends[]`, and the existing `comment` (if any).

## Grounding rules

1. **Never state a default or an option that isn't in the record.** If
   `sourceModel` is a known enum (e.g. `…\Source\Yesno`), you may name the
   options (Yes/No). If the source model is unknown, describe the *kind* of
   choice, not specific values.
2. **Use the field's own words** — its label and the section/group breadcrumb —
   not generic Magento phrasing.
3. **Honour dependencies.** If `depends[]` is present, say the precondition
   ("Applies only when … is enabled").
4. **Note non-obvious scope.** If a field is `website`/`store` scoped, say it can
   differ per store view; skip this for plain `default` fields.
5. **Abstain when you can't be useful.** If the record doesn't let you say
   anything true beyond restating the label, emit nothing for that field. Coverage
   is not the goal — usefulness is. (This mirrors `MageOS_InlineDocs`: a field with
   no note gets no marker.)
6. **No filler.** Never "This setting allows you to…", never marketing. Present
   tense, plain voice.

## Output: `help.json`

An array the `apply` step merges back into `doc.config[]` by `elementId`:

```jsonc
[
  {
    "elementId": "contact_contact_enabled",     // join key, required
    "comment": "Turns the storefront Contact Us form and its /contact route on or off.",
    "modal": "Controls whether the **Contact Us** page and form are available on the storefront…",
    "confidence": "high",                        // high | medium | low
    "basis": ["label", "sourceModel:Yesno", "group:Contact Us"]  // facts used — audit trail
  }
]
```

- `comment` / `modal` omitted (or null) for a field the writer chose to **abstain**
  on — `apply` skips it, leaving the field unhelped rather than wrong.
- `basis` is the honesty trail: which extracted facts the prose stands on.

## Pipeline

```
extract.php        system.xml ──▶ doc.config[]                deterministic
config-help.php worklist   doc.config[] ──▶ tasks (undocumented fields only)
(narrate)          tasks ──▶ help.json     prose, from the record only, may abstain
config-help.php apply      doc.config[] + help.json ──▶ enriched doc.config[]
                           (never overwrites a real <comment>; records generatedComment/Modal)
```

The enriched `doc.config[]` then rides the existing `ingest.php` to DocuHub, which
serves it to the `MageOS_InlineDocs` popovers keyed by `elementId`.
