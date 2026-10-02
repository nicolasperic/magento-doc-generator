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

// Collect all help entries (prose).
$entries = [];
foreach (glob("$dir/*.help.json") ?: [] as $helpFile) {
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
