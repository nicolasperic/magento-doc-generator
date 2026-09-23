<?php
/**
 * Magento module documentation extractor (deterministic, no LLM).
 *
 * Usage:
 *   php extract.php <module-dir> [--vendor-autoload=/path/to/vendor/autoload.php]
 *
 * Emits a single JSON document (module.doc.json) to stdout describing the
 * module's classes AND its Magento "wiring" (plugins, observers, preferences,
 * cron, webapi, db schema, queues). This JSON is the ground-truth that the
 * LLM narrative layer consumes so it can't hallucinate the wiring.
 */

declare(strict_types=1);

use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\Node;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
$moduleDir = $argv[1] ?? null;
if (!$moduleDir || !is_dir($moduleDir)) {
    fwrite(STDERR, "Usage: php extract.php <module-dir> [--vendor-autoload=PATH]\n");
    exit(2);
}
$moduleDir = rtrim(realpath($moduleDir), '/');

$autoload = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--vendor-autoload=')) {
        $autoload = substr($a, strlen('--vendor-autoload='));
    }
}
// This toolkit's own dependencies, if it was installed standalone (composer install).
if (!$autoload && is_file(__DIR__ . '/vendor/autoload.php')) {
    $autoload = __DIR__ . '/vendor/autoload.php';
}
// Best-effort: locate the host project's vendor/autoload.php by walking up.
if (!$autoload) {
    $p = $moduleDir;
    for ($i = 0; $i < 8; $i++) {
        if (is_file("$p/vendor/autoload.php")) { $autoload = "$p/vendor/autoload.php"; break; }
        $p = dirname($p);
    }
}
if (!$autoload || !is_file($autoload)) {
    fwrite(STDERR, "Could not find vendor/autoload.php (need nikic/php-parser). Pass --vendor-autoload=PATH\n");
    exit(2);
}
require $autoload;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function xml_load(string $file): ?SimpleXMLElement {
    if (!is_file($file)) return null;
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file);
    libxml_use_internal_errors($prev);
    return $xml ?: null;
}

/** First sentence/line of a phpdoc block, cleaned. */
function docSummary(?string $doc): ?string {
    if (!$doc) return null;
    $doc = preg_replace('#^/\*\*|\*/$#', '', $doc);
    $lines = [];
    foreach (preg_split('/\R/', $doc) as $ln) {
        $ln = preg_replace('/^\s*\*\s?/', '', $ln);
        if (preg_match('/^\s*@/', $ln)) break;      // stop at first @tag
        if (trim($ln) === '' && $lines) break;       // stop at blank after content
        if (trim($ln) !== '') $lines[] = trim($ln);
    }
    $s = trim(implode(' ', $lines));
    return $s === '' ? null : $s;
}

function shortClass(string $fqcn): string {
    $p = strrpos($fqcn, '\\');
    return $p !== false ? substr($fqcn, $p + 1) : $fqcn;
}

function typeToString(?Node $t): ?string {
    if ($t === null) return null;
    if ($t instanceof Node\NullableType) return '?' . typeToString($t->type);
    if ($t instanceof Node\UnionType) return implode('|', array_map('typeToString', $t->types));
    if ($t instanceof Node\IntersectionType) return implode('&', array_map('typeToString', $t->types));
    if ($t instanceof Node\Identifier) return $t->toString();
    if ($t instanceof Node\Name) return $t->toString();
    return null;
}

/** Recursively list files under a dir, skipping tests and web assets. */
function phpFiles(string $dir): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = $f->getPathname();
        if (!str_ends_with($path, '.php')) continue;
        if (preg_match('#/Test/#', $path)) continue;
        $out[] = $path;
    }
    sort($out);
    return $out;
}

// ---------------------------------------------------------------------------
// 1. Module metadata (module.xml + composer.json)
// ---------------------------------------------------------------------------
$meta = [
    'path'        => $moduleDir,
    'dirName'     => basename($moduleDir),
    'moduleName'  => null,
    'setupVersion'=> null,
    'sequence'    => [],
    'composer'    => null,
];

