<?php
/**
 * Emits a single inline_docs.xml from the generated help plus the extracted facts,
 * for the MageOS_InlineDocs module to serve inside Magento (no doc host).
 *
 * Each <field> is a documentation card:
 *   - <summary>   the one-line note (generated `comment`)
 *   - <usage>     the longer note (generated `modal`), converted from the small
 *                 Markdown subset to the inline HTML the card renders
 *   - <values>    per-option meaning (merged from values/<elementId>.json when present)
 *   - <technical> config_path / scope / source & backend & frontend model / depends,
 *                 all looked up from the matching <module>.doc.json (extracted facts)
 * plus module, section label, and the admin path (section/group/field) for the pill.
 *
 * Prose is generated; everything in <technical>, the path, module and section are
 * extracted facts, not invented.
 *
 * Usage:
 *   php emit-inline-docs.php [output.xml]
 *   (default output: ../mage-os/app/code/MageOS/InlineDocs/etc/inline_docs.xml)
 */
declare(strict_types=1);

$dir = __DIR__ . '/generated-help';
$valuesDir = $dir . '/values';
$out = $argv[1] ?? (__DIR__ . '/../mage-os/app/code/MageOS/InlineDocs/etc/inline_docs.xml');

if (!is_dir($dir)) {
    fwrite(STDERR, "No generated-help/ directory.\n");
    exit(2);
}

/** The scope badge Magento shows: the most granular scope the field is editable at. */
function scopeLabel(array $scope): string
{
    if (in_array('store', $scope, true)) {
        return 'Store View';
    }
    if (in_array('website', $scope, true)) {
        return 'Website';
    }
    if (in_array('default', $scope, true)) {
        return 'Global';
    }
    return '';
}

/** "field=value, other=value" from a field's <depends> list. */
function dependsString(array $depends): string
{
    $parts = [];
    foreach ($depends as $d) {
        $f = trim((string)($d['field'] ?? ''));
        if ($f === '') {
            continue;
        }
        $v = trim((string)($d['value'] ?? ''));
        $parts[] = $v === '' ? $f : "$f=$v";
    }
    return implode(', ', $parts);
}

/**
 * Convert the small Markdown subset the notes use into inline HTML for <usage>.
 * HTML is escaped first, so nothing in the note can inject markup; then `code`,
 * **bold**, *italic* and bare links are marked up and blank lines become <p>.
 */
function mdToHtml(string $src): string
{
    $src = trim($src);
    if ($src === '') {
        return '';
    }
    $esc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
    $esc = preg_replace('/`([^`]+)`/', '<code>$1</code>', $esc);
    $esc = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $esc);
    $esc = preg_replace('/(^|[\s(])\*([^*\n]+)\*/', '$1<em>$2</em>', $esc);
    $esc = preg_replace(
        '#(https?://[^\s<)]+)#',
        '<a href="$1">$1</a>',
        $esc
    );
    $paras = preg_split('/\n{2,}/', $esc);
    $html = array_map(
        static fn($p) => '<p>' . str_replace("\n", ' ', trim($p)) . '</p>',
        array_filter(array_map('trim', $paras), static fn($p) => $p !== '')
    );
    return implode('', $html);
}

/**
 * A source model whose options come from store data (customer groups, CMS pages,
 * tax classes, stores, themes, email templates, EAV attributes, …) reflects THIS
 * installation, not universal Magento — so its values must never be shipped as docs.
 * Inline <options> (no source model) are authored in system.xml and always safe.
 */
function isDynamicSource(?string $sourceModel): bool
{
    if ($sourceModel === null || $sourceModel === '') {
        return false; // inline options defined in system.xml
    }
    $norm = str_replace('\\', '/', strtolower($sourceModel));
    foreach ([
        'customer/model/config/source/group',
        'customer/model/resourcemodel/group',
        'cms/model/config/source/page',
        'cms/model/config/source/block',
        'tax/model/taxclass/source',
        'tax/model/system/config/source/tax/class',
        'store/model/system/store',
        'store/model/config/source',
        'config/model/config/source/email/template',
        'theme/model',
        'adminthemelist',
        'eav/model',
        'catalog/model/category',
        'catalog/model/product/attribute',
        'user/model/system/config/source/role',
        'user/model/resourcemodel/role',
    ] as $needle) {
        if (str_contains($norm, $needle)) {
            return true;
        }
    }
    return false;
}

