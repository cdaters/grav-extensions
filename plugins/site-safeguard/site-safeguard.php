<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\SiteSafeguard\Service\SafeguardService;
use RocketTheme\Toolbox\Event\Event;

class SiteSafeguardPlugin extends Plugin
{
    public const SLUG = 'site-safeguard';

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
        ];
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\SiteSafeguard\\';
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

        $path = '/' . trim((string) $uri->path(), '/');
        if ($path === '/site-safeguard/download') {
            $this->enable(['onPageInitialized' => ['onPackageDownload', 10000]]);
        }
    }

    public function onPackageDownload(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        try {
            $file = (new SafeguardService())->consumeDownloadToken($token);
        } catch (\Throwable $e) {
            http_response_code(403);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            echo 'This package link is invalid or expired.';
            exit;
        }

        $this->streamAndExit((string) $file['path'], (string) $file['name']);
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        require_once __DIR__ . '/classes/Service/SafeguardService.php';
        require_once __DIR__ . '/classes/Controller/ApiController.php';

        $routes = $event['routes'];
        $controller = \Grav\Plugin\SiteSafeguard\Controller\ApiController::class;

        $routes->group('/site-safeguard', static function ($group) use ($controller): void {
            $group->get('/status', [$controller, 'status']);
            $group->post('/packages', [$controller, 'createPackage']);
            $group->post('/packages/upload', [$controller, 'uploadPackage']);
            $group->post('/packages/{name}/inspect', [$controller, 'inspectPackage']);
            $group->post('/packages/{name}/stage', [$controller, 'stagePackage']);
            $group->post('/packages/{name}/download-token', [$controller, 'createDownloadToken']);
            $group->delete('/packages/{name}', [$controller, 'deletePackage']);
            $group->delete('/stages/{id}', [$controller, 'deleteStage']);
        });
    }

    public function onApiSidebarItems(Event $event): void
    {
        if (!$this->grav['config']->get('plugins.site-safeguard.admin.show_sidebar', true)) {
            return;
        }

        $user = $event['user'] ?? null;
        if ($user && !$this->userCan($user, 'site-safeguard.manage')) {
            return;
        }

        $items = $event['items'] ?? [];
        $items[] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'label' => 'Site Safeguard',
            'icon' => 'fa-shield-halved',
            'route' => '/plugin/' . self::SLUG,
            'priority' => 7,
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
            'title' => 'Site Safeguard',
            'icon' => 'fa-shield-halved',
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

    private function streamAndExit(string $path, string $name): void
    {
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            echo 'Package not found.';
            exit;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'site-safeguard.zip';
        header('Content-Type: application/zip');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            http_response_code(500);
            exit;
        }
        while (!feof($handle)) {
            echo fread($handle, 1024 * 1024);
            flush();
        }
        fclose($handle);
        exit;
    }
}
