# generated-help

Curated admin config-field help, one `<module-dir>.help.json` per module, written
by the narrative layer against the `worklist` tasks (see
[`../docs/CONFIG_HELP.md`](../docs/CONFIG_HELP.md)). These files are the valuable,
version-controlled artifact — the prose, grounded in extracted facts.

- `*.help.json` — **tracked.** Each entry: `elementId`, `comment`, `modal`,
  `confidence`, `basis`.
- `*.doc.json` — **gitignored.** Regenerable extracts (`php extract.php …`).
- `PROGRESS.tsv` — inventory + status across all core modules with a `system.xml`.

## Workflow per module

```bash
php extract.php <module-dir> > generated-help/<dir>.doc.json
php config-help.php worklist generated-help/<dir>.doc.json     # tasks to write
#   → write generated-help/<dir>.help.json (prose; abstain where not useful)
php config-help.php apply generated-help/<dir>.doc.json \
    generated-help/<dir>.help.json -o /tmp/<dir>.enriched.json  # verify 0 unknown ids
```

The enriched `doc.config[]` is what ships to Magento (as `inline_docs.xml`, built
in-module) so the admin renders help with no runtime HTTP.
