<?php
/**
 * Shared configuration for every script in this toolkit.
 *
 * Nothing here is vendor-specific: the modules directory, the output location and
 * the label taxonomy all come from the environment (or a local .env), so the same
 * checkout can document any Magento 2 codebase.
 *
 * Settings (env var — default):
 *   MDG_MODULES_DIR   directory containing the module-* dirs to scan   — (required)
 *   MDG_OUTPUT_DIR    where generated bundles are written              — ./output
 *   MDG_TAXONOMY      taxonomy file (see taxonomy.example.php)         — ./taxonomy.php
 *   MDG_LABELS_FILE   derived structure labels                         — ./labels.tsv
 *   MDG_STATUS_FILE   run tracker state                                — ./modules.status.tsv
 *   DOCS_INGEST_URL   DocuHub ingest endpoint      — http://localhost:3001/api/docs/ingest
 *   DOCS_INGEST_TOKEN bearer token for ingest                          — (required to push)
 *
 * A `.env` file next to this one is loaded if present (KEY=value, # comments).
 * Real environment variables always win over .env.
 */
declare(strict_types=1);

/** Load .env once, without overriding anything already in the environment. */
(static function (): void {
    $env = __DIR__ . '/.env';
    if (!is_file($env)) {
        return;
    }
    foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
        }
    }
})();

function mdg_env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

/**
 * Directory holding the module-* directories to document.
 * Resolution order: --modules-dir=PATH on the command line, then MDG_MODULES_DIR.
 */
function mdg_modules_dir(bool $required = true): ?string
{
    foreach ($GLOBALS['argv'] ?? [] as $a) {
        if (str_starts_with((string) $a, '--modules-dir=')) {
            $dir = substr((string) $a, strlen('--modules-dir='));
            return rtrim($dir, '/');
        }
    }
    $dir = mdg_env('MDG_MODULES_DIR');
    if ($dir === null) {
        if (!$required) {
            return null;
        }
        fwrite(STDERR, "MDG_MODULES_DIR is not set (or pass --modules-dir=PATH).\n"
            . "Point it at the directory containing your module-* dirs, e.g.\n"
            . "  MDG_MODULES_DIR=/path/to/magento/app/code/Vendor\n");
        exit(2);
    }
    return rtrim($dir, '/');
}

function mdg_output_dir(): string
{
    $dir = rtrim(mdg_env('MDG_OUTPUT_DIR', __DIR__ . '/output'), '/');
    if (!is_dir($dir)) {
        mkdir($dir, 0o775, true);
    }
    return $dir;
}

function mdg_labels_file(): string
{
    return mdg_env('MDG_LABELS_FILE', __DIR__ . '/labels.tsv');
}

function mdg_status_file(): string
{
    return mdg_env('MDG_STATUS_FILE', __DIR__ . '/modules.status.tsv');
}

/**
 * The label taxonomy: ['domains' => [dir => [...]], 'legacy' => [...], 'integrations' => [label => [needles]]].
 * Falls back to the shipped example so a fresh clone runs without any setup.
 */
function mdg_taxonomy(): array
{
    $file = mdg_env('MDG_TAXONOMY', __DIR__ . '/taxonomy.php');
    if (!is_file($file)) {
        $file = __DIR__ . '/taxonomy.example.php';
    }
    $t = require $file;
    return [
        'domains'      => $t['domains'] ?? [],
        'legacy'       => $t['legacy'] ?? [],
        'integrations' => $t['integrations'] ?? [],
    ];
}
