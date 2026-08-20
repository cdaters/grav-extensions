<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\SiteWorkshop\Service\IconBenchService;
use RocketTheme\Toolbox\Event\Event;
use Twig\TwigFunction;

final class SiteWorkshopPlugin extends Plugin
{
    public const SLUG = 'site-workshop';

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onTwigExtensions' => ['onTwigExtensions', 0],
            'onShortcodeHandlers' => ['onShortcodeHandlers', 0],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
        ];
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\SiteWorkshop\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    public function onPluginsInitialized(): void
    {
        require_once __DIR__ . '/classes/Service/IconBenchService.php';
        if (!isset($this->grav['siteWorkshop.icons'])) {
            $this->grav['siteWorkshop.icons'] = static fn (): IconBenchService => new IconBenchService();
        }
    }

    public function onTwigExtensions(): void
    {
        if (!$this->config->get('plugins.site-workshop.modules.icon_bench.enabled', true)) {
            return;
        }

        $this->grav['twig']->twig()->addFunction(new TwigFunction(
            'workshop_icon',
            fn (string $icon, array|string $options = []): string => $this->icons()->renderReference($icon, $options),
            ['is_safe' => ['html']]
        ));
    }

    public function onShortcodeHandlers(): void
    {
        if ($this->config->get('plugins.site-workshop.modules.icon_bench.enabled', true) && isset($this->grav['shortcode'])) {
            $this->grav['shortcode']->registerAllShortcodes(__DIR__ . '/classes/Shortcodes');
        }
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        require_once __DIR__ . '/classes/Service/IconBenchService.php';
        require_once __DIR__ . '/classes/Controller/ApiController.php';
        $controller = \Grav\Plugin\SiteWorkshop\Controller\ApiController::class;
        $event['routes']->group('/site-workshop', static function ($group) use ($controller): void {
            $group->get('/status', [$controller, 'status']);
            $group->get('/icons', [$controller, 'icons']);
            $group->post('/icons/refresh', [$controller, 'refreshIcons']);
        });
    }

    public function onApiSidebarItems(Event $event): void
    {
        if (!$this->config->get('plugins.site-workshop.admin.show_sidebar', true)) {
            return;
        }
        $user = $event['user'] ?? null;
        if ($user && !$this->userCan($user, 'site-workshop.read')) {
            return;
        }
        $items = $event['items'] ?? [];
        $items[] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'label' => 'Site Workshop',
            'icon' => 'fa-toolbox',
            'route' => '/plugin/' . self::SLUG,
            'priority' => 8,
        ];
        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if (($event['plugin'] ?? null) === self::SLUG) {
            $event['definition'] = [
                'id' => self::SLUG,
                'plugin' => self::SLUG,
                'title' => 'Site Workshop',
                'icon' => 'fa-toolbox',
                'page_type' => 'component',
            ];
        }
    }

    private function icons(): IconBenchService
    {
        $service = $this->grav['siteWorkshop.icons'] ?? null;
        return $service instanceof IconBenchService ? $service : new IconBenchService();
    }

    private function userCan(object $user, string $permission): bool
    {
        foreach ([$permission, 'api.super', 'admin.super'] as $candidate) {
            if ((method_exists($user, 'authorize') && (bool) $user->authorize($candidate))
                || (method_exists($user, 'get') && (bool) $user->get('access.' . $candidate))) {
                return true;
            }
        }
        return false;
    }
}