if ($mx = xml_load("$moduleDir/etc/module.xml")) {
    $m = $mx->module ?? null;
    if ($m !== null) {
        $meta['moduleName']   = (string)($m['name'] ?? '');
        $meta['setupVersion'] = isset($m['setup_version']) ? (string)$m['setup_version'] : null;
        if (isset($m->sequence)) {
            foreach ($m->sequence->module as $dep) {
                $meta['sequence'][] = (string)$dep['name'];
            }
        }
    }
}
if (is_file("$moduleDir/composer.json")) {
    $c = json_decode(file_get_contents("$moduleDir/composer.json"), true);
    if (is_array($c)) {
        $meta['composer'] = [
            'name'        => $c['name'] ?? null,
            'description' => $c['description'] ?? null,
            'require'     => array_keys($c['require'] ?? []),
        ];
    }
}

// ---------------------------------------------------------------------------
// 2. PHP classes (php-parser + NameResolver for FQCNs)
// ---------------------------------------------------------------------------
$parser = (new ParserFactory())->createForHostVersion();
$finder = new NodeFinder();
$classes = [];

foreach (phpFiles($moduleDir) as $file) {
    try {
        $ast = $parser->parse(file_get_contents($file));
        if ($ast === null) continue;
        $trav = new NodeTraverser();
        $trav->addVisitor(new NameResolver());
        $ast = $trav->traverse($ast);
    } catch (Throwable $e) {
        fwrite(STDERR, "parse fail $file: {$e->getMessage()}\n");
        continue;
    }

    $rel = ltrim(str_replace($moduleDir, '', $file), '/');
    $decls = $finder->find($ast, fn(Node $n) =>
        $n instanceof Node\Stmt\Class_
        || $n instanceof Node\Stmt\Interface_
        || $n instanceof Node\Stmt\Trait_);

    foreach ($decls as $d) {
        $fqcn = isset($d->namespacedName) ? $d->namespacedName->toString() : ($d->name?->toString() ?? '(anonymous)');
        $kind = 'class';
        if ($d instanceof Node\Stmt\Interface_) $kind = 'interface';
        elseif ($d instanceof Node\Stmt\Trait_) $kind = 'trait';
        elseif ($d instanceof Node\Stmt\Class_ && $d->isAbstract()) $kind = 'abstract class';

        $extends = [];
        if (isset($d->extends)) {
            $ex = is_array($d->extends) ? $d->extends : [$d->extends];
            foreach ($ex as $e) if ($e) $extends[] = $e->toString();
        }
        $implements = [];
        if (isset($d->implements)) {
            foreach ($d->implements as $i) $implements[] = $i->toString();
        }

        $methods = [];
        $constructorDeps = [];
        foreach ($finder->findInstanceOf($d, Node\Stmt\ClassMethod::class) as $m) {
            $vis = $m->isPublic() ? 'public' : ($m->isProtected() ? 'protected' : 'private');
            $params = [];
            foreach ($m->params as $p) {
                $pt = typeToString($p->type);
                $pname = $p->var instanceof Node\Expr\Variable ? (is_string($p->var->name) ? $p->var->name : null) : null;
                $params[] = [
                    'name'    => $pname,
                    'type'    => $pt,
                    'default' => $p->default !== null,
                ];
                if ($m->name->toString() === '__construct' && $pt) {
                    $constructorDeps[] = $pt;
                }
            }
            // Only surface public API methods in the summary list; keep it readable.
            if ($m->isPublic()) {
                $methods[] = [
                    'name'       => $m->name->toString(),
                    'visibility' => $vis,
                    'static'     => $m->isStatic(),
                    'params'     => $params,
                    'returns'    => typeToString($m->returnType),
                    'summary'    => docSummary($m->getDocComment()?->getText()),
                ];
            }
        }

        $classes[] = [
            'fqcn'            => $fqcn,
            'kind'            => $kind,
            'file'            => $rel,
            'summary'         => docSummary($d->getDocComment()?->getText()),
            'extends'         => $extends,
            'implements'      => $implements,
            'constructorDeps' => array_values(array_unique($constructorDeps)),
            'publicMethods'   => $methods,
        ];
    }
}

