<?php

declare(strict_types=1);

$testHost = trim((string) getenv('SITE_SAFEGUARD_TEST_HOST'));
if ($testHost !== '') {
    $_SERVER['HTTP_HOST'] = $testHost;
    $_SERVER['SERVER_NAME'] = preg_replace('/:\d+$/', '', $testHost);
}

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

$issuer = new Grav\Plugin\SiteSafeguard\Service\SafeguardService();
$status = $issuer->status();
$package = (string) ($status['packages'][0]['name'] ?? '');
if ($package === '') {
    throw new RuntimeException('Site Safeguard has no retained package to test.');
}

$ticket = $issuer->createDownloadToken($package);
$route = (string) ($ticket['path'] ?? $ticket['url'] ?? '');
if ($route === '' || !str_starts_with($route, '/') || str_contains($route, '://')) {
    throw new RuntimeException('Site Safeguard did not issue a root-relative download route.');
}

// Simulate the exact Admin/base versus public/hostname configuration split
// that produced SS-DL-02 in production-restored DDEV installations.
$grav['config']->set('plugins.site-safeguard.package_path', '../black-box-missing-package-directory');
$publicScope = new Grav\Plugin\SiteSafeguard\Service\SafeguardService();
$resolved = $publicScope->resolveDownloadToken((string) $ticket['token']);
if (!hash_equals($package, (string) $resolved['name'])) {
    throw new RuntimeException('The signed ticket did not retain its issuing package scope.');
}

$issuingHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$_SERVER['HTTP_HOST'] = 'foreign-origin.invalid';
$hostBound = false;
try {
    $publicScope->resolveDownloadToken((string) $ticket['token']);
} catch (Grav\Plugin\Api\Exceptions\ForbiddenException) {
    $hostBound = true;
} finally {
    $_SERVER['HTTP_HOST'] = $issuingHost;
}
if (!$hostBound) {
    throw new RuntimeException('The signed ticket was accepted for another host.');
}

echo json_encode([
    'version' => $status['version'],
    'package' => $package,
    'source_path' => $resolved['path'],
    'token' => $ticket['token'],
    'route' => $route,
    'scope_independent' => true,
    'host_bound' => true,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
