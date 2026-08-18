<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\ImageFoundry\Service\ImageFoundryService;
use RocketTheme\Toolbox\Event\Event;
use Twig\TwigFunction;

final class ImageFoundryPlugin extends Plugin
{
    public const SLUG = 'image-foundry';

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onTwigExtensions' => ['onTwigExtensions', 0],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
        ];
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\ImageFoundry\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = __DIR__ . '/classes/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    public function onPluginsInitialized(): void
    {
        $uri = $this->grav['uri'] ?? null;
        if (!$uri || !method_exists($uri, 'path')) {
            return;
        }
        $configured = '/' . trim((string) $this->config->get('plugins.image-foundry.route', '/image-foundry/asset'), '/');
        $path = '/' . trim((string) $uri->path(), '/');
        if ($path === $configured || str_starts_with($path, $configured . '/')) {
            $this->enable(['onPageInitialized' => ['onServeAsset', 10000]]);
        }
    }

    public function onServeAsset(): void
    {
        $configured = '/' . trim((string) $this->config->get('plugins.image-foundry.route', '/image-foundry/asset'), '/');
        $path = '/' . trim((string) $this->grav['uri']->path(), '/');
        $id = rawurldecode(substr($path, strlen($configured) + 1));
        try {
            $asset = (new ImageFoundryService())->resolveAsset($id);
        } catch (\Throwable) {
            http_response_code(404);
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            echo 'Image derivative not found.';
            exit;
        }

        $etag = (string) $asset['etag'];
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            header_remove('Set-Cookie');
            header_remove('Pragma');
            header_remove('Expires');
            http_response_code(304);
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=31536000, immutable');
            exit;
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header_remove('Set-Cookie');
        header_remove('Pragma');
        header_remove('Expires');
        header('Content-Type: ' . $asset['mime']);
        header('Content-Length: ' . (string) $asset['bytes']);
        header('Content-Disposition: inline');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noimageindex');
        header('Cross-Origin-Resource-Policy: same-site');
        header('Referrer-Policy: no-referrer');
        readfile((string) $asset['path']);
        exit;
    }

    public function onTwigExtensions(): void
    {
        $this->grav['twig']->twig()->addFunction(new TwigFunction('foundry_picture', function (
            $source,
            $fallbackUrl,
            $alt = '',
            $sizes = '100vw',
            $class = '',
            $loading = 'lazy'
        ): string {
            try {
                return (new ImageFoundryService())->pictureMarkup(
                    (string) $source,
                    (string) $fallbackUrl,
                    (string) $alt,
                    (string) $sizes,
                    (string) $class,
                    (string) $loading
                );
            } catch (\Throwable $e) {
                $this->grav['log']->warning('[Image Foundry] Twig helper failed: ' . $e->getMessage());
                return '<img src="' . htmlspecialchars((string) $fallbackUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '" alt="' . htmlspecialchars((string) $alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            }
        }, ['is_safe' => ['html']]));
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        require_once __DIR__ . '/classes/Service/ImageFoundryService.php';
        require_once __DIR__ . '/classes/Controller/ApiController.php';
        $routes = $event['routes'];
        $controller = \Grav\Plugin\ImageFoundry\Controller\ApiController::class;
        $routes->group('/image-foundry', static function ($group) use ($controller): void {
            $group->get('/status', [$controller, 'status']);
            $group->post('/scan', [$controller, 'scan']);
            $group->post('/build', [$controller, 'build']);
            $group->delete('/derivatives', [$controller, 'purge']);
        });
    }

    public function onApiSidebarItems(Event $event): void
    {
        if (!$this->config->get('plugins.image-foundry.admin.show_sidebar', true)) {
            return;
        }
        $user = $event['user'] ?? null;
        if ($user && !$this->userCan($user, 'image-foundry.manage')) {
            return;
        }
        $items = $event['items'] ?? [];
        $items[] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'label' => 'Image Foundry',
            'icon' => 'fa-images',
            'route' => '/plugin/' . self::SLUG,
            'priority' => 8,
        ];
        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if (($event['plugin'] ?? null) !== self::SLUG) {
            return;
        }
        $event['definition'] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'title' => 'Image Foundry',
            'icon' => 'fa-images',
            'page_type' => 'component',
        ];
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
