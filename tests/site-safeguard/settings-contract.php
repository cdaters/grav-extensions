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

$pluginRoot = $root . '/user/plugins/site-safeguard';
$blueprint = Symfony\Component\Yaml\Yaml::parseFile($pluginRoot . '/blueprints.yaml');
$blueprintVersion = (string) ($blueprint['version'] ?? '');
$expectedEnabled = filter_var(
    getenv('SITE_SAFEGUARD_EXPECT_RESTORE') ?: 'false',
    FILTER_VALIDATE_BOOLEAN
);

foreach (['restore_enabled', 'admin_restore_enabled'] as $field) {
    $definition = $blueprint['form']['fields'][$field] ?? null;
    if (!is_array($definition)
        || (int) ($definition['default'] ?? -1) !== 0
        || (int) ($definition['highlight'] ?? -1) !== 1) {
        throw new RuntimeException($field . ' must remain disabled by default and highlight Enabled.');
    }

    $effective = (bool) $grav['config']->get('plugins.site-safeguard.' . $field, false);
    if ($effective !== $expectedEnabled) {
        throw new RuntimeException(sprintf(
            '%s is %s after a fresh Grav boot; expected %s.',
            $field,
            $effective ? 'enabled' : 'disabled',
            $expectedEnabled ? 'enabled' : 'disabled'
        ));
    }
}

require_once $pluginRoot . '/site-safeguard.php';
require_once $pluginRoot . '/classes/Service/SafeguardService.php';
$statusVersion = (string) ((new Grav\Plugin\SiteSafeguard\Service\SafeguardService())->status()['version'] ?? '');
if ($statusVersion === '' || $statusVersion !== $blueprintVersion) {
    throw new RuntimeException(sprintf(
        'Dashboard status version %s does not match blueprint version %s.',
        $statusVersion !== '' ? $statusVersion : '(empty)',
        $blueprintVersion !== '' ? $blueprintVersion : '(empty)'
    ));
}

$normalised = Grav\Plugin\SiteSafeguardPlugin::normaliseConfiguredPaths([
    'cache',
    '/cache/',
    'user\\config\\security-private.php',
    'user/config/security-private.php',
    '',
    '.',
    '../outside',
]);
$expectedPaths = ['cache', 'user/config/security-private.php'];
if ($normalised !== $expectedPaths) {
    throw new RuntimeException('Admin path-list normalization contract failed.');
}

echo json_encode([
    'version' => $blueprintVersion,
    'dashboard_version' => $statusVersion,
    'restore_enabled' => $expectedEnabled,
    'admin_restore_enabled' => $expectedEnabled,
    'toggle_default' => 'disabled',
    'toggle_highlight' => 'enabled',
    'path_lists' => 'normalized',
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
