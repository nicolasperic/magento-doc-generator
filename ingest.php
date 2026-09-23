<?php
/**
 * Push a generated doc bundle to DocuHub.
 *
 * Usage:
 *   php ingest.php <output-dirname> [--kind=module] [--force] [--space=slug] [--dry-run]
 *
 * Env (see config.php):
 *   DOCS_INGEST_URL    default http://localhost:3001/api/docs/ingest
 *   DOCS_INGEST_TOKEN  bearer token (required unless --dry-run)
 *   MDG_OUTPUT_DIR     where bundles live      MDG_MODULES_DIR  source modules
 *
 * Reads output/<dirname>/{module.doc.json, README.generated.md} and POSTs the
 * DocuHub /api/docs/ingest contract. generatedHash = sha1 of the generated block
 * (matches apply-readme.php's stamp); generatedAt = now (ISO-8601, UTC).
 */
declare(strict_types=1);
require __DIR__ . '/config.php';

$dirname = $argv[1] ?? null;
$base = mdg_output_dir() . "/$dirname";
if (!$dirname || !is_dir($base)) {
    fwrite(STDERR, "Usage: php ingest.php <output-dirname> [--kind=] [--force] [--space=] [--dry-run]\n");
    exit(2);
}
$opt = function (string $k, $def = null) {
    global $argv;
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$k=")) return substr($a, strlen("--$k="));
    }
    return $def;
};
$flag = fn(string $k) => in_array("--$k", $GLOBALS['argv'], true);

$kind   = $opt('kind', 'module');
$space  = $opt('space');
$dryRun = $flag('dry-run');
$force  = $flag('force');

$url = mdg_env('DOCS_INGEST_URL', 'http://localhost:3001/api/docs/ingest');
if ($force) $url .= (str_contains($url, '?') ? '&' : '?') . 'force=true';
if ($space) $url .= (str_contains($url, '?') ? '&' : '?') . 'space=' . rawurlencode($space);

$doc = json_decode(file_get_contents("$base/module.doc.json"), true);
$markdown = file_get_contents("$base/README.generated.md");

// Fold a pre-existing hand-written README into the payload so its human knowledge
// isn't lost. Only when the module's README.md has real content AND is NOT one we
// generated (no doc:generated markers). The local file is left untouched.
$modulesDir = mdg_modules_dir(false);
$modReadme = $modulesDir ? "$modulesDir/$dirname/README.md" : null;
if ($modReadme && is_file($modReadme)) {
    $orig = file_get_contents($modReadme);
    $nonEmpty = count(array_filter(array_map('trim', preg_split('/\R/', $orig)), fn($l) => $l !== ''));
    if ($nonEmpty > 3 && !str_contains($orig, 'doc:generated')) {
        $markdown .= "\n\n---\n\n## Prior hand-written README\n\n"
                   . "<!-- imported verbatim from the module's original README.md -->\n\n"
                   . trim($orig) . "\n";
    }
}

// generatedHash = sha1(inner generated block), first 12 — same rule as apply-readme.php
$generatedHash = null;
if (preg_match('/<!--\s*doc:generated\b[^>]*-->(.*)<!-- \/doc:generated -->/s', $markdown, $m)) {
    $generatedHash = substr(sha1($m[1]), 0, 12);
}

// Title precedence: README H1 → composer description (unless "N/A") → moduleName.
$title = null;
if (preg_match('/^\s*#\s+(.+?)\s*$/m', $markdown, $hm)) {
    $title = trim($hm[1]);
}
if (!$title) {
    $desc = $doc['module']['composer']['description'] ?? null;
    $title = ($desc && strtoupper($desc) !== 'N/A') ? $desc : ($doc['module']['moduleName'] ?? $dirname);
}

/**
 * Structure labels for a module, read from labels.tsv (dir\tmod\tdomains\tintegrations\tstatus).
 * Colored by family so DocuHub groups them: Domain blue, Integration green, Status grey.
 */
function labelsFor(string $dir): array {
    static $map = null;
    if ($map === null) {
        $map = [];
        $tsv = mdg_labels_file();
        if (is_file($tsv)) {
            foreach (file($tsv, FILE_IGNORE_NEW_LINES) as $line) {
                if ($line === '' || $line[0] === '#') continue;
                $c = explode("\t", $line);
                $map[$c[0]] = [
                    'domains'      => array_filter(explode('|', $c[2] ?? '')),
                    'integrations' => array_filter(explode('|', $c[3] ?? '')),
                    'status'       => array_filter(explode('|', $c[4] ?? '')),
                ];
            }
        }
    }
    $row = $map[$dir] ?? ['domains'=>[], 'integrations'=>[], 'status'=>[]];
    $out = [];
    foreach ($row['domains'] as $n)      $out[] = ['name' => $n, 'color' => '#2563eb']; // Domain — blue
    foreach ($row['integrations'] as $n) $out[] = ['name' => $n, 'color' => '#16a34a']; // Integration — green
    foreach ($row['status'] as $n)       $out[] = ['name' => $n, 'color' => '#a1a1aa']; // Status — grey
    return $out;
}

$payload = [
    'moduleName'    => $doc['module']['moduleName'],
    'dirName'       => $doc['module']['dirName'],
    'title'         => $title,
    'kind'          => $kind,
    'markdown'      => $markdown,
    'generatedHash' => $generatedHash,
    'generatedAt'   => $doc['generatedAt'] ?? gmdate('c'), // from extraction time, stable across re-imports
    'doc'           => $doc,
    'edges'         => $doc['edges'] ?? [],
    'components'    => $doc['components'] ?? [],
    'labels'        => labelsFor($doc['module']['dirName'] ?? $dirname),
];
if ($space) $payload['space'] = $space;

$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($dryRun) {
    fwrite(STDERR, "DRY RUN → $url\n");
    fwrite(STDERR, "payload bytes: " . strlen($json) . "  moduleName={$payload['moduleName']}  hash={$generatedHash}\n");
    exit(0);
}

$token = mdg_env('DOCS_INGEST_TOKEN');
if (!$token) { fwrite(STDERR, "DOCS_INGEST_TOKEN not set\n"); exit(2); }

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "Authorization: Bearer $token",
    ],
    CURLOPT_RETURNTRANSFER => true,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($resp === false) { fwrite(STDERR, "request failed: " . curl_error($ch) . "\n"); exit(1); }
fwrite(STDERR, "POST $url -> HTTP $code\n");
echo $resp, "\n";
exit($code >= 200 && $code < 300 ? 0 : 1);
