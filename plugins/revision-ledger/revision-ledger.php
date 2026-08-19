<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Data\Blueprint;
use Grav\Common\Data\Blueprints;
use Grav\Common\Plugin;
use Grav\Plugin\RevisionLedger\Service\RevisionLedgerService;
use RocketTheme\Toolbox\Event\Event;

final class RevisionLedgerPlugin extends Plugin
{
    public const SLUG = 'revision-ledger';

    private array $savedRoutes = [];

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onBlueprintCreated' => ['onBlueprintCreated', 0],
            'onAdminSave' => ['onAdminSave', 1000],
            'onAdminAfterSave' => ['onAdminAfterSave', -1000],
            'onRevisionLedgerCheckpoint' => ['onExternalCheckpoint', 0],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
        ];
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\RevisionLedger\\';
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
        require_once __DIR__ . '/classes/Service/RevisionLedgerService.php';
        if (!isset($this->grav['revisionLedger'])) {
            $this->grav['revisionLedger'] = static fn (): RevisionLedgerService => new RevisionLedgerService();
        }
    }

    public function onAdminSave(Event $event): void
    {
        if (!$this->config->get('plugins.revision-ledger.automatic.enabled', true)) {
            return;
        }
        $page = $event['page'] ?? $event['object'] ?? null;
        if (!is_object($page) || !method_exists($page, 'filePath') || !method_exists($page, 'route')) {
            return;
        }
        $route = (string) $page->route();
        $path = (string) $page->filePath();
        $this->savedRoutes[$route] = true;
        if (!is_file($path)) {
            return;
        }
        try {
            $content = file_get_contents($path);
            if ($content !== false) {
                $this->service()->checkpointPage($page, $content, 'Automatic checkpoint before Admin save', 'admin-auto');
            }
        } catch (\Throwable $e) {
            $this->grav['log']->error('[Revision Ledger] Pre-save checkpoint failed: ' . $e->getMessage());
            if ($this->config->get('plugins.revision-ledger.automatic.fail_closed', false)) {
                throw $e;
            }
        }
    }

    public function onAdminAfterSave(Event $event): void
    {
        if (!$this->config->get('plugins.revision-ledger.automatic.snapshot_new_pages', true)) {
            return;
        }
        $page = $event['page'] ?? $event['object'] ?? null;
        if (!is_object($page) || !method_exists($page, 'route')) {
            return;
        }
        $route = (string) $page->route();
        if (!isset($this->savedRoutes[$route])) {
            return;
        }
        try {
            if ($this->service()->revisions($route) === []) {
                $path = (string) $page->filePath();
                $content = is_file($path) ? file_get_contents($path) : false;
                if ($content !== false) {
                    $this->service()->checkpointPage($page, $content, 'Initial page checkpoint', 'initial');
                }
            }
        } catch (\Throwable $e) {
            $this->grav['log']->warning('[Revision Ledger] Initial checkpoint failed: ' . $e->getMessage());
        }
    }

    public function onExternalCheckpoint(Event $event): void
    {
        $route = trim((string) ($event['route'] ?? ''));
        if ($route === '') {
            return;
        }
        $event['revision'] = $this->service()->checkpointRoute(
            $route,
            (string) ($event['reason'] ?? 'Plugin-requested checkpoint'),
            (string) ($event['source'] ?? 'plugin'),
            isset($event['author']) ? (string) $event['author'] : null
        );
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
            $blueprint->extend((new Blueprints(__DIR__ . '/blueprints/'))->get('revision-ledger'), true);
        } finally {
            $inEvent = false;
        }
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        require_once __DIR__ . '/classes/Service/RevisionLedgerService.php';
        require_once __DIR__ . '/classes/Controller/ApiController.php';
        $controller = \Grav\Plugin\RevisionLedger\Controller\ApiController::class;
        $event['routes']->group('/revision-ledger', static function ($group) use ($controller): void {
            $group->get('/status', [$controller, 'status']);
            $group->get('/pages', [$controller, 'pages']);
            $group->get('/revisions', [$controller, 'revisions']);
            $group->get('/revisions/{id}', [$controller, 'revision']);
            $group->get('/revisions/{id}/compare', [$controller, 'compare']);
            $group->post('/checkpoints', [$controller, 'checkpoint']);
            $group->post('/revisions/{id}/restore', [$controller, 'restore']);
            $group->post('/prune', [$controller, 'prune']);
        });
    }

    public function onApiSidebarItems(Event $event): void
    {
        if (!$this->config->get('plugins.revision-ledger.admin.show_sidebar', true)) {
            return;
        }
        $user = $event['user'] ?? null;
        if ($user && !$this->userCan($user, 'revision-ledger.read')) {
            return;
        }
        $items = $event['items'] ?? [];
        $items[] = [
            'id' => self::SLUG,
            'plugin' => self::SLUG,
            'label' => 'Revision Ledger',
            'icon' => 'fa-clock-rotate-left',
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
                'title' => 'Revision Ledger',
                'icon' => 'fa-clock-rotate-left',
                'page_type' => 'component',
            ];
        }
    }

    private function service(): RevisionLedgerService
    {
        return $this->grav['revisionLedger'] instanceof RevisionLedgerService
            ? $this->grav['revisionLedger']
            : new RevisionLedgerService();
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
