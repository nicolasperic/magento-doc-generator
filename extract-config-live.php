<?php
/**
 * Live admin config-field extractor.
 *
 * The standalone extract.php reads each module's system.xml in isolation, so it
 * can't resolve <include path="…">, extends="…", or region-specific section
 * re-inclusion — which is why modules like Magento_Paypal come out with blank
 * fields. This reads the ALREADY-MERGED configuration from a running Magento
 * (Config\Model\Config\Structure), which is exactly what the admin renders, so
 * element ids, labels, types and comments all match the DOM.
 *
 * Run it inside the Magento environment, e.g. (Warden):
 *   warden env exec -T php-fpm php < extract-config-live.php > output/config-live.json
 *
 * Output: a flat JSON array of field records, same shape as extract.php's
 * doc.config[] entries (plus sectionLabel/groupLabel), so worklist/apply/emit
 * consume it unchanged.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;

$root = getenv('MAGENTO_ROOT') ?: '/var/www/html';
require $root . '/app/bootstrap.php';

$bootstrap = Bootstrap::create($root, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

/** @var Structure $structure */
$structure = $om->get(Structure::class);

$fields = [];

$clean = static function (?string $s): ?string {
    if ($s === null) return null;
    $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)));
    return $s === '' ? null : $s;
};

$recordField = static function ($field, string $path) use (&$fields, $structure, $clean): void {
    if (substr_count($path, '/') < 2) {
        return;
    }
    $data = $field->getData();

    $scope = [];
    if (!empty($data['showInDefault'])) $scope[] = 'default';
    if (!empty($data['showInWebsite'])) $scope[] = 'website';
    if (!empty($data['showInStore']))   $scope[] = 'store';

    $depends = [];
    if (!empty($data['depends']['fields']) && is_array($data['depends']['fields'])) {
        foreach ($data['depends']['fields'] as $df) {
            $depends[] = ['field' => (string)($df['id'] ?? ''), 'value' => (string)($df['value'] ?? '')];
        }
    }

    // Section + nearest-group label from the path: section/.../group/field.
    $parts = explode('/', $path);
    $sectionEl = $structure->getElement($parts[0]);
    $groupEl   = count($parts) >= 3 ? $structure->getElementByPathParts(array_slice($parts, 0, count($parts) - 1)) : null;

    $fields[] = [
        'path'          => $path,
        'elementId'     => str_replace('/', '_', $path),
        'configPath'    => $field->getConfigPath() ?: null,
        'sectionId'     => $parts[0],
        'sectionLabel'  => $sectionEl ? $clean((string)$sectionEl->getLabel()) : null,
        'groupLabel'    => $groupEl ? $clean((string)$groupEl->getLabel()) : null,
        'label'         => $clean((string)$field->getLabel()),
        'type'          => $clean((string)($data['type'] ?? '')),
        'comment'       => $clean((string)$field->getComment()),
        'tooltip'       => $clean((string)$field->getTooltip()),
        'sourceModel'   => isset($data['source_model'])   ? trim((string)$data['source_model'])   : null,
        'backendModel'  => isset($data['backend_model'])  ? trim((string)$data['backend_model'])  : null,
        'frontendModel' => isset($data['frontend_model']) ? trim((string)$data['frontend_model']) : null,
        'depends'       => $depends,
        'scope'         => $scope,
    ];
};

// Drive off getFieldPaths() — the authoritative config_path → [element paths] map
// (it already reflects all <include>, extends and regional re-inclusions). Each
// element path is a real admin DOM id; one logical field can appear at many.
foreach ($structure->getFieldPaths() as $elementPaths) {
    foreach ((array)$elementPaths as $path) {
        $field = $structure->getElement($path);
        if ($field && method_exists($field, 'getConfigPath')) {
            $recordField($field, $path);
        }
    }
}

// Stable, de-duplicated by elementId (regional re-inclusions can repeat a field;
// the first occurrence — the canonical section — wins).
$byId = [];
foreach ($fields as $f) {
    $byId[$f['elementId']] ??= $f;
}
$out = array_values($byId);
usort($out, static fn($a, $b) => strcmp($a['elementId'], $b['elementId']));

fwrite(STDERR, sprintf(
    "extracted %d distinct config fields (%d with help)\n",
    count($out),
    count(array_filter($out, static fn($f) => $f['comment'] !== null))
));

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
