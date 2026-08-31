<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\GravJarvis\Contracts\ProviderRegistryInterface;
use Grav\Plugin\GravJarvisBrowserFixture\BrowserFixtureProvider;
use RocketTheme\Toolbox\Event\Event;

final class GravJarvisBrowserFixturePlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return ['onJarvisProviderRegister' => ['onJarvisProviderRegister', 100]];
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\GravJarvisBrowserFixture\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    public function onJarvisProviderRegister(Event $event): void
    {
        $registry = $event['registry'] ?? null;
        if (!$registry instanceof ProviderRegistryInterface) {
            return;
        }
        $cache = (string) $this->grav['locator']->findResource('cache://');
        $state = rtrim($cache, '/\\') . '/grav-jarvis-browser-fixture';
        $registry->register(new BrowserFixtureProvider('browser-fixture', 'usable', $state));
        $registry->register(new BrowserFixtureProvider('browser-flaky', 'flaky', $state));
        $registry->register(new BrowserFixtureProvider('browser-missing', 'missing', $state));
        $registry->register(new BrowserFixtureProvider('browser-auth', 'auth', $state));
        $registry->register(new BrowserFixtureProvider('browser-unavailable', 'unavailable', $state));
    }
}