// ---------------------------------------------------------------------------
// 3. Magento wiring (config XML)
// ---------------------------------------------------------------------------
$wiring = [
    'preferences'  => [],
    'plugins'      => [],
    'virtualTypes' => [],
    'observers'    => [],
    'cron'         => [],
    'webapi'       => [],
    'db'           => [],
    'queueConsumers'=> [],
    'acl'          => [],
];
$edges = [];

// di.xml (all scopes: global + adminhtml/frontend/etc)
foreach (glob("$moduleDir/etc/*/di.xml") ?: [] as $extra) { /* handled below via explicit list */ }
$diFiles = array_filter([
    "$moduleDir/etc/di.xml",
    "$moduleDir/etc/adminhtml/di.xml",
    "$moduleDir/etc/frontend/di.xml",
    "$moduleDir/etc/graphql/di.xml",
    "$moduleDir/etc/webapi_rest/di.xml",
], 'is_file');

foreach ($diFiles as $diFile) {
    $scope = basename(dirname($diFile)) === 'etc' ? 'global' : basename(dirname($diFile));
    $di = xml_load($diFile);
    if (!$di) continue;

    foreach ($di->preference as $pref) {
        $for = (string)$pref['for']; $type = (string)$pref['type'];
        $wiring['preferences'][] = ['for' => $for, 'type' => $type, 'scope' => $scope];
        $edges[] = ['kind' => 'preference', 'from' => $type, 'to' => $for, 'scope' => $scope];
    }
    foreach ($di->virtualType as $vt) {
        $wiring['virtualTypes'][] = ['name' => (string)$vt['name'], 'type' => (string)$vt['type'], 'scope' => $scope];
    }
    foreach ($di->type as $t) {
        $target = (string)$t['name'];
        foreach ($t->plugin as $pl) {
            $entry = [
                'target'    => $target,
                'plugin'    => (string)$pl['type'],
                'name'      => (string)$pl['name'],
                'sortOrder' => isset($pl['sortOrder']) ? (int)$pl['sortOrder'] : null,
                'disabled'  => isset($pl['disabled']) && (string)$pl['disabled'] === 'true',
                'scope'     => $scope,
            ];
            $wiring['plugins'][] = $entry;
            if (!$entry['disabled']) {
                $edges[] = ['kind' => 'plugin', 'from' => $entry['plugin'], 'to' => $target, 'scope' => $scope];
            }
        }
    }
}

// events.xml (all scopes)
foreach (array_filter([
    "$moduleDir/etc/events.xml",
    "$moduleDir/etc/adminhtml/events.xml",
    "$moduleDir/etc/frontend/events.xml",
], 'is_file') as $evFile) {
    $scope = basename(dirname($evFile)) === 'etc' ? 'global' : basename(dirname($evFile));
    $ev = xml_load($evFile);
    if (!$ev) continue;
    foreach ($ev->event as $event) {
        $eventName = (string)$event['name'];
        foreach ($event->observer as $obs) {
            $inst = (string)$obs['instance'];
            $disabled = isset($obs['disabled']) && (string)$obs['disabled'] === 'true';
            $wiring['observers'][] = [
                'event'    => $eventName,
                'observer' => $inst,
                'name'     => (string)$obs['name'],
                'disabled' => $disabled,
                'scope'    => $scope,
            ];
            if (!$disabled) {
                // Uniform {kind,from,to} edge shape so downstream graph builders can map it.
                // For observers, `to` is the event the observer listens on.
                $edges[] = ['kind' => 'observer', 'from' => $inst, 'to' => $eventName, 'scope' => $scope];
            }
        }
    }
}

// crontab.xml
if ($cron = xml_load("$moduleDir/etc/crontab.xml")) {
    foreach ($cron->group as $g) {
        $gid = (string)$g['id'];
        foreach ($g->job as $job) {
            $wiring['cron'][] = [
                'group'    => $gid,
                'name'     => (string)$job['name'],
                'instance' => (string)$job['instance'],
                'method'   => (string)$job['method'],
                'schedule' => isset($job->schedule) ? trim((string)$job->schedule) : (string)($job['schedule'] ?? ''),
            ];
        }
    }
}