/** Walk a <default> tree into a config_path => scalar-default map. */
function collectDefaults(DOMElement $node, array $trail, array &$map): void
{
    foreach ($node->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }
        $trail2 = array_merge($trail, [$child->nodeName]);
        $hasChildEl = false;
        foreach ($child->childNodes as $g) {
            if ($g instanceof DOMElement) {
                $hasChildEl = true;
                break;
            }
        }
        if ($hasChildEl) {
            collectDefaults($child, $trail2, $map);
        } else {
            $path = implode('/', $trail2);
            $val = trim($child->textContent);
            if ($path !== '' && $val !== '' && !isset($map[$path])) {
                $map[$path] = $val;
            }
        }
    }
}

/** config_path => default value, read from every module's etc/config.xml <default>. */
function buildDefaultsMap(array $roots): array
{
    $map = [];
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $pathname = $file->getPathname();
            if ($file->getFilename() !== 'config.xml' || basename(dirname($pathname)) !== 'etc') {
                continue;
            }
            $dom = new DOMDocument();
            if (!@$dom->load($pathname)) {
                continue;
            }
            foreach ($dom->getElementsByTagName('default') as $def) {
                if ($def instanceof DOMElement && $def->parentNode && $def->parentNode->nodeName === 'config') {
                    collectDefaults($def, [], $map);
                }
            }
        }
    }
    return $map;
}

// Build elementId -> extracted facts (module, section, path, technical) from the extracts.
$meta = [];
foreach (glob("$dir/*.doc.json") ?: [] as $docFile) {
    $doc = json_decode(file_get_contents($docFile), true);
    if (!is_array($doc)) {
        continue;
    }
    $module = $doc['module']['moduleName'] ?? '';
    foreach ($doc['config'] ?? [] as $f) {
        $id = (string)($f['elementId'] ?? '');
        if ($id === '') {
            continue;
        }
        $path = (string)($f['path'] ?? '');
        $technical = array_filter([
            // config_path defaults to the admin path when not declared in system.xml.
            'config_path'    => (string)($f['configPath'] ?? '') ?: $path,
            'scope'          => scopeLabel((array)($f['scope'] ?? [])),
            'source_model'   => (string)($f['sourceModel'] ?? ''),
            'backend_model'  => (string)($f['backendModel'] ?? ''),
            'frontend_model' => (string)($f['frontendModel'] ?? ''),
            'depends'        => dependsString((array)($f['depends'] ?? [])),
        ], static fn($v) => $v !== '');

        $meta[$id] = [
            'module'    => $module,
            'section'   => (string)($f['sectionLabel'] ?? ''),
            'path'      => $path,
            'technical' => $technical,
        ];
    }
}

// Optional per-field accepted values: values/<elementId>.json -> [{id,label,description?}].
$valuesById = [];
foreach (glob("$valuesDir/*.json") ?: [] as $vf) {
    $data = json_decode(file_get_contents($vf), true);
    if (!is_array($data)) {
        continue;
    }
    // Accept either {elementId: [...]} maps or a bare [...] named by file.
    if (array_is_list($data)) {
        $valuesById[basename($vf, '.json')] = $data;
    } else {
        foreach ($data as $id => $vals) {
            if (is_array($vals)) {
                $valuesById[(string)$id] = $vals;
            }
        }
    }
}

// Collect all help entries (prose). Round 1 lives in generated-help/*.help.json
// (undocumented fields); round 2 in generated-help/commented/*.help.json
// (fields that already had a native <comment>, now enriched). Both are keyed by
// elementId with facts resolved from the extracts.
$entries = [];
$helpFiles = array_merge(
    glob("$dir/*.help.json") ?: [],
    glob("$dir/commented/*.help.json") ?: []
);
foreach ($helpFiles as $helpFile) {
    $help = json_decode(file_get_contents($helpFile), true);
    if (!is_array($help)) {
        continue;
    }
    foreach ($help as $h) {
        $id = trim((string)($h['elementId'] ?? ''));
        $summary = trim((string)($h['comment'] ?? ''));
        if ($id === '' || $summary === '') {
            continue; // a field with no summary contributes nothing to serve
        }
        $entries[$id] = [
            'summary'   => $summary,
            'usage'     => mdToHtml((string)($h['modal'] ?? '')),
            'values'    => $valuesById[$id] ?? [],
            'module'    => $meta[$id]['module'] ?? '',
            'section'   => $meta[$id]['section'] ?? '',
            'path'      => $meta[$id]['path'] ?? '',
            'technical' => $meta[$id]['technical'] ?? [],
        ];
    }
}

