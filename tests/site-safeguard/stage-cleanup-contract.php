<?php

declare(strict_types=1);

define('GRAV_CLI', true);
define('GRAV_REQUEST_TIME', microtime(true));

$root = getcwd();
$autoload = require $root . '/vendor/autoload.php';
$apiAutoload = $root . '/user/plugins/api/vendor/autoload.php';
if (is_file($apiAutoload)) {
    require_once $apiAutoload;
}

$grav = Grav\Common\Grav::instance(['loader' => $autoload]);
$grav->setup();

require_once $root . '/user/plugins/site-safeguard/classes/Service/SafeguardService.php';

$service = new Grav\Plugin\SiteSafeguard\Service\SafeguardService();
$stageRoot = (string) ($service->status()['stage_path'] ?? '');
if ($stageRoot === '' || !is_dir($stageRoot)) {
    throw new RuntimeException('Site Safeguard staging root is unavailable.');
}

$id = 'black-box-unrecognized-' . bin2hex(random_bytes(5));
$path = $stageRoot . '/' . $id;
if (!mkdir($path, 0700) || file_put_contents($path . '/sentinel.txt', 'temporary') === false) {
    throw new RuntimeException('Unable to create the disposable unrecognized directory.');
}

try {
    $item = null;
    foreach ($service->stages() as $candidate) {
        if (($candidate['id'] ?? null) === $id) {
            $item = $candidate;
            break;
        }
    }
    if (!is_array($item) || ($item['recognized'] ?? true) !== false || ($item['verified'] ?? true) !== false) {
        throw new RuntimeException('An unrelated directory was not classified as unrecognized and unverified.');
    }

    $service->deleteStage($id);
    if (file_exists($path)) {
        throw new RuntimeException('The unrecognized staging directory was not removed.');
    }

    $traversalDenied = false;
    try {
        $service->deleteStage('../outside');
    } catch (Grav\Plugin\Api\Exceptions\ValidationException) {
        $traversalDenied = true;
    }
    if (!$traversalDenied) {
        throw new RuntimeException('Stage cleanup accepted a parent-traversal identifier.');
    }
} finally {
    if (is_dir($path)) {
        @unlink($path . '/sentinel.txt');
        @rmdir($path);
    }
}

echo json_encode([
    'unrecognized_directory' => 'classified',
    'cleanup' => 'completed',
    'parent_traversal' => 'denied',
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
