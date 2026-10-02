<?php
/**
 * Admin config-field help: worklist builder + apply step.
 *
 * The narrative layer (an LLM) sits between the two, exactly like the README
 * flow: `worklist` emits the fields that still need help as self-contained tasks;
 * a writer turns them into a help.json (prose from the record only, may abstain);
 * `apply` merges that back into module.doc.json — never overwriting a real
 * <comment>, recording what was generated and on what basis.
 *
 * Usage:
 *   php config-help.php worklist <module.doc.json> [--all]
 *   php config-help.php apply    <module.doc.json> <help.json> [--force] [-o OUT]
 *
 * See docs/CONFIG_HELP.md for the content format and grounding rules.
 */
declare(strict_types=1);

$cmd = $argv[1] ?? null;
if (!in_array($cmd, ['worklist', 'apply'], true)) {
    fwrite(STDERR, "Usage:\n  php config-help.php worklist <module.doc.json> [--all]\n  php config-help.php apply <module.doc.json> <help.json> [--force] [-o OUT]\n");
    exit(2);
}

function loadJson(string $file): array {
    if (!is_file($file)) { fwrite(STDERR, "No such file: $file\n"); exit(2); }
    $j = json_decode(file_get_contents($file), true);
    if (!is_array($j)) { fwrite(STDERR, "Not valid JSON: $file\n"); exit(2); }
    return $j;
}

if ($cmd === 'worklist') {
    $docFile = $argv[2] ?? null;
    $all = in_array('--all', $argv, true);
    if (!$docFile) { fwrite(STDERR, "worklist needs <module.doc.json>\n"); exit(2); }
    $doc = loadJson($docFile);
    $fields = $doc['config'] ?? [];

    $tasks = [];
    foreach ($fields as $f) {
        // By default only fields with no existing help text — the gap we're filling.
        if (!$all && !empty($f['comment'])) {
            continue;
        }
        $tasks[] = [
            'elementId'    => $f['elementId'],
            'path'         => $f['path'],
            'sectionLabel' => $f['sectionLabel'],
            'groupLabel'   => $f['groupLabel'],
            'label'        => $f['label'],
            'type'         => $f['type'],
            'sourceModel'  => $f['sourceModel'],
            'backendModel' => $f['backendModel'],
            'scope'        => $f['scope'],
            'depends'      => $f['depends'],
            'existingComment' => $f['comment'] ?? null,
        ];
    }

    fwrite(STDERR, sprintf(
        "%s: %d field(s) need help (of %d total)%s\n",
        $doc['module']['moduleName'] ?? $doc['module']['dirName'] ?? '(module)',
        count($tasks),
        count($fields),
        $all ? ' [--all: includes already-documented]' : ''
    ));
    echo json_encode([
        'module' => $doc['module']['moduleName'] ?? null,
        'tasks'  => $tasks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

// ---- apply -------------------------------------------------------------------
$docFile  = $argv[2] ?? null;
$helpFile = $argv[3] ?? null;
$force    = in_array('--force', $argv, true);
$out      = null;
foreach ($argv as $i => $a) {
    if ($a === '-o' && isset($argv[$i + 1])) $out = $argv[$i + 1];
}
if (!$docFile || !$helpFile) { fwrite(STDERR, "apply needs <module.doc.json> <help.json>\n"); exit(2); }

$doc  = loadJson($docFile);
$help = loadJson($helpFile);
// help.json may be a bare array or { items: [...] }.
$items = isset($help['items']) && is_array($help['items']) ? $help['items'] : $help;

$byId = [];
foreach ($items as $h) {
    if (!empty($h['elementId'])) $byId[$h['elementId']] = $h;
}

$applied = 0; $skippedReal = 0; $abstained = 0; $unknown = 0;
$seen = [];
// NB: iterate the real array by reference, not `$doc['config'] ?? []` — the
// null-coalesce makes a temporary copy and by-reference writes would be lost.
if (!isset($doc['config']) || !is_array($doc['config'])) $doc['config'] = [];
foreach ($doc['config'] as &$f) {
    $h = $byId[$f['elementId']] ?? null;
    if ($h === null) continue;
    $seen[$f['elementId']] = true;

    // Never overwrite help the field already ships with, unless forced.
    if (!empty($f['comment']) && !$force) {
        $skippedReal++;
        continue;
    }
    // A writer who abstained (no comment/modal) leaves the field unhelped.
    $comment = isset($h['comment']) ? trim((string)$h['comment']) : '';
    $modal   = isset($h['modal'])   ? trim((string)$h['modal'])   : '';
    if ($comment === '' && $modal === '') {
        $abstained++;
        continue;
    }

    $f['generatedComment']    = $comment !== '' ? $comment : null;
    $f['generatedModal']      = $modal   !== '' ? $modal   : null;
    $f['generatedHelpMeta']   = [
        'confidence' => $h['confidence'] ?? null,
        'basis'      => $h['basis'] ?? [],
        'generatedAt'=> gmdate('c'),
    ];
    $applied++;
}
unset($f);

foreach ($byId as $id => $_) {
    if (empty($seen[$id])) $unknown++;
}

// Recompute coverage including generated help.
$fieldsArr = $doc['config'] ?? [];
$withAnyHelp = 0;
foreach ($fieldsArr as $f) {
    if (!empty($f['comment']) || !empty($f['generatedComment'])) $withAnyHelp++;
}
$doc['stats']['configFieldsWithHelp']          = $withAnyHelp;
$doc['stats']['configFieldsWithGeneratedHelp'] = $applied + ($doc['stats']['configFieldsWithGeneratedHelp'] ?? 0);

fwrite(STDERR, sprintf(
    "applied: %d  |  skipped (already documented): %d  |  abstained: %d  |  unknown elementId: %d\ncoverage now: %d/%d (%d%%)\n",
    $applied, $skippedReal, $abstained, $unknown,
    $withAnyHelp, count($fieldsArr),
    count($fieldsArr) ? (int)round(100 * $withAnyHelp / count($fieldsArr)) : 0
));

$json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
if ($out) { file_put_contents($out, $json); fwrite(STDERR, "written: $out\n"); }
else      { echo $json; }
exit(0);
