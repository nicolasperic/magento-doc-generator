<?php
/**
 * Backfill structure labels onto every already-imported module by re-ingesting with
 * --force (generatedAt is unchanged, so the staleness guard would otherwise 409).
 * ingest.php now emits `labels` from labels.tsv, so this just re-sends each bundle.
 *
 * Usage: DOCS_INGEST_TOKEN=... php backfill_labels.php
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
$TOOL = __DIR__;
$OUT = mdg_output_dir();
$q = fn($s) => escapeshellarg($s);

$dirs = array_map('basename', glob("$OUT/*", GLOB_ONLYDIR));
sort($dirs);
echo "backfilling labels on " . count($dirs) . " modules\n\n";

$ok = 0; $fail = 0; $withLabels = 0; $noLabels = [];
foreach ($dirs as $d) {
    if (!is_file("$OUT/$d/module.doc.json")) continue;
    $status = 'unknown'; $http = '???';
    for ($a = 1; $a <= 3; $a++) {
        $res = shell_exec("php {$q("$TOOL/ingest.php")} {$q($d)} --force 2>&1");
        $status = preg_match('/"status":"(\w+)"/', $res, $m) ? $m[1] : 'unknown';
        $http = preg_match('/HTTP (\d+)/', $res, $hm) ? $hm[1] : '???';
        if ((int)$http < 500) break;
        usleep(1_500_000 * $a);
    }
    // count labels this module carries (from labels.tsv)
    $n = 0;
    foreach (file(mdg_labels_file(), FILE_IGNORE_NEW_LINES) as $line) {
        if (str_starts_with($line, "$d\t")) {
            $c = explode("\t", $line);
            $n = count(array_filter(explode('|', $c[2] ?? ''))) + count(array_filter(explode('|', $c[3] ?? ''))) + count(array_filter(explode('|', $c[4] ?? '')));
        }
    }
    if (in_array($status, ['created', 'updated'], true)) { $ok++; if ($n) $withLabels++; else $noLabels[] = $d; }
    else $fail++;
    printf("%-46s labels=%-2d ingest=HTTP %s %s\n", $d, $n, $http, $status);
}
echo "\nok=$ok  failed=$fail  with-labels=$withLabels\n";
if ($noLabels) echo "modules with 0 labels: " . implode(', ', $noLabels) . "\n";