// Config_path-keyed help expanded to every DOM element-id alias a logical field
// appears under (PayPal's regional multiplication, and round 3's merged/include
// fields the file-based extractor missed). These come from the live extractor, so
// doc.json has no facts for them; config_path (the key) is the one technical fact.
$aliasGroups = [
    ['dir' => 'paypal-chunks', 'module' => 'Magento_Paypal', 'section' => 'PayPal'],
    ['dir' => 'round3',        'module' => '',                'section' => ''],
];
foreach ($aliasGroups as $group) {
    $aliasMapFile = "$dir/{$group['dir']}/aliases.json";
    if (!is_file($aliasMapFile)) {
        continue;
    }
    $aliasMap = json_decode(file_get_contents($aliasMapFile), true) ?: [];
    $cfgHelp = [];
    foreach (glob("$dir/{$group['dir']}/*.help.json") ?: [] as $hf) {
        foreach ((array)json_decode(file_get_contents($hf), true) as $h) {
            $cp = trim((string)($h['configPath'] ?? ''));
            $summary = trim((string)($h['comment'] ?? ''));
            if ($cp !== '' && $summary !== '') {
                $cfgHelp[$cp] = ['summary' => $summary, 'usage' => mdToHtml((string)($h['modal'] ?? ''))];
            }
        }
    }
    $expanded = 0;
    foreach ($cfgHelp as $cp => $h) {
        $map = $aliasMap[$cp] ?? null;
        if (!$map) {
            continue; // not in the (core-filtered) alias map — skip
        }
        $section = ($map['section'] ?? '') !== '' ? $map['section'] : $group['section'];
        $module  = ($map['module'] ?? '') !== '' ? $map['module'] : $group['module'];
        foreach ((array)($map['aliases'] ?? []) as $elementId) {
            $elementId = trim((string)$elementId);
            if ($elementId === '' || isset($entries[$elementId])) {
                continue; // don't clobber a field that already ships its own note
            }
            $entries[$elementId] = [
                'summary'   => $h['summary'],
                'usage'     => $h['usage'],
                'values'    => $valuesById[$elementId] ?? [],
                'module'    => $module,
                'section'   => $section,
                'path'      => '',
                'technical' => ['config_path' => $cp],
            ];
            $expanded++;
        }
    }
    fwrite(STDERR, sprintf("[%s] expanded %d config-path entries to %d alias element ids\n", $group['dir'], count($cfgHelp), $expanded));
}

// Tier-2 curated cards: generated-help/rich/*.rich.json carry a clean summary, an
// authored inline-HTML usage body, and per-option values with descriptions. Where a
// field has one, it overrides the comment/modal-derived prose; usage is used as
// authored (already HTML, so NOT run through mdToHtml). Facts (path/module/technical)
// still come from the extracts.
foreach (glob("$dir/rich/*.rich.json") ?: [] as $rf) {
    $rich = json_decode(file_get_contents($rf), true);
    if (!is_array($rich)) {
        continue;
    }
    foreach ($rich as $id => $r) {
        $id = trim((string)$id);
        $summary = trim((string)($r['summary'] ?? ''));
        if ($id === '' || $summary === '') {
            continue;
        }
        $base = $entries[$id] ?? [
            'module'    => $meta[$id]['module'] ?? '',
            'section'   => $meta[$id]['section'] ?? '',
            'path'      => $meta[$id]['path'] ?? '',
            'technical' => $meta[$id]['technical'] ?? [],
        ];
        $base['summary'] = $summary;
        $base['usage']   = trim((string)($r['usage'] ?? ''));
        if (!empty($r['values']) && is_array($r['values'])) {
            $base['values'] = $r['values'];
        } elseif (!isset($base['values'])) {
            $base['values'] = $valuesById[$id] ?? [];
        }
        $entries[$id] = $base;
    }
}

