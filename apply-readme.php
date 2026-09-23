<?php
/**
 * Safely write a generated README into a module directory WITHOUT clobbering
 * manual edits.
 *
 * Usage:
 *   php apply-readme.php <module-dir> <generated-md> [--force] [--merge]
 *
 * Decision rules (in order):
 *   1. No README, or a "trivial" README (<=3 non-empty lines / just a title)
 *        -> write the full generated file. SAFE.
 *   2. README already contains our <!-- doc:generated ... --> markers
 *        -> splice: replace ONLY the generated block, keep everything else
 *           (e.g. "Team Notes"). But first verify nobody hand-edited *inside*
 *           the block: compare the on-disk block hash to the `hash=` stamped in
 *           the marker, AND compare file mtime to the stamped `generatedAt`.
 *           If the block was changed by a human -> REFUSE unless --force.
 *   3. README has real hand-written content but NO markers
 *        -> do NOT overwrite. With --merge, prepend a generated block above the
 *           existing content; otherwise refuse and explain.
 *
 * Exit codes: 0 written/spliced, 3 skipped (needs --force/--merge), 2 usage.
 */
declare(strict_types=1);

$dir = $argv[1] ?? null;
$src = $argv[2] ?? null;
$force = in_array('--force', $argv, true);
$merge = in_array('--merge', $argv, true);
if (!$dir || !is_dir($dir) || !$src || !is_file($src)) {
    fwrite(STDERR, "Usage: php apply-readme.php <module-dir> <generated-md> [--force] [--merge]\n");
    exit(2);
}
$target = rtrim($dir, '/') . '/README.md';
$genFull = file_get_contents($src);

const OPEN_RE  = '/<!--\s*doc:generated\b([^>]*)-->/';
const CLOSE    = '<!-- /doc:generated -->';

/** Extract the inner block text between the generated markers (null if absent). */
function extractBlock(string $md): ?array {
    if (!preg_match(OPEN_RE, $md, $m, PREG_OFFSET_CAPTURE)) return null;
    $openStart = $m[0][1];
    $openEnd   = $openStart + strlen($m[0][0]);
    $closePos  = strpos($md, CLOSE, $openEnd);
    if ($closePos === false) return null;
    return [
        'openTag' => $m[0][0],
        'attrs'   => $m[1][0],
        'inner'   => substr($md, $openEnd, $closePos - $openEnd),
        'start'   => $openStart,
        'end'     => $closePos + strlen(CLOSE),
    ];
}
function attr(string $attrs, string $key): ?string {
    return preg_match('/\b' . preg_quote($key, '/') . '=([^\s"]+)/', $attrs, $mm) ? $mm[1] : null;
}
function isTrivial(string $md): bool {
    $lines = array_filter(array_map('trim', preg_split('/\R/', $md)), fn($l) => $l !== '');
    // trivial = nothing, or just a heading / a single line (common "# Module Name")
    return count($lines) <= 3;
}
/** Stamp the generated file's open marker with generatedAt + hash of its block. */
function stampGenerated(string $genFull): string {
    $blk = extractBlock($genFull);
    if (!$blk) return $genFull; // no markers -> write as-is
    $hash = substr(sha1($blk['inner']), 0, 12);
    $when = gmdate('c');
    $attrs = rtrim($blk['attrs']);
    $attrs = preg_replace('/\s*\b(generatedAt|hash)=[^\s]+/', '', $attrs); // drop stale
    $newOpen = "<!-- doc:generated{$attrs} generatedAt={$when} hash={$hash} -->";
    return substr($genFull, 0, $blk['start']) . $newOpen .
           substr($genFull, $blk['start'] + strlen($blk['openTag']));
}

$stampedGen = stampGenerated($genFull);

// ---- Rule 1: no/trivial README -> write full ----
if (!is_file($target) || isTrivial(file_get_contents($target))) {
    file_put_contents($target, $stampedGen);
    fwrite(STDERR, "wrote $target (was " . (is_file($target) ? "trivial" : "missing") . ")\n");
    exit(0);
}

$existing = file_get_contents($target);
$existingBlk = extractBlock($existing);

// ---- Rule 2: existing markers -> splice with edit-guard ----
if ($existingBlk) {
    $stampedHash = attr($existingBlk['attrs'], 'hash');
    $stampedAt   = attr($existingBlk['attrs'], 'generatedAt');
    $currentHash = substr(sha1($existingBlk['inner']), 0, 12);
    $mtime       = filemtime($target);
    $stampedTs   = $stampedAt ? strtotime($stampedAt) : 0;

    $editedInside = $stampedHash !== null && $currentHash !== $stampedHash;
    $fileNewer    = $stampedTs > 0 && $mtime > $stampedTs + 2; // 2s slack

    if ($editedInside && !$force) {
        fwrite(STDERR, "SKIP: $target — the generated block was hand-edited "
            . "(hash {$currentHash} != stamped {$stampedHash}"
            . ($fileNewer ? ", file mtime newer than last generation" : "")
            . "). Re-run with --force to overwrite the generated block.\n");
        exit(3);
    }
    // Splice ONLY the generated block (markers + inner) from the new file; keep the
    // existing file's manual content on both sides (e.g. its own "Team Notes" tail).
    $g = extractBlock($stampedGen);
    $genBlockWithMarkers = substr($stampedGen, $g['start'], $g['end'] - $g['start']);
    $spliced = substr($existing, 0, $existingBlk['start'])
             . $genBlockWithMarkers
             . substr($existing, $existingBlk['end']);
    file_put_contents($target, $spliced);
    fwrite(STDERR, "spliced generated block into $target (preserved surrounding manual content)\n");
    exit(0);
}

// ---- Rule 3: hand-written README, no markers ----
if ($merge) {
    file_put_contents($target, $stampedGen . "\n\n---\n\n## Original README\n\n" . $existing);
    fwrite(STDERR, "merged: prepended generated block above the original hand-written README in $target\n");
    exit(0);
}
fwrite(STDERR, "SKIP: $target has hand-written content and no doc:generated markers. "
    . "Re-run with --merge to add a generated section above it, or --force to replace.\n");
if ($force) { file_put_contents($target, $stampedGen); fwrite(STDERR, "forced overwrite.\n"); exit(0); }
exit(3);
