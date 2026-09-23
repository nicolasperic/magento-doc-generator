<?php
/**
 * Derive structure labels (Domain / Integration / Status) for every module bundle.
 *
 * Reads <output>/<dir>/module.doc.json and emits labels.tsv + a per-domain summary.
 *   Domain      — curated dirName → domains map from the taxonomy file (reviewable source of truth)
 *   Integration — auto from composer require + dirName signals in the taxonomy file
 *   Status      — auto (taxonomy `legacy` list + stats.classes==0 → Stub)
 *
 * Usage: php derive_labels.php            (writes labels.tsv, prints summary)
 * Config: MDG_OUTPUT_DIR, MDG_TAXONOMY, MDG_LABELS_FILE — see config.php
 */
declare(strict_types=1);
require __DIR__ . '/config.php';

$TAX    = mdg_taxonomy();
$DOMAIN = $TAX['domains'];
$LEGACY = $TAX['legacy'];
$SIGNALS = $TAX['integrations'];
$OUT    = mdg_output_dir();

// ---- Integration signals: [label => needles] against require[] + dirName ----------
function integrationsFor(string $dir, array $require, array $signals): array {
    $req = implode(' ', $require);
    $out = [];
    foreach ($signals as $label => $needles) {
        foreach ((array) $needles as $n) {
            if ($n !== '' && $n[0] === '~') { if (str_contains($dir, substr($n,1))) { $out[]=$label; break; } }
            elseif (str_contains($req, $n)) { $out[]=$label; break; }
        }
    }
    return array_values(array_unique($out));
}

// ---- Run -------------------------------------------------------------------------
$rows = [];
$uncovered = [];
foreach (glob("$OUT/*/module.doc.json") as $f) {
    $doc = json_decode(file_get_contents($f), true);
    $dir = $doc['module']['dirName'] ?? basename(dirname($f));
    $mod = $doc['module']['moduleName'] ?? '';
    $require = $doc['module']['composer']['require'] ?? [];
    $classes = $doc['stats']['classes'] ?? 0;

    $domains = $DOMAIN[$dir] ?? [];
    if (!$domains) $uncovered[] = $dir;
    $integr = integrationsFor($dir, $require, $SIGNALS);
    $status = [];
    if (in_array($dir, $LEGACY, true)) $status[] = 'Legacy';
    if ($classes === 0) $status[] = 'Stub/Placeholder';

    $rows[$dir] = ['module'=>$mod,'domains'=>$domains,'integrations'=>$integr,'status'=>$status];
}
ksort($rows);

// labels.tsv
$out = "# dir\tmoduleName\tdomains\tintegrations\tstatus\n";
foreach ($rows as $dir => $r) {
    $out .= implode("\t", [$dir, $r['module'], implode('|',$r['domains']), implode('|',$r['integrations']), implode('|',$r['status'])])."\n";
}
file_put_contents(mdg_labels_file(), $out);

// summary
$byDomain = []; $byIntegration = []; $byStatus = [];
foreach ($rows as $r) {
    foreach ($r['domains'] as $d) $byDomain[$d][] = 1;
    foreach ($r['integrations'] as $i) $byIntegration[$i][] = 1;
    foreach ($r['status'] as $s) $byStatus[$s][] = 1;
}
arsort($byDomain); arsort($byIntegration);
echo "modules: ".count($rows)."  |  uncovered domain: ".count($uncovered)."\n";
if ($uncovered) echo "  UNCOVERED: ".implode(', ',$uncovered)."\n";
echo "\n== Domain ==\n"; foreach ($byDomain as $d=>$c) printf("  %-22s %d\n",$d,count($c));
echo "\n== Integration ==\n"; foreach ($byIntegration as $i=>$c) printf("  %-26s %d\n",$i,count($c));
echo "\n== Status ==\n"; foreach ($byStatus as $s=>$c) printf("  %-18s %d\n",$s,count($c));
echo "\nwrote ".mdg_labels_file()."\n";
