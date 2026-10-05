<?php
/**
 * Emits a single inline_docs.xml from every generated-help/<module>.help.json,
 * for the MageOS_InlineDocs module to serve inside Magento (no doc host).
 *
 * Each <field> carries the short comment, the longer modal, and — looked up from
 * the matching <module>.doc.json — its owning module and section label (for the
 * card footer). Prose is the generated help; module/section are extracted facts.
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

// Build elementId -> {module, section} from the extracts (facts).
$meta = [];
foreach (glob("$dir/*.doc.json") ?: [] as $docFile) {
    $doc = json_decode(file_get_contents($docFile), true);
    if (!is_array($doc)) {
        continue;
    }
    $module = $doc['module']['moduleName'] ?? '';
    foreach ($doc['config'] ?? [] as $f) {
        if (!empty($f['elementId'])) {
            $meta[$f['elementId']] = [
                'module'  => $module,
                'section' => (string)($f['sectionLabel'] ?? ''),
            ];
        }
    }
}

// Collect all help entries (prose). Round 1 lives in generated-help/*.help.json
// (undocumented fields); round 2 in generated-help/commented/*.help.json
// (fields that already had a native <comment>, now enriched). Both are keyed by
// elementId with meta resolved from the extracts.
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
        $comment = trim((string)($h['comment'] ?? ''));
        if ($id === '' || $comment === '') {
            continue; // a field with no comment contributes nothing to serve
        }
        $entries[$id] = [
            'comment' => $comment,
            'modal'   => trim((string)($h['modal'] ?? '')),
            'module'  => $meta[$id]['module'] ?? '',
            'section' => $meta[$id]['section'] ?? '',
        ];
    }
}

// Config_path-keyed help expanded to every DOM element-id alias a logical field
// appears under (PayPal's regional multiplication, and round 3's merged/include
// fields the file-based extractor missed).
// Each group pairs a dir of config_path-keyed help with an aliases.json
// ({configPath: {aliases, section?, module?}}). An alias entry may carry its own
// section/module (round 3 spans many modules); otherwise the group default wins.
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
            $comment = trim((string)($h['comment'] ?? ''));
            if ($cp !== '' && $comment !== '') {
                $cfgHelp[$cp] = ['comment' => $comment, 'modal' => trim((string)($h['modal'] ?? ''))];
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
                'comment' => $h['comment'],
                'modal'   => $h['modal'],
                'module'  => $module,
                'section' => $section,
            ];
            $expanded++;
        }
    }
    fwrite(STDERR, sprintf("[%s] expanded %d config-path entries to %d alias element ids\n", $group['dir'], count($cfgHelp), $expanded));
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
    $field->appendChild($dom->createElement('comment'))->appendChild($dom->createTextNode($e['comment']));
    if ($e['modal'] !== '') {
        $field->appendChild($dom->createElement('modal'))->appendChild($dom->createTextNode($e['modal']));
    }
    $config->appendChild($field);
}

if (!is_dir(dirname($out))) {
    fwrite(STDERR, "Output directory does not exist: " . dirname($out) . "\n");
    exit(2);
}
$dom->save($out);

fwrite(STDERR, sprintf("Wrote %d fields to %s\n", count($entries), $out));