// ---- Live backfill: fill the holes the file-based scan can't reach -----------------
// From output/config-live.json (the merged admin Structure): the admin path and scope
// for the 6k+ runtime-assembled fields, the resolved accepted values for select fields
// (capped + filtered to static sources), and each field's config.xml default. This only
// FILLS missing facts — it never overwrites generated prose or the curated pilot values.
$liveFile = __DIR__ . '/output/config-live.json';
$VALUES_CAP = 10;
$defaults = buildDefaultsMap([
    __DIR__ . '/../mage-os/vendor',
    __DIR__ . '/../mage-os/app/code',
]);
fwrite(STDERR, sprintf("defaults: %d config_path defaults from config.xml\n", count($defaults)));

// Per-option descriptions keyed by source-model class, reused across every field
// that uses the source model. Written once in generated-help/value-descriptions.json.
$valueDescriptions = [];
$vdFile = $dir . '/value-descriptions.json';
if (is_file($vdFile)) {
    foreach ((array)json_decode(file_get_contents($vdFile), true) as $src => $map) {
        if (is_array($map)) {
            $valueDescriptions[$src] = $map;
        }
    }
}
fwrite(STDERR, sprintf("value-descriptions: %d source models described\n", count($valueDescriptions)));

if (is_file($liveFile)) {
    $live = json_decode(file_get_contents($liveFile), true) ?: [];
    $liveById = [];
    foreach ($live as $lf) {
        if (!empty($lf['elementId'])) {
            $liveById[$lf['elementId']] = $lf;
        }
    }

    $stat = ['path' => 0, 'scope' => 0, 'models' => 0, 'default' => 0, 'values' => 0];
    $valuesShipped = [];   // source model => field count (audit)
    $valuesSkipped = [];   // dynamic source model => field count (audit)

    foreach ($entries as $id => &$e) {
        $t = $e['technical'];

        // Prefer the live default for config_path resolution.
        $cp = $t['config_path'] ?? ($e['path'] ?: '');

        $lf = $liveById[$id] ?? null;
        if ($lf) {
            if (($e['path'] ?? '') === '' && !empty($lf['path'])) {
                $e['path'] = $lf['path'];
                $stat['path']++;
            }
            if (empty($t['scope']) && !empty($lf['scope'])) {
                $sl = scopeLabel((array)$lf['scope']);
                if ($sl !== '') { $t['scope'] = $sl; $stat['scope']++; }
            }
            $modelFilled = false;
            foreach (['source_model' => 'sourceModel', 'backend_model' => 'backendModel', 'frontend_model' => 'frontendModel'] as $tk => $lk) {
                if (empty($t[$tk]) && !empty($lf[$lk])) { $t[$tk] = (string)$lf[$lk]; $modelFilled = true; }
            }
            if ($modelFilled) { $stat['models']++; }
            if (empty($t['depends']) && !empty($lf['depends'])) {
                $ds = dependsString((array)$lf['depends']);
                if ($ds !== '') { $t['depends'] = $ds; }
            }
            if (empty($t['config_path']) && !empty($lf['configPath'])) {
                $t['config_path'] = (string)$lf['configPath'];
            }
            if (empty($t['validation']) && !empty($lf['validation'])) {
                $t['validation'] = (string)$lf['validation'];
            }
            $cp = $t['config_path'] ?? ($e['path'] ?: '');

            // Accepted values: only when the field has none yet, the set is a small
            // enum, and the source is not store-data-dependent.
            if (empty($e['values']) && !empty($lf['options'])) {
                $opts = $lf['options'];
                $src = $lf['sourceModel'] ?? null;
                if (count($opts) >= 2 && count($opts) <= $VALUES_CAP && !isDynamicSource($src)) {
                    $descMap = $valueDescriptions[$src] ?? [];
                    $e['values'] = array_map(
                        static fn($o) => [
                            'id'          => (string)$o['id'],
                            'label'       => (string)$o['label'],
                            'description' => (string)($descMap[(string)$o['id']] ?? ''),
                        ],
                        $opts
                    );
                    $stat['values']++;
                    $valuesShipped[$src ?? '(inline options)'] = ($valuesShipped[$src ?? '(inline options)'] ?? 0) + 1;
                } elseif (count($opts) >= 2 && count($opts) <= $VALUES_CAP && isDynamicSource($src)) {
                    $valuesSkipped[$src] = ($valuesSkipped[$src] ?? 0) + 1;
                }
            }
        }

        // config.xml default (deterministic, env-independent).
        if (empty($t['default_value']) && $cp !== '' && isset($defaults[$cp])) {
            $t['default_value'] = $defaults[$cp];
            $stat['default']++;
        }

        $e['technical'] = $t;
    }
    unset($e);

    fwrite(STDERR, sprintf(
        "live backfill: +%d path, +%d scope, +%d models, +%d default, +%d values\n",
        $stat['path'], $stat['scope'], $stat['models'], $stat['default'], $stat['values']
    ));
    arsort($valuesShipped);
    fwrite(STDERR, "values shipped from " . count($valuesShipped) . " distinct sources; dynamic sources skipped:\n");
    arsort($valuesSkipped);
    foreach ($valuesSkipped as $src => $n) {
        fwrite(STDERR, "  skip $n x $src\n");
    }
} else {
    fwrite(STDERR, "No output/config-live.json — skipping live backfill.\n");
}

