<?php
/**
 * Dead-simple progress tracker for the module documentation run.
 *
 * Data file: modules.status.tsv  (human-readable, greppable)  — MDG_STATUS_FILE
 * Modules scanned from MDG_MODULES_DIR (or --modules-dir=PATH).
 *   columns:  status <TAB> dir <TAB> moduleName <TAB> updatedAt <TAB> note
 *   statuses: pending | done | failed | skip
 *
 * Commands:
 *   php track.php init                 # add any new module dirs as "pending" (idempotent)
 *   php track.php status               # summary counts + progress bar
 *   php track.php next [N] [status]    # print next N dirs (default N=10, status=pending)
 *   php track.php list [status]        # print rows (optionally filtered)
 *   php track.php set <dir> <status> [note...]   # update one module's status
 */
declare(strict_types=1);
require __DIR__ . '/config.php';

define('FILE', mdg_status_file());
define('MODULES_DIR', mdg_modules_dir());
const STATUSES = ['pending', 'done', 'failed', 'skip'];

function loadRows(): array {
    if (!is_file(FILE)) return [];
    $rows = [];
    foreach (file(FILE, FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === '' || $line[0] === '#') continue;
        $p = explode("\t", $line);
        $dir = $p[1] ?? '';
        if ($dir === '') continue;
        $rows[$dir] = [
            'status'     => $p[0] ?? 'pending',
            'dir'        => $dir,
            'moduleName' => $p[2] ?? '',
            'updatedAt'  => $p[3] ?? '',
            'note'       => $p[4] ?? '',
        ];
    }
    return $rows;
}

function saveRows(array $rows): void {
    ksort($rows);
    $out = "# status\tdir\tmoduleName\tupdatedAt\tnote\n";
    foreach ($rows as $r) {
        $out .= implode("\t", [$r['status'], $r['dir'], $r['moduleName'], $r['updatedAt'], $r['note']]) . "\n";
    }
    file_put_contents(FILE, $out);
}

function moduleNameOf(string $dir): string {
    $mx = MODULES_DIR . "/$dir/etc/module.xml";
    if (is_file($mx) && preg_match('/<module\s+name="([^"]+)"/', file_get_contents($mx), $m)) {
        return $m[1];
    }
    return '';
}

$cmd = $argv[1] ?? 'status';
$rows = loadRows();

switch ($cmd) {
    case 'init': {
        $added = 0;
        foreach (glob(MODULES_DIR . '/module-*', GLOB_ONLYDIR) as $path) {
            $dir = basename($path);
            if (isset($rows[$dir])) continue;
            $rows[$dir] = [
                'status' => 'pending', 'dir' => $dir,
                'moduleName' => moduleNameOf($dir), 'updatedAt' => '', 'note' => '',
            ];
            $added++;
        }
        saveRows($rows);
        fwrite(STDERR, "init: +$added new (total " . count($rows) . ") → " . FILE . "\n");
        break;
    }
    case 'status': {
        $counts = array_fill_keys(STATUSES, 0);
        foreach ($rows as $r) { $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1; }
        $total = count($rows) ?: 1;
        $done = $counts['done'];
        $pct = (int) round($done / $total * 100);
        $bar = str_repeat('█', intdiv($pct, 2)) . str_repeat('░', 50 - intdiv($pct, 2));
        echo "modules: " . count($rows) . "\n";
        echo "[$bar] {$pct}%  ({$done}/" . count($rows) . " done)\n";
        foreach (STATUSES as $s) echo "  " . str_pad($s, 8) . ": " . ($counts[$s] ?? 0) . "\n";
        break;
    }
    case 'next': {
        $n = (int) ($argv[2] ?? 10);
        $status = $argv[3] ?? 'pending';
        $i = 0;
        foreach ($rows as $r) {
            if ($r['status'] === $status) { echo $r['dir'] . "\n"; if (++$i >= $n) break; }
        }
        break;
    }
    case 'list': {
        $status = $argv[2] ?? null;
        foreach ($rows as $r) {
            if ($status && $r['status'] !== $status) continue;
            echo str_pad($r['status'], 8) . "  " . str_pad($r['dir'], 48) . "  " . $r['note'] . "\n";
        }
        break;
    }
    case 'set': {
        $dir = $argv[2] ?? null;
        $status = $argv[3] ?? null;
        $note = implode(' ', array_slice($argv, 4));
        if (!$dir || !$status || !in_array($status, STATUSES, true)) {
            fwrite(STDERR, "usage: php track.php set <dir> <" . implode('|', STATUSES) . "> [note...]\n");
            exit(2);
        }
        if (!isset($rows[$dir])) {
            $rows[$dir] = ['status' => $status, 'dir' => $dir, 'moduleName' => moduleNameOf($dir), 'updatedAt' => '', 'note' => ''];
        }
        $rows[$dir]['status'] = $status;
        $rows[$dir]['updatedAt'] = gmdate('c');
        if ($note !== '') $rows[$dir]['note'] = $note;
        saveRows($rows);
        fwrite(STDERR, "set $dir → $status\n");
        break;
    }
    default:
        fwrite(STDERR, "commands: init | status | next [N] [status] | list [status] | set <dir> <status> [note]\n");
        exit(2);
}
