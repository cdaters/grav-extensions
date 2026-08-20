<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Data\Blueprint;
use Grav\Common\Data\Blueprints;
use Grav\Common\Plugin;
use Grav\Plugin\LanternSearch\Service\SearchIndexService;
use RocketTheme\Toolbox\Event\Event;

final class LanternSearchPlugin extends Plugin
{
    public const SLUG = 'lantern-search';

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onBlueprintCreated' => ['onBlueprintCreated', 0],
            'onAssetsInitialized' => ['onAssetsInitialized', 0],
            'onPageInitialized' => ['onPublicQuery', 10000],
            'onAdminAfterSave' => ['onContentChanged', 0],
            'onApiPageCreated' => ['onContentChanged', 0],
            'onApiPageUpdated' => ['onContentChanged', 0],
            'onApiPageDeleted' => ['onContentChanged', 0],
            'onApiPageMoved' => ['onContentChanged', 0],
            'onAfterCacheClear' => ['onContentChanged', 0],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
        ];
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\LanternSearch\\';
            if (!str_starts_with($class, $prefix)) return;
            $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require_once $file;
        });
    }

    public function onPluginsInitialized(): void
    {
        require_once __DIR__ . '/classes/Service/SearchIndexService.php';
        if (!isset($this->grav['lanternSearch'])) {
            $this->grav['lanternSearch'] = static fn (): SearchIndexService => new SearchIndexService();
        }
    }

    public function onBlueprintCreated(Event $event): void
    {
        static $inEvent = false;
        $blueprint = $event['blueprint'] ?? null;
        if ($inEvent || !$blueprint instanceof Blueprint || !$blueprint->get('form/fields/tabs', null, '/')) return;
        if (in_array((string) $blueprint->getFilename(), array_keys($this->grav['pages']->modularTypes()), true)) return;
        $inEvent = true;
        try {
            $blueprint->extend((new Blueprints(__DIR__ . '/blueprints/'))->get('lantern-search'), true);
        } finally {
            $inEvent = false;
        }
    }

    public function onAssetsInitialized(): void
    {
        if ($this->isAdmin() || $this->isCli() || !$this->config->get('plugins.lantern-search.ui.enabled', true)) return;
        $config = [
            'endpoint' => '/' . trim((string) $this->config->get('plugins.lantern-search.route', '/lantern-search/query'), '/'),
            'label' => (string) $this->config->get('plugins.lantern-search.ui.button_label', 'Search'),
            'placeholder' => (string) $this->config->get('plugins.lantern-search.ui.placeholder', 'Search this site…'),
            'shortcut' => (string) $this->config->get('plugins.lantern-search.ui.shortcut', '/'),
            'floating' => (bool) $this->config->get('plugins.lantern-search.ui.show_floating_button', true),
        ];
        $this->grav['assets']->addInlineJs('window.LanternSearchConfig=' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';', ['group' => 'bottom']);
        $this->grav['assets']->addCss('plugin://lantern-search/assets/lantern-search.css');
        $this->grav['assets']->addJs('plugin://lantern-search/assets/lantern-search.js', ['group' => 'bottom', 'defer' => true]);
    }

    public function onPublicQuery(): void
    {
        if ($this->isAdmin() || $this->isCli()) return;
        $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = '/' . trim(is_string($requestPath) && $requestPath !== '' ? rawurldecode($requestPath) : (string) $this->grav['uri']->path(), '/');
        $route = '/' . trim((string) $this->config->get('plugins.lantern-search.route', '/lantern-search/query'), '/');
        if ($path !== $route) return;

        try {
            $result = $this->service()->search((string) ($_GET['q'] ?? ''), [
                'category' => (string) ($_GET['category'] ?? ''),
                'tag' => (string) ($_GET['tag'] ?? ''),
                'language' => (string) ($_GET['language'] ?? ''),
                'template' => (string) ($_GET['template'] ?? ''),
            ], isset($_GET['limit']) ? (int) $_GET['limit'] : null);
            $this->sendJson($result, 200);
        } catch (\Throwable $e) {
            $this->grav['log']->error('[Lantern Search] Public query failed: ' . $e->getMessage());
            $this->sendJson(['message' => 'Search is temporarily unavailable.'], 500);
        }
    }

    public function onContentChanged(): void
    {
        try { $this->service()->markDirty(); } catch (\Throwable $e) {
            $this->grav['log']->warning('[Lantern Search] Could not mark index stale: ' . $e->getMessage());
        }
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        require_once __DIR__ . '/classes/Service/SearchIndexService.php';
        require_once __DIR__ . '/classes/Controller/ApiController.php';
        $controller = \Grav\Plugin\LanternSearch\Controller\ApiController::class;
        $event['routes']->group('/lantern-search', static function ($group) use ($controller): void {
            $group->get('/status', [$controller, 'status']);
            $group->get('/query', [$controller, 'query']);
            $group->post('/rebuild', [$controller, 'rebuild']);
        });
    }

    public function onApiSidebarItems(Event $event): void
    {
        if (!$this->config->get('plugins.lantern-search.admin.show_sidebar', true)) return;
        $user = $event['user'] ?? null;
        if ($user && !$this->userCan($user, 'lantern-search.read')) return;
        $items = $event['items'] ?? [];
        $items[] = ['id' => self::SLUG, 'plugin' => self::SLUG, 'label' => 'Lantern Search', 'icon' => 'fa-lightbulb', 'route' => '/plugin/' . self::SLUG, 'priority' => 7];
        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if (($event['plugin'] ?? null) === self::SLUG) {
            $event['definition'] = ['id' => self::SLUG, 'plugin' => self::SLUG, 'title' => 'Lantern Search', 'icon' => 'fa-lightbulb', 'page_type' => 'component'];
        }
    }

    private function service(): SearchIndexService
    {
        $service = $this->grav['lanternSearch'] ?? null;
        return $service instanceof SearchIndexService ? $service : new SearchIndexService();
    }

    private function sendJson(array $payload, int $status): never
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        http_response_code($status);
        header_remove('Set-Cookie');
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function userCan(object $user, string $permission): bool
    {
        foreach ([$permission, 'api.super', 'admin.super'] as $candidate) {
            if ((method_exists($user, 'authorize') && (bool) $user->authorize($candidate)) || (method_exists($user, 'get') && (bool) $user->get('access.' . $candidate))) return true;
        }
        return false;
    }
}
