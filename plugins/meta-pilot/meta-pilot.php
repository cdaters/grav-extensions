<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Data\Blueprint;
use Grav\Common\Data\Blueprints;
use Grav\Common\Plugin;
use Grav\Plugin\MetaPilot\Service\MetaPilotService;
use RocketTheme\Toolbox\Event\Event;

final class MetaPilotPlugin extends Plugin
{
    public const SLUG = 'meta-pilot';

    public static function getSubscribedEvents(): array
    {
        return [
            'onBlueprintCreated' => ['onBlueprintCreated', 0],
            'onPageInitialized' => ['onServeDocument', 10000],
            'onOutputGenerated' => ['onOutputGenerated', -200],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
        ];
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\MetaPilot\\';
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

    public function onServeDocument(): void
    {
        if ($this->isAdmin() || $this->isCli()) {
            return;
        }
        $service = new MetaPilotService();
        $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = '/' . trim(is_string($requestPath) && $requestPath !== ''
            ? rawurldecode($requestPath)
            : (string) $this->grav['uri']->path(), '/');
        $sitemap = '/' . trim((string) $this->config->get('plugins.meta-pilot.sitemap.route', '/sitemap.xml'), '/');
        $robots = '/' . trim((string) $this->config->get('plugins.meta-pilot.robots.route', '/robots.txt'), '/');
        if ($path !== $sitemap && $path !== $robots) {
            return;
        }
        try {
            if ($path === $sitemap && $this->config->get('plugins.meta-pilot.sitemap.enabled', true)) {
                $this->sendDocument($service->sitemapXml(), 'application/xml; charset=UTF-8');
            }
            if ($path === $robots && $this->config->get('plugins.meta-pilot.robots.enabled', true)) {
                $this->sendDocument($service->robotsText(), 'text/plain; charset=UTF-8');
            }
        } catch (\Throwable $e) {
            $this->grav['log']->error('[Meta Pilot] Document generation failed: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store');
            echo 'Meta document generation failed.';
            exit;
        }
    }

    public function onOutputGenerated(Event $event): void
    {
        if ($this->isAdmin() || $this->isCli()) {
            return;
        }
        $output = (string) ($event['output'] ?? '');
        if ($output === '' || stripos($output, '</head>') === false) {
            return;
        }
        try {
            $event['output'] = (new MetaPilotService())->rewriteHtml($output);
        } catch (\Throwable $e) {
            $this->grav['log']->warning('[Meta Pilot] Metadata injection skipped: ' . $e->getMessage());
        }
    }

    public function onBlueprintCreated(Event $event): void
    {
        static $inEvent = false;
        $blueprint = $event['blueprint'] ?? null;
        if ($inEvent || !$blueprint instanceof Blueprint || !$blueprint->get('form/fields/tabs', null, '/')) {
            return;
        }
        $filename = (string) $blueprint->getFilename();
        if (in_array($filename, array_keys($this->grav['pages']->modularTypes()), true)) {
            return;
        }
        $inEvent = true;
        try {
            $extension = (new Blueprints(__DIR__ . '/blueprints/'))->get('meta-pilot');
            $blueprint->extend($extension, true);
        } finally {
            $inEvent = false;
        }
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        require_once __DIR__ . '/classes/Service/MetaPilotService.php';
        require_once __DIR__ . '/classes/Controller/ApiController.php';
        $routes = $event['routes'];
        $controller = \Grav\Plugin\MetaPilot\Controller\ApiController::class;
        $routes->group('/meta-pilot', static function ($group) use ($controller): void {
            $group->get('/status', [$controller, 'status']);
            $group->get('/report', [$controller, 'report']);
        });
    }

    public function onApiSidebarItems(Event $event): void
    {
        if (!$this->config->get('plugins.meta-pilot.admin.show_sidebar', true)) {
            return;
        }
        $user = $event['user'] ?? null;
        if ($user && !$this->userCan($user, 'meta-pilot.read')) {
            return;
        }
        $items = $event['items'] ?? [];
        $items[] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'label' => 'Meta Pilot',
            'icon' => 'fa-compass',
            'route' => '/plugin/' . self::SLUG,
            'priority' => 9,
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
            'title' => 'Meta Pilot',
            'icon' => 'fa-compass',
            'page_type' => 'component',
        ];
    }

    private function sendDocument(string $body, string $contentType): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header_remove('Set-Cookie');
        header('Content-Type: ' . $contentType);
        header('Cache-Control: public, max-age=900');
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
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