ksort($entries); // stable, diff-friendly output

// Technical nodes in presentation order (matches the converter/view).
$technicalOrder = [
    'config_path', 'default_value', 'scope', 'source_model',
    'backend_model', 'frontend_model', 'validation', 'depends', 'consumed_by',
];

// Build the XML with DOMDocument so escaping is always correct.
$dom = new DOMDocument('1.0');
$dom->formatOutput = true;
$dom->preserveWhiteSpace = false;

$config = $dom->createElement('config');
$config->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
$config->setAttribute('xsi:noNamespaceSchemaLocation', 'urn:magento:module:MageOS_InlineDocs:etc/inline_docs.xsd');
$dom->appendChild($config);

foreach ($entries as $id => $e) {
    $field = $dom->createElement('field');
    $field->setAttribute('id', $id);
    if ($e['module'] !== '') {
        $field->setAttribute('module', $e['module']);
    }
    if ($e['section'] !== '') {
        $field->setAttribute('section', $e['section']);
    }
    if ($e['path'] !== '') {
        $field->setAttribute('path', $e['path']);
    }

    $field->appendChild($dom->createElement('summary'))->appendChild($dom->createTextNode($e['summary']));

    if ($e['usage'] !== '') {
        $usage = $dom->createElement('usage');
        $usage->appendChild($dom->createCDATASection($e['usage']));
        $field->appendChild($usage);
    }

    if (!empty($e['values'])) {
        $values = $dom->createElement('values');
        foreach ($e['values'] as $v) {
            if (!isset($v['id'], $v['label'])) {
                continue;
            }
            $value = $dom->createElement('value');
            $value->setAttribute('id', (string)$v['id']);
            $value->setAttribute('label', (string)$v['label']);
            $desc = trim((string)($v['description'] ?? ''));
            if ($desc !== '') {
                $value->appendChild($dom->createTextNode($desc));
            }
            $values->appendChild($value);
        }
        if ($values->hasChildNodes()) {
            $field->appendChild($values);
        }
    }

    $technical = array_filter(
        $e['technical'],
        static fn($v) => trim((string)$v) !== ''
    );
    if ($technical) {
        $tech = $dom->createElement('technical');
        foreach ($technicalOrder as $name) {
            if (isset($technical[$name])) {
                $tech->appendChild($dom->createElement($name))
                    ->appendChild($dom->createTextNode((string)$technical[$name]));
            }
        }
        $field->appendChild($tech);
    }

    $config->appendChild($field);
}

if (!is_dir(dirname($out))) {
    fwrite(STDERR, "Output directory does not exist: " . dirname($out) . "\n");
    exit(2);
}
$dom->save($out);

fwrite(STDERR, sprintf("Wrote %d fields to %s\n", count($entries), $out));
