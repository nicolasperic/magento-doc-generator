<?php
/**
 * Emits a single inline_docs.xml from the generated help, for the MageOS_InlineDocs
 * module to serve inside Magento (no doc host).
 *
 * Each <field> is a small documentation card:
 *   - <summary>  the one-line note (generated `comment`)
 *   - <usage>    the optional longer note (generated `modal`), converted from the
 *                small Markdown subset to the inline HTML the card renders
 * plus module, section label, and the admin path (section/group/field). The path is
 * carried only as a copy-to-clipboard value in the card header, not shown as prose.
 *
 * Prose is generated; module, section and path are extracted facts. (Accepted-values
 * and technical-detail blocks were dropped per Mage-OS review: low signal for admins
 * and prone to drift from the real source models — see PORT_PLAN.md.)
 *
 * Usage:
 *   php emit-inline-docs.php [output.xml]
 *   (default output: ../mage-os/app/code/MageOS/InlineDocs/etc/inline_docs.xml)
 */
declare(strict_types=1);

$dir = __DIR__ . '/generated-help';
$out = $argv[1] ?? (__DIR__ . '/../mage-os/app/code/MageOS/InlineDocs/etc/inline_docs.xml');

if (!is_dir($dir)) {
    fwrite(STDERR, "No generated-help/ directory.\n");
    exit(2);
}

// Build elementId -> extracted facts (module, section, path) from the extracts.
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
        $meta[$id] = [
            'module'  => $module,
            'section' => (string)($f['sectionLabel'] ?? ''),
            'path'    => (string)($f['path'] ?? ''),
        ];
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
            'summary' => $summary,
            // Generated `usage` (from the old `modal`) is intentionally dropped: for
            // the long tail it mostly restated the label or added scope boilerplate.
            // A crisp one-line summary is the note; a fuller `usage` is kept only
            // where a human curated one (generated-help/rich/*). See PORT_PLAN.md.
            'usage'   => '',
            'module'  => $meta[$id]['module'] ?? '',
            'section' => $meta[$id]['section'] ?? '',
            'path'    => $meta[$id]['path'] ?? '',
        ];
    }
}

// Config_path-keyed help expanded to every DOM element-id alias a logical field
// appears under (PayPal's regional multiplication, and round 3's merged/include
// fields the file-based extractor missed). doc.json has no facts for them; the
// path is filled from the live Structure below.
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
                $cfgHelp[$cp] = ['summary' => $summary];
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
                'summary' => $h['summary'],
                'usage'   => '', // long-tail: one-line summary only (see note above)
                'module'  => $module,
                'section' => $section,
                'path'    => '',
            ];
            $expanded++;
        }
    }
    fwrite(STDERR, sprintf("[%s] expanded %d config-path entries to %d alias element ids\n", $group['dir'], count($cfgHelp), $expanded));
}

// Tier-2 curated cards: generated-help/rich/*.rich.json carry a clean summary and,
// where a human judged a second beat worth keeping, an authored inline-HTML usage
// body. This is the ONLY source of <usage> — the long tail is summary-only. A rich
// entry overrides the generated summary; facts (path/module/section) come from the
// extracts.
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
            'module'  => $meta[$id]['module'] ?? '',
            'section' => $meta[$id]['section'] ?? '',
            'path'    => $meta[$id]['path'] ?? '',
        ];
        $base['summary'] = $summary;
        $base['usage']   = trim((string)($r['usage'] ?? ''));
        $entries[$id] = $base;
    }
}

// ---- Live backfill: the admin path for the runtime-assembled fields ----------------
// From output/config-live.json (the merged admin Structure): the PayPal/round-3 fields
// never existed as plain <field> nodes for the file scan, so their path is only knowable
// from the live tree. This fills the path (for the copy button) and nothing else.
$liveFile = __DIR__ . '/output/config-live.json';
if (is_file($liveFile)) {
    $live = json_decode(file_get_contents($liveFile), true) ?: [];
    $liveById = [];
    foreach ($live as $lf) {
        if (!empty($lf['elementId'])) {
            $liveById[$lf['elementId']] = $lf;
        }
    }
    $filled = 0;
    foreach ($entries as $id => &$e) {
        if (($e['path'] ?? '') === '' && !empty($liveById[$id]['path'])) {
            $e['path'] = (string)$liveById[$id]['path'];
            $filled++;
        }
    }
    unset($e);
    fwrite(STDERR, sprintf("live backfill: +%d path\n", $filled));
} else {
    fwrite(STDERR, "No output/config-live.json — skipping path backfill.\n");
}

ksort($entries); // stable, diff-friendly output

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

    $config->appendChild($field);
}

if (!is_dir(dirname($out))) {
    fwrite(STDERR, "Output directory does not exist: " . dirname($out) . "\n");
    exit(2);
}
$dom->save($out);

fwrite(STDERR, sprintf("Wrote %d fields to %s\n", count($entries), $out));