// webapi.xml
if ($wa = xml_load("$moduleDir/etc/webapi.xml")) {
    foreach ($wa->route as $route) {
        $res = [];
        if (isset($route->resources)) {
            foreach ($route->resources->resource as $r) $res[] = (string)$r['ref'];
        }
        $wiring['webapi'][] = [
            'method'    => (string)$route['method'],
            'url'       => (string)$route['url'],
            'service'   => (string)($route->service['class'] ?? ''),
            'call'      => (string)($route->service['method'] ?? ''),
            'resources' => $res,
        ];
    }
}

// db_schema.xml
if ($db = xml_load("$moduleDir/etc/db_schema.xml")) {
    foreach ($db->table as $tbl) {
        $cols = [];
        foreach ($tbl->column as $col) {
            $cols[] = [
                'name'    => (string)$col['name'],
                'type'    => (string)$col->attributes('xsi', true)['type'] ?: (string)$col['xsi:type'],
                'nullable'=> isset($col['nullable']) ? ((string)$col['nullable'] === 'true') : null,
                'comment' => isset($col['comment']) ? (string)$col['comment'] : null,
            ];
        }
        $wiring['db'][] = [
            'table'    => (string)$tbl['name'],
            'resource' => isset($tbl['resource']) ? (string)$tbl['resource'] : null,
            'comment'  => isset($tbl['comment']) ? (string)$tbl['comment'] : null,
            'columns'  => $cols,
        ];
    }
}

// queue_consumer.xml
if ($qc = xml_load("$moduleDir/etc/queue_consumer.xml")) {
    foreach ($qc->consumer as $c) {
        $wiring['queueConsumers'][] = [
            'name'     => (string)$c['name'],
            'queue'    => (string)$c['queue'],
            'handler'  => (string)($c['handler'] ?? ''),
            'instance' => (string)($c['consumerInstance'] ?? ''),
        ];
    }
}

// acl.xml (flatten resource ids)
if ($acl = xml_load("$moduleDir/etc/acl.xml")) {
    $walk = function ($node) use (&$walk, &$wiring) {
        foreach ($node->resource as $r) {
            $wiring['acl'][] = ['id' => (string)$r['id'], 'title' => (string)($r['title'] ?? '')];
            $walk($r);
        }
    };
    if (isset($acl->acl->resources)) $walk($acl->acl->resources);
}

// Constructor-dependency edges (DI graph) — only for types that look like classes.
foreach ($classes as $c) {
    foreach ($c['constructorDeps'] as $dep) {
        if (preg_match('/^[\\\\A-Za-z]/', $dep) && str_contains($dep, '\\')) {
            $edges[] = ['kind' => 'inject', 'from' => $c['fqcn'], 'to' => ltrim($dep, '\\')];
        }
    }
}

// Which config files exist (so the narrative layer knows the module's "surface")
$configPresent = [];
foreach (glob("$moduleDir/etc/**/*.xml") ?: [] as $f) $configPresent[] = ltrim(str_replace($moduleDir, '', $f), '/');
foreach (glob("$moduleDir/etc/*.xml") ?: [] as $f) $configPresent[] = ltrim(str_replace($moduleDir, '', $f), '/');
sort($configPresent);

// ---------------------------------------------------------------------------
// 4. GraphQL schema (etc/schema.graphqls) — resolver-backed Query/Mutation fields
// ---------------------------------------------------------------------------
$graphql = [];
foreach (glob("$moduleDir/etc/schema.graphqls") ?: [] as $gqlFile) {
    $sdl = file_get_contents($gqlFile);
    // Scan every type/interface block (not just Query/Mutation) for @resolver-backed fields —
    // Magento attaches many resolvers to ProductInterface, CustomerInterface, etc.
    if (preg_match_all('/(?:extend\s+)?(?:type|interface)\s+(\w+)[^{]*\{(.*?)\}/s', $sdl, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $b) {
            $typeName = $b[1];
            // field name + type, then any directives (@doc, newlines…) up to @resolver(class:"…")
            if (preg_match_all(
                '/(\w+)\s*(?:\([^)]*\))?\s*:\s*[\w\[\]!]+[^}]*?@resolver\s*\(\s*class\s*:\s*"([^"]+)"/s',
                $b[2], $fm, PREG_SET_ORDER
            )) {
                foreach ($fm as $f) {
                    $graphql[] = ['root' => $typeName, 'field' => $f[1], 'resolver' => str_replace('\\\\', '\\', $f[2])];
                }
            }
        }
    }
    if (!$graphql) {
        $graphql[] = ['root' => null, 'field' => 'schema.graphqls', 'resolver' => null];
    }
}

