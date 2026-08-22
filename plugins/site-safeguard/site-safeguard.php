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
            'onAdminSave' => ['onAdminSave', 0],
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
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            echo 'Method not allowed.';
            exit;
        }

        $token = (string) ($_GET['token'] ?? '');
        try {
            $file = (new SafeguardService())->resolveDownloadToken($token);
        } catch (\Throwable $e) {
            $reference = $this->downloadErrorReference($e);
            $uri = $this->grav['uri'] ?? null;
            $environment = is_object($uri) && method_exists($uri, 'environment')
                ? (string) $uri->environment()
                : 'unknown';
            if ($reference !== 'SS-DL-01') {
                $this->grav['log']->error(sprintf(
                    '[Site Safeguard] Package download denied (%s; environment=%s; method=%s): %s: %s',
                    $reference,
                    $environment,
                    $method,
                    $e::class,
                    $e->getMessage()
                ));
            }
            http_response_code(403);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            header('X-Site-Safeguard-Error: ' . $reference);
            echo 'This package link is invalid or expired. Reference: ' . $reference;
            exit;
        }

        $this->streamAndExit((string) $file['path'], (string) $file['name'], $method === 'HEAD');
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
            $group->post('/stages/{id}/restore', [$controller, 'restoreStage']);
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

    public function onAdminSave(Event $event): void
    {
        $admin = $this->grav['admin'] ?? null;
        $route = is_object($admin) && property_exists($admin, 'route')
            ? trim((string) $admin->route, '/')
            : '';
        if ($route !== 'plugins/' . self::SLUG) {
            return;
        }

        $object = $event['object'] ?? null;
        if (!is_object($object) || !method_exists($object, 'get') || !method_exists($object, 'set')) {
            return;
        }

        foreach (['restore_preserve_paths', 'exclude_paths'] as $field) {
            $paths = $object->get($field);
            if (is_array($paths)) {
                $object->set($field, self::normaliseConfiguredPaths($paths));
            }
        }
    }

    /**
     * Keep Admin-managed path lists readable and deterministic. Runtime checks
     * still validate every path independently before any package or restore.
     *
     * @param array<mixed> $paths
     * @return list<string>
     */
    public static function normaliseConfiguredPaths(array $paths): array
    {
        $normalised = [];
        foreach ($paths as $path) {
            $path = trim(str_replace('\\', '/', (string) $path), '/');
            if ($path === '' || $path === '.' || str_contains($path, "\0")
                || preg_match('#(^|/)\.\.(/|$)#', $path)) {
                continue;
            }
            $normalised[] = $path;
        }

        return array_values(array_unique($normalised));
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

    private function downloadErrorReference(\Throwable $error): string
    {
        return match (true) {
            $error instanceof \Grav\Plugin\Api\Exceptions\ForbiddenException => 'SS-DL-01',
            $error instanceof \Grav\Plugin\Api\Exceptions\NotFoundException => 'SS-DL-02',
            $error instanceof \RuntimeException => 'SS-DL-03',
            default => 'SS-DL-01',
        };
    }

    private function streamAndExit(string $path, string $name, bool $headersOnly = false): void
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

        $size = (int) filesize($path);
        $start = 0;
        $end = max(0, $size - 1);
        $status = 200;
        $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
        if ($range !== '') {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)
                || ($matches[1] === '' && $matches[2] === '')) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                header('Accept-Ranges: bytes');
                exit;
            }

            if ($matches[1] === '') {
                $suffix = min($size, max(0, (int) $matches[2]));
                $start = max(0, $size - $suffix);
            } else {
                $start = (int) $matches[1];
            }
            if ($matches[2] !== '') {
                $end = min($end, (int) $matches[2]);
            }
            if ($start >= $size || $end < $start) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                header('Accept-Ranges: bytes');
                exit;
            }
            $status = 206;
        }

        $length = $end - $start + 1;
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'site-safeguard.zip';
        http_response_code($status);
        header('Content-Type: application/zip');
        header('Content-Length: ' . $length);
        header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Accept-Ranges: bytes');
        if ($status === 206) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        if ($headersOnly) {
            exit;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            http_response_code(500);
            exit;
        }
        if ($start > 0 && fseek($handle, $start) !== 0) {
            fclose($handle);
            http_response_code(500);
            exit;
        }
        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(1024 * 1024, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($handle);
        exit;
    }
}