// ---------------------------------------------------------------------------
// 5. Components (DocuHub capability facets): {type, name, target?, meta?}
// ---------------------------------------------------------------------------
$components = [];
$addComp = function (string $type, string $name, ?string $target = null, array $meta = []) use (&$components) {
    $meta = array_filter($meta, fn($v) => $v !== null && $v !== '' && $v !== []);
    $c = ['type' => $type, 'name' => $name];
    if ($target) $c['target'] = $target;
    if ($meta) $c['meta'] = $meta;
    $components[] = $c;
};
foreach ($wiring['plugins'] as $p) {
    if ($p['disabled']) continue;
    $addComp('plugin', $p['name'] ?: shortClass($p['plugin']), $p['target'],
        ['class' => $p['plugin'], 'sortOrder' => $p['sortOrder'], 'area' => $p['scope']]);
}
foreach ($wiring['observers'] as $o) {
    if ($o['disabled']) continue;
    $addComp('observer', $o['event'], $o['observer'], ['area' => $o['scope'], 'name' => $o['name']]);
}
foreach ($wiring['cron'] as $c) {
    $addComp('cron', $c['name'], $c['instance'], ['schedule' => $c['schedule'], 'method' => $c['method'], 'group' => $c['group']]);
}
foreach ($wiring['webapi'] as $w) {
    $addComp('webapi', trim($w['method'] . ' ' . $w['url']), $w['service'],
        ['method' => $w['method'], 'service' => $w['service'], 'call' => $w['call'], 'acl' => $w['resources']]);
}
foreach ($wiring['db'] as $t) {
    $addComp('db_schema', $t['table'], null, ['columns' => array_map(fn($col) => $col['name'], $t['columns'])]);
}
foreach ($wiring['preferences'] as $p) {
    $addComp('preference', shortClass($p['for']), $p['for'], ['implementation' => $p['type'], 'area' => $p['scope']]);
}
foreach ($wiring['queueConsumers'] as $q) {
    $addComp('queue', $q['name'], $q['instance'] ?: $q['handler'], ['queue' => $q['queue'], 'handler' => $q['handler']]);
}
foreach ($graphql as $g) {
    $name = $g['root'] ? "{$g['root']}.{$g['field']}" : $g['field'];
    $kind = in_array($g['root'], ['Query', 'Mutation'], true) ? strtolower($g['root']) : ($g['root'] ? 'field' : null);
    $addComp('graphql', $name, $g['resolver'], ['kind' => $kind, 'resolver' => $g['resolver']]);
}
// CLI commands (heuristic: Console namespace or extends a *Command class)
foreach ($classes as $c) {
    if ($c['kind'] === 'interface') continue;
    $isCli = str_contains($c['fqcn'], '\\Console\\')
        || (bool) array_filter($c['extends'], fn($e) => str_ends_with($e, 'Command'));
    if ($isCli) $addComp('cli', shortClass($c['fqcn']), $c['fqcn']);
}

// ---------------------------------------------------------------------------
// Assemble
// ---------------------------------------------------------------------------
$doc = [
    'schemaVersion' => 1,
    'generatedBy'   => 'magento-doc-generator/extract.php',
    'generatedAt'   => gmdate('c'),   // when the SOURCE was scanned; stable across re-imports
    'module'        => $meta,
    'stats'         => [
        'classes'   => count($classes),
        'plugins'   => count($wiring['plugins']),
        'observers' => count($wiring['observers']),
        'preferences'=> count($wiring['preferences']),
        'cronJobs'  => count($wiring['cron']),
        'webapi'    => count($wiring['webapi']),
        'graphql'   => count($graphql),
        'tables'    => count($wiring['db']),
        'components'=> count($components),
    ],
    'configFiles'   => array_values(array_unique($configPresent)),
    'classes'       => $classes,
    'wiring'        => $wiring + ['graphql' => $graphql],
    'edges'         => $edges,
    'components'    => $components,
];

echo json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
